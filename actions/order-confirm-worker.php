#!/usr/bin/php
<?php
/**
 * order-confirm-worker.php  (daemon)
 *
 * Runs continuously and does two things every cycle:
 *   1. Delivers merchant callbacks  - POSTs {order_id, dtmf, ...} to the
 *      configured confirm_url with optional auth (none|bearer|basic|hmac),
 *      with its own retry policy.
 *   2. Runs the call retry engine   - re-dials orders that were not answered
 *      / busy / voicemail / failed, honouring the per-domain retry policy.
 *
 * Start:  nohup php /var/www/fusionpbx/app/rest_api/actions/order-confirm-worker.php >/dev/null 2>&1 &
 * Stop:   kill $(cat /var/run/fusionpbx/order_confirm_worker.pid)
 * (or run it once per minute from cron as a fallback)
 */

$document_root = '/var/www/fusionpbx';
require_once $document_root . '/resources/require.php';
require_once $document_root . '/resources/classes/database.php';
require_once __DIR__ . '/order-confirm-helper.php';

date_default_timezone_set('Asia/Dhaka');

$pid_file = '/var/run/fusionpbx/order_confirm_worker.pid';
$log_file = '/var/log/fusionpbx/order_confirm_worker.log';
@mkdir('/var/run/fusionpbx', 0755, true);

if (file_exists($pid_file)) {
    $pid = trim(file_get_contents($pid_file));
    if ($pid && file_exists("/proc/$pid")) { exit(0); }   // already running
    @unlink($pid_file);
}
file_put_contents($pid_file, getmypid());
register_shutdown_function(function () use ($pid_file) { @unlink($pid_file); });

$running = true;
if (function_exists('pcntl_signal')) {
    pcntl_signal(SIGTERM, function () use (&$running) { $running = false; });
    pcntl_signal(SIGINT,  function () use (&$running) { $running = false; });
}

function w_log($m) {
    global $log_file;
    file_put_contents($log_file, '[' . date('Y-m-d H:i:s') . "] $m\n", FILE_APPEND);
}

$config_cache = array();
function w_config($database, $domain_uuid) {
    global $config_cache;
    if (!isset($config_cache[$domain_uuid])) {
        $config_cache[$domain_uuid] = oc_get_config($database, $domain_uuid);
    }
    return $config_cache[$domain_uuid];
}

function truthy($v) { return ($v === true || $v === 'true' || $v === 't' || $v === 1 || $v === '1'); }

/* -------------------- 1. MERCHANT CALLBACKS -------------------- */
function process_callbacks($database) {
    $rows = $database->select(
        "SELECT * FROM v_order_confirm_calls
          WHERE callback_pending = TRUE
          ORDER BY complete_date ASC LIMIT 50", array(), 'all');
    if (!$rows) return;

    foreach ($rows as $r) {
        $cfg = w_config($database, $r['domain_uuid']);
        $interval = intval($cfg['callback_retry_interval']) ?: 60;
        $max      = intval($cfg['callback_retry_max']) ?: 5;

        // respect retry interval
        if (!empty($r['callback_date'])) {
            $elapsed = time() - strtotime($r['callback_date']);
            if ($elapsed < $interval) continue;
        }

        // Resolve the pressed digit's option (its own method/url/auth/payload).
        $opts = json_decode($cfg['dtmf_options'] ?: '[]', true);
        $opt = null;
        if (is_array($opts)) foreach ($opts as $o) {
            if (isset($o['digit']) && (string)$o['digit'] === (string)$r['dtmf_pressed']) { $opt = $o; break; }
        }

        // placeholder substitution for URL / payload templates
        $subs = array(
            '{order_id}' => $r['order_id'], '{dtmf}' => $r['dtmf_pressed'],
            '{name}' => $r['customer_name'], '{phone}' => $r['phone'],
            '{label}' => $r['disposition'], '{status}' => $r['status'],
        );
        $sub = function ($s) use ($subs) { return strtr((string)$s, $subs); };

        $method = strtoupper(!empty($opt['method']) ? $opt['method'] : 'POST');
        $url = !empty($opt['url']) ? $sub($opt['url'])
             : ($r['confirm_url'] !== '' ? $r['confirm_url'] : $cfg['default_confirm_url']);
        if (empty($url)) {
            $database->execute("UPDATE v_order_confirm_calls
                SET callback_pending=FALSE, callback_status='no_url', status='done' WHERE call_uuid=:c",
                array('c' => $r['call_uuid']));
            continue;
        }

        // Default JSON body if no template given
        $default_payload = json_encode(array(
            'order_id' => $r['order_id'], 'dtmf' => intval($r['dtmf_pressed']),
            'response' => $r['disposition'], 'status' => $r['status'],
            'phone' => $r['phone'], 'customer_name' => $r['customer_name'],
            'metadata' => json_decode($r['metadata'] ?: '{}')));
        $content_type = !empty($opt['contentType']) ? $opt['contentType'] : 'application/json';
        $body = null;
        if ($method === 'POST') {
            $body = (isset($opt['payload']) && $opt['payload'] !== '') ? $sub($opt['payload']) : $default_payload;
        }

        // Auth: per-option, falling back to the global callback auth
        $authType   = isset($opt['authType'])   ? $opt['authType']   : ($cfg['callback_auth_type'] ?: 'none');
        $authToken  = isset($opt['authToken'])  ? $opt['authToken']  : $cfg['callback_auth_token'];
        $authSecret = isset($opt['authSecret']) ? $opt['authSecret'] : $cfg['callback_hmac_secret'];
        $authHeader = !empty($opt['authHeader']) ? $opt['authHeader'] : ($cfg['callback_hmac_header'] ?: 'X-Signature');

        $headers = array();
        if ($method === 'POST') $headers[] = 'Content-Type: ' . $content_type;
        if ($authType === 'bearer' && $authToken !== '') $headers[] = 'Authorization: Bearer ' . $authToken;
        elseif ($authType === 'basic' && $authToken !== '') $headers[] = 'Authorization: Basic ' . base64_encode($authToken);
        elseif ($authType === 'hmac' && $authSecret !== '') $headers[] = $authHeader . ': ' . hash_hmac('sha256', (string)$body, $authSecret);

        $ch = curl_init($url);
        $copts = array(CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => intval($cfg['callback_timeout']) ?: 15,
                       CURLOPT_HTTPHEADER => $headers,
                       // accept self-signed certs on internal endpoints (same as -k)
                       CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0,
                       CURLOPT_FOLLOWLOCATION => true);
        if ($method === 'POST') { $copts[CURLOPT_POST] = true; $copts[CURLOPT_POSTFIELDS] = $body; }
        else { $copts[CURLOPT_CUSTOMREQUEST] = 'GET'; }
        curl_setopt_array($ch, $copts);
        $resp = curl_exec($ch);
        $code = intval(curl_getinfo($ch, CURLINFO_HTTP_CODE));
        $err  = curl_error($ch);
        curl_close($ch);
        w_log("callback $method $url dtmf={$r['dtmf_pressed']} -> http=$code");

        $attempts = intval($r['callback_attempts']) + 1;

        if ($code >= 200 && $code < 300) {
            $database->execute("UPDATE v_order_confirm_calls
                SET callback_pending=FALSE, callback_status='ok', callback_http_code=:h,
                    callback_response=:b, callback_attempts=:a, callback_date=NOW(), status='done'
                WHERE call_uuid=:c",
                array('h' => $code, 'b' => substr((string)$resp, 0, 2000), 'a' => $attempts, 'c' => $r['call_uuid']));
            w_log("callback OK order={$r['order_id']} dtmf={$r['dtmf_pressed']} http=$code");
        } else {
            $final = $attempts >= $max;
            $status_label = $code >= 500 ? 'http_5xx' : ($code >= 400 ? 'http_4xx' : 'failed');
            $database->execute("UPDATE v_order_confirm_calls
                SET callback_pending=:pending, callback_status=:s, callback_http_code=:h,
                    callback_response=:b, callback_attempts=:a, callback_date=NOW()
                WHERE call_uuid=:c",
                array('pending' => $final ? 'false' : 'true', 's' => $status_label,
                      'h' => $code ?: null, 'b' => substr($err ?: (string)$resp, 0, 2000),
                      'a' => $attempts, 'c' => $r['call_uuid']));
            w_log("callback FAIL order={$r['order_id']} http=$code err=$err attempt=$attempts" . ($final ? ' (final)' : ''));
        }
    }
}

/* -------------------- 2. CALL RETRY ENGINE -------------------- */
function classify_cause($cause) {
    $cause = strtoupper((string)$cause);
    if (strpos($cause, 'USER_BUSY') !== false) return 'busy';
    if (strpos($cause, 'NO_ANSWER') !== false || strpos($cause, 'NO_USER_RESPONSE') !== false
        || strpos($cause, 'ORIGINATOR_CANCEL') !== false || strpos($cause, 'ALLOTTED_TIMEOUT') !== false) return 'no_answer';
    return 'failed';
}

function process_call_retries($database) {
    // (a) 'calling' rows that never got answered -> classify from the CDR
    $stuck = $database->select(
        "SELECT * FROM v_order_confirm_calls
          WHERE status = 'calling' AND last_attempt_date < NOW() - INTERVAL '90 seconds'
          LIMIT 50", array(), 'all');
    if ($stuck) {
        foreach ($stuck as $r) {
            $cdr = $database->select("SELECT hangup_cause FROM v_xml_cdr WHERE xml_cdr_uuid = :u LIMIT 1",
                array('u' => $r['fs_call_uuid']), 'row');
            $newstatus = $cdr ? classify_cause($cdr['hangup_cause']) : 'no_answer';
            $cfg = w_config($database, $r['domain_uuid']);
            $interval = intval($cfg['retry_interval']) ?: 300;
            $database->execute("UPDATE v_order_confirm_calls
                SET status=:s, hangup_cause=:h, disposition=:s, next_attempt_date = NOW() + (:iv || ' seconds')::interval
                WHERE call_uuid=:c",
                array('s' => $newstatus, 'h' => $cdr ? $cdr['hangup_cause'] : 'NO_CDR',
                      'iv' => $interval, 'c' => $r['call_uuid']));
        }
    }

    // (b) schedule retries for failed-type statuses
    $retry = $database->select(
        "SELECT * FROM v_order_confirm_calls
          WHERE status IN ('no_answer','busy','voicemail','failed')
            AND attempts < max_attempts
            AND next_attempt_date <= NOW()
          LIMIT 25", array(), 'all');
    if (!$retry) return;

    foreach ($retry as $r) {
        $cfg = w_config($database, $r['domain_uuid']);
        $flag = array('no_answer' => 'retry_on_no_answer', 'busy' => 'retry_on_busy',
                      'voicemail' => 'retry_on_voicemail', 'failed' => 'retry_on_failed');
        $allowed = truthy($cfg['retry_enabled']) && isset($flag[$r['status']]) && truthy($cfg[$flag[$r['status']]]);

        if (!$allowed) {
            // terminal: stop re-processing
            $database->execute("UPDATE v_order_confirm_calls
                SET attempts = max_attempts, complete_date = NOW() WHERE call_uuid=:c",
                array('c' => $r['call_uuid']));
            continue;
        }

        $interval = intval($cfg['retry_interval']) ?: 300;
        // set the NEXT window first (so a failed re-dial waits again)
        $database->execute("UPDATE v_order_confirm_calls
            SET next_attempt_date = NOW() + (:iv || ' seconds')::interval WHERE call_uuid=:c",
            array('iv' => $interval, 'c' => $r['call_uuid']));

        $res = oc_originate($database, $r, $cfg);   // sets status='calling', attempts++
        w_log("retry order={$r['order_id']} attempt=" . (intval($r['attempts']) + 1) . '/' . $r['max_attempts']
              . ' ' . ($res['ok'] ? 'placed' : 'originate_failed'));
    }
}

/* -------------------- MAIN LOOP -------------------- */
w_log('Order confirm worker started (PID ' . getmypid() . ')');
while ($running) {
    if (function_exists('pcntl_signal_dispatch')) pcntl_signal_dispatch();
    try {
        $database = new database;
        process_callbacks($database);
        process_call_retries($database);
    } catch (Exception $e) {
        w_log('ERROR ' . $e->getMessage());
    }
    // cron mode: run one pass and exit if invoked with 'once'
    if (isset($argv[1]) && $argv[1] === 'once') break;
    sleep(5);
}
w_log('Order confirm worker stopped');
