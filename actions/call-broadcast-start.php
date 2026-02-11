<?php

$required_params = array("callBroadcastUuid");

function do_action($body) {
    global $domain_uuid, $domain_name;

    // Use domain_uuid from request if provided, otherwise use global
    $db_domain_uuid = isset($body->domain_uuid) ? $body->domain_uuid : $domain_uuid;

    $call_broadcast_uuid = isset($body->callBroadcastUuid) ? $body->callBroadcastUuid :
                          (isset($body->call_broadcast_uuid) ? $body->call_broadcast_uuid : null);

    if (empty($call_broadcast_uuid)) {
        return array(
            "success" => false,
            "error" => "callBroadcastUuid is required"
        );
    }

    $database = new database;

    // Get domain name if we're using a different domain
    $db_domain_name = $domain_name;
    if ($db_domain_uuid != $domain_uuid) {
        $sql = "SELECT domain_name FROM v_domains WHERE domain_uuid = :domain_uuid";
        $domain_row = $database->select($sql, array("domain_uuid" => $db_domain_uuid), "row");
        if ($domain_row) {
            $db_domain_name = $domain_row['domain_name'];
        }
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
    $broadcast_caller_id_number = !empty($broadcast['broadcast_caller_id_number']) ? $broadcast['broadcast_caller_id_number'] : '0000000000';
    $broadcast_destination_data = $broadcast['broadcast_destination_data'];
    $broadcast_avmd = $broadcast['broadcast_avmd'];
    $broadcast_accountcode = !empty($broadcast['broadcast_accountcode']) ? $broadcast['broadcast_accountcode'] : $db_domain_name;
    $broadcast_toll_allow = isset($broadcast['broadcast_toll_allow']) ? $broadcast['broadcast_toll_allow'] : '';

    // Create event socket connection
    $fp = event_socket::create();

    if (!$fp) {
        return array(
            "success" => false,
            "error" => "Failed to connect to Event Socket"
        );
    }

    // Parse phone numbers
    $phone_numbers = array_filter(explode("\n", trim($broadcast['broadcast_phone_numbers'])));
    $sched_seconds = $broadcast_start_time;
    $count = 1;
    $scheduled_calls = 0;

    foreach ($phone_numbers as $phone_line) {
        // Parse phone number (may contain other data separated by | or ;)
        $phone_line = str_replace(";", "|", $phone_line);
        $phone_parts = explode("|", $phone_line);
        $phone_number = preg_replace('/\D/', '', trim($phone_parts[0]));

        if (!empty($phone_number) && is_numeric($phone_number)) {
            // Build channel variables
            $channel_variables = "ignore_early_media=true";
            $channel_variables .= ",origination_number=" . $phone_number;
            $channel_variables .= ",origination_caller_id_name='" . $broadcast_caller_id_name . "'";
            $channel_variables .= ",origination_caller_id_number=" . $broadcast_caller_id_number;
            $channel_variables .= ",domain_uuid=" . $db_domain_uuid;
            $channel_variables .= ",domain=" . $db_domain_name;
            $channel_variables .= ",domain_name=" . $db_domain_name;
            $channel_variables .= ",accountcode='" . $broadcast_accountcode . "'";

            if (!empty($broadcast_toll_allow)) {
                $channel_variables .= ",toll_allow='" . $broadcast_toll_allow . "'";
            }

            if ($broadcast_avmd == "true") {
                $channel_variables .= ",execute_on_answer='avmd start'";
            }

            // Build origination URL using loopback
            $origination_url = "{" . $channel_variables . "}loopback/" . $phone_number . "/" . $db_domain_name;

            // Schedule the call
            $cmd = "bgapi sched_api +" . $sched_seconds . " " . $call_broadcast_uuid . " bgapi originate " .
                   $origination_url . " " . $broadcast_destination_data . " XML " . $db_domain_name;

            // Re-connect if needed
            if (!$fp) {
                $fp = event_socket::create();
            }

            $response = event_socket::command($cmd);
            $scheduled_calls++;

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
        "destination" => $broadcast_destination_data
    );
}
