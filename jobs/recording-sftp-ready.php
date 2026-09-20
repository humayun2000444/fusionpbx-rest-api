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

define('RTC_BASE', getenv('RTC_BASE_URL') ?: 'https://vbs.alaapcloud.gov.bd:4000/FREESWITCHREST');
define('EMAIL_ENDPOINT',    RTC_BASE . '/api/v1/email/send');
define('NOTIFY_ENDPOINT',   RTC_BASE . '/api/v1/notifications/create');
define('PARTNERS_ENDPOINT', RTC_BASE . '/partner/get-partners');
define('PORTAL_URL', rtrim(getenv('PORTAL_URL') ?: 'https://ippbx.alaapcloud.gov.bd:5174', '/'));
// Empty means skip the bell. Correct anywhere the endpoint does not exist,
// such as a platform still running an older TelcoREST.
define('SERVICE_KEY', getenv('SYSTEM_ACCESS_KEY') ?: '');
define('MAIL_TEMPLATE', __DIR__ . '/templates/sftp-ready.html');

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

// Partner address first, then a per-domain override. Same order as the
// retention notice, so a customer hears from one place.
$to = '';
$ch = curl_init(PARTNERS_ENDPOINT);
curl_setopt_array($ch, array(
    CURLOPT_RETURNTRANSFER => true, CURLOPT_SSL_VERIFYPEER => false, CURLOPT_TIMEOUT => 15,
    CURLOPT_HTTPHEADER => array('Content-Type: application/json')));
$partners = json_decode((string) curl_exec($ch), true);
curl_close($ch);
if (is_array($partners) && preg_match('/-(\d+)\./', $domain['domain_name'], $mp)) {
    $want = (int) $mp[1];
    $list = isset($partners['data']) && is_array($partners['data']) ? $partners['data'] : $partners;
    foreach ((array) $list as $p) {
        if (!is_array($p)) { continue; }
        $id = isset($p['idPartner']) ? (int) $p['idPartner'] : (isset($p['id']) ? (int) $p['id'] : 0);
        if ($id === $want && !empty($p['email'])) { $to = trim($p['email']); break; }
    }
}
if ($to === '' && !empty($sftp['notify_email'])) { $to = trim($sftp['notify_email']); }

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
    if (!empty($row['default_setting_value'])) { $to = trim($row['default_setting_value']); }
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
