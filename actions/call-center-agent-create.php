<?php

$required_params = array("agentName", "agentContact");

function do_action($body) {
    global $domain_uuid;

    // Support both camelCase and snake_case
    $db_domain_uuid = isset($body->domainUuid) ? $body->domainUuid : (isset($body->domain_uuid) ? $body->domain_uuid : $domain_uuid);

    // Required fields (support both naming conventions)
    $agent_name = isset($body->agentName) ? $body->agentName : $body->agent_name;
    $agent_contact = isset($body->agentContact) ? $body->agentContact : $body->agent_contact;

    // Optional fields with defaults (support both naming conventions)
    $agent_type = isset($body->agentType) ? $body->agentType : (isset($body->agent_type) ? $body->agent_type : 'callback');
    $agent_status = isset($body->agentStatus) ? $body->agentStatus : (isset($body->agent_status) ? $body->agent_status : 'Logged Out');
    $agent_call_timeout = isset($body->agentCallTimeout) ? intval($body->agentCallTimeout) : (isset($body->agent_call_timeout) ? intval($body->agent_call_timeout) : 20);
    $agent_max_no_answer = isset($body->agentMaxNoAnswer) ? intval($body->agentMaxNoAnswer) : (isset($body->agent_max_no_answer) ? intval($body->agent_max_no_answer) : 3);
    $agent_wrap_up_time = isset($body->agentWrapUpTime) ? intval($body->agentWrapUpTime) : (isset($body->agent_wrap_up_time) ? intval($body->agent_wrap_up_time) : 10);
    $agent_reject_delay_time = isset($body->agentRejectDelayTime) ? intval($body->agentRejectDelayTime) : (isset($body->agent_reject_delay_time) ? intval($body->agent_reject_delay_time) : 90);
    $agent_busy_delay_time = isset($body->agentBusyDelayTime) ? intval($body->agentBusyDelayTime) : (isset($body->agent_busy_delay_time) ? intval($body->agent_busy_delay_time) : 90);
    $agent_no_answer_delay_time = isset($body->agentNoAnswerDelayTime) ? $body->agentNoAnswerDelayTime : (isset($body->agent_no_answer_delay_time) ? $body->agent_no_answer_delay_time : '30');
    $agent_record = isset($body->agentRecord) ? $body->agentRecord : (isset($body->agent_record) ? $body->agent_record : '');
    $user_uuid = isset($body->userUuid) ? $body->userUuid : (isset($body->user_uuid) ? $body->user_uuid : null);

    // Validate agent type
    $valid_types = array('callback', 'uuid-standby');
    if (!in_array($agent_type, $valid_types)) {
        return array("error" => "Invalid agent_type. Valid values: " . implode(', ', $valid_types));
    }

    // Validate agent status
    $valid_statuses = array('Available', 'Available (On Demand)', 'On Break', 'Logged Out');
    if (!in_array($agent_status, $valid_statuses)) {
        return array("error" => "Invalid agent_status. Valid values: " . implode(', ', $valid_statuses));
    }

    $database = new database;

    // Get domain name
    $sql_domain = "SELECT domain_name FROM v_domains WHERE domain_uuid = :domain_uuid";
    $domain_result = $database->select($sql_domain, array("domain_uuid" => $db_domain_uuid), "row");
    if (!$domain_result) {
        return array("error" => "Domain not found");
    }
    $domain_name = $domain_result['domain_name'];

    // Check if agent name already exists in this domain
    $sql_check = "SELECT call_center_agent_uuid FROM v_call_center_agents
                  WHERE domain_uuid = :domain_uuid AND agent_name = :agent_name";
    $existing = $database->select($sql_check, array(
        "domain_uuid" => $db_domain_uuid,
        "agent_name" => $agent_name
    ), "row");
    if ($existing) {
        return array("error" => "Agent with name '$agent_name' already exists");
    }

    // Generate agent UUID
    $agent_uuid = uuid();

    // Insert agent
    $sql_insert = "INSERT INTO v_call_center_agents (
        call_center_agent_uuid, domain_uuid, user_uuid, agent_name, agent_type,
        agent_call_timeout, agent_contact, agent_status, agent_max_no_answer,
        agent_wrap_up_time, agent_reject_delay_time, agent_busy_delay_time,
        agent_no_answer_delay_time, agent_record, insert_date
    ) VALUES (
        :agent_uuid, :domain_uuid, :user_uuid, :agent_name, :agent_type,
        :agent_call_timeout, :agent_contact, :agent_status, :agent_max_no_answer,
        :agent_wrap_up_time, :agent_reject_delay_time, :agent_busy_delay_time,
        :agent_no_answer_delay_time, :agent_record, NOW()
    )";

    $parameters = array(
        "agent_uuid" => $agent_uuid,
        "domain_uuid" => $db_domain_uuid,
        "user_uuid" => $user_uuid,
        "agent_name" => $agent_name,
        "agent_type" => $agent_type,
        "agent_call_timeout" => $agent_call_timeout,
        "agent_contact" => $agent_contact,
        "agent_status" => $agent_status,
        "agent_max_no_answer" => $agent_max_no_answer,
        "agent_wrap_up_time" => $agent_wrap_up_time,
        "agent_reject_delay_time" => $agent_reject_delay_time,
        "agent_busy_delay_time" => $agent_busy_delay_time,
        "agent_no_answer_delay_time" => $agent_no_answer_delay_time,
        "agent_record" => $agent_record
    );

    $database->execute($sql_insert, $parameters);

    // Clear the callcenter config cache so FreeSWITCH regenerates it
    require_once "resources/switch.php";
    remove_config_from_cache('configuration:callcenter.conf');

    // Add agent to FreeSWITCH mod_callcenter
    $esl = event_socket::create();
    $esl_results = array();
    if ($esl) {
        // Reload XML first to regenerate configuration
        event_socket::api("reloadxml");
        usleep(500000);

        // Add agent
        $cmd = "callcenter_config agent add $agent_uuid $agent_type";
        $esl_results['add'] = trim(event_socket::api($cmd));

        // Set agent contact
        $cmd = "callcenter_config agent set contact $agent_uuid '$agent_contact'";
        $esl_results['contact'] = trim(event_socket::api($cmd));

        // Set agent status
        $cmd = "callcenter_config agent set status $agent_uuid '$agent_status'";
        $esl_results['status'] = trim(event_socket::api($cmd));

        // Set other properties
        $cmd = "callcenter_config agent set max_no_answer $agent_uuid $agent_max_no_answer";
        event_socket::api($cmd);

        $cmd = "callcenter_config agent set wrap_up_time $agent_uuid $agent_wrap_up_time";
        event_socket::api($cmd);

        $cmd = "callcenter_config agent set reject_delay_time $agent_uuid $agent_reject_delay_time";
        event_socket::api($cmd);

        $cmd = "callcenter_config agent set busy_delay_time $agent_uuid $agent_busy_delay_time";
        event_socket::api($cmd);

        $cmd = "callcenter_config agent set no_answer_delay_time $agent_uuid $agent_no_answer_delay_time";
        event_socket::api($cmd);
    }

    return array(
        "success" => true,
        "callCenterAgentUuid" => $agent_uuid,
        "agentName" => $agent_name,
        "agentType" => $agent_type,
        "agentContact" => $agent_contact,
        "agentStatus" => $agent_status,
        "domainName" => $domain_name,
        "eslResults" => $esl_results
    );
}
