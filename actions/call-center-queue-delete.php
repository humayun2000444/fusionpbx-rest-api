<?php

$required_params = array("callCenterQueueUuid");

function do_action($body) {
    global $domain_uuid;

    $queue_uuid = isset($body->callCenterQueueUuid) ? $body->callCenterQueueUuid : $body->call_center_queue_uuid;

    $database = new database;

    // Get queue details
    $sql = "SELECT q.*, d.domain_name FROM v_call_center_queues q
            LEFT JOIN v_domains d ON q.domain_uuid = d.domain_uuid
            WHERE q.call_center_queue_uuid = :queue_uuid";
    $queue = $database->select($sql, array("queue_uuid" => $queue_uuid), "row");

    if (!$queue) {
        return array("error" => "Queue not found");
    }

    $domain_name = $queue['domain_name'];
    $queue_extension = $queue['queue_extension'];
    $queue_name = $queue['queue_name'];
    $dialplan_uuid = $queue['dialplan_uuid'];

    // Delete tiers associated with this queue
    $sql_delete_tiers = "DELETE FROM v_call_center_tiers WHERE call_center_queue_uuid = :queue_uuid";
    $database->execute($sql_delete_tiers, array("queue_uuid" => $queue_uuid));

    // Delete dialplan details if exists
    if ($dialplan_uuid) {
        $sql_delete_details = "DELETE FROM v_dialplan_details WHERE dialplan_uuid = :dialplan_uuid";
        $database->execute($sql_delete_details, array("dialplan_uuid" => $dialplan_uuid));

        // Delete dialplan
        $sql_delete_dialplan = "DELETE FROM v_dialplans WHERE dialplan_uuid = :dialplan_uuid";
        $database->execute($sql_delete_dialplan, array("dialplan_uuid" => $dialplan_uuid));
    }

    // Delete queue
    $sql_delete = "DELETE FROM v_call_center_queues WHERE call_center_queue_uuid = :queue_uuid";
    $database->execute($sql_delete, array("queue_uuid" => $queue_uuid));

    // Clear the callcenter config cache
    require_once "resources/switch.php";
    remove_config_from_cache('configuration:callcenter.conf');

    // Unload queue from FreeSWITCH
    $queue_id = $queue_extension . '@' . $domain_name;
    $esl = event_socket::create();
    $esl_result = null;
    if ($esl) {
        $esl_result = event_socket::api("callcenter_config queue unload $queue_id");
        // Reload XML
        event_socket::api("reloadxml");
    }

    return array(
        "success" => true,
        "callCenterQueueUuid" => $queue_uuid,
        "queueName" => $queue_name,
        "queueExtension" => $queue_extension,
        "message" => "Queue deleted successfully",
        "eslResult" => $esl_result ? trim($esl_result) : "Event socket not available"
    );
}
