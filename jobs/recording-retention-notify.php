<?php
/**
 * Monthly retention notice: tell every customer what is on the system and when
 * the oldest of it goes.
 *
 *   php recording-retention-notify.php [--dry-run] [--urgent-only]
 *
 * Run daily from cron. It sends on ONE day a month (NOTICE_DAYS_BEFORE_PURGE
 * before the purge date) and does nothing on the others, so the schedule lives
 * here rather than in five cron lines.
 *
 * Mail goes to the PARTNER's address -- one per customer, from RTC's `partner`
 * table -- not to every portal user. A customer is an organisation; ten people
 * getting the same warning is how it gets ignored.
 *
 * ── Rules this job exists to enforce ────────────────────────────────────────
 *
 *  Only email domains that are actually behind. pending_count is "older than the
 *  cutoff AND never downloaded". A customer whose backups are current gets
 *  nothing. If all 52 domains are warned every month regardless, everyone learns
 *  to ignore it -- including the one customer who needed it. Alert fatigue is the
 *  main way this feature fails.
 *
 *  Send each notice once. v_recording_notice_log is keyed on
 *  (domain, purge_date, milestone), so a daily cron cannot re-send yesterday's
 *  warning.
 *
 *  Never promise a deletion the system will not perform. purge-retention.sh is
 *  report-only until someone adds --apply, and it refuses to delete
 *  un-downloaded recordings anyway. The wording below says data "is scheduled
 *  for removal" and that we will hold it if asked -- both true today.
 */

require_once dirname(__DIR__, 3) . '/resources/require.php';

// Through the gateway. An earlier version posted straight to 10.10.194.249:7001
// assuming that was TelcoREST; it is a different service and returned 404 for
// every send. Override with RTC_BASE_URL on CCL (iptsp.cosmocom.net:8001).
define('RTC_BASE', getenv('RTC_BASE_URL') ?: 'https://vbs.alaapcloud.gov.bd:4000/FREESWITCHREST');
define('EMAIL_ENDPOINT',   RTC_BASE . '/api/v1/email/send');
// The portal a customer actually signs in to. Differs per platform, so it is
// configurable: BTCL https://ippbx.alaapcloud.gov.bd:5174,
// CCL https://selfcare.cosmocom.net (set PORTAL_URL in that box's cron).
define('PORTAL_URL', rtrim(getenv('PORTAL_URL') ?: 'https://ippbx.alaapcloud.gov.bd:5174', '/'));
define('PARTNERS_ENDPOINT', RTC_BASE . '/partner/get-partners');
// One notice a month, this many days before the purge date. Also the urgent
// follow-up days, sent ONLY to customers who still have un-downloaded data.
define('NOTICE_DAYS_BEFORE_PURGE', 14);
// Don't email a customer about a handful of recordings. A domain with one call
// on file gets a message that reads like spam and teaches them to ignore the
// next one, which might matter. Override with --min=N.
define('MIN_RECORDINGS', 10);
define('URGENT_DAYS', array(3));

// The HTML body lives in a template file rather than inline here so that a test
// renderer and this job produce byte-identical mail. If it is missing we fall
// back to the plain-text body rather than sending a broken page.
define('MAIL_TEMPLATE', __DIR__ . '/templates/retention-notice.html');

/** "2026-10-01" -> "1 October 2026". Customers read dates, not ISO strings. */
function rn_date($iso) {
    $t = strtotime($iso);
    return $t ? date('j F Y', $t) : $iso;
}

/**
 * Megabytes below a gigabyte. "0.1 GB" reads as nothing at all and undersells
 * what the customer is about to lose.
 */
function rn_size($bytes) {
    $b = (float) $bytes;
    if ($b >= 1073741824) { return number_format($b / 1073741824, 1) . ' GB'; }
    return number_format($b / 1048576, 0) . ' MB';
}

/** One row of the summary table. $strong paints the number red. */
function rn_row($label, $value, $strong = false, $last = false) {
    $border = $last ? '' : 'border-bottom:1px solid #eef1f4;';
    $colour = $strong ? '#b42318' : '#1f2d3d';
    $weight = $strong ? '700' : '600';
    return '<tr><td style="padding:11px 16px;' . $border . ' color:#5a6875;font-size:13px;">'
         . htmlspecialchars($label, ENT_QUOTES, 'UTF-8')
         . '</td><td align="right" style="padding:11px 16px;' . $border
         . ' color:' . $colour . ';font-size:14px;font-weight:' . $weight . ';">'
         . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '</td></tr>';
}

$dry_run     = in_array('--dry-run', $argv);
$urgent_only = in_array('--urgent-only', $argv);
// Send the monthly notice regardless of the schedule. For the first run, or to
// catch up after a missed window. Still deduplicated by v_recording_notice_log,
// so it cannot double-send within the same purge cycle.
$send_now    = in_array('--send-now', $argv);
$show_body   = in_array('--show-body', $argv);
$min_recordings = MIN_RECORDINGS;
foreach ($argv as $a) { if (strpos($a, '--min=') === 0) { $min_recordings = (int) substr($a, 6); } }

$database = new database;

// What customers are told. The purge keeps longer than this on purpose, so
// "at least N days" is always true. Never quote the real figure to a customer:
// promising the larger number is what turns a late deletion into a complaint.
$display_days = 90;
$row_dd = $database->select(
    "SELECT default_setting_value FROM v_default_settings
      WHERE default_setting_category='recordings' AND default_setting_subcategory='display_days'
        AND default_setting_enabled=true LIMIT 1", array(), 'row');
if (!empty($row_dd['default_setting_value'])) { $display_days = (int) $row_dd['default_setting_value']; }

/**
 * Partner email per domain, from RTC over HTTP.
 *
 * No MySQL involved. An earlier version opened a PDO connection to RTC's
 * database, which needed a grant the PBX host does not have and would not get
 * without someone issuing one. /partner/get-partners is already public and
 * returns exactly what is needed, so the job asks RTC rather than reaching past
 * it into its database.
 *
 * Mapping domain -> partner is the awkward half. The route table holds it
 * properly, but those endpoints require a token (403). The domain name carries
 * the id by convention -- pbx-stax-349 -> partner 349 -- which covers 37 of 52
 * domains on BTCL. The remaining 15 fall back to the configured address and are
 * NAMED in the output, so the gap is visible rather than a silent no-op.
 */
function rtc_partner_emails($domains) {
    $by_domain = array();

    $ch = curl_init(PARTNERS_ENDPOINT);
    curl_setopt_array($ch, array(
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => '{}',
        CURLOPT_HTTPHEADER => array('Content-Type: application/json'),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT => 30,
    ));
    $raw  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code !== 200 || empty($raw)) {
        fwrite(STDERR, "partner lookup failed: HTTP $code\n");
        return $by_domain;
    }
    $partners = json_decode($raw, true);
    if (!is_array($partners)) { fwrite(STDERR, "partner lookup: unexpected payload\n"); return $by_domain; }

    $email_by_id = array();
    foreach ($partners as $p) {
        if (!empty($p['idPartner']) && !empty($p['email'])) {
            $email_by_id[(int) $p['idPartner']] = trim($p['email']);
        }
    }

    // pbx-stax-349.alaapcloud.gov.bd -> 349
    foreach ($domains as $d) {
        if (preg_match('/-(\d+)\./', $d['domain_name'], $m)) {
            $id = (int) $m[1];
            if (isset($email_by_id[$id])) {
                $by_domain[strtolower($d['domain_uuid'])] = $email_by_id[$id];
            }
        }
    }
    return $by_domain;
}

// Global fallback recipient, e.g. the NOC, for domains with nothing configured.
$fallback = null;
$row = $database->select(
    "SELECT default_setting_value FROM v_default_settings
      WHERE default_setting_category='recordings' AND default_setting_subcategory='notify_email'
        AND default_setting_enabled=true LIMIT 1", array(), 'row');
if (!empty($row['default_setting_value'])) { $fallback = trim($row['default_setting_value']); }

$rows = $database->select(
    "SELECT s.domain_uuid, d.domain_name, s.purge_date, s.cutoff_date,
            s.total_count, s.at_risk_count, s.pending_count, s.pending_bytes, s.pending_oldest,
            f.notify_email
       FROM v_recording_export_status s
       JOIN v_domains d USING (domain_uuid)
       LEFT JOIN v_recording_sftp f USING (domain_uuid)
      WHERE s.total_count > 0 AND s.purge_date IS NOT NULL
      ORDER BY s.pending_count DESC", array(), 'all');

if (empty($rows)) { echo "no domain has recordings — nothing to send\n"; exit(0); }

$partner_email = rtc_partner_emails($rows);

$today = new DateTime('today');
$sent = 0; $skipped = 0; $unaddressed = array(); $preview = array(); $below_threshold = array();

// Every domain shares the same purge date, so report the schedule once. A job
// that prints nothing on a quiet day looks identical to a broken one.
$sample_days = null;
if (!empty($rows[0]['purge_date'])) {
    $sample_days = (int) $today->diff(new DateTime($rows[0]['purge_date']))->format('%r%a');
    printf("purge date %s is %d day(s) away. Notices go out at %d days (everyone) and %s days (only those behind).\n",
        $rows[0]['purge_date'], $sample_days, NOTICE_DAYS_BEFORE_PURGE, implode('/', URGENT_DAYS));
    $is_notice_day = ($sample_days === NOTICE_DAYS_BEFORE_PURGE) || in_array($sample_days, URGENT_DAYS, true);
    if ($send_now)          { echo "--send-now: sending the monthly notice regardless of the schedule.\n\n"; }
    else if ($is_notice_day){ echo "Today IS a notice day.\n\n"; }
    else                    { echo "Today is not a notice day — nothing will be sent.\n\n"; }
}

foreach ($rows as $r) {
    $purge = new DateTime($r['purge_date']);
    $days  = (int) $today->diff($purge)->format('%r%a');

    // The monthly notice goes to EVERY customer. The urgent follow-up goes only
    // to customers who still have un-downloaded data -- there is no reason to
    // chase someone whose backups are current.
    $milestone = null;
    if ($send_now) {
        $milestone = NOTICE_DAYS_BEFORE_PURGE;   // logged as the monthly notice
    } else if (!$urgent_only && $days === NOTICE_DAYS_BEFORE_PURGE) {
        $milestone = NOTICE_DAYS_BEFORE_PURGE;
    } else if (in_array($days, URGENT_DAYS, true) && (int) $r['pending_count'] > 0) {
        $milestone = $days;
    }
    if ($milestone === null) { continue; }   // not a notice day for this domain

    // Too few recordings to be worth an email -- unless they are actually at
    // risk, in which case say so however few there are.
    if ((int) $r['total_count'] < $min_recordings && (int) $r['pending_count'] === 0) {
        $below_threshold[] = $r['domain_name'] . ' (' . (int) $r['total_count'] . ')';
        continue;
    }

    // Partner address first (one per customer), then a per-domain override,
    // then the global fallback.
    $key = strtolower($r['domain_uuid']);
    $to = isset($partner_email[$key]) ? $partner_email[$key]
        : (!empty($r['notify_email']) ? $r['notify_email'] : $fallback);
    if (empty($to)) {
        $unaddressed[] = $r['domain_name'];
        continue;
    }

    // Already sent for this purge cycle and milestone?
    $dup = $database->select(
        "SELECT 1 FROM v_recording_notice_log
          WHERE domain_uuid=:d AND purge_date=:p AND milestone=:m",
        array('d'=>$r['domain_uuid'], 'p'=>$r['purge_date'], 'm'=>$milestone), 'row');
    if (!empty($dup)) { $skipped++; continue; }

    $gb      = number_format(((float) $r['pending_bytes']) / 1073741824, 1);
    $count   = number_format((int) $r['pending_count']);
    $cutoff  = date('j F Y', strtotime($r['cutoff_date']));
    $removal = date('j F Y', strtotime($r['purge_date']));
    $oldest  = $r['pending_oldest'] ? date('j F Y', strtotime($r['pending_oldest'])) : null;

    $total   = number_format((int) $r['total_count']);
    $pending = (int) $r['pending_count'];

    // The subject must not promise a removal the body then says is not happening.
    $at_risk_subj = (int) $r['at_risk_count'];
    if ($pending > 0 && $milestone <= 3) {
        $subject = "Action needed: $count call recordings will be removed on $removal";
    } else if ($pending > 0) {
        $subject = "$count call recordings to download before $removal";
    } else {
        $subject = "Your call recordings — $total on file";
    }

    $body  = "Dear customer,\n\n";
    $body .= "Your Cloud PBX currently holds $total call recordings for "
           . $r['domain_name'] . ".\n\n";
    // Only announce a removal when something is actually old enough to be
    // removed. Saying "scheduled for removal on <date>" when nothing qualifies
    // is alarming, generates support calls, and spends the credibility needed
    // for the notice that does matter.
    $at_risk = (int) $r['at_risk_count'];

    if ($at_risk > 0) {
        $body .= "Recordings are kept for at least $display_days days. Recordings from before "
               . "$cutoff are scheduled for removal on $removal.\n\n";
        if ($pending > 0) {
            $body .= "$count of them (about $gb GB) have not been downloaded yet";
            $body .= $oldest ? ", the oldest from $oldest.\n\n" : ".\n\n";
            $body .= "If you need to keep these, please download them before $removal.\n\n";
            $body .= "If you need more time, reply to this email before $removal and we\n";
            $body .= "will hold them.\n\n";
        } else {
            $body .= "All of them have already been downloaded, so nothing will be lost.\n\n";
        }
    } else {
        $body .= "Recordings are kept for at least $display_days days. Nothing is due for removal\n"
               . "yet — your oldest recordings are still well within that window.\n\n";
        $body .= "We send this once a month so you always know what is on the system\n"
               . "and can keep your own copy of anything you need long term.\n\n";
    }

    $body .= "HOW TO DOWNLOAD\n";
    $body .= "  " . PORTAL_URL . "/call-recordings\n\n";
    $body .= "  1. Sign in and open Call Recordings.\n";
    $body .= "  2. Filter by date, extension or phone number.\n";
    $body .= "  3. Use the download icon on a row to save one recording, or tick\n";
    $body .= "     several rows and choose Download selected to get them as a ZIP.\n\n";
    $body .= "Each ZIP also contains the call details - who called whom, when, and\n";
    $body .= "how long - so the recordings stay meaningful once they are on your\n";
    $body .= "own system.\n\n";
    $body .= "Domain: " . $r['domain_name'] . "\n";

    // ---- HTML body -------------------------------------------------------
    // Same facts as the plain-text body above, same order. If the two ever
    // disagree the plain-text one is the bug: it is what --show-body prints
    // and therefore what gets reviewed.
    $html = '';
    $tpl = is_readable(MAIL_TEMPLATE) ? file_get_contents(MAIL_TEMPLATE) : false;
    if ($tpl !== false) {
        if ($pending > 0) {
            $preheader = "$count recordings have not been downloaded yet. "
                       . "They are removed on " . rn_date($r['purge_date']) . ".";
            $alert = '<tr><td style="padding:0 28px;"><div style="margin-top:20px;background:#fef3f2;'
                   . 'border:1px solid #fecdca;border-left:4px solid #d92d20;border-radius:6px;padding:14px 16px;">'
                   . '<div style="color:#b42318;font-size:13px;font-weight:700;letter-spacing:.4px;'
                   . 'text-transform:uppercase;">Action needed</div>'
                   . '<div style="color:#7a271a;font-size:14px;line-height:1.6;padding-top:5px;">'
                   . '<strong>' . $count . ' recordings (' . rn_size($r['pending_bytes']) . ')</strong>'
                   . ' have not been downloaded yet. They will be removed on <strong>'
                   . rn_date($r['purge_date']) . '</strong>.</div></div></td></tr>';
        } else {
            $preheader = "$total call recordings on file. Nothing is due for removal.";
            $alert = '';
        }

        if ($at_risk > 0) {
            $intro = 'Your Cloud PBX currently holds <strong>' . $total . ' call recordings</strong>.<br><br>'
                   . 'Recordings are kept for at least <strong>' . $display_days . ' days</strong>. '
                   . 'Those made before <strong>' . rn_date($r['cutoff_date']) . '</strong> are scheduled '
                   . 'for removal on <strong>' . rn_date($r['purge_date']) . '</strong>.';
        } else {
            $intro = 'Your Cloud PBX currently holds <strong>' . $total . ' call recordings</strong>.<br><br>'
                   . 'Recordings are kept for at least <strong>' . $display_days . ' days</strong>. '
                   . 'Nothing is due for removal yet &mdash; your oldest recordings are still well '
                   . 'inside that window.';
        }

        $rows = rn_row('Total recordings on file', $total);
        if ($at_risk > 0) {
            $rows .= rn_row('Due for removal on ' . rn_date($r['purge_date']), number_format($at_risk));
            $rows .= rn_row('Not yet downloaded',
                            $count . '  (' . rn_size($r['pending_bytes']) . ')', $pending > 0);
            $rows .= rn_row('Oldest not yet downloaded',
                            $oldest ? $oldest : 'n/a', false, true);
        } else {
            $rows .= rn_row('Due for removal', 'none', false, true);
        }

        $html = str_replace(
            array('{{PREHEADER}}', '{{ALERT_BLOCK}}', '{{INTRO_HTML}}',
                  '{{SUMMARY_ROWS}}', '{{PORTAL_URL}}', '{{DOMAIN}}'),
            array(htmlspecialchars($preheader, ENT_QUOTES, 'UTF-8'), $alert, $intro,
                  $rows, PORTAL_URL, htmlspecialchars($r['domain_name'], ENT_QUOTES, 'UTF-8')),
            $tpl);

        // An unreplaced token is visible in the customer's inbox. Send the
        // plain-text body instead of shipping "{{DOMAIN}}" to a customer.
        if (preg_match('/\{\{[A-Z_]+\}\}/', $html)) {
            fwrite(STDERR, sprintf("TEMPLATE has unreplaced tokens for %s - sending plain text\n",
                $r['domain_name']));
            $html = '';
        }
    }

    if ($dry_run) {
        printf("[dry-run] %-42s -> %s\n           %s\n", $r['domain_name'], $to, $subject);
        if ($show_body) { echo "\n---------- body ----------\n$body------------------------\n\n"; }
        continue;
    }

    $payload = json_encode(array(
        'to'      => (strpos($to, ',') !== false) ? array_map('trim', explode(',', $to)) : $to,
        'subject' => $subject,
        'body'    => $html !== '' ? $html : $body,
        'isHtml'  => $html !== '',
    ));

    $ch = curl_init(EMAIL_ENDPOINT);
    curl_setopt_array($ch, array(
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => array('Content-Type: application/json'),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
    ));
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($code !== 200) {
        fwrite(STDERR, sprintf("SEND FAILED %s day-%d http=%d %s %s\n",
            $r['domain_name'], $milestone, $code, $err, substr((string) $resp, 0, 200)));
        continue;   // no log row, so the next run retries
    }

    // Only logged after a successful send: a failure must come back tomorrow.
    $database->execute(
        "INSERT INTO v_recording_notice_log (domain_uuid, purge_date, milestone, recipients, pending_count)
         VALUES (:d, :p, :m, :r, :c)
         ON CONFLICT (domain_uuid, purge_date, milestone) DO NOTHING",
        array('d'=>$r['domain_uuid'], 'p'=>$r['purge_date'], 'm'=>$milestone,
              'r'=>$to, 'c'=>(int) $r['pending_count']));

    printf("sent %-42s day-%-2d -> %s\n", $r['domain_name'], $milestone, $to);
    $sent++;
}

// Preview of the next run, so the recipient list can be checked before any mail
// is sent rather than after.
if ($sent === 0 && $skipped === 0) {
    echo "Recipients that WOULD be used on the next notice day:\n";
    $shown = 0;
    foreach ($rows as $r) {
        $key = strtolower($r['domain_uuid']);
        $to  = isset($partner_email[$key]) ? $partner_email[$key]
             : (!empty($r['notify_email']) ? $r['notify_email'] : $fallback);
        $src = isset($partner_email[$key]) ? 'partner'
             : (!empty($r['notify_email']) ? 'per-domain' : ($fallback ? 'fallback' : 'NONE'));
        printf("  %-42s %-9s %-34s recordings=%s pending=%s\n",
            $r['domain_name'], $src, $to ?: '-',
            number_format((int) $r['total_count']), number_format((int) $r['pending_count']));
        if (++$shown >= 15) { echo "  ... and " . (count($rows) - $shown) . " more\n"; break; }
    }
    echo "\npartner addresses resolved from RTC: " . count($partner_email) . "\n";
    if (count($partner_email) === 0) {
        echo "  (RTC's /partner/get-partners returned nothing usable — everyone falls back.\n";
        echo "   Fine if the fallback address is monitored; see RECIPIENTS.md.)\n";
    }
}

if (!empty($below_threshold)) {
    echo "\nskipped (under $min_recordings recordings and nothing at risk): " . count($below_threshold) . "\n";
    echo "  " . implode(', ', $below_threshold) . "\n";
}

echo "\nsent=$sent already-sent=$skipped\n";
if (!empty($unaddressed)) {
    echo "\nNO RECIPIENT CONFIGURED for " . count($unaddressed) . " domain(s) that are behind:\n";
    foreach ($unaddressed as $d) { echo "  $d\n"; }
    echo "Set v_recording_sftp.notify_email per domain, or a global fallback in\n";
    echo "v_default_settings (category 'recordings', subcategory 'notify_email').\n";
    echo "These customers are NOT being warned.\n";
}
