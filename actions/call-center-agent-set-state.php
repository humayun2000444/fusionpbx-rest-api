<?php

$required_params = array("callCenterAgentUuid", "state");

function do_action($body) {
    global $domain_uuid;

    $agent_uuid = isset($body->callCenterAgentUuid) ? $body->callCenterAgentUuid :
                  (isset($body->call_center_agent_uuid) ? $body->call_center_agent_uuid : null);
    $state = $body->state; // Waiting, Idle

    if (empty($agent_uuid)) {
        return array("error" => "callCenterAgentUuid is required");
    }

    // Validate state
    $valid_states = array('Waiting', 'Idle');
    if (!in_array($state, $valid_states)) {
        return array("error" => "Invalid state. Valid values: " . implode(', ', $valid_states));
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

    // Update state in FreeSWITCH via Event Socket
    $esl = event_socket::create();

    $esl_result = null;
    if ($esl) {
        $cmd = "callcenter_config agent set state $agent_uuid $state";
        $esl_result = event_socket::api($cmd);
    } else {
        return array("error" => "Event socket not available");
    }

    // Get current state to confirm
    $current_state = null;
    if ($esl) {
        $get_cmd = "callcenter_config agent get state $agent_uuid";
        $current_state = trim(event_socket::api($get_cmd));
    }

    return array(
        "success" => true,
        "callCenterAgentUuid" => $agent_uuid,
        "agentName" => $agent['agent_name'],
        "newState" => $state,
        "confirmedState" => $current_state,
        "eslResult" => $esl_result ? trim($esl_result) : null
    );
}
