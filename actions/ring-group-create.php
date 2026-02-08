<?php

$required_params = array("ring_group_name", "ring_group_extension");

function do_action($body) {
    global $domain_uuid;

    // Get domain_uuid - use provided or global
    $rg_domain_uuid = isset($body->domain_uuid) ? $body->domain_uuid : $domain_uuid;

    if (empty($rg_domain_uuid)) {
        return array("error" => "Domain UUID is required");
    }

    // Get domain name
    $sql = "SELECT domain_name FROM v_domains WHERE domain_uuid = :domain_uuid";
    $parameters = array("domain_uuid" => $rg_domain_uuid);
    $database = new database;
    $domain = $database->select($sql, $parameters, "row");

    if (!$domain) {
        return array("error" => "Domain not found");
    }

    $domain_name = $domain["domain_name"];

    // Check if extension already exists
    $ring_group_extension = $body->ring_group_extension;
    $sql = "SELECT ring_group_uuid FROM v_ring_groups WHERE domain_uuid = :domain_uuid AND ring_group_extension = :extension";
    $parameters = array("domain_uuid" => $rg_domain_uuid, "extension" => $ring_group_extension);
    $database = new database;
    $existing = $database->select($sql, $parameters, "row");

    if ($existing) {
        return array("error" => "Ring group extension already exists");
    }

    // Generate UUIDs
    $ring_group_uuid = uuid();
    $dialplan_uuid = uuid();

    // Get ring group data
    $ring_group_name = preg_replace("/[^A-Za-z0-9\- ]/", "", $body->ring_group_name);
    $ring_group_greeting = isset($body->ring_group_greeting) ? $body->ring_group_greeting : null;
    $ring_group_strategy = isset($body->ring_group_strategy) ? $body->ring_group_strategy : "simultaneous";
    $ring_group_call_timeout = isset($body->ring_group_call_timeout) ? (int)$body->ring_group_call_timeout : 30;
    $ring_group_forward_destination = isset($body->ring_group_forward_destination) ? $body->ring_group_forward_destination : null;
    $ring_group_forward_enabled = isset($body->ring_group_forward_enabled) ? ($body->ring_group_forward_enabled ? "true" : "false") : "false";
    $ring_group_caller_id_name = isset($body->ring_group_caller_id_name) ? $body->ring_group_caller_id_name : null;
    $ring_group_caller_id_number = isset($body->ring_group_caller_id_number) ? $body->ring_group_caller_id_number : null;
    $ring_group_cid_name_prefix = isset($body->ring_group_cid_name_prefix) ? $body->ring_group_cid_name_prefix : null;
    $ring_group_cid_number_prefix = isset($body->ring_group_cid_number_prefix) ? $body->ring_group_cid_number_prefix : null;
    $ring_group_timeout_app = isset($body->ring_group_timeout_app) ? $body->ring_group_timeout_app : null;
    $ring_group_timeout_data = isset($body->ring_group_timeout_data) ? $body->ring_group_timeout_data : null;
    $ring_group_distinctive_ring = isset($body->ring_group_distinctive_ring) ? $body->ring_group_distinctive_ring : null;
    $ring_group_ringback = isset($body->ring_group_ringback) ? $body->ring_group_ringback : null;
    $ring_group_call_screen_enabled = isset($body->ring_group_call_screen_enabled) ? ($body->ring_group_call_screen_enabled ? "true" : "false") : "false";
    $ring_group_call_forward_enabled = isset($body->ring_group_call_forward_enabled) ? ($body->ring_group_call_forward_enabled ? "true" : "false") : "false";
    $ring_group_follow_me_enabled = isset($body->ring_group_follow_me_enabled) ? ($body->ring_group_follow_me_enabled ? "true" : "false") : "false";
    $ring_group_missed_call_app = isset($body->ring_group_missed_call_app) ? $body->ring_group_missed_call_app : null;
    $ring_group_missed_call_data = isset($body->ring_group_missed_call_data) ? $body->ring_group_missed_call_data : null;
    $ring_group_enabled = isset($body->ring_group_enabled) ? ($body->ring_group_enabled ? "true" : "false") : "true";
    $ring_group_description = isset($body->ring_group_description) ? $body->ring_group_description : null;
    $ring_group_forward_toll_allow = isset($body->ring_group_forward_toll_allow) ? $body->ring_group_forward_toll_allow : null;
    $ring_group_context = $domain_name;

    // Build the dialplan XML
    $dialplan_xml = "<extension name=\"" . htmlspecialchars($ring_group_name) . "\" continue=\"\" uuid=\"" . $dialplan_uuid . "\">\n";
    $dialplan_xml .= "\t<condition field=\"destination_number\" expression=\"^" . htmlspecialchars($ring_group_extension) . "$\">\n";
    $dialplan_xml .= "\t\t<action application=\"ring_ready\" data=\"\"/>\n";
    $dialplan_xml .= "\t\t<action application=\"set\" data=\"ring_group_uuid=" . $ring_group_uuid . "\"/>\n";
    $dialplan_xml .= "\t\t<action application=\"lua\" data=\"app.lua ring_groups\"/>\n";
    $dialplan_xml .= "\t</condition>\n";
    $dialplan_xml .= "</extension>\n";

    // Insert ring group record using direct SQL
    $sql = "INSERT INTO v_ring_groups (
            ring_group_uuid, domain_uuid, dialplan_uuid, ring_group_name, ring_group_extension,
            ring_group_greeting, ring_group_context, ring_group_strategy, ring_group_call_timeout,
            ring_group_forward_destination, ring_group_forward_enabled, ring_group_caller_id_name,
            ring_group_caller_id_number, ring_group_cid_name_prefix, ring_group_cid_number_prefix,
            ring_group_timeout_app, ring_group_timeout_data, ring_group_distinctive_ring,
            ring_group_ringback, ring_group_call_screen_enabled, ring_group_call_forward_enabled,
            ring_group_follow_me_enabled, ring_group_missed_call_app, ring_group_missed_call_data,
            ring_group_enabled, ring_group_description, ring_group_forward_toll_allow, insert_date
        ) VALUES (
            :ring_group_uuid, :domain_uuid, :dialplan_uuid, :ring_group_name, :ring_group_extension,
            :ring_group_greeting, :ring_group_context, :ring_group_strategy, :ring_group_call_timeout,
            :ring_group_forward_destination, :ring_group_forward_enabled, :ring_group_caller_id_name,
            :ring_group_caller_id_number, :ring_group_cid_name_prefix, :ring_group_cid_number_prefix,
            :ring_group_timeout_app, :ring_group_timeout_data, :ring_group_distinctive_ring,
            :ring_group_ringback, :ring_group_call_screen_enabled, :ring_group_call_forward_enabled,
            :ring_group_follow_me_enabled, :ring_group_missed_call_app, :ring_group_missed_call_data,
            :ring_group_enabled, :ring_group_description, :ring_group_forward_toll_allow, NOW()
        )";

    $parameters = array();
    $parameters["ring_group_uuid"] = $ring_group_uuid;
    $parameters["domain_uuid"] = $rg_domain_uuid;
    $parameters["dialplan_uuid"] = $dialplan_uuid;
    $parameters["ring_group_name"] = $ring_group_name;
    $parameters["ring_group_extension"] = $ring_group_extension;
    $parameters["ring_group_greeting"] = $ring_group_greeting;
    $parameters["ring_group_context"] = $ring_group_context;
    $parameters["ring_group_strategy"] = $ring_group_strategy;
    $parameters["ring_group_call_timeout"] = $ring_group_call_timeout;
    $parameters["ring_group_forward_destination"] = $ring_group_forward_destination;
    $parameters["ring_group_forward_enabled"] = $ring_group_forward_enabled;
    $parameters["ring_group_caller_id_name"] = $ring_group_caller_id_name;
    $parameters["ring_group_caller_id_number"] = $ring_group_caller_id_number;
    $parameters["ring_group_cid_name_prefix"] = $ring_group_cid_name_prefix;
    $parameters["ring_group_cid_number_prefix"] = $ring_group_cid_number_prefix;
    $parameters["ring_group_timeout_app"] = $ring_group_timeout_app;
    $parameters["ring_group_timeout_data"] = $ring_group_timeout_data;
    $parameters["ring_group_distinctive_ring"] = $ring_group_distinctive_ring;
    $parameters["ring_group_ringback"] = $ring_group_ringback;
    $parameters["ring_group_call_screen_enabled"] = $ring_group_call_screen_enabled;
    $parameters["ring_group_call_forward_enabled"] = $ring_group_call_forward_enabled;
    $parameters["ring_group_follow_me_enabled"] = $ring_group_follow_me_enabled;
    $parameters["ring_group_missed_call_app"] = $ring_group_missed_call_app;
    $parameters["ring_group_missed_call_data"] = $ring_group_missed_call_data;
    $parameters["ring_group_enabled"] = $ring_group_enabled;
    $parameters["ring_group_description"] = $ring_group_description;
    $parameters["ring_group_forward_toll_allow"] = $ring_group_forward_toll_allow;

    $database = new database;
    $database->execute($sql, $parameters);
    unset($parameters);

    // Insert dialplan record using direct SQL
    $sql = "INSERT INTO v_dialplans (
            dialplan_uuid, domain_uuid, app_uuid, dialplan_name, dialplan_number,
            dialplan_context, dialplan_continue, dialplan_order, dialplan_enabled,
            dialplan_description, dialplan_xml, insert_date
        ) VALUES (
            :dialplan_uuid, :domain_uuid, :app_uuid, :dialplan_name, :dialplan_number,
            :dialplan_context, :dialplan_continue, :dialplan_order, :dialplan_enabled,
            :dialplan_description, :dialplan_xml, NOW()
        )";

    $parameters = array();
    $parameters["dialplan_uuid"] = $dialplan_uuid;
    $parameters["domain_uuid"] = $rg_domain_uuid;
    $parameters["app_uuid"] = '1d61fb65-1eec-bc73-a6ee-a6203b4fe6f2';
    $parameters["dialplan_name"] = $ring_group_name;
    $parameters["dialplan_number"] = $ring_group_extension;
    $parameters["dialplan_context"] = $ring_group_context;
    $parameters["dialplan_continue"] = "false";
    $parameters["dialplan_order"] = 101;
    $parameters["dialplan_enabled"] = $ring_group_enabled;
    $parameters["dialplan_description"] = $ring_group_description;
    $parameters["dialplan_xml"] = $dialplan_xml;

    $database = new database;
    $database->execute($sql, $parameters);
    unset($parameters);

    // Add destinations if provided
    if (isset($body->destinations) && is_array($body->destinations)) {
        foreach ($body->destinations as $dest) {
            // Convert to array if it's an object
            if (is_object($dest)) {
                $dest = (array) $dest;
            }

            // Get destination values - check both snake_case and camelCase
            $dest_number = null;
            if (isset($dest['destination_number'])) {
                $dest_number = $dest['destination_number'];
            } elseif (isset($dest['destinationNumber'])) {
                $dest_number = $dest['destinationNumber'];
            }

            // Skip if no destination number
            if (empty($dest_number)) {
                continue;
            }

            $dest_delay = 0;
            if (isset($dest['destination_delay'])) {
                $dest_delay = (int)$dest['destination_delay'];
            } elseif (isset($dest['destinationDelay'])) {
                $dest_delay = (int)$dest['destinationDelay'];
            }

            $dest_timeout = 30;
            if (isset($dest['destination_timeout'])) {
                $dest_timeout = (int)$dest['destination_timeout'];
            } elseif (isset($dest['destinationTimeout'])) {
                $dest_timeout = (int)$dest['destinationTimeout'];
            }

            $dest_enabled = "true";
            if (isset($dest['destination_enabled'])) {
                $dest_enabled = $dest['destination_enabled'] ? "true" : "false";
            } elseif (isset($dest['destinationEnabled'])) {
                $dest_enabled = $dest['destinationEnabled'] ? "true" : "false";
            }

            // destination_prompt is a numeric column - must be null or numeric, not empty string
            $dest_prompt = null;
            if (isset($dest['destination_prompt']) && $dest['destination_prompt'] !== '' && $dest['destination_prompt'] !== null) {
                $dest_prompt = (int)$dest['destination_prompt'];
            } elseif (isset($dest['destinationPrompt']) && $dest['destinationPrompt'] !== '' && $dest['destinationPrompt'] !== null) {
                $dest_prompt = (int)$dest['destinationPrompt'];
            }

            $dest_description = null;
            if (isset($dest['destination_description']) && $dest['destination_description'] !== '') {
                $dest_description = $dest['destination_description'];
            } elseif (isset($dest['destinationDescription']) && $dest['destinationDescription'] !== '') {
                $dest_description = $dest['destinationDescription'];
            }

            $dest_uuid = uuid();

            $sql = "INSERT INTO v_ring_group_destinations (
                    ring_group_destination_uuid, domain_uuid, ring_group_uuid,
                    destination_number, destination_delay, destination_timeout,
                    destination_enabled, destination_prompt, destination_description, insert_date
                ) VALUES (
                    :dest_uuid, :domain_uuid, :ring_group_uuid,
                    :destination_number, :destination_delay, :destination_timeout,
                    :destination_enabled, :destination_prompt, :destination_description, NOW()
                )";

            $parameters = array();
            $parameters["dest_uuid"] = $dest_uuid;
            $parameters["domain_uuid"] = $rg_domain_uuid;
            $parameters["ring_group_uuid"] = $ring_group_uuid;
            $parameters["destination_number"] = $dest_number;
            $parameters["destination_delay"] = $dest_delay;
            $parameters["destination_timeout"] = $dest_timeout;
            $parameters["destination_enabled"] = $dest_enabled;
            $parameters["destination_prompt"] = $dest_prompt;
            $parameters["destination_description"] = $dest_description;

            $database = new database;
            $database->execute($sql, $parameters);
        }
    }

    // Clear the dialplan cache
    if (class_exists('cache')) {
        $cache = new cache;
        $cache->delete("dialplan:" . $domain_name);
    }

    return array(
        "success" => true,
        "message" => "Ring group created successfully",
        "ringGroupUuid" => $ring_group_uuid,
        "dialplanUuid" => $dialplan_uuid,
        "ringGroupName" => $ring_group_name,
        "ringGroupExtension" => $ring_group_extension,
        "ringGroupStrategy" => $ring_group_strategy,
        "ringGroupEnabled" => $ring_group_enabled === "true"
    );
}
