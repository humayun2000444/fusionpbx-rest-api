<?php

$required_params = array();

function do_action($body) {
    global $domain_uuid;

    $db_domain_uuid = isset($body->domain_uuid) ? $body->domain_uuid : $domain_uuid;
    $queue_uuid = isset($body->call_center_queue_uuid) ? $body->call_center_queue_uuid : null;
    $agent_uuid = isset($body->call_center_agent_uuid) ? $body->call_center_agent_uuid : null;

    $database = new database;

    $sql = "SELECT
                t.call_center_tier_uuid,
                t.domain_uuid,
                t.call_center_queue_uuid,
                t.call_center_agent_uuid,
                t.tier_level,
                t.tier_position,
                t.insert_date,
                q.queue_name,
                q.queue_extension,
                a.agent_name,
                a.agent_contact,
                a.agent_status
            FROM v_call_center_tiers t
            LEFT JOIN v_call_center_queues q ON t.call_center_queue_uuid = q.call_center_queue_uuid
            LEFT JOIN v_call_center_agents a ON t.call_center_agent_uuid = a.call_center_agent_uuid
            WHERE t.domain_uuid = :domain_uuid";

    $parameters = array("domain_uuid" => $db_domain_uuid);

    if ($queue_uuid) {
        $sql .= " AND t.call_center_queue_uuid = :queue_uuid";
        $parameters['queue_uuid'] = $queue_uuid;
    }

    if ($agent_uuid) {
        $sql .= " AND t.call_center_agent_uuid = :agent_uuid";
        $parameters['agent_uuid'] = $agent_uuid;
    }

    $sql .= " ORDER BY q.queue_name ASC, t.tier_level ASC, t.tier_position ASC";

    $tiers = $database->select($sql, $parameters, "all");

    return array(
        "success" => true,
        "total" => count($tiers ?: array()),
        "tiers" => $tiers ?: array()
    );
}
