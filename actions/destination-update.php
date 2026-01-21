<?php
$required_params = array("destination_uuid");

function do_action($body) {
    // Verify destination exists
    $sql = "SELECT d.*, dom.domain_name FROM v_destinations d
            LEFT JOIN v_domains dom ON d.domain_uuid = dom.domain_uuid
            WHERE d.destination_uuid = :destination_uuid";
    $parameters = array("destination_uuid" => $body->destination_uuid);
    $database = new database;
    $destination = $database->select($sql, $parameters, "row");

    if (!$destination) {
        return array("error" => "Destination not found");
    }

    // Build update query dynamically based on provided fields
    $updates = array();
    $parameters = array("destination_uuid" => $body->destination_uuid);

    if (isset($body->destination_number)) {
        $updates[] = "destination_number = :destination_number";
        $parameters["destination_number"] = $body->destination_number;

        // Update regex too
        $destination_number_regex = "^(" . preg_quote($body->destination_number, '/') . ")$";
        $destination_number_regex = str_replace('\+', '\\+?', $destination_number_regex);
        $updates[] = "destination_number_regex = :destination_number_regex";
        $parameters["destination_number_regex"] = $destination_number_regex;
    }

    if (isset($body->destination_type)) {
        $updates[] = "destination_type = :destination_type";
        $parameters["destination_type"] = $body->destination_type;
    }

    if (isset($body->destination_context)) {
        $updates[] = "destination_context = :destination_context";
        $parameters["destination_context"] = $body->destination_context;
    }

    if (isset($body->destination_caller_id_name)) {
        $updates[] = "destination_caller_id_name = :destination_caller_id_name";
        $parameters["destination_caller_id_name"] = $body->destination_caller_id_name;
    }

    if (isset($body->destination_caller_id_number)) {
        $updates[] = "destination_caller_id_number = :destination_caller_id_number";
        $parameters["destination_caller_id_number"] = $body->destination_caller_id_number;
    }

    if (isset($body->destination_app)) {
        $updates[] = "destination_app = :destination_app";
        $parameters["destination_app"] = $body->destination_app;
    }

    if (isset($body->destination_data)) {
        $updates[] = "destination_data = :destination_data";
        $parameters["destination_data"] = $body->destination_data;
    }

    // Update destination_actions if app or data changed
    if (isset($body->destination_app) || isset($body->destination_data)) {
        $app = isset($body->destination_app) ? $body->destination_app : $destination['destination_app'];
        $data = isset($body->destination_data) ? $body->destination_data : $destination['destination_data'];
        $destination_actions = array(
            array(
                "destination_app" => $app,
                "destination_data" => $data
            )
        );
        $updates[] = "destination_actions = :destination_actions";
        $parameters["destination_actions"] = json_encode($destination_actions);
    }

    if (isset($body->destination_enabled)) {
        $updates[] = "destination_enabled = :destination_enabled";
        $parameters["destination_enabled"] = $body->destination_enabled;
    }

    if (isset($body->destination_description)) {
        $updates[] = "destination_description = :destination_description";
        $parameters["destination_description"] = $body->destination_description;
    }

    if (isset($body->destination_record)) {
        $updates[] = "destination_record = :destination_record";
        $parameters["destination_record"] = $body->destination_record;
    }

    if (isset($body->destination_accountcode)) {
        $updates[] = "destination_accountcode = :destination_accountcode";
        $parameters["destination_accountcode"] = $body->destination_accountcode;
    }

    if (isset($body->destination_order)) {
        $updates[] = "destination_order = :destination_order";
        $parameters["destination_order"] = $body->destination_order;
    }

    if (count($updates) == 0) {
        return array("error" => "No fields to update");
    }

    $updates[] = "update_date = NOW()";
    $sql = "UPDATE v_destinations SET " . implode(", ", $updates) . " WHERE destination_uuid = :destination_uuid";

    $database = new database;
    $database->execute($sql, $parameters);

    // Update associated dialplan if exists
    if ($destination['dialplan_uuid']) {
        $dialplan_updates = array();
        $dialplan_params = array("dialplan_uuid" => $destination['dialplan_uuid']);

        if (isset($body->destination_number)) {
            $dialplan_updates[] = "dialplan_name = :dialplan_name";
            $dialplan_updates[] = "dialplan_number = :dialplan_number";
            $dialplan_params["dialplan_name"] = $body->destination_number;
            $dialplan_params["dialplan_number"] = $body->destination_number;
        }

        if (isset($body->destination_context)) {
            $dialplan_updates[] = "dialplan_context = :dialplan_context";
            $dialplan_params["dialplan_context"] = $body->destination_context;
        }

        if (isset($body->destination_enabled)) {
            $dialplan_updates[] = "dialplan_enabled = :dialplan_enabled";
            $dialplan_params["dialplan_enabled"] = $body->destination_enabled;
        }

        if (isset($body->destination_description)) {
            $dialplan_updates[] = "dialplan_description = :dialplan_description";
            $dialplan_params["dialplan_description"] = $body->destination_description;
        }

        if (isset($body->destination_order)) {
            $dialplan_updates[] = "dialplan_order = :dialplan_order";
            $dialplan_params["dialplan_order"] = $body->destination_order;
        }

        if (count($dialplan_updates) > 0) {
            $dialplan_updates[] = "update_date = NOW()";
            $sql = "UPDATE v_dialplans SET " . implode(", ", $dialplan_updates) . " WHERE dialplan_uuid = :dialplan_uuid";
            $database = new database;
            $database->execute($sql, $dialplan_params);
        }

        // Regenerate dialplan details and XML if destination_number, app, or data changed
        if (isset($body->destination_number) || isset($body->destination_app) || isset($body->destination_data)) {
            // Delete old dialplan details
            $sql = "DELETE FROM v_dialplan_details WHERE dialplan_uuid = :dialplan_uuid";
            $database = new database;
            $database->execute($sql, array("dialplan_uuid" => $destination['dialplan_uuid']));

            // Get updated values
            $dest_number = isset($body->destination_number) ? $body->destination_number : $destination['destination_number'];
            $dest_app = isset($body->destination_app) ? $body->destination_app : $destination['destination_app'];
            $dest_data = isset($body->destination_data) ? $body->destination_data : $destination['destination_data'];
            $dest_record = isset($body->destination_record) ? $body->destination_record : $destination['destination_record'];
            $domain_uuid = $destination['domain_uuid'];
            $domain_name = $destination['domain_name'];

            $destination_number_regex = "^(" . preg_quote($dest_number, '/') . ")$";
            $destination_number_regex = str_replace('\+', '\\+?', $destination_number_regex);

            // Insert new dialplan details
            $detail_order = 0;
            insert_detail($destination['dialplan_uuid'], $domain_uuid, 'condition', 'destination_number', $destination_number_regex, 0, $detail_order += 20);
            insert_detail_inline($destination['dialplan_uuid'], $domain_uuid, 'action', 'export', 'call_direction=inbound', 0, $detail_order += 10, 'true');
            insert_detail_inline($destination['dialplan_uuid'], $domain_uuid, 'action', 'set', 'domain_uuid=' . $domain_uuid, 0, $detail_order += 10, 'true');
            insert_detail_inline($destination['dialplan_uuid'], $domain_uuid, 'action', 'set', 'domain_name=' . $domain_name, 0, $detail_order += 10, 'true');

            if ($dest_record === "true") {
                insert_detail($destination['dialplan_uuid'], $domain_uuid, 'action', 'set', 'record_path=${recordings_dir}/${domain_name}/archive/${strftime(%Y)}/${strftime(%b)}/${strftime(%d)}', 0, $detail_order += 10);
                insert_detail($destination['dialplan_uuid'], $domain_uuid, 'action', 'set', 'record_name=${uuid}.${record_ext}', 0, $detail_order += 10);
                insert_detail($destination['dialplan_uuid'], $domain_uuid, 'action', 'set', 'record_append=true', 0, $detail_order += 10);
                insert_detail($destination['dialplan_uuid'], $domain_uuid, 'action', 'set', 'record_in_progress=true', 0, $detail_order += 10);
                insert_detail($destination['dialplan_uuid'], $domain_uuid, 'action', 'record_session', '${record_path}/${record_name}', 0, $detail_order += 10);
            }

            insert_detail($destination['dialplan_uuid'], $domain_uuid, 'action', $dest_app, $dest_data, 0, $detail_order += 10);

            // Regenerate XML
            $xml = generate_destination_xml($destination['dialplan_uuid'], $dest_number, $domain_uuid, $domain_name, $destination_number_regex, $dest_app, $dest_data);
            update_dialplan_xml($destination['dialplan_uuid'], $xml);
        }
    }

    // Clear cache and reload dialplan
    $reload_output = "";
    $reload_success = false;
    $context = isset($body->destination_context) ? $body->destination_context : $destination['destination_context'];

    if (class_exists('event_socket')) {
        $esl = event_socket::create();
        if ($esl) {
            if (class_exists('cache')) {
                $cache = new cache;
                $cache->delete("dialplan:" . $context);
            }
            $reload_output = event_socket::api('reloadxml');
            $reload_success = true;
        }
    }

    if (!$reload_success) {
        $reload_output = shell_exec("/usr/bin/fs_cli -x 'reloadxml' 2>&1");
        $reload_success = ($reload_output !== null);
    }

    // Return updated destination
    $sql = "SELECT d.*, dom.domain_name FROM v_destinations d
            LEFT JOIN v_domains dom ON d.domain_uuid = dom.domain_uuid
            WHERE d.destination_uuid = :destination_uuid";
    $parameters = array("destination_uuid" => $body->destination_uuid);
    $database = new database;
    $result = $database->select($sql, $parameters, "row");

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

function generate_destination_xml($dialplan_uuid, $destination_number, $domain_uuid, $domain_name, $regex, $app, $data) {
    $xml = '<extension name="' . htmlspecialchars($destination_number) . '" continue="false" uuid="' . $dialplan_uuid . '">' . "\n";
    $xml .= "\t" . '<condition field="destination_number" expression="' . htmlspecialchars($regex) . '">' . "\n";
    $xml .= "\t\t" . '<action application="export" data="call_direction=inbound" inline="true"/>' . "\n";
    $xml .= "\t\t" . '<action application="set" data="domain_uuid=' . $domain_uuid . '" inline="true"/>' . "\n";
    $xml .= "\t\t" . '<action application="set" data="domain_name=' . htmlspecialchars($domain_name) . '" inline="true"/>' . "\n";
    $xml .= "\t\t" . '<action application="' . htmlspecialchars($app) . '" data="' . htmlspecialchars($data) . '"/>' . "\n";
    $xml .= "\t" . '</condition>' . "\n";
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
