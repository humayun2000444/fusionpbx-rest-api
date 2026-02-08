<?php

$required_params = array("callCenterAgentUuid", "status");

function do_action($body) {
    global $domain_uuid;

    $agent_uuid = isset($body->callCenterAgentUuid) ? $body->callCenterAgentUuid : $body->call_center_agent_uuid;
    $status = $body->status; // Available, On Break, Logged Out

    // Validate status
    $valid_statuses = array('Available', 'Available (On Demand)', 'On Break', 'Logged Out');
    if (!in_array($status, $valid_statuses)) {
        return array("error" => "Invalid status. Valid values: " . implode(', ', $valid_statuses));
    }

    $database = new database;

    // Get agent details
    $sql = "SELECT a.*, d.domain_name
            FROM v_call_center_agents a
            LEFT JOIN v_domains d ON a.domain_uuid = d.domain_uuid
            WHERE a.call_center_agent_uuid = :agent_uuid";
    $agent = $database->select($sql, array("agent_uuid" => $agent_uuid), "row");

    if (!$agent) {
        return array("error" => "Agent not found");
    }

    // Update status in database
    $sql_update = "UPDATE v_call_center_agents
                   SET agent_status = :status, update_date = NOW()
                   WHERE call_center_agent_uuid = :agent_uuid";
    $database->execute($sql_update, array("status" => $status, "agent_uuid" => $agent_uuid));

    // Update status in FreeSWITCH via Event Socket using FusionPBX class
    $esl = event_socket::create();

    $esl_result = null;
    if ($esl) {
        $cmd = "callcenter_config agent set status $agent_uuid '$status'";
        $esl_result = event_socket::api($cmd);
    }

    return array(
        "success" => true,
        "callCenterAgentUuid" => $agent_uuid,
        "agentName" => $agent['agent_name'],
        "previousStatus" => $agent['agent_status'],
        "newStatus" => $status,
        "eslResult" => $esl_result ? trim($esl_result) : "Event socket not available"
    );
}
