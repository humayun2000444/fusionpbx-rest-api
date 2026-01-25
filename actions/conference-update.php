<?php

$required_params = array("conference_uuid");

function do_action($body) {
    global $domain_uuid;

    $conference_uuid = $body->conference_uuid;

    // Get current conference details
    $sql = "SELECT c.*, d.domain_name FROM v_conferences c
            JOIN v_domains d ON c.domain_uuid = d.domain_uuid
            WHERE c.conference_uuid = :conference_uuid";
    $parameters = array("conference_uuid" => $conference_uuid);

    $database = new database;
    $conf = $database->select($sql, $parameters, "row");

    if (!$conf) {
        return array("error" => "Conference not found");
    }

    $conf_domain_uuid = $conf["domain_uuid"];
    $domain_name = $conf["domain_name"];
    $dialplan_uuid = $conf["dialplan_uuid"];

    // Get updated values or keep existing
    $conference_name = isset($body->conference_name) ? preg_replace("/[^A-Za-z0-9\- ]/", "", $body->conference_name) : $conf["conference_name"];
    $conference_extension = isset($body->conference_extension) ? $body->conference_extension : $conf["conference_extension"];
    $conference_pin_number = isset($body->conference_pin_number) ? $body->conference_pin_number : $conf["conference_pin_number"];
    $conference_profile = isset($body->conference_profile) ? $body->conference_profile : $conf["conference_profile"];
    $conference_flags = isset($body->conference_flags) ? $body->conference_flags : $conf["conference_flags"];
    $conference_email_address = isset($body->conference_email_address) ? $body->conference_email_address : $conf["conference_email_address"];
    $conference_account_code = isset($body->conference_account_code) ? $body->conference_account_code : $conf["conference_account_code"];
    $conference_order = isset($body->conference_order) ? (int)$body->conference_order : $conf["conference_order"];
    $conference_description = isset($body->conference_description) ? $body->conference_description : $conf["conference_description"];
    $conference_context = $domain_name;

    // Handle enabled field
    if (isset($body->conference_enabled)) {
        $conference_enabled = ($body->conference_enabled === true || $body->conference_enabled === "true") ? "true" : "false";
    } else {
        $conference_enabled = $conf["conference_enabled"];
    }

    // Build the dialplan XML
    $pin_number = !empty($conference_pin_number) ? '+' . $conference_pin_number : '';
    $dialplan_xml = "<extension name=\"" . htmlspecialchars($conference_name) . "\" continue=\"\" uuid=\"" . $dialplan_uuid . "\">\n";
    $dialplan_xml .= "	<condition field=\"destination_number\" expression=\"^" . htmlspecialchars($conference_extension) . "$\">\n";
    $dialplan_xml .= "		<action application=\"answer\" data=\"\"/>\n";
    $dialplan_xml .= "		<action application=\"set\" data=\"conference_uuid=" . $conference_uuid . "\" inline=\"true\"/>\n";
    $dialplan_xml .= "		<action application=\"set\" data=\"conference_extension=" . htmlspecialchars($conference_extension) . "\" inline=\"true\"/>\n";
    $dialplan_xml .= "		<action application=\"conference\" data=\"" . htmlspecialchars($conference_extension) . "@" . $domain_name . "@" . htmlspecialchars($conference_profile . $pin_number) . "+flags{'" . htmlspecialchars($conference_flags) . "'}\"/>\n";
    $dialplan_xml .= "	</condition>\n";
    $dialplan_xml .= "</extension>\n";

    // Update conference record using direct SQL
    $sql = "UPDATE v_conferences SET
            conference_name = :conference_name,
            conference_extension = :conference_extension,
            conference_pin_number = :conference_pin_number,
            conference_profile = :conference_profile,
            conference_flags = :conference_flags,
            conference_email_address = :conference_email_address,
            conference_account_code = :conference_account_code,
            conference_order = :conference_order,
            conference_description = :conference_description,
            conference_context = :conference_context,
            conference_enabled = :conference_enabled,
            update_date = NOW()
            WHERE conference_uuid = :conference_uuid";

    $parameters = array();
    $parameters["conference_uuid"] = $conference_uuid;
    $parameters["conference_name"] = $conference_name;
    $parameters["conference_extension"] = $conference_extension;
    $parameters["conference_pin_number"] = $conference_pin_number;
    $parameters["conference_profile"] = $conference_profile;
    $parameters["conference_flags"] = $conference_flags;
    $parameters["conference_email_address"] = $conference_email_address;
    $parameters["conference_account_code"] = $conference_account_code;
    $parameters["conference_order"] = $conference_order;
    $parameters["conference_description"] = $conference_description;
    $parameters["conference_context"] = $conference_context;
    $parameters["conference_enabled"] = $conference_enabled;

    $database = new database;
    $database->execute($sql, $parameters);
    unset($parameters);

    // Update dialplan record using direct SQL
    $sql = "UPDATE v_dialplans SET
            dialplan_name = :dialplan_name,
            dialplan_number = :dialplan_number,
            dialplan_context = :dialplan_context,
            dialplan_xml = :dialplan_xml,
            dialplan_order = :dialplan_order,
            dialplan_enabled = :dialplan_enabled,
            dialplan_description = :dialplan_description,
            update_date = NOW()
            WHERE dialplan_uuid = :dialplan_uuid";

    $parameters = array();
    $parameters["dialplan_uuid"] = $dialplan_uuid;
    $parameters["dialplan_name"] = $conference_name;
    $parameters["dialplan_number"] = $conference_extension;
    $parameters["dialplan_context"] = $conference_context;
    $parameters["dialplan_xml"] = $dialplan_xml;
    $parameters["dialplan_order"] = $conference_order;
    $parameters["dialplan_enabled"] = $conference_enabled;
    $parameters["dialplan_description"] = $conference_description;

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
        "message" => "Conference updated successfully",
        "conferenceUuid" => $conference_uuid,
        "conferenceName" => $conference_name,
        "conferenceExtension" => $conference_extension,
        "conferenceProfile" => $conference_profile,
        "conferenceEnabled" => $conference_enabled === "true"
    );
}
