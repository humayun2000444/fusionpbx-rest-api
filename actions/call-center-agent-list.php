<?php

$required_params = array();

function do_action($body) {
    global $domain_uuid;

    $db_domain_uuid = isset($body->domain_uuid) ? $body->domain_uuid : $domain_uuid;
    $limit = isset($body->limit) ? intval($body->limit) : 100;
    $offset = isset($body->offset) ? intval($body->offset) : 0;

    $database = new database;

    // Get agents
    $sql = "SELECT
                a.call_center_agent_uuid,
                a.domain_uuid,
                a.user_uuid,
                a.agent_name,
                a.agent_type,
                a.agent_call_timeout,
                a.agent_id,
                a.agent_contact,
                a.agent_status,
                a.agent_max_no_answer,
                a.agent_wrap_up_time,
                a.agent_reject_delay_time,
                a.agent_busy_delay_time,
                a.agent_no_answer_delay_time,
                a.agent_record,
                a.insert_date,
                a.update_date,
                d.domain_name
            FROM v_call_center_agents a
            LEFT JOIN v_domains d ON a.domain_uuid = d.domain_uuid
            WHERE a.domain_uuid = :domain_uuid
            ORDER BY a.agent_name ASC
            LIMIT :limit OFFSET :offset";

    $parameters = array(
        "domain_uuid" => $db_domain_uuid,
        "limit" => $limit,
        "offset" => $offset
    );

    $agents = $database->select($sql, $parameters, "all");

    // Get total count
    $sql_count = "SELECT COUNT(*) as total FROM v_call_center_agents WHERE domain_uuid = :domain_uuid";
    $count_result = $database->select($sql_count, array("domain_uuid" => $db_domain_uuid), "row");
    $total = $count_result ? intval($count_result['total']) : 0;

    // Get queue assignments for each agent
    if (!empty($agents)) {
        foreach ($agents as &$agent) {
            $sql_queues = "SELECT
                            t.call_center_tier_uuid,
                            t.call_center_queue_uuid,
                            t.tier_level,
                            t.tier_position,
                            q.queue_name,
                            q.queue_extension
                          FROM v_call_center_tiers t
                          LEFT JOIN v_call_center_queues q ON t.call_center_queue_uuid = q.call_center_queue_uuid
                          WHERE t.call_center_agent_uuid = :agent_uuid
                          ORDER BY q.queue_name ASC";
            $queues = $database->select($sql_queues, array("agent_uuid" => $agent['call_center_agent_uuid']), "all");
            $agent['queues'] = $queues ?: array();
            $agent['queue_count'] = count($agent['queues']);
        }
    }

    return array(
        "success" => true,
        "total" => $total,
        "limit" => $limit,
        "offset" => $offset,
        "agents" => $agents ?: array()
    );
}
