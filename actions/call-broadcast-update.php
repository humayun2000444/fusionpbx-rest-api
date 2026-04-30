<?php

$required_params = array("callBroadcastUuid");

function do_action($body) {
    global $domain_uuid;




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

    // Check if broadcast exists
    $sql = "SELECT call_broadcast_uuid FROM v_call_broadcasts
            WHERE call_broadcast_uuid = :call_broadcast_uuid
            AND domain_uuid = :domain_uuid";

    $exists = $database->select($sql, array(
        "call_broadcast_uuid" => $call_broadcast_uuid,
        "domain_uuid" => $db_domain_uuid
    ), "row");

    if (empty($exists)) {
        return array(
            "success" => false,
            "error" => "Broadcast not found"
        );
    }

    // Build update query dynamically
    $updates = array();
    $parameters = array(
        "call_broadcast_uuid" => $call_broadcast_uuid,
        "domain_uuid" => $db_domain_uuid
    );

    $field_mappings = array(
        "broadcastName" => "broadcast_name",
        "broadcast_name" => "broadcast_name",
        "broadcastDescription" => "broadcast_description",
        "broadcast_description" => "broadcast_description",
        "broadcastStartTime" => "broadcast_start_time",
        "broadcast_start_time" => "broadcast_start_time",
        "broadcastTimeout" => "broadcast_timeout",
        "broadcast_timeout" => "broadcast_timeout",
        "broadcastConcurrentLimit" => "broadcast_concurrent_limit",
        "broadcast_concurrent_limit" => "broadcast_concurrent_limit",
        "broadcastCallerIdName" => "broadcast_caller_id_name",
        "broadcast_caller_id_name" => "broadcast_caller_id_name",
        "broadcastCallerIdNumber" => "broadcast_caller_id_number",
        "broadcast_caller_id_number" => "broadcast_caller_id_number",
        "broadcastDestinationType" => "broadcast_destination_type",
        "broadcast_destination_type" => "broadcast_destination_type",
        "broadcastDestinationData" => "broadcast_destination_data",
        "broadcast_destination_data" => "broadcast_destination_data",
        "broadcastPhoneNumbers" => "broadcast_phone_numbers",
        "broadcast_phone_numbers" => "broadcast_phone_numbers",
        "broadcastAvmd" => "broadcast_avmd",
        "broadcast_avmd" => "broadcast_avmd",
        "broadcastAccountcode" => "broadcast_accountcode",
        "broadcast_accountcode" => "broadcast_accountcode",
        // Schedule fields
        "scheduleEnabled" => "broadcast_schedule_enabled",
        "schedule_enabled" => "broadcast_schedule_enabled",
        "scheduleType" => "broadcast_schedule_type",
        "schedule_type" => "broadcast_schedule_type",
        "scheduleDate" => "broadcast_schedule_date",
        "schedule_date" => "broadcast_schedule_date",
        "scheduleTime" => "broadcast_schedule_time",
        "schedule_time" => "broadcast_schedule_time",
        "scheduleDays" => "broadcast_schedule_days",
        "schedule_days" => "broadcast_schedule_days",
        "scheduleEndDate" => "broadcast_schedule_end_date",
        "schedule_end_date" => "broadcast_schedule_end_date",
        // Retry fields
        "retryEnabled" => "broadcast_retry_enabled",
        "retry_enabled" => "broadcast_retry_enabled",
        "retryMax" => "broadcast_retry_max",
        "retry_max" => "broadcast_retry_max",
        "retryInterval" => "broadcast_retry_interval",
        "retry_interval" => "broadcast_retry_interval",
        "retryCauses" => "broadcast_retry_causes",
        "retry_causes" => "broadcast_retry_causes"
    );

    // Date/time fields that must be NULL instead of empty string (PostgreSQL rejects "" for date/time types)
    $nullable_fields = array(
        "broadcast_schedule_date", "broadcast_schedule_time",
        "broadcast_schedule_end_date", "broadcast_schedule_days"
    );

    foreach ($field_mappings as $input_field => $db_field) {
        if (isset($body->$input_field)) {
            $value = $body->$input_field;

            // Handle phone numbers as array
            if ($db_field == "broadcast_phone_numbers" && is_array($value)) {
                $value = implode("\n", $value);
            }

            // Handle schedule_days as array
            if ($db_field == "broadcast_schedule_days" && is_array($value)) {
                $value = implode(",", $value);
            }

            // Handle boolean fields
            if ($db_field == "broadcast_schedule_enabled" || $db_field == "broadcast_retry_enabled") {
                $value = ($value === true || $value === 'true') ? 'true' : 'false';
            }

            // Handle retry_causes as array
            if ($db_field == "broadcast_retry_causes" && is_array($value)) {
                $value = implode(",", $value);
            }

            // Convert empty strings to NULL for date/time columns
            if (in_array($db_field, $nullable_fields) && (is_string($value) && trim($value) === '' || (is_array($value) && empty($value)))) {
                $value = null;
            }

            $updates[] = "$db_field = :$db_field";
            $parameters[$db_field] = $value;
        }
    }

    if (empty($updates)) {
        return array(
            "success" => false,
            "error" => "No fields to update"
        );
    }

    // Add update_date
    $updates[] = "update_date = NOW()";

    $sql = "UPDATE v_call_broadcasts SET " . implode(", ", $updates) .
           " WHERE call_broadcast_uuid = :call_broadcast_uuid AND domain_uuid = :domain_uuid";

    try {
        $result = $database->execute($sql, $parameters);
        if ($result === false) {
            $msg = isset($database->message['message']) ? $database->message['message'] : 'Unknown database error';
            error_log("BROADCAST_UPDATE_ERROR: " . $msg);
            return array(
                "success" => false,
                "error" => "Failed to update broadcast: " . $msg
            );
        }
    } catch (Exception $e) {
        return array(
            "success" => false,
            "error" => "Failed to update broadcast: " . $e->getMessage()
        );
    }

    // Verify update by checking update_date
    $verify_sql = "SELECT update_date FROM v_call_broadcasts WHERE call_broadcast_uuid = :call_broadcast_uuid";
    $verify_result = $database->select($verify_sql, array("call_broadcast_uuid" => $call_broadcast_uuid), "row");
    if (empty($verify_result) || empty($verify_result['update_date'])) {
        return array(
            "success" => false,
            "error" => "Broadcast update failed - database update did not succeed"
        );
    }

    return array(
        "success" => true,
        "message" => "Broadcast updated successfully",
        "callBroadcastUuid" => $call_broadcast_uuid
    );
}
