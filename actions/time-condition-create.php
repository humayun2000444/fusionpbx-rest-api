<?php

require_once __DIR__ . '/time-condition-helper.php';

$required_params = array("name", "extension", "conditions");

function do_action($body) {
    global $domain_uuid;

    $db_domain_uuid = isset($body->domainUuid) ? $body->domainUuid : (isset($body->domain_uuid) ? $body->domain_uuid : $domain_uuid);

    $name = $body->name;
    $extension = $body->extension;
    $description = isset($body->description) ? $body->description : '';
    $enabled = isset($body->enabled) ? $body->enabled : 'true';
    $conditions = $body->conditions; // array of {timeType, timeValue, action, actionData} for matched, plus optional anti-action

    $database = new database;

    // Get domain name
    $sql_domain = "SELECT domain_name FROM v_domains WHERE domain_uuid = :domain_uuid";
    $domain_result = $database->select($sql_domain, array("domain_uuid" => $db_domain_uuid), "row");
    if (!$domain_result) {
        return array("error" => "Domain not found");
    }
    $domain_name = $domain_result['domain_name'];

    // Check if extension already used
    $sql_check = "SELECT dialplan_uuid FROM v_dialplans
                  WHERE domain_uuid = :domain_uuid AND dialplan_number = :extension
                  AND app_uuid = '4b821450-926b-175a-af93-a03c441818b1'";
    $existing = $database->select($sql_check, array(
        "domain_uuid" => $db_domain_uuid,
        "extension" => $extension
    ), "row");
    if ($existing) {
        return array("error" => "Time condition with extension $extension already exists");
    }

    // Generate UUID
    $dialplan_uuid = uuid();

    // Insert into v_dialplans
    $sql = "INSERT INTO v_dialplans (
        dialplan_uuid, domain_uuid, app_uuid, dialplan_name, dialplan_number,
        dialplan_context, dialplan_continue, dialplan_order, dialplan_enabled,
        dialplan_description, insert_date
    ) VALUES (
        :dialplan_uuid, :domain_uuid, '4b821450-926b-175a-af93-a03c441818b1', :name, :extension,
        :context, 'false', '350', :enabled,
        :description, NOW()
    )";
    $result = $database->execute($sql, array(
        "dialplan_uuid" => $dialplan_uuid,
        "domain_uuid" => $db_domain_uuid,
        "name" => $name,
        "extension" => $extension,
        "context" => $domain_name,
        "enabled" => $enabled,
        "description" => $description
    ));
    if ($result === false) {
        return array("error" => "Failed to create time condition dialplan");
    }

    // Insert dialplan details for each condition group
    // conditions is an array of objects:
    // [
    //   {
    //     "timeType": "minute-of-day",   (or "day-of-week", "mday", "mon", "hour", "wday", etc.)
    //     "timeValue": "540-1020",        (9:00 AM to 5:00 PM in minute-of-day)
    //     "dayOfWeek": "1-5",             (optional: Mon-Fri)
    //     "action": "transfer",
    //     "actionData": "3000 XML domain" (destination extension or full transfer string)
    //   }
    // ]
    // Last element can have "isAntiAction": true for the default/else route

    // The default / after-hours route is written the way the FusionPBX GUI writes
    // its "Alternate Destination" (app/time_conditions/time_condition_edit.php, the
    // $dialplan_anti_action block): detail group 999, a destination_number
    // condition, and a plain ACTION - not an anti-action, and with no time
    // condition of its own.
    //
    // It has to be an action. An <anti-action> only fires when the condition of its
    // own group fails, and the only condition in that group is the extension's own
    // destination_number - which always matches for a call that got this far, so an
    // anti-action there can never run. What makes this work instead is that the
    // matched group's action is a transfer: a transfer restarts dialplan processing
    // and abandons the rest of the extension, so group 999 is only ever reached
    // when no earlier group matched.
    //
    // Group 999 also sorts last, which is what puts it after every business-hours
    // group regardless of how many there are.
    $group = 500;
    foreach ($conditions as $cond) {
        $cond = (object) $cond;
        $is_default = (isset($cond->isAntiAction) && $cond->isAntiAction);
        $detail_group = $is_default ? 999 : $group;
        $order = 10;

        // Destination number condition
        $detail_uuid = uuid();
        $sql_detail = "INSERT INTO v_dialplan_details (
            dialplan_detail_uuid, domain_uuid, dialplan_uuid, dialplan_detail_tag,
            dialplan_detail_type, dialplan_detail_data, dialplan_detail_order, dialplan_detail_group,
            dialplan_detail_enabled
        ) VALUES (
            :detail_uuid, :domain_uuid, :dialplan_uuid, 'condition',
            'destination_number', :pattern, :detail_order, :detail_group,
            'true'
        )";
        $database->execute($sql_detail, array(
            "detail_uuid" => $detail_uuid,
            "domain_uuid" => $db_domain_uuid,
            "dialplan_uuid" => $dialplan_uuid,
            "pattern" => '^' . $extension . '$',
            "detail_order" => $order,
            "detail_group" => $detail_group
        ));
        $order += 10;

        // Day of week condition (if specified). Never on the default route:
        // a condition there would gate the fallback instead of catching it.
        if (!$is_default && isset($cond->dayOfWeek) && $cond->dayOfWeek !== '') {
            $detail_uuid = uuid();
            $database->execute($sql_detail, array(
                "detail_uuid" => $detail_uuid,
                "domain_uuid" => $db_domain_uuid,
                "dialplan_uuid" => $dialplan_uuid,
                "pattern" => $cond->dayOfWeek,
                "detail_order" => $order,
                "detail_group" => $detail_group
            ));
            // Re-insert with correct type
            $database->execute("DELETE FROM v_dialplan_details WHERE dialplan_detail_uuid = :uuid", array("uuid" => $detail_uuid));
            $sql_dow = "INSERT INTO v_dialplan_details (
                dialplan_detail_uuid, domain_uuid, dialplan_uuid, dialplan_detail_tag,
                dialplan_detail_type, dialplan_detail_data, dialplan_detail_order, dialplan_detail_group,
                dialplan_detail_enabled
            ) VALUES (
                :detail_uuid, :domain_uuid, :dialplan_uuid, 'condition',
                'wday', :pattern, :detail_order, :detail_group,
                'true'
            )";
            $database->execute($sql_dow, array(
                "detail_uuid" => $detail_uuid,
                "domain_uuid" => $db_domain_uuid,
                "dialplan_uuid" => $dialplan_uuid,
                "pattern" => $cond->dayOfWeek,
                "detail_order" => $order,
                "detail_group" => $detail_group
            ));
            $order += 10;
        }

        // Time condition (minute-of-day, hour, etc.)
        if (!$is_default && isset($cond->timeType) && $cond->timeType !== '' && isset($cond->timeValue) && $cond->timeValue !== '') {
            $detail_uuid = uuid();
            $sql_time = "INSERT INTO v_dialplan_details (
                dialplan_detail_uuid, domain_uuid, dialplan_uuid, dialplan_detail_tag,
                dialplan_detail_type, dialplan_detail_data, dialplan_detail_order, dialplan_detail_group,
                dialplan_detail_enabled
            ) VALUES (
                :detail_uuid, :domain_uuid, :dialplan_uuid, 'condition',
                :time_type, :time_value, :detail_order, :detail_group,
                'true'
            )";
            $database->execute($sql_time, array(
                "detail_uuid" => $detail_uuid,
                "domain_uuid" => $db_domain_uuid,
                "dialplan_uuid" => $dialplan_uuid,
                "time_type" => $cond->timeType,
                "time_value" => $cond->timeValue,
                "detail_order" => $order,
                "detail_group" => $detail_group
            ));
            $order += 10;
        }

        // Action: transfer to an extension / ring group / IVR / queue /
        // conference / voicemail / another time condition, lua to play a
        // recording, hangup, or anything else the FusionPBX destination picker
        // can produce. See time-condition-helper.php for what each shape has to
        // end up looking like.
        $action_type = isset($cond->action) ? $cond->action : 'transfer';
        $action_data = isset($cond->actionData) ? $cond->actionData : '';
        list($action_type, $action_data) = time_condition_normalize_action(
            $database, $action_type, $action_data, $db_domain_uuid, $domain_name
        );

        $detail_uuid = uuid();
        $action_tag = 'action';
        $sql_action = "INSERT INTO v_dialplan_details (
            dialplan_detail_uuid, domain_uuid, dialplan_uuid, dialplan_detail_tag,
            dialplan_detail_type, dialplan_detail_data, dialplan_detail_order, dialplan_detail_group,
            dialplan_detail_enabled
        ) VALUES (
            :detail_uuid, :domain_uuid, :dialplan_uuid, :tag,
            :action_type, :action_data, :detail_order, :detail_group,
            'true'
        )";
        $database->execute($sql_action, array(
            "detail_uuid" => $detail_uuid,
            "domain_uuid" => $db_domain_uuid,
            "dialplan_uuid" => $dialplan_uuid,
            "tag" => $action_tag,
            "action_type" => $action_type,
            "action_data" => $action_data,
            "detail_order" => $order,
            "detail_group" => $detail_group
        ));

        if (!$is_default) { $group += 5; }
    }

    // Build the XML FreeSWITCH is actually served. Without this the rows above
    // exist and the time condition does nothing at all.
    $xml_result = time_condition_rebuild_xml($dialplan_uuid, $domain_name);

    // Reload dialplan via ESL
    require_once "resources/switch.php";
    $esl = event_socket::create();
    $esl_result = null;
    if ($esl) {
        event_socket::api("reloadxml");
        $esl_result = "Dialplan reloaded";
    }

    return array(
        "success" => true,
        "dialplanUuid" => $dialplan_uuid,
        "name" => $name,
        "extension" => $extension,
        "domainName" => $domain_name,
        "conditionCount" => count((array)$conditions),
        "xmlResult" => $xml_result,
        "eslResult" => $esl_result ?: "Event socket not available"
    );
}
