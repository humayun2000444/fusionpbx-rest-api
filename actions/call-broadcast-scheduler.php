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
    exit(0);
}

log_scheduler("Found " . count($broadcasts) . " scheduled broadcast(s) to check");

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

    // Authenticate
    $response = fgets($fp, 1024);
    fputs($fp, "auth ClueCon\n\n");
    $response = fgets($fp, 1024);

    if (strpos($response, '+OK') === false) {
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
        if ($destination_type === 'queue') {
            // Route to call center queue
            $parts = explode(' ', $destination_data);
            $queue_extension = $parts[0];
            $originate_cmd = "originate {origination_caller_id_name='$caller_id_name',origination_caller_id_number='$caller_id_number',accountcode='$accountcode',domain_uuid='$domain_uuid',domain_name='$domain_name',call_broadcast_uuid='$call_broadcast_uuid'}sofia/gateway/BTCL/$phone_number &transfer($queue_extension XML $domain_name)";
        } else {
            // Transfer to extension/dialplan
            $originate_cmd = "originate {origination_caller_id_name='$caller_id_name',origination_caller_id_number='$caller_id_number',accountcode='$accountcode',domain_uuid='$domain_uuid',domain_name='$domain_name',call_broadcast_uuid='$call_broadcast_uuid'}sofia/gateway/BTCL/$phone_number &transfer($destination_data)";
        }

        // Add AMD if enabled
        if ($avmd) {
            $originate_cmd = str_replace('}', ',execute_on_answer=avmd_start}', $originate_cmd);
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
