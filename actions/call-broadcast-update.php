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
        "broadcast_accountcode" => "broadcast_accountcode"
    );

    foreach ($field_mappings as $input_field => $db_field) {
        if (isset($body->$input_field)) {
            $value = $body->$input_field;

            // Handle phone numbers as array
            if ($db_field == "broadcast_phone_numbers" && is_array($value)) {
                $value = implode("\n", $value);
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

    $database->execute($sql, $parameters);

    return array(
        "success" => true,
        "message" => "Broadcast updated successfully",
        "callBroadcastUuid" => $call_broadcast_uuid
    );
}
