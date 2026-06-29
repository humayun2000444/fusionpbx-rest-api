<?php

$required_params = array("queueName", "queueExtension");

function do_action($body) {
    global $domain_uuid;

    // Support both camelCase (from Java) and snake_case
    $db_domain_uuid = isset($body->domainUuid) ? $body->domainUuid : (isset($body->domain_uuid) ? $body->domain_uuid : $domain_uuid);

    // Required fields (support both naming conventions)
    $queue_name = isset($body->queueName) ? $body->queueName : $body->queue_name;
    $queue_extension = isset($body->queueExtension) ? $body->queueExtension : $body->queue_extension;

    // Optional fields with defaults (support both naming conventions)
    $queue_strategy = isset($body->queueStrategy) ? $body->queueStrategy : (isset($body->queue_strategy) ? $body->queue_strategy : 'ring-all');
    $queue_moh_sound = isset($body->queueMohSound) ? $body->queueMohSound : (isset($body->queue_moh_sound) ? $body->queue_moh_sound : 'local_stream://default');
    $queue_greeting = isset($body->queueGreeting) ? $body->queueGreeting : (isset($body->queue_greeting) ? $body->queue_greeting : '');
    $queue_max_wait_time = isset($body->queueMaxWaitTime) ? intval($body->queueMaxWaitTime) : (isset($body->queue_max_wait_time) ? intval($body->queue_max_wait_time) : 0);
    $queue_max_wait_time_with_no_agent = isset($body->queueMaxWaitTimeWithNoAgent) ? intval($body->queueMaxWaitTimeWithNoAgent) : (isset($body->queue_max_wait_time_with_no_agent) ? intval($body->queue_max_wait_time_with_no_agent) : 90);
    $queue_timeout_action = isset($body->queueTimeoutAction) ? $body->queueTimeoutAction : (isset($body->queue_timeout_action) ? $body->queue_timeout_action : '');
    $queue_tier_rules_apply = isset($body->queueTierRulesApply) ? $body->queueTierRulesApply : (isset($body->queue_tier_rules_apply) ? $body->queue_tier_rules_apply : 'false');
    $queue_tier_rule_wait_second = isset($body->queueTierRuleWaitSecond) ? intval($body->queueTierRuleWaitSecond) : (isset($body->queue_tier_rule_wait_second) ? intval($body->queue_tier_rule_wait_second) : 300);
    $queue_tier_rule_no_agent_no_wait = isset($body->queueTierRuleNoAgentNoWait) ? $body->queueTierRuleNoAgentNoWait : (isset($body->queue_tier_rule_no_agent_no_wait) ? $body->queue_tier_rule_no_agent_no_wait : 'true');
    $queue_discard_abandoned_after = isset($body->queueDiscardAbandonedAfter) ? intval($body->queueDiscardAbandonedAfter) : (isset($body->queue_discard_abandoned_after) ? intval($body->queue_discard_abandoned_after) : 900);
    $queue_abandoned_resume_allowed = isset($body->queueAbandonedResumeAllowed) ? $body->queueAbandonedResumeAllowed : (isset($body->queue_abandoned_resume_allowed) ? $body->queue_abandoned_resume_allowed : 'false');
    $queue_cid_prefix = isset($body->queueCidPrefix) ? $body->queueCidPrefix : (isset($body->queue_cid_prefix) ? $body->queue_cid_prefix : '');
    $queue_announce_position = isset($body->queueAnnouncePosition) ? $body->queueAnnouncePosition : (isset($body->queue_announce_position) ? $body->queue_announce_position : 'false');
    $queue_announce_sound = isset($body->queueAnnounceSound) ? $body->queueAnnounceSound : (isset($body->queue_announce_sound) ? $body->queue_announce_sound : '');
    $queue_announce_frequency = isset($body->queueAnnounceFrequency) ? intval($body->queueAnnounceFrequency) : (isset($body->queue_announce_frequency) ? intval($body->queue_announce_frequency) : 0);
    $queue_description = isset($body->queueDescription) ? $body->queueDescription : (isset($body->queue_description) ? $body->queue_description : '');
    $queue_record_template = isset($body->queueRecordTemplate) ? $body->queueRecordTemplate : (isset($body->queue_record_template) ? $body->queue_record_template : '');
    $queue_time_base_score = isset($body->queueTimeBaseScore) ? $body->queueTimeBaseScore : (isset($body->queue_time_base_score) ? $body->queue_time_base_score : 'system');
    $queue_cc_exit_keys = isset($body->queueCcExitKeys) ? $body->queueCcExitKeys : (isset($body->queue_cc_exit_keys) ? $body->queue_cc_exit_keys : '');

    $database = new database;

    // Get domain name for context
    $sql_domain = "SELECT domain_name FROM v_domains WHERE domain_uuid = :domain_uuid";
    $domain_result = $database->select($sql_domain, array("domain_uuid" => $db_domain_uuid), "row");
    if (!$domain_result) {
        return array("error" => "Domain not found");
    }
    $domain_name = $domain_result['domain_name'];
    $queue_context = $domain_name;

    // Check if queue extension already exists
    $sql_check = "SELECT call_center_queue_uuid FROM v_call_center_queues
                  WHERE domain_uuid = :domain_uuid AND queue_extension = :queue_extension";
    $existing = $database->select($sql_check, array(
        "domain_uuid" => $db_domain_uuid,
        "queue_extension" => $queue_extension
    ), "row");
    if ($existing) {
        return array("error" => "Queue with extension $queue_extension already exists");
    }

    // Generate UUIDs
    $queue_uuid = uuid();
    $dialplan_uuid = uuid();

    // Insert queue
    $sql_insert = "INSERT INTO v_call_center_queues (
        call_center_queue_uuid, domain_uuid, dialplan_uuid, queue_name, queue_extension,
        queue_greeting, queue_strategy, queue_moh_sound, queue_record_template,
        queue_time_base_score, queue_max_wait_time, queue_max_wait_time_with_no_agent,
        queue_tier_rules_apply, queue_tier_rule_wait_second, queue_tier_rule_no_agent_no_wait,
        queue_timeout_action, queue_discard_abandoned_after, queue_abandoned_resume_allowed,
        queue_cid_prefix, queue_announce_position, queue_announce_sound, queue_announce_frequency,
        queue_cc_exit_keys, queue_context, queue_description, insert_date
    ) VALUES (
        :queue_uuid, :domain_uuid, :dialplan_uuid, :queue_name, :queue_extension,
        :queue_greeting, :queue_strategy, :queue_moh_sound, :queue_record_template,
        :queue_time_base_score, :queue_max_wait_time, :queue_max_wait_time_with_no_agent,
        :queue_tier_rules_apply, :queue_tier_rule_wait_second, :queue_tier_rule_no_agent_no_wait,
        :queue_timeout_action, :queue_discard_abandoned_after, :queue_abandoned_resume_allowed,
        :queue_cid_prefix, :queue_announce_position, :queue_announce_sound, :queue_announce_frequency,
        :queue_cc_exit_keys, :queue_context, :queue_description, NOW()
    )";

    $parameters = array(
        "queue_uuid" => $queue_uuid,
        "domain_uuid" => $db_domain_uuid,
        "dialplan_uuid" => $dialplan_uuid,
        "queue_name" => $queue_name,
        "queue_extension" => $queue_extension,
        "queue_greeting" => $queue_greeting,
        "queue_strategy" => $queue_strategy,
        "queue_moh_sound" => $queue_moh_sound,
        "queue_record_template" => $queue_record_template,
        "queue_time_base_score" => $queue_time_base_score,
        "queue_max_wait_time" => $queue_max_wait_time,
        "queue_max_wait_time_with_no_agent" => $queue_max_wait_time_with_no_agent,
        "queue_tier_rules_apply" => $queue_tier_rules_apply,
        "queue_tier_rule_wait_second" => $queue_tier_rule_wait_second,
        "queue_tier_rule_no_agent_no_wait" => $queue_tier_rule_no_agent_no_wait,
        "queue_timeout_action" => $queue_timeout_action,
        "queue_discard_abandoned_after" => $queue_discard_abandoned_after,
        "queue_abandoned_resume_allowed" => $queue_abandoned_resume_allowed,
        "queue_cid_prefix" => $queue_cid_prefix,
        "queue_announce_position" => $queue_announce_position,
        "queue_announce_sound" => $queue_announce_sound,
        "queue_announce_frequency" => $queue_announce_frequency,
        "queue_cc_exit_keys" => $queue_cc_exit_keys,
        "queue_context" => $queue_context,
        "queue_description" => $queue_description
    );

    $database->execute($sql_insert, $parameters);

    // Create dialplan for the queue (with dialplan_xml like FusionPBX GUI)
    create_queue_dialplan($database, $dialplan_uuid, $db_domain_uuid, $domain_name, $queue_name, $queue_extension, $queue_uuid, $queue_cid_prefix, $queue_greeting, $queue_cc_exit_keys, $queue_timeout_action);

    // Clear the callcenter config cache so FreeSWITCH regenerates it
    require_once "resources/switch.php";
    remove_config_from_cache('configuration:callcenter.conf');

    // Load queue into FreeSWITCH mod_callcenter
    $queue_id = $queue_extension . '@' . $domain_name;
    $esl = event_socket::create();
    $esl_result = null;
    if ($esl) {
        // Reload XML first to regenerate configuration
        event_socket::api("reloadxml");

        // Small delay to allow XML to be processed
        usleep(500000);

        // Load the queue into mod_callcenter
        $cmd = "callcenter_config queue load $queue_id";
        $esl_result = event_socket::api($cmd);
    }

    return array(
        "success" => true,
        "callCenterQueueUuid" => $queue_uuid,
        "dialplanUuid" => $dialplan_uuid,
        "queueName" => $queue_name,
        "queueExtension" => $queue_extension,
        "queueStrategy" => $queue_strategy,
        "domainName" => $domain_name,
        "eslResult" => $esl_result ? trim($esl_result) : "Event socket not available"
    );
}

function create_queue_dialplan($database, $dialplan_uuid, $domain_uuid, $domain_name, $queue_name, $queue_extension, $queue_uuid, $queue_cid_prefix = '', $queue_greeting = '', $queue_cc_exit_keys = '', $queue_timeout_action = '') {
    // Build dialplan_xml exactly like FusionPBX GUI does
    $xml = '<extension name="' . htmlspecialchars($queue_name) . '" continue="false" uuid="' . $dialplan_uuid . '">' . "\n";

    // Group 1: CID prefix strip (caller_id_name contains #)
    $xml .= '	<condition field="${caller_id_name}" expression="^([^#]+#)(.*)$" break="never">' . "\n";
    $xml .= '		<action application="set" data="caller_id_name=$2"/>' . "\n";
    $xml .= '	</condition>' . "\n";

    // Group 2: Main condition + actions
    $xml .= '	<condition field="destination_number" expression="^' . $queue_extension . '$">' . "\n";
    $xml .= '		<action application="answer" data=""/>' . "\n";
    $xml .= '		<action application="set" data="hangup_after_bridge=true"/>' . "\n";

    if (!empty($queue_cid_prefix)) {
        $xml .= '		<action application="set" data="effective_caller_id_name=' . htmlspecialchars($queue_cid_prefix) . '#${caller_id_name}"/>' . "\n";
    }

    if (!empty($queue_greeting)) {
        $xml .= '		<action application="sleep" data="1000"/>' . "\n";
        $xml .= '		<action application="playback" data="' . htmlspecialchars($queue_greeting) . '"/>' . "\n";
    }

    if (!empty($queue_cc_exit_keys)) {
        $xml .= '		<action application="set" data="cc_exit_keys=' . htmlspecialchars($queue_cc_exit_keys) . '"/>' . "\n";
    }

    $xml .= '		<action application="callcenter" data="' . $queue_name . '@' . $domain_name . '"/>' . "\n";

    if (!empty($queue_timeout_action)) {
        $action_parts = explode(":", $queue_timeout_action, 2);
        $xml .= '		<action application="' . htmlspecialchars($action_parts[0]) . '" data="' . htmlspecialchars($action_parts[1] ?? '') . '"/>' . "\n";
    }

    $xml .= '		<action application="hangup" data=""/>' . "\n";
    $xml .= '	</condition>' . "\n";
    $xml .= '</extension>';

    // Insert dialplan WITH dialplan_xml (same app_uuid and order as FusionPBX GUI)
    $sql = "INSERT INTO v_dialplans (
        dialplan_uuid, domain_uuid, app_uuid, dialplan_name, dialplan_number,
        dialplan_context, dialplan_continue, dialplan_order, dialplan_enabled,
        dialplan_description, dialplan_xml, insert_date
    ) VALUES (
        :dialplan_uuid, :domain_uuid, '95788e50-9500-079e-2807-fd530b0ea370', :dialplan_name, :dialplan_number,
        :dialplan_context, 'false', '210', 'true',
        :dialplan_description, :dialplan_xml, NOW()
    )";

    $database->execute($sql, array(
        "dialplan_uuid" => $dialplan_uuid,
        "domain_uuid" => $domain_uuid,
        "dialplan_name" => $queue_name,
        "dialplan_number" => $queue_extension,
        "dialplan_context" => $domain_name,
        "dialplan_description" => "Call Center Queue: $queue_name",
        "dialplan_xml" => $xml
    ));

    // Also insert dialplan details for FusionPBX UI compatibility
    $y = 1;

    // Detail: CID name condition (group 1)
    $database->execute("INSERT INTO v_dialplan_details (dialplan_detail_uuid, domain_uuid, dialplan_uuid,
        dialplan_detail_tag, dialplan_detail_type, dialplan_detail_data, dialplan_detail_break,
        dialplan_detail_group, dialplan_detail_order)
        VALUES (:uuid, :domain_uuid, :dialplan_uuid, 'condition', '\${caller_id_name}', '^([^#]+#)(.*)$', 'never', '1', :ord)",
        array("uuid" => uuid(), "domain_uuid" => $domain_uuid, "dialplan_uuid" => $dialplan_uuid, "ord" => $y * 10));
    $y++;

    // Detail: CID name set action (group 1)
    $database->execute("INSERT INTO v_dialplan_details (dialplan_detail_uuid, domain_uuid, dialplan_uuid,
        dialplan_detail_tag, dialplan_detail_type, dialplan_detail_data,
        dialplan_detail_group, dialplan_detail_order)
        VALUES (:uuid, :domain_uuid, :dialplan_uuid, 'action', 'set', 'caller_id_name=\$2', '1', :ord)",
        array("uuid" => uuid(), "domain_uuid" => $domain_uuid, "dialplan_uuid" => $dialplan_uuid, "ord" => $y * 10));
    $y++;

    // Detail: destination_number condition (group 2)
    $database->execute("INSERT INTO v_dialplan_details (dialplan_detail_uuid, domain_uuid, dialplan_uuid,
        dialplan_detail_tag, dialplan_detail_type, dialplan_detail_data,
        dialplan_detail_group, dialplan_detail_order)
        VALUES (:uuid, :domain_uuid, :dialplan_uuid, 'condition', 'destination_number', :pattern, '2', :ord)",
        array("uuid" => uuid(), "domain_uuid" => $domain_uuid, "dialplan_uuid" => $dialplan_uuid,
              "pattern" => '^' . $queue_extension . '$', "ord" => $y * 10));
    $y++;

    // Detail: answer (group 2)
    $database->execute("INSERT INTO v_dialplan_details (dialplan_detail_uuid, domain_uuid, dialplan_uuid,
        dialplan_detail_tag, dialplan_detail_type, dialplan_detail_data,
        dialplan_detail_group, dialplan_detail_order)
        VALUES (:uuid, :domain_uuid, :dialplan_uuid, 'action', 'answer', '', '2', :ord)",
        array("uuid" => uuid(), "domain_uuid" => $domain_uuid, "dialplan_uuid" => $dialplan_uuid, "ord" => $y * 10));
    $y++;

    // Detail: hangup_after_bridge (group 2)
    $database->execute("INSERT INTO v_dialplan_details (dialplan_detail_uuid, domain_uuid, dialplan_uuid,
        dialplan_detail_tag, dialplan_detail_type, dialplan_detail_data,
        dialplan_detail_group, dialplan_detail_order)
        VALUES (:uuid, :domain_uuid, :dialplan_uuid, 'action', 'set', 'hangup_after_bridge=true', '2', :ord)",
        array("uuid" => uuid(), "domain_uuid" => $domain_uuid, "dialplan_uuid" => $dialplan_uuid, "ord" => $y * 10));
    $y++;

    // Detail: callcenter action (group 2)
    $database->execute("INSERT INTO v_dialplan_details (dialplan_detail_uuid, domain_uuid, dialplan_uuid,
        dialplan_detail_tag, dialplan_detail_type, dialplan_detail_data,
        dialplan_detail_group, dialplan_detail_order)
        VALUES (:uuid, :domain_uuid, :dialplan_uuid, 'action', 'callcenter', :queue_id, '2', :ord)",
        array("uuid" => uuid(), "domain_uuid" => $domain_uuid, "dialplan_uuid" => $dialplan_uuid,
              "queue_id" => $queue_name . '@' . $domain_name, "ord" => $y * 10));
    $y++;

    // Detail: hangup (group 2)
    $database->execute("INSERT INTO v_dialplan_details (dialplan_detail_uuid, domain_uuid, dialplan_uuid,
        dialplan_detail_tag, dialplan_detail_type, dialplan_detail_data,
        dialplan_detail_group, dialplan_detail_order)
        VALUES (:uuid, :domain_uuid, :dialplan_uuid, 'action', 'hangup', '', '2', :ord)",
        array("uuid" => uuid(), "domain_uuid" => $domain_uuid, "dialplan_uuid" => $dialplan_uuid, "ord" => $y * 10));

    // Clear dialplan cache for this domain
    $cache_file = '/var/cache/fusionpbx/dialplan.' . $domain_name;
    if (file_exists($cache_file)) {
        @unlink($cache_file);
    }
}
