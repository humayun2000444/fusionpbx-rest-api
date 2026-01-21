<?php
$required_params = array("dialplan_uuid");

function do_action($body) {
    // Check if route exists
    $sql = "SELECT dialplan_uuid, dialplan_context FROM v_dialplans WHERE dialplan_uuid = :dialplan_uuid";
    $parameters = array("dialplan_uuid" => $body->dialplan_uuid);
    $database = new database;
    $existing = $database->select($sql, $parameters, "row");

    if(!$existing) {
        return array("error" => "Outbound route not found");
    }
    $context = $existing["dialplan_context"];
    unset($parameters);

    // Build update query dynamically
    $updates = array();
    $parameters = array();
    $parameters["dialplan_uuid"] = $body->dialplan_uuid;

    if(isset($body->dialplan_name)) {
        $updates[] = "dialplan_name = :dialplan_name";
        $parameters["dialplan_name"] = $body->dialplan_name;
    }
    if(isset($body->dialplan_number)) {
        $updates[] = "dialplan_number = :dialplan_number";
        $parameters["dialplan_number"] = $body->dialplan_number;
    }
    if(isset($body->dialplan_context)) {
        $updates[] = "dialplan_context = :dialplan_context";
        $parameters["dialplan_context"] = $body->dialplan_context;
        $context = $body->dialplan_context;
    }
    if(isset($body->dialplan_continue)) {
        $updates[] = "dialplan_continue = :dialplan_continue";
        $parameters["dialplan_continue"] = $body->dialplan_continue;
    }
    if(isset($body->dialplan_order)) {
        $updates[] = "dialplan_order = :dialplan_order";
        $parameters["dialplan_order"] = $body->dialplan_order;
    }
    if(isset($body->dialplan_enabled)) {
        $updates[] = "dialplan_enabled = :dialplan_enabled";
        $parameters["dialplan_enabled"] = $body->dialplan_enabled;
    }
    if(isset($body->dialplan_description)) {
        $updates[] = "dialplan_description = :dialplan_description";
        $parameters["dialplan_description"] = $body->dialplan_description;
    }

    // Update main dialplan record if there are updates
    if(!empty($updates)) {
        $updates[] = "update_date = NOW()";
        $sql = "UPDATE v_dialplans SET " . implode(", ", $updates) . " WHERE dialplan_uuid = :dialplan_uuid";
        $database = new database;
        $database->execute($sql, $parameters);
    }
    unset($parameters);

    // Update gateway if provided
    if(isset($body->gateway_uuid)) {
        // Verify gateway exists
        $sql = "SELECT gateway_uuid, gateway FROM v_gateways WHERE gateway_uuid = :gateway_uuid";
        $parameters = array("gateway_uuid" => $body->gateway_uuid);
        $database = new database;
        $gateway = $database->select($sql, $parameters, "row");

        if(!$gateway) {
            return array("error" => "Gateway not found");
        }
        unset($parameters);

        // Get prefix if provided
        $prefix = isset($body->prefix) ? $body->prefix : "";

        // Build new bridge data
        $bridge_data = "sofia/gateway/" . $body->gateway_uuid . "/" . $prefix . "\$1";

        // Update the bridge action
        $sql = "UPDATE v_dialplan_details SET dialplan_detail_data = :bridge_data, update_date = NOW()
                WHERE dialplan_uuid = :dialplan_uuid
                AND dialplan_detail_tag = 'action'
                AND dialplan_detail_type = 'bridge'";
        $parameters = array(
            "bridge_data" => $bridge_data,
            "dialplan_uuid" => $body->dialplan_uuid
        );
        $database = new database;
        $database->execute($sql, $parameters);
        unset($parameters);
    }

    // Update destination pattern if provided
    if(isset($body->destination_pattern)) {
        $sql = "UPDATE v_dialplan_details SET dialplan_detail_data = :pattern, update_date = NOW()
                WHERE dialplan_uuid = :dialplan_uuid
                AND dialplan_detail_tag = 'condition'
                AND dialplan_detail_type = 'destination_number'";
        $parameters = array(
            "pattern" => $body->destination_pattern,
            "dialplan_uuid" => $body->dialplan_uuid
        );
        $database = new database;
        $database->execute($sql, $parameters);
        unset($parameters);
    }

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

    // Return updated route
    $sql = "SELECT dialplan_uuid, domain_uuid, dialplan_name, dialplan_number,
            dialplan_context, dialplan_order, dialplan_enabled, dialplan_description,
            insert_date, update_date
            FROM v_dialplans WHERE dialplan_uuid = :dialplan_uuid";
    $parameters = array("dialplan_uuid" => $body->dialplan_uuid);
    $database = new database;
    $result = $database->select($sql, $parameters, "row");

    // Get bridge data and gateway info
    $detail_sql = "SELECT dialplan_detail_data FROM v_dialplan_details
                   WHERE dialplan_uuid = :dialplan_uuid
                   AND dialplan_detail_tag = 'action'
                   AND dialplan_detail_type = 'bridge'
                   ORDER BY dialplan_detail_order LIMIT 1";
    $database = new database;
    $bridge = $database->select($detail_sql, $parameters, "row");

    if($bridge && !empty($bridge["dialplan_detail_data"])) {
        $result["bridge_data"] = $bridge["dialplan_detail_data"];
        if(preg_match('/sofia\/gateway\/([a-f0-9\-]+)\//i', $bridge["dialplan_detail_data"], $matches)) {
            $result["gateway_uuid"] = $matches[1];
            $gw_sql = "SELECT gateway FROM v_gateways WHERE gateway_uuid = :gateway_uuid";
            $gw_params = array("gateway_uuid" => $matches[1]);
            $database = new database;
            $gw = $database->select($gw_sql, $gw_params, "row");
            $result["gateway_name"] = $gw ? $gw["gateway"] : null;
        }
    }

    // Get destination pattern
    $pattern_sql = "SELECT dialplan_detail_data FROM v_dialplan_details
                    WHERE dialplan_uuid = :dialplan_uuid
                    AND dialplan_detail_tag = 'condition'
                    AND dialplan_detail_type = 'destination_number'
                    ORDER BY dialplan_detail_order LIMIT 1";
    $database = new database;
    $pattern = $database->select($pattern_sql, $parameters, "row");
    if($pattern) {
        $result["destination_pattern"] = $pattern["dialplan_detail_data"];
    }

    $result["reloaded"] = $reload_success;
    $result["reload_output"] = trim($reload_output);

    return $result;
}
