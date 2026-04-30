<?php

$required_params = array("callBroadcastUuid");

function do_action($body) {
    global $domain_uuid, $domain_name;

    // Use domain_uuid from request if provided, otherwise use global
    $db_domain_uuid = isset($body->domain_uuid) ? $body->domain_uuid :
                     (isset($body->domainUuid) ? $body->domainUuid : $domain_uuid);

    $call_broadcast_uuid = isset($body->callBroadcastUuid) ? $body->callBroadcastUuid :
                          (isset($body->call_broadcast_uuid) ? $body->call_broadcast_uuid : null);

    if (empty($call_broadcast_uuid)) {
        return array(
            "success" => false,
            "error" => "callBroadcastUuid is required"
        );
    }

    $database = new database;

    // Get domain name
    $db_domain_name = $domain_name;
    $sql = "SELECT domain_name FROM v_domains WHERE domain_uuid = :domain_uuid";
    $domain_row = $database->select($sql, array("domain_uuid" => $db_domain_uuid), "row");
    if ($domain_row) {
        $db_domain_name = $domain_row['domain_name'];
    }

    // Get broadcast details
    $sql = "SELECT * FROM v_call_broadcasts
            WHERE call_broadcast_uuid = :call_broadcast_uuid
            AND domain_uuid = :domain_uuid";

    $broadcast = $database->select($sql, array(
        "call_broadcast_uuid" => $call_broadcast_uuid,
        "domain_uuid" => $db_domain_uuid
    ), "row");

    if (empty($broadcast)) {
        return array(
            "success" => false,
            "error" => "Broadcast not found"
        );
    }

    // Check if there are phone numbers
    if (empty($broadcast['broadcast_phone_numbers'])) {
        return array(
            "success" => false,
            "error" => "No phone numbers in broadcast"
        );
    }

    // Get broadcast settings
    $broadcast_name = $broadcast['broadcast_name'];
    $broadcast_start_time = is_numeric($broadcast['broadcast_start_time']) ? $broadcast['broadcast_start_time'] : 3;
    $broadcast_timeout = is_numeric($broadcast['broadcast_timeout']) ? $broadcast['broadcast_timeout'] : 30;
    $broadcast_concurrent_limit = is_numeric($broadcast['broadcast_concurrent_limit']) ? $broadcast['broadcast_concurrent_limit'] : 5;
    $broadcast_caller_id_name = !empty($broadcast['broadcast_caller_id_name']) ? $broadcast['broadcast_caller_id_name'] : 'Call Broadcast';
    // Support multiple caller IDs (comma-separated) - random pick per call
    $broadcast_caller_id_raw = !empty($broadcast['broadcast_caller_id_number']) ? $broadcast['broadcast_caller_id_number'] : '0000000000';
    $broadcast_caller_id_pool = array_filter(array_map('trim', explode(',', $broadcast_caller_id_raw)));
    if (empty($broadcast_caller_id_pool)) $broadcast_caller_id_pool = array('0000000000');
    $broadcast_destination_data = $broadcast['broadcast_destination_data'];
    $broadcast_avmd = $broadcast['broadcast_avmd'];
    $broadcast_accountcode = !empty($broadcast['broadcast_accountcode']) ? $broadcast['broadcast_accountcode'] : $db_domain_name;
    $broadcast_toll_allow = isset($broadcast['broadcast_toll_allow']) ? $broadcast['broadcast_toll_allow'] : '';

    // Update status to 'running' first
    $update_sql = "UPDATE v_call_broadcasts SET broadcast_status = 'running', broadcast_last_run = NOW(), update_date = NOW()
                   WHERE call_broadcast_uuid = :call_broadcast_uuid AND domain_uuid = :domain_uuid";
    try {
        $database->execute($update_sql, array(
            "call_broadcast_uuid" => $call_broadcast_uuid,
            "domain_uuid" => $db_domain_uuid
        ));
    } catch (Exception $e) {
        return array(
            "success" => false,
            "error" => "Failed to update broadcast status: " . $e->getMessage()
        );
    }

    // Verify status was updated
    $verify_sql = "SELECT broadcast_status FROM v_call_broadcasts WHERE call_broadcast_uuid = :call_broadcast_uuid";
    $verify_result = $database->select($verify_sql, array("call_broadcast_uuid" => $call_broadcast_uuid), "row");
    if (empty($verify_result) || $verify_result['broadcast_status'] !== 'running') {
        return array(
            "success" => false,
            "error" => "Failed to update broadcast status"
        );
    }

    // Try to create event socket connection
    $fp = null;
    $esl_available = false;
    if (class_exists('event_socket')) {
        $fp = @event_socket::create();
        $esl_available = ($fp !== false && $fp !== null);
    }

    if (!$esl_available) {
        // Update status back to idle if ESL not available
        $database->execute("UPDATE v_call_broadcasts SET broadcast_status = 'idle' WHERE call_broadcast_uuid = :call_broadcast_uuid",
            array("call_broadcast_uuid" => $call_broadcast_uuid));
        return array(
            "success" => false,
            "error" => "Failed to connect to Event Socket - FreeSWITCH may not be running"
        );
    }

    // Retry settings
    $retry_enabled = isset($broadcast['broadcast_retry_enabled']) && $broadcast['broadcast_retry_enabled'] === 'true';
    $retry_max = isset($broadcast['broadcast_retry_max']) ? intval($broadcast['broadcast_retry_max']) : 0;
    $max_attempts = $retry_enabled ? ($retry_max + 1) : 1; // +1 for initial attempt

    // Parse phone numbers
    $phone_numbers = array_filter(explode("\n", trim($broadcast['broadcast_phone_numbers'])));
    $sched_seconds = $broadcast_start_time;
    $count = 1;
    $scheduled_calls = 0;

    // Insert leads into tracking table (skip duplicates)
    foreach ($phone_numbers as $phone_line) {
        $phone_line_clean = str_replace(";", "|", $phone_line);
        $phone_parts_clean = explode("|", $phone_line_clean);
        $phone_clean = preg_replace('/\D/', '', trim($phone_parts_clean[0]));
        if (!empty($phone_clean) && is_numeric($phone_clean)) {
            // Check if lead already exists for this broadcast
            $check_sql = "SELECT call_broadcast_lead_uuid FROM v_call_broadcast_leads
                          WHERE call_broadcast_uuid = :broadcast_uuid AND phone_number = :phone";
            $existing = $database->select($check_sql, array(
                "broadcast_uuid" => $call_broadcast_uuid,
                "phone" => $phone_clean
            ), "row");

            if (empty($existing)) {
                $lead_uuid = uuid();
                $insert_lead_sql = "INSERT INTO v_call_broadcast_leads
                    (call_broadcast_lead_uuid, call_broadcast_uuid, domain_uuid, phone_number, lead_status, attempts, max_attempts, insert_date)
                    VALUES (:lead_uuid, :broadcast_uuid, :domain_uuid, :phone, 'pending', 0, :max_attempts, NOW())";
                try {
                    $database->execute($insert_lead_sql, array(
                        "lead_uuid" => $lead_uuid,
                        "broadcast_uuid" => $call_broadcast_uuid,
                        "domain_uuid" => $db_domain_uuid,
                        "phone" => $phone_clean,
                        "max_attempts" => $max_attempts
                    ));
                } catch (Exception $e) {
                    // Skip duplicate or error, continue
                }
            } else {
                // Reset existing lead for re-run
                $reset_sql = "UPDATE v_call_broadcast_leads SET lead_status = 'pending', attempts = 0,
                              max_attempts = :max_attempts, hangup_cause = NULL, next_retry_at = NULL, update_date = NOW()
                              WHERE call_broadcast_lead_uuid = :lead_uuid";
                $database->execute($reset_sql, array(
                    "lead_uuid" => $existing['call_broadcast_lead_uuid'],
                    "max_attempts" => $max_attempts
                ));
            }
        }
    }

    foreach ($phone_numbers as $phone_line) {
        // Parse phone number (may contain other data separated by | or ;)
        $phone_line = str_replace(";", "|", $phone_line);
        $phone_parts = explode("|", $phone_line);
        $phone_number = preg_replace('/\D/', '', trim($phone_parts[0]));

        if (!empty($phone_number) && is_numeric($phone_number)) {
            // Randomly pick a caller ID from the pool for this call
            $broadcast_caller_id_number = $broadcast_caller_id_pool[array_rand($broadcast_caller_id_pool)];

            // Build channel variables
            // For outbound broadcast:
            //   origination_caller_id = what called party sees (company caller ID)
            //   caller_id = what shows in call center queue (destination number being called)
            // Use ^^: prefix to export variables through all legs including loopback
            $channel_variables = "^^:ignore_early_media=true:ignore_display_updates=true:sip_cid_type=none";
            $channel_variables .= ":origination_number=" . $phone_number;
            $channel_variables .= ":destination_number=" . $phone_number;
            $channel_variables .= ":origination_caller_id_name='" . $broadcast_caller_id_name . "'";
            $channel_variables .= ":origination_caller_id_number=" . $broadcast_caller_id_number;
            // Set caller_id to destination number for queue display (both Name and Number)
            $channel_variables .= ":caller_id_number=" . $phone_number;
            $channel_variables .= ":caller_id_name=" . $phone_number;
            $channel_variables .= ":effective_caller_id_number=" . $phone_number;
            $channel_variables .= ":effective_caller_id_name=" . $phone_number;
            $channel_variables .= ":domain_uuid=" . $db_domain_uuid;
            $channel_variables .= ":domain=" . $db_domain_name;
            $channel_variables .= ":domain_name=" . $db_domain_name;
            $channel_variables .= ":accountcode='" . $broadcast_accountcode . "'";
            $channel_variables .= ":call_broadcast_uuid=" . $call_broadcast_uuid;

            if (!empty($broadcast_toll_allow)) {
                $channel_variables .= ":toll_allow='" . $broadcast_toll_allow . "'";
            }

            // AMD (Answering Machine Detection) settings
            // If AMD is enabled, use silence detection to filter machines
            if ($broadcast_avmd == "true") {
                // Use wait_for_silence to detect answering machines
                // Human: says "Hello?" then waits (quick silence)
                // Machine: plays long greeting (no quick silence)
                // Parameters: silence_thresh silence_hits listen_hits timeout_ms
                $channel_variables .= ":amd_destination=" . $broadcast_destination_data;
                $channel_variables .= ":execute_on_answer='wait_for_silence 200 25 3 4000'";

                // Build origination URL
                $origination_url = "{" . $channel_variables . "}loopback/" . $phone_number . "/" . $db_domain_name;

                // Get context
                $context = $db_domain_name;

                // After wait_for_silence, transfer to destination
                // If silence detected = human, if timeout = machine (but we'll transfer anyway for simplicity)
                $cmd = "bgapi sched_api +" . $sched_seconds . " " . $call_broadcast_uuid . " bgapi originate " .
                       $origination_url . " " . $broadcast_destination_data . " XML " . $context;
            } else {
                // No AMD - direct transfer to destination
                $origination_url = "{" . $channel_variables . "}loopback/" . $phone_number . "/" . $db_domain_name;

                // Get context
                $context = $db_domain_name;

                // Schedule the call
                $cmd = "bgapi sched_api +" . $sched_seconds . " " . $call_broadcast_uuid . " bgapi originate " .
                       $origination_url . " " . $broadcast_destination_data . " XML " . $context;
            }

            @event_socket::command($cmd);
            $scheduled_calls++;

            // Update lead status to 'calling'
            $update_lead_sql = "UPDATE v_call_broadcast_leads SET lead_status = 'calling',
                                attempts = attempts + 1, last_attempt_at = NOW(), update_date = NOW()
                                WHERE call_broadcast_uuid = :broadcast_uuid AND phone_number = :phone AND lead_status IN ('pending', 'retry_pending')";
            try {
                $database->execute($update_lead_sql, array(
                    "broadcast_uuid" => $call_broadcast_uuid,
                    "phone" => $phone_number
                ));
            } catch (Exception $e) {
                // non-critical, continue
            }

            // Spread calls out based on concurrent limit
            if ($broadcast_concurrent_limit > 0 && $broadcast_timeout > 0) {
                if ($count >= $broadcast_concurrent_limit) {
                    $sched_seconds = $sched_seconds + $broadcast_timeout;
                    $count = 0;
                }
            }

            $count++;
        }
    }

    return array(
        "success" => true,
        "message" => "Broadcast started successfully",
        "callBroadcastUuid" => $call_broadcast_uuid,
        "broadcastName" => $broadcast_name,
        "scheduledCalls" => $scheduled_calls,
        "totalNumbers" => count($phone_numbers),
        "destination" => $broadcast_destination_data,
        "status" => "running"
    );
}
