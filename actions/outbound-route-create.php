<?php
$required_params = array("dialplan_name", "gateway_uuid", "destination_pattern");

function do_action($body) {
    global $domain_uuid;

    // Outbound routes app_uuid
    $outbound_app_uuid = '8c914ec3-9fc0-8ab5-4cda-6c9288bdc9a3';

    // Get domain_uuid - use provided or global
    $route_domain_uuid = isset($body->domain_uuid) ? $body->domain_uuid : $domain_uuid;

    // Get context - use provided or get from domain
    $context = isset($body->context) ? $body->context : null;
    if(!$context && $route_domain_uuid) {
        $sql = "SELECT domain_name FROM v_domains WHERE domain_uuid = :domain_uuid";
        $parameters = array("domain_uuid" => $route_domain_uuid);
        $database = new database;
        $domain = $database->select($sql, $parameters, "row");
        $context = $domain ? $domain["domain_name"] : "public";
        unset($parameters);
    }
    if(!$context) {
        $context = "public";
    }

    // Verify gateway exists
    $sql = "SELECT gateway_uuid, gateway FROM v_gateways WHERE gateway_uuid = :gateway_uuid";
    $parameters = array("gateway_uuid" => $body->gateway_uuid);
    $database = new database;
    $gateway = $database->select($sql, $parameters, "row");

    if(!$gateway) {
        return array("error" => "Gateway not found");
    }
    unset($parameters);

    // Get order - default to 100 or use provided
    $dialplan_order = isset($body->dialplan_order) ? $body->dialplan_order : 100;

    // Get prefix - optional
    $prefix = isset($body->prefix) ? $body->prefix : "";

    // Build bridge data
    $bridge_data = "sofia/gateway/" . $body->gateway_uuid . "/" . $prefix . "\$1";

    // Get enabled status
    $dialplan_enabled = isset($body->dialplan_enabled) ? $body->dialplan_enabled : "true";

    // Get description
    $dialplan_description = isset($body->dialplan_description) ? $body->dialplan_description : null;

    // ========================================
    // DIALPLAN 1: call_direction-outbound (order 22)
    // ========================================
    $dialplan_uuid_1 = uuid();

    $sql = "INSERT INTO v_dialplans (dialplan_uuid, domain_uuid, app_uuid, dialplan_name,
            dialplan_number, dialplan_context, dialplan_continue, dialplan_order,
            dialplan_enabled, dialplan_description, insert_date)
            VALUES (:dialplan_uuid, :domain_uuid, :app_uuid, :dialplan_name,
            :dialplan_number, :dialplan_context, :dialplan_continue, :dialplan_order,
            :dialplan_enabled, :dialplan_description, NOW())";

    $parameters = array();
    $parameters["dialplan_uuid"] = $dialplan_uuid_1;
    $parameters["domain_uuid"] = $route_domain_uuid;
    $parameters["app_uuid"] = $outbound_app_uuid;
    $parameters["dialplan_name"] = "call_direction-outbound";
    $parameters["dialplan_number"] = null;
    $parameters["dialplan_context"] = $context;
    $parameters["dialplan_continue"] = "true";  // IMPORTANT: continue to next dialplan
    $parameters["dialplan_order"] = 22;
    $parameters["dialplan_enabled"] = $dialplan_enabled;
    $parameters["dialplan_description"] = $dialplan_description;

    $database = new database;
    $database->execute($sql, $parameters);
    unset($parameters);

    // Insert details for call_direction-outbound
    $detail_order = 0;

    // Condition: user_exists = false
    insert_detail($dialplan_uuid_1, $route_domain_uuid, 'condition', '${user_exists}', 'false', 0, $detail_order += 10);

    // Condition: call_direction = ^$ (empty)
    insert_detail($dialplan_uuid_1, $route_domain_uuid, 'condition', '${call_direction}', '^$', 0, $detail_order += 10);

    // Condition: destination_number pattern
    insert_detail($dialplan_uuid_1, $route_domain_uuid, 'condition', 'destination_number', $body->destination_pattern, 0, $detail_order += 10);

    // Action: export call_direction=outbound (inline)
    insert_detail_inline($dialplan_uuid_1, $route_domain_uuid, 'action', 'export', 'call_direction=outbound', 0, $detail_order += 10, 'true');

    // ========================================
    // DIALPLAN 2: The actual route (order from request)
    // ========================================
    $dialplan_uuid_2 = uuid();

    $sql = "INSERT INTO v_dialplans (dialplan_uuid, domain_uuid, app_uuid, dialplan_name,
            dialplan_number, dialplan_context, dialplan_continue, dialplan_order,
            dialplan_enabled, dialplan_description, insert_date)
            VALUES (:dialplan_uuid, :domain_uuid, :app_uuid, :dialplan_name,
            :dialplan_number, :dialplan_context, :dialplan_continue, :dialplan_order,
            :dialplan_enabled, :dialplan_description, NOW())";

    $parameters = array();
    $parameters["dialplan_uuid"] = $dialplan_uuid_2;
    $parameters["domain_uuid"] = $route_domain_uuid;
    $parameters["app_uuid"] = $outbound_app_uuid;
    $parameters["dialplan_name"] = $body->dialplan_name;
    $parameters["dialplan_number"] = isset($body->dialplan_number) ? $body->dialplan_number : null;
    $parameters["dialplan_context"] = $context;
    $parameters["dialplan_continue"] = isset($body->dialplan_continue) ? $body->dialplan_continue : "false";
    $parameters["dialplan_order"] = $dialplan_order;
    $parameters["dialplan_enabled"] = $dialplan_enabled;
    $parameters["dialplan_description"] = $dialplan_description;

    $database = new database;
    $database->execute($sql, $parameters);
    unset($parameters);

    // Insert details for the actual route
    $detail_order = 0;

    // Condition: user_exists = false
    insert_detail($dialplan_uuid_2, $route_domain_uuid, 'condition', '${user_exists}', 'false', 0, $detail_order += 10);

    // Condition: destination_number pattern
    insert_detail($dialplan_uuid_2, $route_domain_uuid, 'condition', 'destination_number', $body->destination_pattern, 0, $detail_order += 10);

    // Action: set accountcode
    insert_detail($dialplan_uuid_2, $route_domain_uuid, 'action', 'set', 'sip_h_accountcode=${accountcode}', 0, $detail_order += 10);

    // Action: export call_direction (inline)
    insert_detail_inline($dialplan_uuid_2, $route_domain_uuid, 'action', 'export', 'call_direction=outbound', 0, $detail_order += 10, 'true');

    // Action: unset call_timeout
    insert_detail($dialplan_uuid_2, $route_domain_uuid, 'action', 'unset', 'call_timeout', 0, $detail_order += 10);

    // Action: set hangup_after_bridge
    insert_detail($dialplan_uuid_2, $route_domain_uuid, 'action', 'set', 'hangup_after_bridge=true', 0, $detail_order += 10);

    // Action: set effective_caller_id_name
    insert_detail($dialplan_uuid_2, $route_domain_uuid, 'action', 'set', 'effective_caller_id_name=${outbound_caller_id_name}', 0, $detail_order += 10);

    // Action: set effective_caller_id_number
    insert_detail($dialplan_uuid_2, $route_domain_uuid, 'action', 'set', 'effective_caller_id_number=${outbound_caller_id_number}', 0, $detail_order += 10);

    // Action: set inherit_codec
    insert_detail($dialplan_uuid_2, $route_domain_uuid, 'action', 'set', 'inherit_codec=true', 0, $detail_order += 10);

    // Action: set ignore_display_updates
    insert_detail($dialplan_uuid_2, $route_domain_uuid, 'action', 'set', 'ignore_display_updates=true', 0, $detail_order += 10);

    // Action: set callee_id_number
    insert_detail($dialplan_uuid_2, $route_domain_uuid, 'action', 'set', 'callee_id_number=$1', 0, $detail_order += 10);

    // Action: set continue_on_fail
    $continue_on_fail = isset($body->continue_on_fail) ? $body->continue_on_fail : '1,2,3,6,18,21,27,28,31,34,38,41,42,44,58,88,111,403,501,602,607,809';
    insert_detail($dialplan_uuid_2, $route_domain_uuid, 'action', 'set', 'continue_on_fail=' . $continue_on_fail, 0, $detail_order += 10);

    // Action: bridge to gateway
    insert_detail($dialplan_uuid_2, $route_domain_uuid, 'action', 'bridge', $bridge_data, 0, $detail_order += 10);

    // ========================================
    // Generate dialplan_xml for both dialplans
    // ========================================

    // Generate XML for call_direction-outbound dialplan
    $xml_1 = generate_dialplan_xml($dialplan_uuid_1, "call_direction-outbound", "true");
    update_dialplan_xml($dialplan_uuid_1, $xml_1);

    // Generate XML for the actual route dialplan
    $dialplan_continue_2 = isset($body->dialplan_continue) ? $body->dialplan_continue : "false";
    $xml_2 = generate_dialplan_xml($dialplan_uuid_2, $body->dialplan_name, $dialplan_continue_2);
    update_dialplan_xml($dialplan_uuid_2, $xml_2);

    // Clear cache and reload dialplan
    $reload_output = "";
    $reload_success = false;

    if (class_exists('event_socket')) {
        $esl = event_socket::create();
        if ($esl) {
            $hostname = trim(event_socket::api('switchname'));

            // Clear dialplan cache
            if (class_exists('cache')) {
                $cache = new cache;
                $cache->delete("dialplan:" . $context);
            }

            // Reload XML
            $reload_output = event_socket::api('reloadxml');
            $reload_success = true;
        }
    }

    if (!$reload_success) {
        $reload_output = shell_exec("/usr/bin/fs_cli -x 'reloadxml' 2>&1");
        $reload_success = ($reload_output !== null);
    }

    // Return created route (return the main route, not the call_direction one)
    $sql = "SELECT dialplan_uuid, domain_uuid, dialplan_name, dialplan_number,
            dialplan_context, dialplan_order, dialplan_enabled, dialplan_description, insert_date
            FROM v_dialplans WHERE dialplan_uuid = :dialplan_uuid";
    $parameters = array("dialplan_uuid" => $dialplan_uuid_2);
    $database = new database;
    $result = $database->select($sql, $parameters, "row");

    $result["call_direction_dialplan_uuid"] = $dialplan_uuid_1;
    $result["gateway_uuid"] = $body->gateway_uuid;
    $result["gateway_name"] = $gateway["gateway"];
    $result["destination_pattern"] = $body->destination_pattern;
    $result["bridge_data"] = $bridge_data;
    $result["reloaded"] = $reload_success;
    $result["reload_output"] = trim($reload_output);

    return $result;
}

function insert_detail($dialplan_uuid, $domain_uuid, $tag, $type, $data, $group, $order) {
    $sql = "INSERT INTO v_dialplan_details (dialplan_detail_uuid, domain_uuid, dialplan_uuid,
            dialplan_detail_tag, dialplan_detail_type, dialplan_detail_data,
            dialplan_detail_group, dialplan_detail_order, dialplan_detail_enabled, insert_date)
            VALUES (:dialplan_detail_uuid, :domain_uuid, :dialplan_uuid,
            :dialplan_detail_tag, :dialplan_detail_type, :dialplan_detail_data,
            :dialplan_detail_group, :dialplan_detail_order, :dialplan_detail_enabled, NOW())";

    $parameters = array();
    $parameters["dialplan_detail_uuid"] = uuid();
    $parameters["domain_uuid"] = $domain_uuid;
    $parameters["dialplan_uuid"] = $dialplan_uuid;
    $parameters["dialplan_detail_tag"] = $tag;
    $parameters["dialplan_detail_type"] = $type;
    $parameters["dialplan_detail_data"] = $data;
    $parameters["dialplan_detail_group"] = $group;
    $parameters["dialplan_detail_order"] = $order;
    $parameters["dialplan_detail_enabled"] = "true";

    $database = new database;
    $database->execute($sql, $parameters);
}

function insert_detail_inline($dialplan_uuid, $domain_uuid, $tag, $type, $data, $group, $order, $inline) {
    $sql = "INSERT INTO v_dialplan_details (dialplan_detail_uuid, domain_uuid, dialplan_uuid,
            dialplan_detail_tag, dialplan_detail_type, dialplan_detail_data, dialplan_detail_inline,
            dialplan_detail_group, dialplan_detail_order, dialplan_detail_enabled, insert_date)
            VALUES (:dialplan_detail_uuid, :domain_uuid, :dialplan_uuid,
            :dialplan_detail_tag, :dialplan_detail_type, :dialplan_detail_data, :dialplan_detail_inline,
            :dialplan_detail_group, :dialplan_detail_order, :dialplan_detail_enabled, NOW())";

    $parameters = array();
    $parameters["dialplan_detail_uuid"] = uuid();
    $parameters["domain_uuid"] = $domain_uuid;
    $parameters["dialplan_uuid"] = $dialplan_uuid;
    $parameters["dialplan_detail_tag"] = $tag;
    $parameters["dialplan_detail_type"] = $type;
    $parameters["dialplan_detail_data"] = $data;
    $parameters["dialplan_detail_inline"] = $inline;
    $parameters["dialplan_detail_group"] = $group;
    $parameters["dialplan_detail_order"] = $order;
    $parameters["dialplan_detail_enabled"] = "true";

    $database = new database;
    $database->execute($sql, $parameters);
}

function generate_dialplan_xml($dialplan_uuid, $dialplan_name, $dialplan_continue) {
    // Get dialplan details from database
    $sql = "SELECT dialplan_detail_tag, dialplan_detail_type, dialplan_detail_data,
            dialplan_detail_break, dialplan_detail_inline, dialplan_detail_group,
            dialplan_detail_order, dialplan_detail_enabled
            FROM v_dialplan_details
            WHERE dialplan_uuid = :dialplan_uuid AND dialplan_detail_enabled = 'true'
            ORDER BY dialplan_detail_group, dialplan_detail_order";
    $parameters = array("dialplan_uuid" => $dialplan_uuid);
    $database = new database;
    $details = $database->select($sql, $parameters, "all");

    if (!$details || count($details) == 0) {
        return null;
    }

    // Build XML
    $xml = '<extension name="' . htmlspecialchars($dialplan_name) . '" continue="' . $dialplan_continue . '" uuid="' . $dialplan_uuid . '">' . "\n";

    $conditions = array();
    $actions = array();

    // Group details by group number
    foreach ($details as $detail) {
        $group = $detail['dialplan_detail_group'];
        if (!isset($conditions[$group])) {
            $conditions[$group] = array();
            $actions[$group] = array();
        }

        if ($detail['dialplan_detail_tag'] == 'condition') {
            $conditions[$group][] = $detail;
        } else {
            $actions[$group][] = $detail;
        }
    }

    // Generate XML for each group
    foreach ($conditions as $group => $group_conditions) {
        $group_actions = isset($actions[$group]) ? $actions[$group] : array();

        // Process conditions
        $condition_count = count($group_conditions);
        for ($i = 0; $i < $condition_count; $i++) {
            $cond = $group_conditions[$i];
            $is_last_condition = ($i == $condition_count - 1);

            $field = $cond['dialplan_detail_type'];
            $expression = htmlspecialchars($cond['dialplan_detail_data']);

            if ($is_last_condition && count($group_actions) > 0) {
                // Last condition contains the actions
                $xml .= "\t" . '<condition field="' . $field . '" expression="' . $expression . '">' . "\n";

                // Add actions
                foreach ($group_actions as $action) {
                    $app = $action['dialplan_detail_type'];
                    $data = htmlspecialchars($action['dialplan_detail_data']);
                    $inline = $action['dialplan_detail_inline'];

                    $action_xml = "\t\t" . '<action application="' . $app . '" data="' . $data . '"';
                    if ($inline == 'true') {
                        $action_xml .= ' inline="true"';
                    }
                    $action_xml .= '/>' . "\n";
                    $xml .= $action_xml;
                }

                $xml .= "\t" . '</condition>' . "\n";
            } else {
                // Standalone condition (no actions)
                $xml .= "\t" . '<condition field="' . $field . '" expression="' . $expression . '"/>' . "\n";
            }
        }
    }

    $xml .= '</extension>';

    return $xml;
}

function update_dialplan_xml($dialplan_uuid, $xml) {
    if (!$xml) return;

    $sql = "UPDATE v_dialplans SET dialplan_xml = :dialplan_xml, update_date = NOW()
            WHERE dialplan_uuid = :dialplan_uuid";
    $parameters = array(
        "dialplan_uuid" => $dialplan_uuid,
        "dialplan_xml" => $xml
    );
    $database = new database;
    $database->execute($sql, $parameters);
}
