<?php

$required_params = array("conference_name", "conference_extension");

function do_action($body) {
    global $domain_uuid;

    // Get domain_uuid - use provided or global
    $conf_domain_uuid = isset($body->domain_uuid) ? $body->domain_uuid : $domain_uuid;

    if (empty($conf_domain_uuid)) {
        return array("error" => "Domain UUID is required");
    }

    // Get domain name
    $sql = "SELECT domain_name FROM v_domains WHERE domain_uuid = :domain_uuid";
    $parameters = array("domain_uuid" => $conf_domain_uuid);
    $database = new database;
    $domain = $database->select($sql, $parameters, "row");

    if (!$domain) {
        return array("error" => "Domain not found");
    }

    $domain_name = $domain["domain_name"];

    // Check if extension already exists
    $conference_extension = $body->conference_extension;
    $sql = "SELECT conference_uuid FROM v_conferences WHERE domain_uuid = :domain_uuid AND conference_extension = :extension";
    $parameters = array("domain_uuid" => $conf_domain_uuid, "extension" => $conference_extension);
    $database = new database;
    $existing = $database->select($sql, $parameters, "row");

    if ($existing) {
        return array("error" => "Conference extension already exists");
    }

    // Generate UUIDs
    $conference_uuid = uuid();
    $dialplan_uuid = uuid();

    // Get conference data
    $conference_name = preg_replace("/[^A-Za-z0-9\- ]/", "", $body->conference_name);
    $conference_pin_number = isset($body->conference_pin_number) ? $body->conference_pin_number : null;
    $conference_profile = isset($body->conference_profile) ? $body->conference_profile : "default";
    $conference_flags = isset($body->conference_flags) ? $body->conference_flags : "";
    $conference_email_address = isset($body->conference_email_address) ? $body->conference_email_address : null;
    $conference_account_code = isset($body->conference_account_code) ? $body->conference_account_code : null;
    $conference_order = isset($body->conference_order) ? (int)$body->conference_order : 333;
    $conference_description = isset($body->conference_description) ? $body->conference_description : null;
    $conference_enabled = isset($body->conference_enabled) ? ($body->conference_enabled ? "true" : "false") : "true";
    $conference_context = $domain_name;

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

    // Insert conference record using direct SQL
    $sql = "INSERT INTO v_conferences (
            conference_uuid, domain_uuid, dialplan_uuid, conference_name, conference_extension,
            conference_pin_number, conference_profile, conference_flags, conference_email_address,
            conference_account_code, conference_order, conference_description, conference_context,
            conference_enabled, insert_date
        ) VALUES (
            :conference_uuid, :domain_uuid, :dialplan_uuid, :conference_name, :conference_extension,
            :conference_pin_number, :conference_profile, :conference_flags, :conference_email_address,
            :conference_account_code, :conference_order, :conference_description, :conference_context,
            :conference_enabled, NOW()
        )";

    $parameters = array();
    $parameters["conference_uuid"] = $conference_uuid;
    $parameters["domain_uuid"] = $conf_domain_uuid;
    $parameters["dialplan_uuid"] = $dialplan_uuid;
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
    $parameters["domain_uuid"] = $conf_domain_uuid;
    $parameters["app_uuid"] = 'b81412e8-7253-91f4-e48e-42fc2c9a38d9';
    $parameters["dialplan_name"] = $conference_name;
    $parameters["dialplan_number"] = $conference_extension;
    $parameters["dialplan_context"] = $conference_context;
    $parameters["dialplan_continue"] = "false";
    $parameters["dialplan_order"] = $conference_order;
    $parameters["dialplan_enabled"] = $conference_enabled;
    $parameters["dialplan_description"] = $conference_description;
    $parameters["dialplan_xml"] = $dialplan_xml;

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
        "message" => "Conference created successfully",
        "conferenceUuid" => $conference_uuid,
        "dialplanUuid" => $dialplan_uuid,
        "conferenceName" => $conference_name,
        "conferenceExtension" => $conference_extension,
        "conferenceProfile" => $conference_profile,
        "conferenceEnabled" => $conference_enabled === "true"
    );
}
