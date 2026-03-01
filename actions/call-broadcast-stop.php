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
    $sql = "SELECT broadcast_name FROM v_call_broadcasts
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

    // Create event socket connection
    $fp = event_socket::create();

    if (!$fp) {
        return array(
            "success" => false,
            "error" => "Failed to connect to Event Socket"
        );
    }

    // Cancel all scheduled calls for this broadcast
    $cmd = "sched_del " . $call_broadcast_uuid;
    $result = event_socket::api($cmd);

    return array(
        "success" => true,
        "message" => "Broadcast stopped successfully",
        "callBroadcastUuid" => $call_broadcast_uuid,
        "broadcastName" => $broadcast['broadcast_name'],
        "eslResult" => trim($result)
    );
}
