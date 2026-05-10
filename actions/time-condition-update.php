<?php

$required_params = array("dialplanUuid");

function do_action($body) {
    global $domain_uuid;

    $dialplan_uuid = isset($body->dialplanUuid) ? $body->dialplanUuid : $body->dialplan_uuid;

    $database = new database;

    // Verify the time condition exists
    $sql_check = "SELECT d.*, dom.domain_name FROM v_dialplans d
                  LEFT JOIN v_domains dom ON d.domain_uuid = dom.domain_uuid
                  WHERE d.dialplan_uuid = :dialplan_uuid
                  AND d.app_uuid = '4b821450-926b-175a-af93-a03c441818b1'";
    $existing = $database->select($sql_check, array("dialplan_uuid" => $dialplan_uuid), "row");
    if (!$existing) {
        return array("error" => "Time condition not found");
    }

    $db_domain_uuid = $existing['domain_uuid'];
    $domain_name = $existing['domain_name'];

    // Update dialplan fields if provided
    $updates = array();
    $params = array("dialplan_uuid" => $dialplan_uuid);

    if (isset($body->name)) {
        $updates[] = "dialplan_name = :name";
        $params["name"] = $body->name;
    }
    if (isset($body->extension)) {
        $updates[] = "dialplan_number = :extension";
        $params["extension"] = $body->extension;
    }
    if (isset($body->description)) {
        $updates[] = "dialplan_description = :description";
        $params["description"] = $body->description;
    }
    if (isset($body->enabled)) {
        $updates[] = "dialplan_enabled = :enabled";
        $params["enabled"] = $body->enabled;
    }

    if (!empty($updates)) {
        $updates[] = "update_date = NOW()";
        $sql_update = "UPDATE v_dialplans SET " . implode(", ", $updates) . " WHERE dialplan_uuid = :dialplan_uuid";
        $database->execute($sql_update, $params);
    }

    // If conditions are provided, rebuild all dialplan details
    if (isset($body->conditions)) {
        $extension = isset($body->extension) ? $body->extension : $existing['dialplan_number'];

        // Delete existing details
        $sql_delete = "DELETE FROM v_dialplan_details WHERE dialplan_uuid = :dialplan_uuid";
        $database->execute($sql_delete, array("dialplan_uuid" => $dialplan_uuid));

        // Re-insert conditions
        $group = 500;
        foreach ($body->conditions as $cond) {
            $cond = (object) $cond;
            $order = 10;

            // Destination number condition
            $detail_uuid = uuid();
            $sql_detail = "INSERT INTO v_dialplan_details (
                dialplan_detail_uuid, domain_uuid, dialplan_uuid, dialplan_detail_tag,
                dialplan_detail_type, dialplan_detail_data, dialplan_detail_order, dialplan_detail_group
            ) VALUES (
                :detail_uuid, :domain_uuid, :dialplan_uuid, 'condition',
                'destination_number', :pattern, :detail_order, :detail_group
            )";
            $database->execute($sql_detail, array(
                "detail_uuid" => $detail_uuid,
                "domain_uuid" => $db_domain_uuid,
                "dialplan_uuid" => $dialplan_uuid,
                "pattern" => '^' . $extension . '$',
                "detail_order" => $order,
                "detail_group" => $group
            ));
            $order += 10;

            // Day of week condition
            if (isset($cond->dayOfWeek) && $cond->dayOfWeek !== '') {
                $detail_uuid = uuid();
                $sql_dow = "INSERT INTO v_dialplan_details (
                    dialplan_detail_uuid, domain_uuid, dialplan_uuid, dialplan_detail_tag,
                    dialplan_detail_type, dialplan_detail_data, dialplan_detail_order, dialplan_detail_group
                ) VALUES (
                    :detail_uuid, :domain_uuid, :dialplan_uuid, 'condition',
                    'wday', :pattern, :detail_order, :detail_group
                )";
                $database->execute($sql_dow, array(
                    "detail_uuid" => $detail_uuid,
                    "domain_uuid" => $db_domain_uuid,
                    "dialplan_uuid" => $dialplan_uuid,
                    "pattern" => $cond->dayOfWeek,
                    "detail_order" => $order,
                    "detail_group" => $group
                ));
                $order += 10;
            }

            // Time condition
            if (isset($cond->timeType) && $cond->timeType !== '' && isset($cond->timeValue) && $cond->timeValue !== '') {
                $detail_uuid = uuid();
                $sql_time = "INSERT INTO v_dialplan_details (
                    dialplan_detail_uuid, domain_uuid, dialplan_uuid, dialplan_detail_tag,
                    dialplan_detail_type, dialplan_detail_data, dialplan_detail_order, dialplan_detail_group
                ) VALUES (
                    :detail_uuid, :domain_uuid, :dialplan_uuid, 'condition',
                    :time_type, :time_value, :detail_order, :detail_group
                )";
                $database->execute($sql_time, array(
                    "detail_uuid" => $detail_uuid,
                    "domain_uuid" => $db_domain_uuid,
                    "dialplan_uuid" => $dialplan_uuid,
                    "time_type" => $cond->timeType,
                    "time_value" => $cond->timeValue,
                    "detail_order" => $order,
                    "detail_group" => $group
                ));
                $order += 10;
            }

            // Action
            $action_type = isset($cond->action) ? $cond->action : 'transfer';
            $action_data = isset($cond->actionData) ? $cond->actionData : '';

            if ($action_type === 'transfer' && strpos($action_data, 'XML') === false) {
                $action_data = $action_data . ' XML ' . $domain_name;
            }

            $detail_uuid = uuid();
            $action_tag = (isset($cond->isAntiAction) && $cond->isAntiAction) ? 'anti-action' : 'action';
            $sql_action = "INSERT INTO v_dialplan_details (
                dialplan_detail_uuid, domain_uuid, dialplan_uuid, dialplan_detail_tag,
                dialplan_detail_type, dialplan_detail_data, dialplan_detail_order, dialplan_detail_group
            ) VALUES (
                :detail_uuid, :domain_uuid, :dialplan_uuid, :tag,
                :action_type, :action_data, :detail_order, :detail_group
            )";
            $database->execute($sql_action, array(
                "detail_uuid" => $detail_uuid,
                "domain_uuid" => $db_domain_uuid,
                "dialplan_uuid" => $dialplan_uuid,
                "tag" => $action_tag,
                "action_type" => $action_type,
                "action_data" => $action_data,
                "detail_order" => $order,
                "detail_group" => $group
            ));

            $group += 5;
        }
    }

    // Reload dialplan
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
        "message" => "Time condition updated successfully",
        "eslResult" => $esl_result ?: "Event socket not available"
    );
}
