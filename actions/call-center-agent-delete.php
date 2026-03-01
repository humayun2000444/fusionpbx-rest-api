<?php

$required_params = array("callCenterAgentUuid");

function do_action($body) {
    global $domain_uuid;

    $agent_uuid = isset($body->callCenterAgentUuid) ? $body->callCenterAgentUuid : $body->call_center_agent_uuid;

    $database = new database;

    // Get agent details
    $sql = "SELECT a.*, d.domain_name FROM v_call_center_agents a
            LEFT JOIN v_domains d ON a.domain_uuid = d.domain_uuid
            WHERE a.call_center_agent_uuid = :agent_uuid";
    $agent = $database->select($sql, array("agent_uuid" => $agent_uuid), "row");

    if (!$agent) {
        return array("error" => "Agent not found");
    }

    $agent_name = $agent['agent_name'];

    // Get all tiers for this agent to remove from FreeSWITCH
    $sql_tiers = "SELECT t.*, q.queue_extension, d.domain_name
                  FROM v_call_center_tiers t
                  LEFT JOIN v_call_center_queues q ON t.call_center_queue_uuid = q.call_center_queue_uuid
                  LEFT JOIN v_domains d ON t.domain_uuid = d.domain_uuid
                  WHERE t.call_center_agent_uuid = :agent_uuid";
    $tiers = $database->select($sql_tiers, array("agent_uuid" => $agent_uuid), "all");

    // Delete tiers from FreeSWITCH first
    $esl = event_socket::create();
    $esl_results = array();
    if ($esl && $tiers) {
        foreach ($tiers as $tier) {
            $queue_id = $tier['queue_extension'] . '@' . $tier['domain_name'];
            $cmd = "callcenter_config tier del $queue_id $agent_uuid";
            $esl_results['tier_del_' . $queue_id] = trim(event_socket::api($cmd));
        }
    }

    // Delete tiers from database
    $sql_delete_tiers = "DELETE FROM v_call_center_tiers WHERE call_center_agent_uuid = :agent_uuid";
    $database->execute($sql_delete_tiers, array("agent_uuid" => $agent_uuid));

    // Delete agent from database
    $sql_delete = "DELETE FROM v_call_center_agents WHERE call_center_agent_uuid = :agent_uuid";
    $database->execute($sql_delete, array("agent_uuid" => $agent_uuid));

    // Delete agent from FreeSWITCH
    if ($esl) {
        $cmd = "callcenter_config agent del $agent_uuid";
        $esl_results['agent_del'] = trim(event_socket::api($cmd));
    }

    return array(
        "success" => true,
        "callCenterAgentUuid" => $agent_uuid,
        "agentName" => $agent_name,
        "message" => "Agent deleted successfully",
        "tiersRemoved" => $tiers ? count($tiers) : 0,
        "eslResults" => $esl_results
    );
}
