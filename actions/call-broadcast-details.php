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

    // Get broadcast details
    $sql = "SELECT
                call_broadcast_uuid,
                domain_uuid,
                broadcast_name,
                broadcast_description,
                broadcast_start_time,
                broadcast_timeout,
                broadcast_concurrent_limit,
                recording_uuid,
                broadcast_caller_id_name,
                broadcast_caller_id_number,
                broadcast_destination_type,
                broadcast_destination_data,
                broadcast_phone_numbers,
                broadcast_avmd,
                broadcast_accountcode,
                broadcast_toll_allow,
                insert_date,
                insert_user,
                update_date,
                update_user
            FROM v_call_broadcasts
            WHERE call_broadcast_uuid = :call_broadcast_uuid
            AND domain_uuid = :domain_uuid";

    $parameters = array(
        "call_broadcast_uuid" => $call_broadcast_uuid,
        "domain_uuid" => $db_domain_uuid
    );

    $broadcast = $database->select($sql, $parameters, "row");

    if (empty($broadcast)) {
        return array(
            "success" => false,
            "error" => "Broadcast not found"
        );
    }

    // Calculate phone count
    $phone_numbers = $broadcast['broadcast_phone_numbers'];
    if (!empty($phone_numbers)) {
        $phone_array = array_filter(explode("\n", trim($phone_numbers)));
        $broadcast['phone_count'] = count($phone_array);
        $broadcast['phone_list'] = array_values($phone_array);
    } else {
        $broadcast['phone_count'] = 0;
        $broadcast['phone_list'] = array();
    }

    return array(
        "success" => true,
        "broadcast" => $broadcast
    );
}
