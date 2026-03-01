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

    // Check if broadcast exists and get name
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

    // Stop any scheduled calls first
    $fp = event_socket::create();
    if ($fp) {
        $cmd = "sched_del " . $call_broadcast_uuid;
        event_socket::api($cmd);
    }

    // Delete the broadcast
    $sql = "DELETE FROM v_call_broadcasts
            WHERE call_broadcast_uuid = :call_broadcast_uuid
            AND domain_uuid = :domain_uuid";

    $database->execute($sql, array(
        "call_broadcast_uuid" => $call_broadcast_uuid,
        "domain_uuid" => $db_domain_uuid
    ));

    return array(
        "success" => true,
        "message" => "Broadcast '" . $broadcast['broadcast_name'] . "' deleted successfully",
        "callBroadcastUuid" => $call_broadcast_uuid
    );
}
