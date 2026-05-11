<?php

// Speed Dial dialplan generator
// Creates a dialplan entry that matches *XX pattern and runs Lua lookup

define('SPEED_DIAL_APP_UUID', '5d1a1001-5d1a-4000-5d1a-000000000001');

function generate_speed_dial_dialplan($database, $domain_uuid, $domain_name) {
    // Check if dialplan already exists for this domain
    $existing = $database->select(
        "SELECT dialplan_uuid FROM v_dialplans WHERE domain_uuid = :domain AND app_uuid = :app",
        array("domain" => $domain_uuid, "app" => SPEED_DIAL_APP_UUID), "row");

    if ($existing) {
        // Update existing dialplan XML
        $dialplan_uuid = $existing['dialplan_uuid'];
        $xml = build_speed_dial_xml($dialplan_uuid, $domain_name);
        $database->execute(
            "UPDATE v_dialplans SET dialplan_xml = :xml, update_date = NOW() WHERE dialplan_uuid = :uuid",
            array("xml" => $xml, "uuid" => $dialplan_uuid));
    } else {
        // Create new dialplan
        $dialplan_uuid = uuid();
        $xml = build_speed_dial_xml($dialplan_uuid, $domain_name);

        $sql = "INSERT INTO v_dialplans (
            dialplan_uuid, domain_uuid, app_uuid, dialplan_name, dialplan_number,
            dialplan_context, dialplan_continue, dialplan_order, dialplan_enabled,
            dialplan_description, dialplan_xml, insert_date
        ) VALUES (
            :uuid, :domain, :app, 'Speed Dial', '*',
            :context, 'false', '65', 'true',
            'Custom speed dial lookup', :xml, NOW()
        )";
        $database->execute($sql, array(
            "uuid" => $dialplan_uuid, "domain" => $domain_uuid,
            "app" => SPEED_DIAL_APP_UUID,
            "context" => $domain_name, "xml" => $xml
        ));
    }

    // Clear cache and reload
    clear_speed_dial_cache($domain_name);

    return $dialplan_uuid;
}

function build_speed_dial_xml($dialplan_uuid, $domain_name) {
    $xml = '<extension name="Speed Dial" continue="false" uuid="' . $dialplan_uuid . '">' . "\n";
    $xml .= '	<condition field="destination_number" expression="^\*(\d{1,3})$">' . "\n";
    $xml .= '		<action application="lua" data="speed-dial-lookup.lua $1"/>' . "\n";
    $xml .= '	</condition>' . "\n";
    $xml .= '</extension>';
    return $xml;
}

function clear_speed_dial_cache($domain_name) {
    $cache_file = '/var/cache/fusionpbx/dialplan.' . $domain_name;
    if (file_exists($cache_file)) {
        @unlink($cache_file);
    }
}
