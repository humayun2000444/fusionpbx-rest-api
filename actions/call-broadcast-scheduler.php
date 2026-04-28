#!/usr/bin/php
<?php
/**
 * Call Broadcast Scheduler
 *
 * This script runs via cron every minute to check for scheduled broadcasts
 * and start them automatically.
 *
 * Cron entry: * * * * * /usr/bin/php /var/www/fusionpbx/app/rest_api/actions/call-broadcast-scheduler.php
 */

// Include FusionPBX config
$document_root = '/var/www/fusionpbx';
require_once $document_root . '/resources/require.php';
require_once $document_root . '/resources/classes/database.php';

// Set timezone
date_default_timezone_set('Asia/Dhaka');

// Logging function
function log_scheduler($message) {
    $log_file = '/var/log/fusionpbx/call_broadcast_scheduler.log';
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($log_file, "[$timestamp] $message\n", FILE_APPEND);
}

// Read full ESL response (handles multi-line Content-Type headers)
function esl_read_response($fp) {
    $response = '';
    $blank_count = 0;
    stream_set_timeout($fp, 3);
    while ($line = fgets($fp, 4096)) {
        $response .= $line;
        if (trim($line) === '') $blank_count++;
        if ($blank_count >= 1 || strpos($response, '+OK') !== false || strpos($response, '-ERR') !== false) break;
    }
    return $response;
}

log_scheduler("Scheduler started");

$database = new database;

// Get current day and time
$current_day = strtolower(date('D')); // mon, tue, wed, etc.
$current_time = date('H:i');
$current_date = date('Y-m-d');

// Query for broadcasts that should be started
$sql = "SELECT
            call_broadcast_uuid,
            domain_uuid,
            broadcast_name,
            broadcast_schedule_type,
            broadcast_schedule_date,
            broadcast_schedule_time,
            broadcast_schedule_days,
            broadcast_schedule_end_date,
            broadcast_last_run,
            broadcast_status
        FROM v_call_broadcasts
        WHERE broadcast_schedule_enabled = true
        AND broadcast_status != 'running'
        AND (broadcast_schedule_end_date IS NULL OR broadcast_schedule_end_date >= :current_date)";

$parameters = array("current_date" => $current_date);
$broadcasts = $database->select($sql, $parameters, "all");

if (empty($broadcasts)) {
    log_scheduler("No scheduled broadcasts found");
    $broadcasts = array();
}

if (count($broadcasts) > 0) {
    log_scheduler("Found " . count($broadcasts) . " scheduled broadcast(s) to check");
}

foreach ($broadcasts as $broadcast) {
    $uuid = $broadcast['call_broadcast_uuid'];
    $name = $broadcast['broadcast_name'];
    $schedule_type = $broadcast['broadcast_schedule_type'];
    $schedule_date = $broadcast['broadcast_schedule_date'];
    $schedule_time = $broadcast['broadcast_schedule_time'];
    $schedule_days = $broadcast['broadcast_schedule_days'];
    $last_run = $broadcast['broadcast_last_run'];

    $should_run = false;

    // Format schedule_time to H:i for comparison
    $schedule_time_formatted = date('H:i', strtotime($schedule_time));

    log_scheduler("Checking broadcast '$name' (type: $schedule_type, time: $schedule_time_formatted, current: $current_time)");

    switch ($schedule_type) {
        case 'one_time':
            // Run once on specific date/time
            if ($schedule_date == $current_date && $schedule_time_formatted == $current_time) {
                // Check if already ran today
                if (empty($last_run) || date('Y-m-d', strtotime($last_run)) != $current_date) {
                    $should_run = true;
                }
            }
            break;

        case 'daily':
            // Run every day at specific time
            if ($schedule_time_formatted == $current_time) {
                // Check if already ran today
                if (empty($last_run) || date('Y-m-d', strtotime($last_run)) != $current_date) {
                    $should_run = true;
                }
            }
            break;

        case 'weekly':
            // Run on specific days at specific time
            $allowed_days = array_map('trim', explode(',', strtolower($schedule_days)));
            if (in_array($current_day, $allowed_days) && $schedule_time_formatted == $current_time) {
                // Check if already ran today
                if (empty($last_run) || date('Y-m-d', strtotime($last_run)) != $current_date) {
                    $should_run = true;
                }
            }
            break;
    }

    if ($should_run) {
        log_scheduler("Starting broadcast '$name' ($uuid)");

        // Update status to running and last_run timestamp
        $update_sql = "UPDATE v_call_broadcasts
                       SET broadcast_status = 'running',
                           broadcast_last_run = NOW()
                       WHERE call_broadcast_uuid = :uuid";
        $database->execute($update_sql, array("uuid" => $uuid));

        // Get broadcast details for starting
        $detail_sql = "SELECT * FROM v_call_broadcasts WHERE call_broadcast_uuid = :uuid";
        $broadcast_detail = $database->select($detail_sql, array("uuid" => $uuid), "row");

        if ($broadcast_detail) {
            // Start the broadcast using FreeSWITCH
            $result = start_broadcast($broadcast_detail, $database);

            if ($result['success']) {
                log_scheduler("Broadcast '$name' started successfully. Scheduled {$result['scheduledCalls']} calls.");

                // For one-time schedule, disable it after running
                if ($schedule_type == 'one_time') {
                    $disable_sql = "UPDATE v_call_broadcasts
                                   SET broadcast_schedule_enabled = false
                                   WHERE call_broadcast_uuid = :uuid";
                    $database->execute($disable_sql, array("uuid" => $uuid));
                    log_scheduler("One-time broadcast '$name' schedule disabled after execution");
                }
            } else {
                log_scheduler("Failed to start broadcast '$name': " . $result['error']);

                // Reset status
                $reset_sql = "UPDATE v_call_broadcasts
                             SET broadcast_status = 'idle'
                             WHERE call_broadcast_uuid = :uuid";
                $database->execute($reset_sql, array("uuid" => $uuid));
            }
        }
    }
}

// ==================== AUTO-RETRY PROCESSING ====================
// Process retry-pending leads for broadcasts that have retry enabled
log_scheduler("Checking for retry-pending leads...");

$retry_sql = "SELECT DISTINCT b.call_broadcast_uuid, b.domain_uuid, b.broadcast_name
              FROM v_call_broadcasts b
              INNER JOIN v_call_broadcast_leads l ON b.call_broadcast_uuid = l.call_broadcast_uuid
              WHERE b.broadcast_retry_enabled = 'true'
              AND b.broadcast_retry_max > 0
              AND l.lead_status = 'retry_pending'
              AND l.next_retry_at <= NOW()";

$retry_broadcasts = $database->select($retry_sql, array(), "all");

if (is_array($retry_broadcasts) && count($retry_broadcasts) > 0) {
    log_scheduler("Found " . count($retry_broadcasts) . " broadcast(s) with retry-pending leads");

    foreach ($retry_broadcasts as $rb) {
        log_scheduler("Processing retries for broadcast '{$rb['broadcast_name']}' ({$rb['call_broadcast_uuid']})");

        // Call the retry logic
        $retry_result = process_broadcast_retry($rb['call_broadcast_uuid'], $rb['domain_uuid'], $database);

        if ($retry_result['success']) {
            log_scheduler("Retry result: synced={$retry_result['synced']}, retried={$retry_result['retried']}");
        } else {
            log_scheduler("Retry failed: " . ($retry_result['error'] ?? 'unknown'));
        }
    }
} else {
    log_scheduler("No retry-pending leads found");
}

// Sync CDR status for any leads in 'calling' state (older than 1 minute to allow call to complete)
$stuck_sql = "SELECT DISTINCT b.call_broadcast_uuid, b.domain_uuid, b.broadcast_name
              FROM v_call_broadcasts b
              INNER JOIN v_call_broadcast_leads l ON b.call_broadcast_uuid = l.call_broadcast_uuid
              WHERE l.lead_status = 'calling'
              AND l.last_attempt_at < NOW() - INTERVAL '1 minute'";

$stuck_broadcasts = $database->select($stuck_sql, array(), "all");

if (is_array($stuck_broadcasts) && count($stuck_broadcasts) > 0) {
    log_scheduler("Found " . count($stuck_broadcasts) . " broadcast(s) with stuck leads, syncing CDR...");
    foreach ($stuck_broadcasts as $sb) {
        process_broadcast_retry($sb['call_broadcast_uuid'], $sb['domain_uuid'], $database);
    }
}

log_scheduler("Scheduler completed");

/**
 * Start a broadcast (same logic as call-broadcast-start.php)
 */
function start_broadcast($broadcast, $database) {
    $call_broadcast_uuid = $broadcast['call_broadcast_uuid'];
    $domain_uuid = $broadcast['domain_uuid'];
    $phone_numbers_raw = $broadcast['broadcast_phone_numbers'];

    if (empty($phone_numbers_raw)) {
        return array("success" => false, "error" => "No phone numbers in broadcast");
    }

    // Parse phone numbers
    $phone_numbers = array_filter(array_map('trim', explode("\n", $phone_numbers_raw)));

    if (empty($phone_numbers)) {
        return array("success" => false, "error" => "No valid phone numbers");
    }

    // Get broadcast settings
    $caller_id_name = $broadcast['broadcast_caller_id_name'] ?: 'Call Broadcast';
    $caller_id_number = $broadcast['broadcast_caller_id_number'] ?: '0000000000';
    $destination_type = $broadcast['broadcast_destination_type'] ?: 'transfer';
    $destination_data = $broadcast['broadcast_destination_data'];
    $concurrent_limit = intval($broadcast['broadcast_concurrent_limit']) ?: 5;
    $timeout = intval($broadcast['broadcast_timeout']) ?: 30;
    $start_time = intval($broadcast['broadcast_start_time']) ?: 3;
    $avmd = $broadcast['broadcast_avmd'] === 'true';
    $accountcode = $broadcast['broadcast_accountcode'];

    // Get domain name
    $domain_sql = "SELECT domain_name FROM v_domains WHERE domain_uuid = :domain_uuid";
    $domain_result = $database->select($domain_sql, array("domain_uuid" => $domain_uuid), "row");
    $domain_name = $domain_result ? $domain_result['domain_name'] : 'default';

    // Connect to FreeSWITCH Event Socket
    $fp = @fsockopen('127.0.0.1', 8021, $errno, $errstr, 5);
    if (!$fp) {
        return array("success" => false, "error" => "Cannot connect to FreeSWITCH: $errstr");
    }

    // Authenticate - read greeting then send auth
    esl_read_response($fp);
    fputs($fp, "auth ClueCon\n\n");
    $auth_response = esl_read_response($fp);

    if (strpos($auth_response, '+OK') === false) {
        fclose($fp);
        return array("success" => false, "error" => "FreeSWITCH authentication failed");
    }

    // Schedule calls
    $scheduled_calls = 0;
    $batch = 0;

    foreach ($phone_numbers as $index => $phone_number) {
        $phone_number = preg_replace('/[^0-9+]/', '', $phone_number);
        if (empty($phone_number)) continue;

        // Calculate delay for this call
        $batch_number = floor($index / $concurrent_limit);
        $delay = $start_time + ($batch_number * $timeout);

        // Build originate command based on destination type
        // origination_caller_id = what called party sees (company number)
        // caller_id = what shows in queue (the destination number being dialed - both Name and Number)
        // Use ^^: prefix to export variables through all legs
        $common_vars = "^^:origination_caller_id_name='$caller_id_name':origination_caller_id_number='$caller_id_number':caller_id_number='$phone_number':caller_id_name='$phone_number':effective_caller_id_number='$phone_number':effective_caller_id_name='$phone_number':destination_number='$phone_number':accountcode='$accountcode':domain_uuid='$domain_uuid':domain_name='$domain_name':call_broadcast_uuid='$call_broadcast_uuid'";

        // Add AMD if enabled - use silence detection to filter machines
        if ($avmd) {
            // wait_for_silence: threshold silence_hits listen_hits timeout_ms
            // Human: says "Hello?" then silence (detected quickly)
            // Machine: long greeting (times out)
            $common_vars .= ":execute_on_answer='wait_for_silence 200 25 3 4000'";
        }

        if ($destination_type === 'queue') {
            // Route to call center queue
            $parts = explode(' ', $destination_data);
            $queue_extension = $parts[0];
            $originate_cmd = "originate {" . $common_vars . "}sofia/gateway/BTCL/$phone_number &transfer($queue_extension XML $domain_name)";
        } else {
            // Transfer to extension/dialplan
            $originate_cmd = "originate {" . $common_vars . "}sofia/gateway/BTCL/$phone_number &transfer($destination_data)";
        }

        // Schedule the call
        $sched_id = "broadcast_{$call_broadcast_uuid}_{$index}";
        $sched_cmd = "sched_api +$delay $sched_id $originate_cmd";

        fputs($fp, "api $sched_cmd\n\n");
        $response = fgets($fp, 1024);

        $scheduled_calls++;
    }

    fclose($fp);

    return array(
        "success" => true,
        "scheduledCalls" => $scheduled_calls,
        "totalNumbers" => count($phone_numbers)
    );
}

/**
 * Process retry for a broadcast - sync CDR + re-queue eligible leads
 */
function process_broadcast_retry($call_broadcast_uuid, $domain_uuid, $database) {
    // Get broadcast config
    $broadcast = $database->select(
        "SELECT * FROM v_call_broadcasts WHERE call_broadcast_uuid = :uuid",
        array("uuid" => $call_broadcast_uuid), "row"
    );
    if (empty($broadcast)) return array("success" => false, "error" => "Not found");

    $retry_max = intval($broadcast['broadcast_retry_max'] ?? 0);
    $retry_interval = intval($broadcast['broadcast_retry_interval'] ?? 300);
    $retry_causes_str = $broadcast['broadcast_retry_causes'] ?? 'NO_ANSWER,USER_BUSY';
    $retry_causes = array_map('trim', explode(',', $retry_causes_str));

    $domain_row = $database->select("SELECT domain_name FROM v_domains WHERE domain_uuid = :uuid",
        array("uuid" => $domain_uuid), "row");
    $domain_name = $domain_row ? $domain_row['domain_name'] : 'default';

    // Step 1: Sync 'calling' leads from CDR
    $calling = $database->select(
        "SELECT call_broadcast_lead_uuid, phone_number, attempts, max_attempts FROM v_call_broadcast_leads
         WHERE call_broadcast_uuid = :uuid AND lead_status = 'calling'",
        array("uuid" => $call_broadcast_uuid), "all"
    );

    $synced = 0;
    if (is_array($calling)) {
        foreach ($calling as $lead) {
            $cdr = $database->select(
                "SELECT xml_cdr_uuid, hangup_cause, billsec, duration FROM v_xml_cdr
                 WHERE domain_uuid = :domain AND (destination_number = :phone OR caller_id_number = :phone2)
                 AND start_stamp >= (NOW() - INTERVAL '24 hours') ORDER BY billsec DESC, start_stamp DESC LIMIT 1",
                array("domain" => $domain_uuid, "phone" => $lead['phone_number'], "phone2" => $lead['phone_number']),
                "row"
            );

            if (!empty($cdr)) {
                $hangup = $cdr['hangup_cause'];
                $is_answered = (intval($cdr['billsec']) > 0 && $hangup === 'NORMAL_CLEARING');
                $is_retryable = in_array($hangup, $retry_causes) ||
                                ($hangup === 'NORMAL_CLEARING' && intval($cdr['billsec']) == 0);

                if ($is_answered) {
                    $status = 'answered';
                } elseif ($is_retryable && intval($lead['attempts']) < intval($lead['max_attempts'])) {
                    $status = 'retry_pending';
                } elseif ($is_retryable) {
                    $status = 'skipped';
                } else {
                    $status = 'failed';
                }

                $database->execute(
                    "UPDATE v_call_broadcast_leads SET lead_status = :status, hangup_cause = :hangup,
                     billsec = :billsec, call_duration = :duration, xml_cdr_uuid = :cdr,
                     next_retry_at = " . ($status === 'retry_pending' ? "NOW() + INTERVAL '$retry_interval seconds'" : "NULL") . ",
                     update_date = NOW() WHERE call_broadcast_lead_uuid = :uuid",
                    array("status" => $status, "hangup" => $hangup, "billsec" => intval($cdr['billsec']),
                          "duration" => intval($cdr['duration']), "cdr" => $cdr['xml_cdr_uuid'],
                          "uuid" => $lead['call_broadcast_lead_uuid'])
                );
                $synced++;
            }
        }
    }

    // Step 2: Re-queue retry_pending leads
    $retries = $database->select(
        "SELECT call_broadcast_lead_uuid, phone_number FROM v_call_broadcast_leads
         WHERE call_broadcast_uuid = :uuid AND lead_status = 'retry_pending' AND next_retry_at <= NOW()",
        array("uuid" => $call_broadcast_uuid), "all"
    );

    $retried = 0;
    if (is_array($retries) && count($retries) > 0) {
        $fp = @fsockopen('127.0.0.1', 8021, $errno, $errstr, 5);
        if (!$fp) return array("success" => false, "error" => "Cannot connect to FreeSWITCH", "synced" => $synced);

        esl_read_response($fp);
        fputs($fp, "auth ClueCon\n\n");
        $auth = esl_read_response($fp);
        if (strpos($auth, '+OK') === false) { fclose($fp); return array("success" => false, "error" => "ESL auth failed"); }

        $caller_name = $broadcast['broadcast_caller_id_name'] ?: 'Call Broadcast';
        $caller_number = $broadcast['broadcast_caller_id_number'] ?: '0000000000';
        $dest = $broadcast['broadcast_destination_data'];
        $accountcode = $broadcast['broadcast_accountcode'] ?: $domain_name;
        $concurrent = intval($broadcast['broadcast_concurrent_limit']) ?: 5;
        $timeout = intval($broadcast['broadcast_timeout']) ?: 30;
        $count = 1;
        $delay = 3;

        foreach ($retries as $lead) {
            $phone = $lead['phone_number'];
            $vars = "^^:ignore_early_media=true:ignore_display_updates=true:sip_cid_type=none";
            $vars .= ":origination_caller_id_name='$caller_name':origination_caller_id_number=$caller_number";
            $vars .= ":caller_id_number=$phone:caller_id_name=$phone";
            $vars .= ":effective_caller_id_number=$phone:effective_caller_id_name=$phone";
            $vars .= ":destination_number=$phone:domain_uuid=$domain_uuid:domain_name=$domain_name";
            $vars .= ":accountcode='$accountcode':call_broadcast_uuid=$call_broadcast_uuid";

            $origination_url = "{" . $vars . "}loopback/$phone/$domain_name";
            $cmd = "api sched_api +$delay retry_{$call_broadcast_uuid} originate $origination_url $dest XML $domain_name\n\n";
            fputs($fp, $cmd);
            esl_read_response($fp);

            $database->execute(
                "UPDATE v_call_broadcast_leads SET lead_status = 'calling', attempts = attempts + 1,
                 last_attempt_at = NOW(), next_retry_at = NULL, update_date = NOW()
                 WHERE call_broadcast_lead_uuid = :uuid",
                array("uuid" => $lead['call_broadcast_lead_uuid'])
            );

            $retried++;
            if ($count >= $concurrent) { $delay += $timeout; $count = 0; }
            $count++;
        }

        fclose($fp);
    }

    return array("success" => true, "synced" => $synced, "retried" => $retried);
}
