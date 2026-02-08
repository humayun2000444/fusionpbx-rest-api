<?php

$required_params = array("call_center_queue_uuid");

function do_action($body) {
    global $domain_uuid;

    $queue_uuid = $body->call_center_queue_uuid;

    $database = new database;

    // Get queue details
    $sql = "SELECT
                q.*,
                d.domain_name
            FROM v_call_center_queues q
            LEFT JOIN v_domains d ON q.domain_uuid = d.domain_uuid
            WHERE q.call_center_queue_uuid = :queue_uuid";

    $queue = $database->select($sql, array("queue_uuid" => $queue_uuid), "row");

    if (!$queue) {
        return array("error" => "Queue not found");
    }

    // Get agents assigned to this queue (tiers)
    $sql_tiers = "SELECT
                    t.call_center_tier_uuid,
                    t.call_center_agent_uuid,
                    t.tier_level,
                    t.tier_position,
                    a.agent_name,
                    a.agent_contact,
                    a.agent_status,
                    a.agent_type
                FROM v_call_center_tiers t
                LEFT JOIN v_call_center_agents a ON t.call_center_agent_uuid = a.call_center_agent_uuid
                WHERE t.call_center_queue_uuid = :queue_uuid
                ORDER BY t.tier_level ASC, t.tier_position ASC";

    $tiers = $database->select($sql_tiers, array("queue_uuid" => $queue_uuid), "all");

    $queue['agents'] = $tiers ?: array();
    $queue['agent_count'] = count($queue['agents']);

    return array(
        "success" => true,
        "queue" => $queue
    );
}
