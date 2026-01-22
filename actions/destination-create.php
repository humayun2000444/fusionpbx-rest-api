<?php
$required_params = array("destination_number", "destination_app");

function do_action($body) {
    global $domain_uuid;

    // Destinations app_uuid
    $destinations_app_uuid = 'faf4d498-7953-ad48-eb58-a8b8ab69892c';

    // Get domain_uuid - use provided or global
    $dest_domain_uuid = isset($body->domain_uuid) ? $body->domain_uuid : $domain_uuid;

    // Get domain name for context
    $sql = "SELECT domain_name FROM v_domains WHERE domain_uuid = :domain_uuid";
    $parameters = array("domain_uuid" => $dest_domain_uuid);
    $database = new database;
    $domain = $database->select($sql, $parameters, "row");
    $domain_name = $domain ? $domain["domain_name"] : "public";

    // Get context - default to "public" for inbound
    $context = isset($body->destination_context) ? $body->destination_context : "public";

    // Get destination type - default to "inbound"
    $destination_type = isset($body->destination_type) ? $body->destination_type : "inbound";

    // Get destination_data and auto-append "XML domain_name" for transfer apps if not already present
    $destination_data = isset($body->destination_data) ? $body->destination_data : '';
    $dest_app = isset($body->destination_app) ? $body->destination_app : 'transfer';

    // Normalize transfer app names - FreeSWITCH only understands "transfer"
    // "transfer ivr", "transfer queue" etc. should all become "transfer"
    if (strpos($dest_app, 'transfer') !== false) {
        $dest_app = 'transfer';
    }

    if ($dest_app == 'transfer' && !empty($destination_data) && stripos($destination_data, 'XML') === false) {
        $destination_data = $destination_data . ' XML ' . $domain_name;
    }

    // Get enabled status
    $destination_enabled = isset($body->destination_enabled) ? $body->destination_enabled : "true";

    // Generate UUIDs
    $destination_uuid = uuid();
    $dialplan_uuid = uuid();

    // Build destination_actions JSON
    $destination_actions = array(
        array(
            "destination_app" => $dest_app,
            "destination_data" => $destination_data
        )
    );

    // Build destination number regex (escape special regex chars)
    $destination_number_regex = "^(" . preg_quote($body->destination_number, '/') . ")$";
    $destination_number_regex = str_replace('\+', '\\+?', $destination_number_regex);

    // Insert destination record
    $sql = "INSERT INTO v_destinations (destination_uuid, domain_uuid, dialplan_uuid,
            destination_type, destination_number, destination_number_regex,
            destination_caller_id_name, destination_caller_id_number, destination_cid_name_prefix,
            destination_context, destination_app, destination_data,
            destination_actions, destination_enabled, destination_description,
            destination_record, destination_accountcode, destination_order,
            destination_hold_music, destination_distinctive_ring, destination_ringback,
            destination_type_voice, destination_type_fax, destination_type_text, destination_type_emergency,
            user_uuid, group_uuid,
            insert_date)
            VALUES (:destination_uuid, :domain_uuid, :dialplan_uuid,
            :destination_type, :destination_number, :destination_number_regex,
            :destination_caller_id_name, :destination_caller_id_number, :destination_cid_name_prefix,
            :destination_context, :destination_app, :destination_data,
            :destination_actions, :destination_enabled, :destination_description,
            :destination_record, :destination_accountcode, :destination_order,
            :destination_hold_music, :destination_distinctive_ring, :destination_ringback,
            :destination_type_voice, :destination_type_fax, :destination_type_text, :destination_type_emergency,
            :user_uuid, :group_uuid,
            NOW())";

    $parameters = array();
    $parameters["destination_uuid"] = $destination_uuid;
    $parameters["domain_uuid"] = $dest_domain_uuid;
    $parameters["dialplan_uuid"] = $dialplan_uuid;
    $parameters["destination_type"] = $destination_type;
    $parameters["destination_number"] = $body->destination_number;
    $parameters["destination_number_regex"] = $destination_number_regex;
    $parameters["destination_caller_id_name"] = isset($body->destination_caller_id_name) ? $body->destination_caller_id_name : null;
    $parameters["destination_caller_id_number"] = isset($body->destination_caller_id_number) ? $body->destination_caller_id_number : null;
    $parameters["destination_cid_name_prefix"] = isset($body->destination_cid_name_prefix) ? $body->destination_cid_name_prefix : null;
    $parameters["destination_context"] = $context;
    $parameters["destination_app"] = $dest_app;
    $parameters["destination_data"] = $destination_data;
    $parameters["destination_actions"] = json_encode($destination_actions);
    $parameters["destination_enabled"] = $destination_enabled;
    $parameters["destination_description"] = isset($body->destination_description) ? $body->destination_description : null;
    $parameters["destination_record"] = isset($body->destination_record) ? $body->destination_record : "false";
    $parameters["destination_accountcode"] = isset($body->destination_accountcode) ? $body->destination_accountcode : null;
    $parameters["destination_order"] = isset($body->destination_order) ? $body->destination_order : 100;
    $parameters["destination_hold_music"] = isset($body->destination_hold_music) ? $body->destination_hold_music : null;
    $parameters["destination_distinctive_ring"] = isset($body->destination_distinctive_ring) ? $body->destination_distinctive_ring : null;
    $parameters["destination_ringback"] = isset($body->destination_ringback) ? $body->destination_ringback : null;
    $parameters["destination_type_voice"] = isset($body->destination_type_voice) ? $body->destination_type_voice : 1;
    $parameters["destination_type_fax"] = isset($body->destination_type_fax) ? $body->destination_type_fax : 0;
    $parameters["destination_type_text"] = isset($body->destination_type_text) ? $body->destination_type_text : 0;
    $parameters["destination_type_emergency"] = isset($body->destination_type_emergency) ? $body->destination_type_emergency : 0;
    $parameters["user_uuid"] = isset($body->user_uuid) ? $body->user_uuid : null;
    $parameters["group_uuid"] = isset($body->group_uuid) ? $body->group_uuid : null;

    $database = new database;
    $database->execute($sql, $parameters);
    unset($parameters);

    // Create dialplan for destination
    $sql = "INSERT INTO v_dialplans (dialplan_uuid, domain_uuid, app_uuid, dialplan_name,
            dialplan_number, dialplan_context, dialplan_continue, dialplan_order,
            dialplan_enabled, dialplan_description, insert_date)
            VALUES (:dialplan_uuid, :domain_uuid, :app_uuid, :dialplan_name,
            :dialplan_number, :dialplan_context, :dialplan_continue, :dialplan_order,
            :dialplan_enabled, :dialplan_description, NOW())";

    $parameters = array();
    $parameters["dialplan_uuid"] = $dialplan_uuid;
    $parameters["domain_uuid"] = $dest_domain_uuid;
    $parameters["app_uuid"] = $destinations_app_uuid;
    $parameters["dialplan_name"] = $body->destination_number;
    $parameters["dialplan_number"] = $body->destination_number;
    $parameters["dialplan_context"] = $context;
    $parameters["dialplan_continue"] = "false";
    $parameters["dialplan_order"] = isset($body->destination_order) ? $body->destination_order : 100;
    $parameters["dialplan_enabled"] = $destination_enabled;
    $parameters["dialplan_description"] = isset($body->destination_description) ? $body->destination_description : null;

    $database = new database;
    $database->execute($sql, $parameters);
    unset($parameters);

    // Insert dialplan details
    $detail_order = 0;

    // Condition: destination_number
    insert_detail($dialplan_uuid, $dest_domain_uuid, 'condition', 'destination_number', $destination_number_regex, 0, $detail_order += 20);

    // Action: export call_direction=inbound (inline)
    insert_detail_inline($dialplan_uuid, $dest_domain_uuid, 'action', 'export', 'call_direction=inbound', 0, $detail_order += 10, 'true');

    // Action: set domain_uuid (inline)
    insert_detail_inline($dialplan_uuid, $dest_domain_uuid, 'action', 'set', 'domain_uuid=' . $dest_domain_uuid, 0, $detail_order += 10, 'true');

    // Action: set domain_name (inline)
    insert_detail_inline($dialplan_uuid, $dest_domain_uuid, 'action', 'set', 'domain_name=' . $domain_name, 0, $detail_order += 10, 'true');

    // Action: record if enabled
    if (isset($body->destination_record) && $body->destination_record === "true") {
        insert_detail($dialplan_uuid, $dest_domain_uuid, 'action', 'set', 'record_path=${recordings_dir}/${domain_name}/archive/${strftime(%Y)}/${strftime(%b)}/${strftime(%d)}', 0, $detail_order += 10);
        insert_detail($dialplan_uuid, $dest_domain_uuid, 'action', 'set', 'record_name=${uuid}.${record_ext}', 0, $detail_order += 10);
        insert_detail($dialplan_uuid, $dest_domain_uuid, 'action', 'set', 'record_append=true', 0, $detail_order += 10);
        insert_detail($dialplan_uuid, $dest_domain_uuid, 'action', 'set', 'record_in_progress=true', 0, $detail_order += 10);
        insert_detail($dialplan_uuid, $dest_domain_uuid, 'action', 'record_session', '${record_path}/${record_name}', 0, $detail_order += 10);
    }

    // Action: main destination action (transfer, bridge, etc.)
    insert_detail($dialplan_uuid, $dest_domain_uuid, 'action', $dest_app, $destination_data, 0, $detail_order += 10);

    // Generate and save dialplan XML
    $xml = generate_destination_xml($dialplan_uuid, $body->destination_number, $dest_domain_uuid, $domain_name, $destination_number_regex, $dest_app, $destination_data);
    update_dialplan_xml($dialplan_uuid, $xml);

    // Clear cache and reload dialplan
    $reload_output = "";
    $reload_success = false;

    if (class_exists('event_socket')) {
        $esl = event_socket::create();
        if ($esl) {
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

    // Return created destination
    $sql = "SELECT destination_uuid, domain_uuid, dialplan_uuid, destination_type,
            destination_number, destination_context, destination_app, destination_data,
            destination_enabled, destination_description, insert_date
            FROM v_destinations WHERE destination_uuid = :destination_uuid";
    $parameters = array("destination_uuid" => $destination_uuid);
    $database = new database;
    $result = $database->select($sql, $parameters, "row");

    $result["domain_name"] = $domain_name;
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
