<?php

$required_params = array("call_center_agent_uuid");

function do_action($body) {
    global $domain_uuid;

    $agent_uuid = $body->call_center_agent_uuid;

    $database = new database;

    // Get agent details
    $sql = "SELECT
                a.*,
                d.domain_name,
                u.username
            FROM v_call_center_agents a
            LEFT JOIN v_domains d ON a.domain_uuid = d.domain_uuid
            LEFT JOIN v_users u ON a.user_uuid = u.user_uuid
            WHERE a.call_center_agent_uuid = :agent_uuid";

    $agent = $database->select($sql, array("agent_uuid" => $agent_uuid), "row");

    if (!$agent) {
        return array("error" => "Agent not found");
    }

    // Get queues assigned to this agent
    $sql_queues = "SELECT
                    t.call_center_tier_uuid,
                    t.call_center_queue_uuid,
                    t.tier_level,
                    t.tier_position,
                    q.queue_name,
                    q.queue_extension,
                    q.queue_strategy
                  FROM v_call_center_tiers t
                  LEFT JOIN v_call_center_queues q ON t.call_center_queue_uuid = q.call_center_queue_uuid
                  WHERE t.call_center_agent_uuid = :agent_uuid
                  ORDER BY t.tier_level ASC, t.tier_position ASC";

    $queues = $database->select($sql_queues, array("agent_uuid" => $agent_uuid), "all");

    $agent['queues'] = $queues ?: array();
    $agent['queue_count'] = count($agent['queues']);

    return array(
        "success" => true,
        "agent" => $agent
    );
}
