<?php

$required_params = array("ring_group_uuid");

function do_action($body) {
    global $domain_uuid;

    $ring_group_uuid = $body->ring_group_uuid;

    // Get current ring group details
    $sql = "SELECT rg.*, d.domain_name FROM v_ring_groups rg
            JOIN v_domains d ON rg.domain_uuid = d.domain_uuid
            WHERE rg.ring_group_uuid = :ring_group_uuid";
    $parameters = array("ring_group_uuid" => $ring_group_uuid);

    $database = new database;
    $rg = $database->select($sql, $parameters, "row");

    if (!$rg) {
        return array("error" => "Ring group not found");
    }

    $rg_domain_uuid = $rg["domain_uuid"];
    $domain_name = $rg["domain_name"];
    $dialplan_uuid = $rg["dialplan_uuid"];

    // Get updated values or keep existing
    $ring_group_name = isset($body->ring_group_name) ? preg_replace("/[^A-Za-z0-9\- ]/", "", $body->ring_group_name) : $rg["ring_group_name"];
    $ring_group_extension = isset($body->ring_group_extension) ? $body->ring_group_extension : $rg["ring_group_extension"];
    $ring_group_greeting = isset($body->ring_group_greeting) ? $body->ring_group_greeting : $rg["ring_group_greeting"];
    $ring_group_strategy = isset($body->ring_group_strategy) ? $body->ring_group_strategy : $rg["ring_group_strategy"];
    $ring_group_call_timeout = isset($body->ring_group_call_timeout) ? (int)$body->ring_group_call_timeout : $rg["ring_group_call_timeout"];
    $ring_group_forward_destination = isset($body->ring_group_forward_destination) ? $body->ring_group_forward_destination : $rg["ring_group_forward_destination"];
    $ring_group_caller_id_name = isset($body->ring_group_caller_id_name) ? $body->ring_group_caller_id_name : $rg["ring_group_caller_id_name"];
    $ring_group_caller_id_number = isset($body->ring_group_caller_id_number) ? $body->ring_group_caller_id_number : $rg["ring_group_caller_id_number"];
    $ring_group_cid_name_prefix = isset($body->ring_group_cid_name_prefix) ? $body->ring_group_cid_name_prefix : $rg["ring_group_cid_name_prefix"];
    $ring_group_cid_number_prefix = isset($body->ring_group_cid_number_prefix) ? $body->ring_group_cid_number_prefix : $rg["ring_group_cid_number_prefix"];
    $ring_group_timeout_app = isset($body->ring_group_timeout_app) ? $body->ring_group_timeout_app : $rg["ring_group_timeout_app"];
    $ring_group_timeout_data = isset($body->ring_group_timeout_data) ? $body->ring_group_timeout_data : $rg["ring_group_timeout_data"];
    $ring_group_distinctive_ring = isset($body->ring_group_distinctive_ring) ? $body->ring_group_distinctive_ring : $rg["ring_group_distinctive_ring"];
    $ring_group_ringback = isset($body->ring_group_ringback) ? $body->ring_group_ringback : $rg["ring_group_ringback"];
    $ring_group_missed_call_app = isset($body->ring_group_missed_call_app) ? $body->ring_group_missed_call_app : $rg["ring_group_missed_call_app"];
    $ring_group_missed_call_data = isset($body->ring_group_missed_call_data) ? $body->ring_group_missed_call_data : $rg["ring_group_missed_call_data"];
    $ring_group_description = isset($body->ring_group_description) ? $body->ring_group_description : $rg["ring_group_description"];
    $ring_group_forward_toll_allow = isset($body->ring_group_forward_toll_allow) ? $body->ring_group_forward_toll_allow : $rg["ring_group_forward_toll_allow"];
    $ring_group_context = $domain_name;

    // Handle boolean fields
    if (isset($body->ring_group_forward_enabled)) {
        $ring_group_forward_enabled = ($body->ring_group_forward_enabled === true || $body->ring_group_forward_enabled === "true") ? "true" : "false";
    } else {
        $ring_group_forward_enabled = $rg["ring_group_forward_enabled"];
    }

    if (isset($body->ring_group_call_screen_enabled)) {
        $ring_group_call_screen_enabled = ($body->ring_group_call_screen_enabled === true || $body->ring_group_call_screen_enabled === "true") ? "true" : "false";
    } else {
        $ring_group_call_screen_enabled = $rg["ring_group_call_screen_enabled"];
    }

    if (isset($body->ring_group_call_forward_enabled)) {
        $ring_group_call_forward_enabled = ($body->ring_group_call_forward_enabled === true || $body->ring_group_call_forward_enabled === "true") ? "true" : "false";
    } else {
        $ring_group_call_forward_enabled = $rg["ring_group_call_forward_enabled"];
    }

    if (isset($body->ring_group_follow_me_enabled)) {
        $ring_group_follow_me_enabled = ($body->ring_group_follow_me_enabled === true || $body->ring_group_follow_me_enabled === "true") ? "true" : "false";
    } else {
        $ring_group_follow_me_enabled = $rg["ring_group_follow_me_enabled"];
    }

    if (isset($body->ring_group_enabled)) {
        $ring_group_enabled = ($body->ring_group_enabled === true || $body->ring_group_enabled === "true") ? "true" : "false";
    } else {
        $ring_group_enabled = $rg["ring_group_enabled"];
    }

    // Build the dialplan XML
    $dialplan_xml = "<extension name=\"" . htmlspecialchars($ring_group_name) . "\" continue=\"\" uuid=\"" . $dialplan_uuid . "\">\n";
    $dialplan_xml .= "\t<condition field=\"destination_number\" expression=\"^" . htmlspecialchars($ring_group_extension) . "$\">\n";
    $dialplan_xml .= "\t\t<action application=\"ring_ready\" data=\"\"/>\n";
    $dialplan_xml .= "\t\t<action application=\"set\" data=\"ring_group_uuid=" . $ring_group_uuid . "\"/>\n";
    $dialplan_xml .= "\t\t<action application=\"lua\" data=\"app.lua ring_groups\"/>\n";
    $dialplan_xml .= "\t</condition>\n";
    $dialplan_xml .= "</extension>\n";

    // Update ring group record using direct SQL
    $sql = "UPDATE v_ring_groups SET
            ring_group_name = :ring_group_name,
            ring_group_extension = :ring_group_extension,
            ring_group_greeting = :ring_group_greeting,
            ring_group_context = :ring_group_context,
            ring_group_strategy = :ring_group_strategy,
            ring_group_call_timeout = :ring_group_call_timeout,
            ring_group_forward_destination = :ring_group_forward_destination,
            ring_group_forward_enabled = :ring_group_forward_enabled,
            ring_group_caller_id_name = :ring_group_caller_id_name,
            ring_group_caller_id_number = :ring_group_caller_id_number,
            ring_group_cid_name_prefix = :ring_group_cid_name_prefix,
            ring_group_cid_number_prefix = :ring_group_cid_number_prefix,
            ring_group_timeout_app = :ring_group_timeout_app,
            ring_group_timeout_data = :ring_group_timeout_data,
            ring_group_distinctive_ring = :ring_group_distinctive_ring,
            ring_group_ringback = :ring_group_ringback,
            ring_group_call_screen_enabled = :ring_group_call_screen_enabled,
            ring_group_call_forward_enabled = :ring_group_call_forward_enabled,
            ring_group_follow_me_enabled = :ring_group_follow_me_enabled,
            ring_group_missed_call_app = :ring_group_missed_call_app,
            ring_group_missed_call_data = :ring_group_missed_call_data,
            ring_group_enabled = :ring_group_enabled,
            ring_group_description = :ring_group_description,
            ring_group_forward_toll_allow = :ring_group_forward_toll_allow,
            update_date = NOW()
            WHERE ring_group_uuid = :ring_group_uuid";

    $parameters = array();
    $parameters["ring_group_uuid"] = $ring_group_uuid;
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

    // Update dialplan record using direct SQL
    $sql = "UPDATE v_dialplans SET
            dialplan_name = :dialplan_name,
            dialplan_number = :dialplan_number,
            dialplan_context = :dialplan_context,
            dialplan_xml = :dialplan_xml,
            dialplan_enabled = :dialplan_enabled,
            dialplan_description = :dialplan_description,
            update_date = NOW()
            WHERE dialplan_uuid = :dialplan_uuid";

    $parameters = array();
    $parameters["dialplan_uuid"] = $dialplan_uuid;
    $parameters["dialplan_name"] = $ring_group_name;
    $parameters["dialplan_number"] = $ring_group_extension;
    $parameters["dialplan_context"] = $ring_group_context;
    $parameters["dialplan_xml"] = $dialplan_xml;
    $parameters["dialplan_enabled"] = $ring_group_enabled;
    $parameters["dialplan_description"] = $ring_group_description;

    $database = new database;
    $database->execute($sql, $parameters);
    unset($parameters);

    // Clear the dialplan cache
    if (class_exists('cache')) {
        $cache = new cache;
        $cache->delete("dialplan:" . $domain_name);
    }

    return array(
        "success" => true,
        "message" => "Ring group updated successfully",
        "ringGroupUuid" => $ring_group_uuid,
        "ringGroupName" => $ring_group_name,
        "ringGroupExtension" => $ring_group_extension,
        "ringGroupStrategy" => $ring_group_strategy,
        "ringGroupEnabled" => $ring_group_enabled === "true"
    );
}
