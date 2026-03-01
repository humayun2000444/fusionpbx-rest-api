<?php

$required_params = array("callCenterQueueUuid", "callCenterAgentUuid");

function do_action($body) {
    global $domain_uuid;

    $queue_uuid = isset($body->callCenterQueueUuid) ? $body->callCenterQueueUuid : $body->call_center_queue_uuid;
    $agent_uuid = isset($body->callCenterAgentUuid) ? $body->callCenterAgentUuid : $body->call_center_agent_uuid;

    $database = new database;

    // Get tier details before deletion
    $sql_tier = "SELECT t.*, q.queue_extension, d.domain_name
                 FROM v_call_center_tiers t
                 LEFT JOIN v_call_center_queues q ON t.call_center_queue_uuid = q.call_center_queue_uuid
                 LEFT JOIN v_domains d ON t.domain_uuid = d.domain_uuid
                 WHERE t.call_center_queue_uuid = :queue_uuid
                 AND t.call_center_agent_uuid = :agent_uuid";
    $tier = $database->select($sql_tier, array("queue_uuid" => $queue_uuid, "agent_uuid" => $agent_uuid), "row");

    if (!$tier) {
        return array("error" => "Tier not found - agent is not assigned to this queue");
    }

    // Delete tier from database
    $sql_delete = "DELETE FROM v_call_center_tiers
                   WHERE call_center_queue_uuid = :queue_uuid
                   AND call_center_agent_uuid = :agent_uuid";
    $database->execute($sql_delete, array("queue_uuid" => $queue_uuid, "agent_uuid" => $agent_uuid));

    // Remove tier in FreeSWITCH via Event Socket using FusionPBX class
    $queue_id = $tier['queue_extension'] . '@' . $tier['domain_name'];

    $esl = event_socket::create();

    $esl_result = null;
    if ($esl) {
        $cmd = "callcenter_config tier del $queue_id $agent_uuid";
        $esl_result = event_socket::api($cmd);
    }

    return array(
        "success" => true,
        "callCenterTierUuid" => $tier['call_center_tier_uuid'],
        "callCenterQueueUuid" => $queue_uuid,
        "callCenterAgentUuid" => $agent_uuid,
        "queueName" => $tier['queue_name'],
        "agentName" => $tier['agent_name'],
        "eslResult" => $esl_result ? trim($esl_result) : "Event socket not available"
    );
}
