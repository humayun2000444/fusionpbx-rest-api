<?php
/**
 * Tell a tenant their SFTP account exists.
 *
 *   php recording-sftp-ready.php <domain_name|domain_uuid> [--dry-run]
 *
 * Called by provision-pending.sh immediately after provision-tenant.sh
 * succeeds. Before this, the portal said "usually ready within the hour" and
 * then told the customer nothing: the only way to discover it was done was to
 * keep reloading the page.
 *
 * Best-effort on purpose. The account already exists by the time this runs, so
 * a failure here must not fail the provisioning -- it just means the customer
 * finds out from the portal instead. Every exit path returns 0 for that reason.
 */

require_once dirname(__DIR__, 3) . '/resources/require.php';


/**
 * This platform's own RTC gateway and portal.
 *
 * There is deliberately NO default. BTCL and CCL are separate organisations,
 * and a default pointing at either one means the other silently sends its
 * customers' mail through a third party's gateway -- logged in their database,
 * delivered from their sender identity.
 *
 * That is not hypothetical: on 2026-09-21 a CCL SFTP notice for
 * 103.95.96.100 went out through vbs.alaapcloud.gov.bd and landed in BTCL's
 * email_log as row 125, because the provisioner cron did not set RTC_BASE_URL
 * and the code fell back to the BTCL URL baked into it.
 *
 * Resolution order, each strictly this platform's own:
 *   1. the environment (what the cron lines set)
 *   2. v_default_settings, category 'recordings'
 *   3. nothing -- and then we refuse to send rather than guess
 *
 * Guessing wrong here leaks one organisation's customer data into another's
 * systems, so not sending is the safer failure.
 */
function oc_platform_setting($database, $subcategory, $env_name) {
    $v = getenv($env_name);
    if ($v !== false && trim($v) !== '') { return rtrim(trim($v), '/'); }
    try {
        $row = $database->select(
            "SELECT default_setting_value FROM v_default_settings
              WHERE default_setting_category='recordings'
                AND default_setting_subcategory=:s
                AND default_setting_enabled=true LIMIT 1",
            array('s' => $subcategory), 'row');
        if (!empty($row['default_setting_value'])) { return rtrim(trim($row['default_setting_value']), '/'); }
    } catch (Exception $e) { /* fall through to the refusal below */ }
    return '';
}

$oc_db_for_settings = new database;
define('RTC_BASE',   oc_platform_setting($oc_db_for_settings, 'rtc_base_url', 'RTC_BASE_URL'));
define('EMAIL_ENDPOINT',    RTC_BASE . '/api/v1/email/send');
define('NOTIFY_ENDPOINT',   RTC_BASE . '/api/v1/notifications/create');
define('PARTNERS_ENDPOINT', RTC_BASE . '/partner/get-partners');
// auth_user is the only place holding both pbx_uuid and partner_id, and it
// lives in MySQL behind RTC. Asking it directly beats parsing the partner id
// out of the domain name, which silently fails for every domain not shaped
// like "pbx-something-349.".
define('PARTNER_BY_DOMAIN_ENDPOINT', RTC_BASE . '/partner/partner-by-pbx-uuid');
// Primary source: route.RouteName is the domain name and route.idPartner its
// owner, one-to-one, and the partner's own address is a contact address rather
// than a login.
define('DOMAIN_MAP_ENDPOINT', RTC_BASE . '/partner/domain-partner-map');
define('PORTAL_URL', oc_platform_setting($oc_db_for_settings, 'portal_url', 'PORTAL_URL'));
if (RTC_BASE === '' || PORTAL_URL === '') {
    fwrite(STDERR, "REFUSING TO SEND: this platform's own rtc_base_url / portal_url is not set.\n"
        . "Set RTC_BASE_URL and PORTAL_URL in the cron line, or add them to v_default_settings\n"
        . "under category 'recordings'. There is no default on purpose: guessing sends one\n"
        . "organisation's customer mail through another's gateway.\n");
    exit(1);
}
// Empty means skip the bell. Correct anywhere the endpoint does not exist,
// such as a platform still running an older TelcoREST.
define('SERVICE_KEY', getenv('SYSTEM_ACCESS_KEY') ?: '');
define('MAIL_TEMPLATE', __DIR__ . '/templates/sftp-ready.html');

/**
 * Keep only the addresses that are actually addresses.
 *
 * Every source here is free text somebody typed: auth_user.email holds
 * "admintelco.com" for at least one partner, with no @ at all. Accepting that
 * sends the mail nowhere, and silently -- the send returns 200 because the
 * gateway queues it. Better to reject it and fall through to a source that can
 * receive.
 *
 * Handles the comma-separated lists the fallback setting uses, and returns the
 * valid ones joined, or '' if none survive.
 */
function rn_valid_emails($raw) {
    if ($raw === null) { return ''; }
    $ok = array();
    foreach (explode(',', (string) $raw) as $part) {
        $e = trim($part);
        if ($e !== '' && filter_var($e, FILTER_VALIDATE_EMAIL)) { $ok[] = $e; }
    }
    return implode(', ', $ok);
}

$dry_run = in_array('--dry-run', $argv);
$arg = null;
foreach (array_slice($argv, 1) as $a) { if (substr($a, 0, 2) !== '--') { $arg = $a; break; } }
if (empty($arg)) {
    fwrite(STDERR, "usage: recording-sftp-ready.php <domain_name|domain_uuid> [--dry-run]\n");
    exit(0);
}

$is_uuid = (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-/i', $arg);
$domain = $database->select(
    $is_uuid ? "SELECT domain_uuid, domain_name FROM v_domains WHERE domain_uuid = :a"
             : "SELECT domain_uuid, domain_name FROM v_domains WHERE domain_name = :a",
    array('a' => $arg), 'row');
if (empty($domain['domain_uuid'])) { fwrite(STDERR, "unknown domain: $arg\n"); exit(0); }

$sftp = $database->select(
    "SELECT * FROM v_recording_sftp WHERE domain_uuid = :d",
    array('d' => $domain['domain_uuid']), 'row');

// Only announce an account that actually exists. Sending "here are your
// details" with empty details is worse than sending nothing.
if (empty($sftp['enabled']) || empty($sftp['username']) || empty($sftp['host'])) {
    fwrite(STDERR, sprintf("not provisioned yet (%s) - nothing sent\n", $domain['domain_name']));
    exit(0);
}

// 1. route -> partner -> email. Unambiguous: no route name maps to two
//    partners, and the address is the partner's own rather than a user login.
$to = '';
$ch = curl_init(DOMAIN_MAP_ENDPOINT);
curl_setopt_array($ch, array(
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => '{}',
    CURLOPT_HTTPHEADER => array('Content-Type: application/json',
                                'system-access-key: ' . SERVICE_KEY),
    CURLOPT_RETURNTRANSFER => true, CURLOPT_SSL_VERIFYPEER => false, CURLOPT_TIMEOUT => 30));
$map_raw  = curl_exec($ch);
$map_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
if ($map_code === 200) {
    $m = json_decode((string) $map_raw, true);
    $k = strtolower($domain['domain_name']);
    if (is_array($m) && isset($m[$k]['email'])) { $to = rn_valid_emails($m[$k]['email']); }
} else {
    fwrite(STDERR, "domain-partner map lookup: HTTP $map_code (falling back)\n");
}

// 2. auth_user, for a domain with no route entry.
$ch = $to !== '' ? null : curl_init(PARTNER_BY_DOMAIN_ENDPOINT);
if ($ch !== null) {
curl_setopt_array($ch, array(
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode(array('pbxUuid' => $domain['domain_uuid'])),
    // Service-key gated: it answers with a partner's email address, so it is
    // not a public endpoint. No key means no lookup, and the fallbacks below
    // take over.
    CURLOPT_HTTPHEADER => array('Content-Type: application/json',
                                'system-access-key: ' . SERVICE_KEY),
    CURLOPT_RETURNTRANSFER => true, CURLOPT_SSL_VERIFYPEER => false, CURLOPT_TIMEOUT => 15));
$owner_raw  = curl_exec($ch);
$owner_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
} else { $owner_code = 0; $owner_raw = ''; }
if ($owner_code === 200) {
    $owner = json_decode((string) $owner_raw, true);
    if (is_array($owner) && !empty($owner['email'])) {
        $to = rn_valid_emails($owner['email']);
        if ($to === '') {
            fwrite(STDERR, sprintf("owner of %s has an unusable address (%s) - trying the next source\n",
                $domain['domain_name'], $owner['email']));
        }
    }
} else if ($owner_code !== 404) {
    // 404 is a real answer: nobody owns this domain. Anything else means the
    // lookup itself is broken, which is worth seeing in the log.
    fwrite(STDERR, sprintf("owner lookup for %s: HTTP %d\n", $domain['domain_name'], $owner_code));
}

// 2. Older platforms have no such endpoint. Fall back to matching a partner id
//    parsed out of the domain name, which is what both jobs did before.
$ch = $to !== '' ? null : curl_init(PARTNERS_ENDPOINT);
if ($ch === null) { $partners = null; } else
{
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true, CURLOPT_SSL_VERIFYPEER => false, CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => array('Content-Type: application/json')));
    $partners = json_decode((string) curl_exec($ch), true);
    curl_close($ch);
}
if ($to === '' && is_array($partners) && preg_match('/-(\d+)\./', $domain['domain_name'], $mp)) {
    $want = (int) $mp[1];
    $list = isset($partners['data']) && is_array($partners['data']) ? $partners['data'] : $partners;
    foreach ((array) $list as $p) {
        if (!is_array($p)) { continue; }
        $id = isset($p['idPartner']) ? (int) $p['idPartner'] : (isset($p['id']) ? (int) $p['id'] : 0);
        if ($id === $want && !empty($p['email'])) { $to = rn_valid_emails($p['email']); if ($to !== '') { break; } }
    }
}
if ($to === '' && !empty($sftp['notify_email'])) { $to = rn_valid_emails($sftp['notify_email']); }

// Global fallback, e.g. the NOC. Same setting the retention notice reads, so
// both jobs address a given domain the same way.
//
// This is not optional. The partner lookup keys on a numeric id in the domain
// name (pbx-innoversal-345...), and plenty of domains have none -- the first
// account this job ever announced, samsung.btcliptelephony.gov.bd, matched no
// partner, had no notify_email, and so was told nothing at all.
if ($to === '') {
    $row = $database->select(
        "SELECT default_setting_value FROM v_default_settings
          WHERE default_setting_category='recordings' AND default_setting_subcategory='notify_email'
            AND default_setting_enabled=true LIMIT 1", array(), 'row');
    if (!empty($row['default_setting_value'])) { $to = rn_valid_emails($row['default_setting_value']); }
}

if ($to === '') {
    fwrite(STDERR, sprintf("no address for %s - nothing sent. Set v_recording_sftp.notify_email "
        . "for this domain, or a global fallback in v_default_settings "
        . "(recordings / notify_email).\n", $domain['domain_name']));
    exit(0);
}

$host = $sftp['host'];
$port = (int) ($sftp['port'] ?: 22);
$user = $sftp['username'];
$path = $sftp['chroot_path'] ?: '/recordings';

$rsync = "rsync -av --ignore-existing -e 'ssh -p $port' \\\n"
       . "  $user@$host:$path/ \\\n"
       . "  /backup/pbx-recordings/";

// The account is key-only. Saying so here prevents the support ticket that
// otherwise arrives the moment they try to connect and are asked for a
// password that does not exist.
if (empty($sftp['public_key_installed'])) {
    $key_block =
      '<tr><td style="padding:0 28px;"><div style="margin-top:18px;background:#fff8e6;border:1px solid #f5d98e;'
    . 'border-left:4px solid #d9a441;border-radius:6px;padding:14px 16px;">'
    . '<div style="color:#8a6100;font-size:13px;font-weight:700;letter-spacing:.4px;text-transform:uppercase;">One step left</div>'
    . '<div style="color:#6b4c00;font-size:14px;line-height:1.6;padding-top:5px;">'
    . 'This account has no password &mdash; it uses an SSH key. Add your public key on the '
    . '<strong>Call Recordings</strong> page under <strong>Downloading &amp; backup</strong>, '
    . 'and the connection above will start working. The page shows you how to create one.'
    . '</div></div></td></tr>';
    $subject = "Automatic download is ready for " . $domain['domain_name'] . " - one step left";
} else {
    $key_block = '';
    $subject = "Automatic download is ready for " . $domain['domain_name'];
}

$html = '';
$tpl = is_readable(MAIL_TEMPLATE) ? file_get_contents(MAIL_TEMPLATE) : false;
if ($tpl !== false) {
    $html = str_replace(
        array('{{PREHEADER}}', '{{DOMAIN}}', '{{HOST}}', '{{PORT}}', '{{USERNAME}}',
              '{{PATH}}', '{{KEY_BLOCK}}', '{{RSYNC}}', '{{PORTAL_URL}}'),
        array(htmlspecialchars('Your SFTP details for ' . $domain['domain_name'], ENT_QUOTES, 'UTF-8'),
              htmlspecialchars($domain['domain_name'], ENT_QUOTES, 'UTF-8'),
              htmlspecialchars($host, ENT_QUOTES, 'UTF-8'), $port,
              htmlspecialchars($user, ENT_QUOTES, 'UTF-8'),
              htmlspecialchars($path, ENT_QUOTES, 'UTF-8'),
              $key_block,
              htmlspecialchars($rsync, ENT_QUOTES, 'UTF-8'),
              PORTAL_URL),
        $tpl);
    // An unreplaced token reaches the customer's inbox as literal "{{HOST}}".
    if (preg_match('/\{\{[A-Z_]+\}\}/', $html)) {
        fwrite(STDERR, "template has unreplaced tokens - sending plain text\n");
        $html = '';
    }
}

$body = "Automatic download is ready for " . $domain['domain_name'] . ".\n\n"
      . "  Host     : $host\n  Port     : $port\n  Username : $user\n"
      . "  Folder   : $path (read-only)\n\n"
      . "Copy everything, once:\n\n$rsync\n\n"
      . "Running it again later copies only what is new, so it is safe in a nightly cron.\n\n"
      . (empty($sftp['public_key_installed'])
          ? "ONE STEP LEFT\nThis account has no password - it uses an SSH key. Add your public\n"
          . "key at " . PORTAL_URL . "/call-recordings under 'Downloading & backup'.\n\n"
          : "")
      . PORTAL_URL . "/call-recordings\n";

if ($dry_run) {
    printf("[dry-run] %s -> %s\n          %s\n", $domain['domain_name'], $to, $subject);
    exit(0);
}

$ch = curl_init(EMAIL_ENDPOINT);
curl_setopt_array($ch, array(
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode(array(
        'to'      => (strpos($to, ',') !== false) ? array_map('trim', explode(',', $to)) : $to,
        'subject' => $subject,
        'body'    => $html !== '' ? $html : $body,
        'isHtml'  => $html !== '')),
    CURLOPT_HTTPHEADER => array('Content-Type: application/json'),
    CURLOPT_RETURNTRANSFER => true, CURLOPT_SSL_VERIFYPEER => false, CURLOPT_TIMEOUT => 20));
$resp = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
printf("%s email -> %s http=%d %s\n", $domain['domain_name'], $to, $code,
    $code === 200 ? '' : substr((string) $resp, 0, 160));

// Bell, same best-effort contract as the retention notice.
if (SERVICE_KEY !== '' && preg_match('/-(\d+)\./', $domain['domain_name'], $mp2)) {
    $nch = curl_init(NOTIFY_ENDPOINT);
    curl_setopt_array($nch, array(
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode(array(
            'idPartner' => (int) $mp2[1], 'type' => 'SFTP_READY')),
        CURLOPT_HTTPHEADER => array('Content-Type: application/json',
                                    'system-access-key: ' . SERVICE_KEY),
        CURLOPT_RETURNTRANSFER => true, CURLOPT_SSL_VERIFYPEER => false, CURLOPT_TIMEOUT => 15));
    curl_exec($nch);
    $ncode = curl_getinfo($nch, CURLINFO_HTTP_CODE);
    curl_close($nch);
    if ($ncode !== 200) {
        fwrite(STDERR, sprintf("notification failed for %s: HTTP %d (SFTP_READY needs a TelcoREST that knows the type)\n",
            $domain['domain_name'], $ncode));
    }
}
exit(0);
