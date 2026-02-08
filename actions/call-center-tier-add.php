<?php

$required_params = array("callCenterQueueUuid", "callCenterAgentUuid");

function do_action($body) {
    global $domain_uuid;

    $queue_uuid = isset($body->callCenterQueueUuid) ? $body->callCenterQueueUuid : $body->call_center_queue_uuid;
    $agent_uuid = isset($body->callCenterAgentUuid) ? $body->callCenterAgentUuid : $body->call_center_agent_uuid;
    $tier_level = isset($body->tierLevel) ? intval($body->tierLevel) : (isset($body->tier_level) ? intval($body->tier_level) : 1);
    $tier_position = isset($body->tierPosition) ? intval($body->tierPosition) : (isset($body->tier_position) ? intval($body->tier_position) : 1);

    $database = new database;

    // Verify queue exists
    $sql_queue = "SELECT q.*, d.domain_name FROM v_call_center_queues q
                  LEFT JOIN v_domains d ON q.domain_uuid = d.domain_uuid
                  WHERE q.call_center_queue_uuid = :queue_uuid";
    $queue = $database->select($sql_queue, array("queue_uuid" => $queue_uuid), "row");
    if (!$queue) {
        return array("error" => "Queue not found");
    }

    // Verify agent exists
    $sql_agent = "SELECT * FROM v_call_center_agents WHERE call_center_agent_uuid = :agent_uuid";
    $agent = $database->select($sql_agent, array("agent_uuid" => $agent_uuid), "row");
    if (!$agent) {
        return array("error" => "Agent not found");
    }

    // Check if tier already exists
    $sql_check = "SELECT * FROM v_call_center_tiers
                  WHERE call_center_queue_uuid = :queue_uuid
                  AND call_center_agent_uuid = :agent_uuid";
    $existing = $database->select($sql_check, array("queue_uuid" => $queue_uuid, "agent_uuid" => $agent_uuid), "row");
    if ($existing) {
        return array("error" => "Agent is already assigned to this queue");
    }

    // Create tier UUID
    $tier_uuid = uuid();

    // Insert tier
    $sql_insert = "INSERT INTO v_call_center_tiers
                   (call_center_tier_uuid, domain_uuid, call_center_queue_uuid, call_center_agent_uuid,
                    agent_name, queue_name, tier_level, tier_position, insert_date)
                   VALUES
                   (:tier_uuid, :domain_uuid, :queue_uuid, :agent_uuid,
                    :agent_name, :queue_name, :tier_level, :tier_position, NOW())";

    $parameters = array(
        "tier_uuid" => $tier_uuid,
        "domain_uuid" => $queue['domain_uuid'],
        "queue_uuid" => $queue_uuid,
        "agent_uuid" => $agent_uuid,
        "agent_name" => $agent['agent_name'],
        "queue_name" => $queue['queue_name'],
        "tier_level" => $tier_level,
        "tier_position" => $tier_position
    );

    $database->execute($sql_insert, $parameters);

    // Clear the callcenter config cache so FreeSWITCH regenerates it
    require_once "resources/switch.php";
    remove_config_from_cache('configuration:callcenter.conf');

    // Add tier in FreeSWITCH via Event Socket using FusionPBX class
    $queue_id = $queue['queue_extension'] . '@' . $queue['domain_name'];

    $esl = event_socket::create();

    $esl_result = null;
    if ($esl) {
        // Reload XML first to regenerate configuration
        event_socket::api("reloadxml");
        usleep(500000);

        $cmd = "callcenter_config tier add $queue_id $agent_uuid $tier_level $tier_position";
        $esl_result = event_socket::api($cmd);
    }

    return array(
        "success" => true,
        "callCenterTierUuid" => $tier_uuid,
        "callCenterQueueUuid" => $queue_uuid,
        "callCenterAgentUuid" => $agent_uuid,
        "queueName" => $queue['queue_name'],
        "agentName" => $agent['agent_name'],
        "tierLevel" => $tier_level,
        "tierPosition" => $tier_position,
        "eslResult" => $esl_result ? trim($esl_result) : "Event socket not available"
    );
}
