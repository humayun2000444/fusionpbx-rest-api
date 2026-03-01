<?php

$required_params = array();

function do_action($body) {
    global $domain_uuid;

    $db_domain_uuid = isset($body->domain_uuid) ? $body->domain_uuid : $domain_uuid;
    $limit = isset($body->limit) ? intval($body->limit) : 100;
    $offset = isset($body->offset) ? intval($body->offset) : 0;

    $database = new database;

    // Get queues
    $sql = "SELECT
                q.call_center_queue_uuid,
                q.domain_uuid,
                q.queue_name,
                q.queue_extension,
                q.queue_strategy,
                q.queue_moh_sound,
                q.queue_record_template,
                q.queue_time_base_score,
                q.queue_max_wait_time,
                q.queue_max_wait_time_with_no_agent,
                q.queue_tier_rules_apply,
                q.queue_tier_rule_wait_second,
                q.queue_timeout_action,
                q.queue_cid_prefix,
                q.queue_announce_position,
                q.queue_announce_frequency,
                q.queue_description,
                q.insert_date,
                q.update_date,
                d.domain_name
            FROM v_call_center_queues q
            LEFT JOIN v_domains d ON q.domain_uuid = d.domain_uuid
            WHERE q.domain_uuid = :domain_uuid
            ORDER BY q.queue_name ASC
            LIMIT :limit OFFSET :offset";

    $parameters = array(
        "domain_uuid" => $db_domain_uuid,
        "limit" => $limit,
        "offset" => $offset
    );

    $queues = $database->select($sql, $parameters, "all");

    // Get total count
    $sql_count = "SELECT COUNT(*) as total FROM v_call_center_queues WHERE domain_uuid = :domain_uuid";
    $count_result = $database->select($sql_count, array("domain_uuid" => $db_domain_uuid), "row");
    $total = $count_result ? intval($count_result['total']) : 0;

    // Get agent count per queue
    if (!empty($queues)) {
        foreach ($queues as &$queue) {
            $sql_agents = "SELECT COUNT(*) as agent_count
                          FROM v_call_center_tiers
                          WHERE call_center_queue_uuid = :queue_uuid";
            $agent_result = $database->select($sql_agents, array("queue_uuid" => $queue['call_center_queue_uuid']), "row");
            $queue['agent_count'] = $agent_result ? intval($agent_result['agent_count']) : 0;
        }
    }

    return array(
        "success" => true,
        "total" => $total,
        "limit" => $limit,
        "offset" => $offset,
        "queues" => $queues ?: array()
    );
}
