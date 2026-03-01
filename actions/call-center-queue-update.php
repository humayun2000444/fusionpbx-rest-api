<?php

$required_params = array("callCenterQueueUuid");

function do_action($body) {
    global $domain_uuid;

    $queue_uuid = isset($body->callCenterQueueUuid) ? $body->callCenterQueueUuid : $body->call_center_queue_uuid;

    $database = new database;

    // Get existing queue
    $sql = "SELECT q.*, d.domain_name FROM v_call_center_queues q
            LEFT JOIN v_domains d ON q.domain_uuid = d.domain_uuid
            WHERE q.call_center_queue_uuid = :queue_uuid";
    $queue = $database->select($sql, array("queue_uuid" => $queue_uuid), "row");

    if (!$queue) {
        return array("error" => "Queue not found");
    }

    $domain_name = $queue['domain_name'];
    $old_extension = $queue['queue_extension'];

    // Build update query dynamically based on provided fields
    $update_fields = array();
    $parameters = array("queue_uuid" => $queue_uuid);

    // Map camelCase to snake_case
    $field_mapping = array(
        'queueName' => 'queue_name',
        'queueExtension' => 'queue_extension',
        'queueGreeting' => 'queue_greeting',
        'queueStrategy' => 'queue_strategy',
        'queueMohSound' => 'queue_moh_sound',
        'queueRecordTemplate' => 'queue_record_template',
        'queueTimeBaseScore' => 'queue_time_base_score',
        'queueMaxWaitTime' => 'queue_max_wait_time',
        'queueMaxWaitTimeWithNoAgent' => 'queue_max_wait_time_with_no_agent',
        'queueTierRulesApply' => 'queue_tier_rules_apply',
        'queueTierRuleWaitSecond' => 'queue_tier_rule_wait_second',
        'queueTierRuleNoAgentNoWait' => 'queue_tier_rule_no_agent_no_wait',
        'queueTimeoutAction' => 'queue_timeout_action',
        'queueDiscardAbandonedAfter' => 'queue_discard_abandoned_after',
        'queueAbandonedResumeAllowed' => 'queue_abandoned_resume_allowed',
        'queueCidPrefix' => 'queue_cid_prefix',
        'queueAnnouncePosition' => 'queue_announce_position',
        'queueAnnounceSound' => 'queue_announce_sound',
        'queueAnnounceFrequency' => 'queue_announce_frequency',
        'queueCcExitKeys' => 'queue_cc_exit_keys',
        'queueDescription' => 'queue_description'
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

    $sql_update = "UPDATE v_call_center_queues SET " . implode(", ", $update_fields) .
                  " WHERE call_center_queue_uuid = :queue_uuid";

    $database->execute($sql_update, $parameters);

    // Get the new extension if changed
    $new_extension = isset($body->queueExtension) ? $body->queueExtension : (isset($body->queue_extension) ? $body->queue_extension : $old_extension);

    // Reload queue in FreeSWITCH
    $esl = event_socket::create();
    $esl_result = null;
    if ($esl) {
        // If extension changed, unload old queue and load new
        if ($old_extension != $new_extension) {
            $old_queue_id = $old_extension . '@' . $domain_name;
            event_socket::api("callcenter_config queue unload $old_queue_id");
        }

        $queue_id = $new_extension . '@' . $domain_name;
        $esl_result = event_socket::api("callcenter_config queue reload $queue_id");

        // Reload XML
        event_socket::api("reloadxml");
    }

    // Get updated queue
    $sql = "SELECT * FROM v_call_center_queues WHERE call_center_queue_uuid = :queue_uuid";
    $updated_queue = $database->select($sql, array("queue_uuid" => $queue_uuid), "row");

    return array(
        "success" => true,
        "callCenterQueueUuid" => $queue_uuid,
        "queueName" => $updated_queue['queue_name'],
        "queueExtension" => $updated_queue['queue_extension'],
        "queueStrategy" => $updated_queue['queue_strategy'],
        "eslResult" => $esl_result ? trim($esl_result) : "Event socket not available"
    );
}
