<?php

$required_params = array("callCenterAgentUuid");

function do_action($body) {
    global $domain_uuid;

    $agent_uuid = isset($body->callCenterAgentUuid) ? $body->callCenterAgentUuid : $body->call_center_agent_uuid;

    $database = new database;

    // Get existing agent
    $sql = "SELECT a.*, d.domain_name FROM v_call_center_agents a
            LEFT JOIN v_domains d ON a.domain_uuid = d.domain_uuid
            WHERE a.call_center_agent_uuid = :agent_uuid";
    $agent = $database->select($sql, array("agent_uuid" => $agent_uuid), "row");

    if (!$agent) {
        return array("error" => "Agent not found");
    }

    // Validate agent_type if provided
    $agent_type = isset($body->agentType) ? $body->agentType : (isset($body->agent_type) ? $body->agent_type : null);
    if ($agent_type !== null) {
        $valid_types = array('callback', 'uuid-standby');
        if (!in_array($agent_type, $valid_types)) {
            return array("error" => "Invalid agent_type. Valid values: " . implode(', ', $valid_types));
        }
    }

    // Validate agent_status if provided
    $agent_status = isset($body->agentStatus) ? $body->agentStatus : (isset($body->agent_status) ? $body->agent_status : null);
    if ($agent_status !== null) {
        $valid_statuses = array('Available', 'Available (On Demand)', 'On Break', 'Logged Out');
        if (!in_array($agent_status, $valid_statuses)) {
            return array("error" => "Invalid agent_status. Valid values: " . implode(', ', $valid_statuses));
        }
    }

    // Build update query dynamically based on provided fields
    $update_fields = array();
    $parameters = array("agent_uuid" => $agent_uuid);

    // Map camelCase to snake_case
    $field_mapping = array(
        'agentName' => 'agent_name',
        'agentType' => 'agent_type',
        'agentCallTimeout' => 'agent_call_timeout',
        'agentContact' => 'agent_contact',
        'agentStatus' => 'agent_status',
        'agentMaxNoAnswer' => 'agent_max_no_answer',
        'agentWrapUpTime' => 'agent_wrap_up_time',
        'agentRejectDelayTime' => 'agent_reject_delay_time',
        'agentBusyDelayTime' => 'agent_busy_delay_time',
        'agentNoAnswerDelayTime' => 'agent_no_answer_delay_time',
        'agentRecord' => 'agent_record',
        'userUuid' => 'user_uuid'
    );

    foreach ($field_mapping as $camel => $snake) {
        if (isset($body->$camel)) {
            $update_fields[] = "$snake = :$snake";
            $parameters[$snake] = $body->$camel;
        } elseif (isset($body->$snake)) {
            $update_fields[] = "$snake = :$snake";
            $parameters[$snake] = $body->$snake;
        }
    }

    if (empty($update_fields)) {
        return array("error" => "No fields to update");
    }

    $update_fields[] = "update_date = NOW()";

    $sql_update = "UPDATE v_call_center_agents SET " . implode(", ", $update_fields) .
                  " WHERE call_center_agent_uuid = :agent_uuid";

    $database->execute($sql_update, $parameters);

    // Update agent in FreeSWITCH
    $esl = event_socket::create();
    $esl_results = array();
    if ($esl) {
        // Get values for ESL commands
        $contact = isset($body->agentContact) ? $body->agentContact : (isset($body->agent_contact) ? $body->agent_contact : null);
        $status = isset($body->agentStatus) ? $body->agentStatus : (isset($body->agent_status) ? $body->agent_status : null);
        $type = isset($body->agentType) ? $body->agentType : (isset($body->agent_type) ? $body->agent_type : null);
        $max_no_answer = isset($body->agentMaxNoAnswer) ? $body->agentMaxNoAnswer : (isset($body->agent_max_no_answer) ? $body->agent_max_no_answer : null);
        $wrap_up_time = isset($body->agentWrapUpTime) ? $body->agentWrapUpTime : (isset($body->agent_wrap_up_time) ? $body->agent_wrap_up_time : null);
        $reject_delay = isset($body->agentRejectDelayTime) ? $body->agentRejectDelayTime : (isset($body->agent_reject_delay_time) ? $body->agent_reject_delay_time : null);
        $busy_delay = isset($body->agentBusyDelayTime) ? $body->agentBusyDelayTime : (isset($body->agent_busy_delay_time) ? $body->agent_busy_delay_time : null);
        $no_answer_delay = isset($body->agentNoAnswerDelayTime) ? $body->agentNoAnswerDelayTime : (isset($body->agent_no_answer_delay_time) ? $body->agent_no_answer_delay_time : null);

        // Update contact if changed
        if ($contact !== null) {
            $cmd = "callcenter_config agent set contact $agent_uuid '$contact'";
            $esl_results['contact'] = trim(event_socket::api($cmd));
        }

        // Update status if changed
        if ($status !== null) {
            $cmd = "callcenter_config agent set status $agent_uuid '$status'";
            $esl_results['status'] = trim(event_socket::api($cmd));
        }

        // Update type if changed
        if ($type !== null) {
            $cmd = "callcenter_config agent set type $agent_uuid $type";
            $esl_results['type'] = trim(event_socket::api($cmd));
        }

        // Update other properties
        if ($max_no_answer !== null) {
            $cmd = "callcenter_config agent set max_no_answer $agent_uuid $max_no_answer";
            event_socket::api($cmd);
        }

        if ($wrap_up_time !== null) {
            $cmd = "callcenter_config agent set wrap_up_time $agent_uuid $wrap_up_time";
            event_socket::api($cmd);
        }

        if ($reject_delay !== null) {
            $cmd = "callcenter_config agent set reject_delay_time $agent_uuid $reject_delay";
            event_socket::api($cmd);
        }

        if ($busy_delay !== null) {
            $cmd = "callcenter_config agent set busy_delay_time $agent_uuid $busy_delay";
            event_socket::api($cmd);
        }

        if ($no_answer_delay !== null) {
            $cmd = "callcenter_config agent set no_answer_delay_time $agent_uuid $no_answer_delay";
            event_socket::api($cmd);
        }
    }

    // Get updated agent
    $sql = "SELECT * FROM v_call_center_agents WHERE call_center_agent_uuid = :agent_uuid";
    $updated_agent = $database->select($sql, array("agent_uuid" => $agent_uuid), "row");

    return array(
        "success" => true,
        "callCenterAgentUuid" => $agent_uuid,
        "agentName" => $updated_agent['agent_name'],
        "agentType" => $updated_agent['agent_type'],
        "agentContact" => $updated_agent['agent_contact'],
        "agentStatus" => $updated_agent['agent_status'],
        "eslResults" => $esl_results
    );
}
