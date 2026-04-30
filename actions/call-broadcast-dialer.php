#!/usr/bin/php
<?php
/**
 * Predictive Dialer Engine
 *
 * This daemon script runs continuously and manages call pacing for broadcasts
 * in 'predictive' mode. It checks agent availability in the destination queue,
 * calculates how many calls to originate, and adjusts the dial ratio dynamically
 * based on answer rate and abandon rate.
 *
 * Usage:
 *   Start:  nohup php /var/www/fusionpbx/app/rest_api/actions/call-broadcast-dialer.php &
 *   Stop:   kill $(cat /var/run/fusionpbx/dialer.pid)
 *
 * Or via the scheduler which auto-manages this process.
 *
 * Loop interval: ~5 seconds
 */

$document_root = '/var/www/fusionpbx';
require_once $document_root . '/resources/require.php';
require_once $document_root . '/resources/classes/database.php';

date_default_timezone_set('Asia/Dhaka');

// PID file for process management
$pid_file = '/var/run/fusionpbx/dialer.pid';
$log_file = '/var/log/fusionpbx/call_broadcast_dialer.log';

// Ensure directory exists
@mkdir('/var/run/fusionpbx', 0755, true);

// Check if already running
if (file_exists($pid_file)) {
    $existing_pid = trim(file_get_contents($pid_file));
    if ($existing_pid && file_exists("/proc/$existing_pid")) {
        dialer_log("Dialer already running (PID: $existing_pid). Exiting.");
        exit(0);
    }
    // Stale PID file, remove it
    unlink($pid_file);
}

// Write our PID
file_put_contents($pid_file, getmypid());

// Cleanup on exit
register_shutdown_function(function() use ($pid_file) {
    @unlink($pid_file);
});

// Handle signals for graceful shutdown
$running = true;
if (function_exists('pcntl_signal')) {
    pcntl_signal(SIGTERM, function() use (&$running) { $running = false; });
    pcntl_signal(SIGINT, function() use (&$running) { $running = false; });
}

function dialer_log($message) {
    global $log_file;
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($log_file, "[$timestamp] $message\n", FILE_APPEND);
}

/**
 * Read full ESL response
 */
function esl_read_response($fp) {
    $response = '';
    $content_length = 0;
    stream_set_timeout($fp, 3);

    // Read headers
    while ($line = fgets($fp, 4096)) {
        $response .= $line;
        if (preg_match('/^Content-Length:\s*(\d+)/i', $line, $m)) {
            $content_length = intval($m[1]);
        }
        if (trim($line) === '') break;
    }

    // Read body if Content-Length present
    if ($content_length > 0) {
        $body = '';
        while (strlen($body) < $content_length) {
            $chunk = fread($fp, $content_length - strlen($body));
            if ($chunk === false || $chunk === '') break;
            $body .= $chunk;
        }
        $response .= $body;
    }

    return $response;
}

/**
 * Connect to FreeSWITCH ESL
 */
function esl_connect() {
    $fp = @fsockopen('127.0.0.1', 8021, $errno, $errstr, 5);
    if (!$fp) return false;

    esl_read_response($fp); // greeting
    fputs($fp, "auth ClueCon\n\n");
    $auth = esl_read_response($fp);

    if (strpos($auth, '+OK') === false) {
        fclose($fp);
        return false;
    }

    return $fp;
}

/**
 * Send ESL API command and get response
 */
function esl_api($fp, $command) {
    fputs($fp, "api $command\n\n");
    return esl_read_response($fp);
}

/**
 * Get available (idle) agent count for a queue
 * Queries FreeSWITCH call center module via ESL
 */
function get_available_agents($fp, $queue_name, $domain_name) {
    // Build full queue name: queue_name@domain_name
    $full_queue_name = $queue_name . '@' . $domain_name;

    $response = esl_api($fp, "callcenter_config queue list agents $full_queue_name");

    $available = 0;
    $total = 0;
    $on_call = 0;

    $lines = explode("\n", $response);
    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line) || strpos($line, '+OK') !== false || strpos($line, '-ERR') !== false) continue;
        if (strpos($line, 'Content-') !== false || strpos($line, 'Reply-') !== false) continue;

        // Format: name|system|uuid|type|contact|status|state|max_no_answer|wrap_up_time|reject_delay_time|busy_delay_time|...
        $fields = explode('|', $line);
        if (count($fields) < 7) continue;

        $total++;
        $status = trim($fields[5]); // Available, Logged Out, On Break
        $state = trim($fields[6]);  // Waiting, Receiving, In a queue call

        if ($status === 'Available' && $state === 'Waiting') {
            $available++;
        } elseif ($status === 'Available' && $state === 'In a queue call') {
            $on_call++;
        }
    }

    return array(
        'available' => $available,
        'on_call' => $on_call,
        'total' => $total
    );
}

/**
 * Get count of active (in-progress) calls for a broadcast
 */
function get_active_calls($database, $call_broadcast_uuid) {
    $sql = "SELECT COUNT(*) as cnt FROM v_call_broadcast_leads
            WHERE call_broadcast_uuid = :uuid AND lead_status = 'calling'";
    $row = $database->select($sql, array("uuid" => $call_broadcast_uuid), "row");
    return $row ? intval($row['cnt']) : 0;
}

/**
 * Get pending leads for a broadcast (limited)
 */
function get_pending_leads($database, $call_broadcast_uuid, $limit) {
    $sql = "SELECT call_broadcast_lead_uuid, phone_number FROM v_call_broadcast_leads
            WHERE call_broadcast_uuid = :uuid AND lead_status IN ('pending', 'retry_pending')
            AND (next_retry_at IS NULL OR next_retry_at <= NOW())
            ORDER BY lead_status ASC, insert_date ASC
            LIMIT :limit";
    return $database->select($sql, array(
        "uuid" => $call_broadcast_uuid,
        "limit" => $limit
    ), "all") ?: array();
}

/**
 * Calculate current abandon rate for a broadcast
 */
function get_abandon_rate($database, $call_broadcast_uuid) {
    $sql = "SELECT
                COUNT(*) FILTER (WHERE lead_status = 'answered' OR abandoned = true) as total_connected,
                COUNT(*) FILTER (WHERE abandoned = true) as total_abandoned
            FROM v_call_broadcast_leads
            WHERE call_broadcast_uuid = :uuid";
    $row = $database->select($sql, array("uuid" => $call_broadcast_uuid), "row");

    if (!$row || intval($row['total_connected']) == 0) return 0.0;

    return (floatval($row['total_abandoned']) / floatval($row['total_connected'])) * 100.0;
}

/**
 * Originate a call for a lead
 */
function originate_call($fp, $lead, $broadcast, $domain_name) {
    $phone = $lead['phone_number'];
    $call_broadcast_uuid = $broadcast['call_broadcast_uuid'];
    $domain_uuid = $broadcast['domain_uuid'];

    // Random CID from pool
    $caller_id_pool = array_filter(array_map('trim', explode(',', $broadcast['broadcast_caller_id_number'] ?: '0000000000')));
    if (empty($caller_id_pool)) $caller_id_pool = array('0000000000');
    $caller_id_number = $caller_id_pool[array_rand($caller_id_pool)];
    $caller_id_name = $broadcast['broadcast_caller_id_name'] ?: 'Call Broadcast';
    $accountcode = $broadcast['broadcast_accountcode'] ?: $domain_name;
    $destination = $broadcast['broadcast_destination_data'];
    $timeout = intval($broadcast['broadcast_timeout']) ?: 30;
    $avmd = $broadcast['broadcast_avmd'] === 'true';

    // Build channel variables - EXACT same format as working start.php (power mode)
    $vars = "^^:ignore_early_media=true:ignore_display_updates=true:sip_cid_type=none";
    $vars .= ":origination_number=$phone";
    $vars .= ":destination_number=$phone";
    $vars .= ":origination_caller_id_name='$caller_id_name'";
    $vars .= ":origination_caller_id_number=$caller_id_number";
    $vars .= ":caller_id_number=$phone";
    $vars .= ":caller_id_name=$phone";
    $vars .= ":effective_caller_id_number=$phone";
    $vars .= ":effective_caller_id_name=$phone";
    $vars .= ":domain_uuid=$domain_uuid";
    $vars .= ":domain=$domain_name";
    $vars .= ":domain_name=$domain_name";
    $vars .= ":accountcode='$accountcode'";
    $vars .= ":call_broadcast_uuid=$call_broadcast_uuid";

    if ($avmd) {
        $vars .= ":amd_destination=$destination";
        $vars .= ":execute_on_answer='wait_for_silence 200 25 3 4000'";
    }

    // Originate via loopback - EXACT same as working start.php
    $cmd = "bgapi originate {" . $vars . "}loopback/$phone/$domain_name $destination XML $domain_name";
    fputs($fp, "$cmd\n\n");
    $response = esl_read_response($fp);

    // Log originate result for debugging
    $result_line = trim(str_replace(array("\n", "\r"), ' ', $response));
    dialer_log("[originate] $phone → " . substr($result_line, 0, 200));
}

/**
 * Adjust dial ratio based on abandon rate
 * If abandon rate > max, reduce ratio
 * If abandon rate < max * 0.5, increase ratio (slowly)
 */
function adjust_dial_ratio($current_ratio, $abandon_rate, $max_abandon_rate, $base_ratio) {
    if ($abandon_rate > $max_abandon_rate) {
        // Too many abandons - reduce aggressively
        $new_ratio = $current_ratio * 0.85;
        // Don't go below 1.0 (at least 1 call per agent)
        return max(1.0, round($new_ratio, 2));
    } elseif ($abandon_rate < $max_abandon_rate * 0.5 && $current_ratio < $base_ratio) {
        // Low abandon rate - slowly increase back towards base
        $new_ratio = $current_ratio * 1.05;
        return min($base_ratio, round($new_ratio, 2));
    }
    return $current_ratio;
}

// ==================== MAIN LOOP ====================

dialer_log("Predictive dialer engine started (PID: " . getmypid() . ")");

$loop_interval = 5; // seconds between cycles
$idle_count = 0;

while ($running) {
    if (function_exists('pcntl_signal_dispatch')) pcntl_signal_dispatch();

    $database = new database;

    // Find all running broadcasts in predictive mode
    $sql = "SELECT * FROM v_call_broadcasts
            WHERE broadcast_status = 'running'
            AND broadcast_pacing_mode = 'predictive'";
    $broadcasts = $database->select($sql, array(), "all");

    if (empty($broadcasts) || !is_array($broadcasts)) {
        $idle_count++;
        // Log every 60 seconds when idle (12 * 5s)
        if ($idle_count % 12 == 1) {
            dialer_log("No running predictive broadcasts. Idling...");
        }

        // If idle for 5 minutes (60 cycles), exit and let scheduler restart if needed
        if ($idle_count >= 60) {
            dialer_log("No activity for 5 minutes. Exiting.");
            break;
        }

        sleep($loop_interval);
        continue;
    }

    $idle_count = 0;

    // Connect to ESL (reconnect each cycle to avoid stale connections)
    $fp = esl_connect();
    if (!$fp) {
        dialer_log("ERROR: Cannot connect to FreeSWITCH ESL. Retrying in 10s...");
        sleep(10);
        continue;
    }

    foreach ($broadcasts as $broadcast) {
        $uuid = $broadcast['call_broadcast_uuid'];
        $name = $broadcast['broadcast_name'];
        $domain_uuid = $broadcast['domain_uuid'];
        $destination = $broadcast['broadcast_destination_data'];
        $dest_type = $broadcast['broadcast_destination_type'];
        $base_ratio = floatval($broadcast['broadcast_dial_ratio'] ?: 1.5);
        $current_ratio = floatval($broadcast['broadcast_current_dial_ratio'] ?: $base_ratio);
        $max_abandon = floatval($broadcast['broadcast_max_abandon_rate'] ?: 3.0);
        $concurrent_limit = intval($broadcast['broadcast_concurrent_limit']) ?: 20;

        // Get domain name
        $domain_row = $database->select(
            "SELECT domain_name FROM v_domains WHERE domain_uuid = :uuid",
            array("uuid" => $domain_uuid), "row"
        );
        $domain_name = $domain_row ? $domain_row['domain_name'] : 'default';

        // 1. Get available agents in the destination queue
        $queue_name = $destination; // e.g., "3000" (queue extension)
        $agents = get_available_agents($fp, $queue_name, $domain_name);

        // 2. Get current active calls for this broadcast
        $active_calls = get_active_calls($database, $uuid);

        // 3. Calculate abandon rate and adjust ratio
        $abandon_rate = get_abandon_rate($database, $uuid);
        $current_ratio = adjust_dial_ratio($current_ratio, $abandon_rate, $max_abandon, $base_ratio);

        // 4. Calculate how many calls to make
        // Formula: target_calls = available_agents * dial_ratio
        // calls_to_make = target_calls - active_calls
        $available_agents = $agents['available'];
        $target_calls = ceil($available_agents * $current_ratio);

        // Cap at concurrent limit
        $target_calls = min($target_calls, $concurrent_limit);

        $calls_to_make = max(0, $target_calls - $active_calls);

        dialer_log("[$name] agents: avail={$agents['available']}, on_call={$agents['on_call']}, total={$agents['total']} | active_calls=$active_calls | ratio=$current_ratio | abandon={$abandon_rate}% | to_dial=$calls_to_make");

        if ($calls_to_make <= 0) {
            // Update stats but don't dial
            $database->execute(
                "UPDATE v_call_broadcasts SET broadcast_current_dial_ratio = :ratio WHERE call_broadcast_uuid = :uuid",
                array("ratio" => $current_ratio, "uuid" => $uuid)
            );
            continue;
        }

        // 5. Get pending leads
        $leads = get_pending_leads($database, $uuid, $calls_to_make);

        if (empty($leads)) {
            // No more leads - check if any still calling
            if ($active_calls == 0) {
                dialer_log("[$name] No pending leads and no active calls. Marking as completed.");
                $database->execute(
                    "UPDATE v_call_broadcasts SET broadcast_status = 'completed', update_date = NOW() WHERE call_broadcast_uuid = :uuid",
                    array("uuid" => $uuid)
                );
            }
            continue;
        }

        // 6. Originate calls
        $dialed = 0;
        foreach ($leads as $lead) {
            originate_call($fp, $lead, $broadcast, $domain_name);

            // Mark lead as calling
            $database->execute(
                "UPDATE v_call_broadcast_leads SET lead_status = 'calling', attempts = attempts + 1,
                 last_attempt_at = NOW(), update_date = NOW()
                 WHERE call_broadcast_lead_uuid = :uuid",
                array("uuid" => $lead['call_broadcast_lead_uuid'])
            );

            $dialed++;
        }

        // 7. Update broadcast stats
        $database->execute(
            "UPDATE v_call_broadcasts SET broadcast_current_dial_ratio = :ratio, update_date = NOW()
             WHERE call_broadcast_uuid = :uuid",
            array("ratio" => $current_ratio, "uuid" => $uuid)
        );

        dialer_log("[$name] Dialed $dialed calls");
    }

    fclose($fp);

    // Sync CDR for all running predictive broadcasts (to update lead statuses)
    sync_cdr_for_predictive($database, $broadcasts);

    sleep($loop_interval);
}

dialer_log("Predictive dialer engine stopped.");

/**
 * Sync CDR status for calling leads in predictive broadcasts
 * Similar to the retry logic in scheduler but also tracks abandons
 */
function sync_cdr_for_predictive($database, $broadcasts) {
    foreach ($broadcasts as $broadcast) {
        $uuid = $broadcast['call_broadcast_uuid'];
        $domain_uuid = $broadcast['domain_uuid'];
        $retry_enabled = isset($broadcast['broadcast_retry_enabled']) && $broadcast['broadcast_retry_enabled'] === 'true';
        $retry_max = intval($broadcast['broadcast_retry_max'] ?? 0);
        $retry_interval = intval($broadcast['broadcast_retry_interval'] ?? 300);
        $retry_causes_str = $broadcast['broadcast_retry_causes'] ?? 'NO_ANSWER,USER_BUSY';
        $retry_causes = array_map('trim', explode(',', $retry_causes_str));
        $max_attempts = $retry_enabled ? ($retry_max + 1) : 1;

        // Get leads in 'calling' state older than 30 seconds
        $calling = $database->select(
            "SELECT call_broadcast_lead_uuid, phone_number, attempts, max_attempts FROM v_call_broadcast_leads
             WHERE call_broadcast_uuid = :uuid AND lead_status = 'calling'
             AND last_attempt_at < NOW() - INTERVAL '30 seconds'",
            array("uuid" => $uuid), "all"
        );

        if (!is_array($calling) || empty($calling)) continue;

        foreach ($calling as $lead) {
            $cdr = $database->select(
                "SELECT xml_cdr_uuid, hangup_cause, billsec, duration FROM v_xml_cdr
                 WHERE domain_uuid = :domain AND (destination_number = :phone OR caller_id_number = :phone2)
                 AND start_stamp >= (NOW() - INTERVAL '10 minutes') ORDER BY billsec DESC, start_stamp DESC LIMIT 1",
                array("domain" => $domain_uuid, "phone" => $lead['phone_number'], "phone2" => $lead['phone_number']),
                "row"
            );

            if (empty($cdr)) {
                // No CDR found - check if lead has been stuck too long (>5 min = originate failed)
                $stuck_check = $database->select(
                    "SELECT last_attempt_at FROM v_call_broadcast_leads WHERE call_broadcast_lead_uuid = :uuid",
                    array("uuid" => $lead['call_broadcast_lead_uuid']), "row"
                );
                if ($stuck_check && !empty($stuck_check['last_attempt_at'])) {
                    $last_attempt = strtotime($stuck_check['last_attempt_at']);
                    $stuck_minutes = (time() - $last_attempt) / 60;
                    if ($stuck_minutes > 5) {
                        // Stuck for >5 min with no CDR = originate failed, mark as failed
                        dialer_log("[CDR sync] {$lead['phone_number']} stuck in calling for " . round($stuck_minutes) . " min with no CDR. Marking failed.");
                        $database->execute(
                            "UPDATE v_call_broadcast_leads SET lead_status = 'failed', hangup_cause = 'ORIGINATE_FAILED',
                             update_date = NOW() WHERE call_broadcast_lead_uuid = :uuid",
                            array("uuid" => $lead['call_broadcast_lead_uuid'])
                        );
                    }
                }
                continue;
            }

            $hangup = $cdr['hangup_cause'];
            $billsec = intval($cdr['billsec']);
            $is_answered = ($billsec > 0 && $hangup === 'NORMAL_CLEARING');
            $is_retryable = in_array($hangup, $retry_causes) ||
                            ($hangup === 'NORMAL_CLEARING' && $billsec == 0);

            if ($is_answered) {
                $status = 'answered';
            } elseif ($is_retryable && $retry_enabled && intval($lead['attempts']) < $max_attempts) {
                $status = 'retry_pending';
            } elseif ($is_retryable) {
                $status = 'skipped';
            } else {
                $status = 'failed';
            }

            $next_retry = ($status === 'retry_pending') ? "NOW() + INTERVAL '$retry_interval seconds'" : "NULL";

            $database->execute(
                "UPDATE v_call_broadcast_leads SET lead_status = :status, hangup_cause = :hangup,
                 billsec = :billsec, call_duration = :duration, xml_cdr_uuid = :cdr,
                 next_retry_at = $next_retry, update_date = NOW()
                 WHERE call_broadcast_lead_uuid = :uuid",
                array(
                    "status" => $status, "hangup" => $hangup,
                    "billsec" => $billsec, "duration" => intval($cdr['duration']),
                    "cdr" => $cdr['xml_cdr_uuid'],
                    "uuid" => $lead['call_broadcast_lead_uuid']
                )
            );
        }
    }
}
