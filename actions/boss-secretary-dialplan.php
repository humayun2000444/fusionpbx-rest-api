<?php

// Shared dialplan generation for boss-secretary feature
// Included by create, update, and mode PHP actions

define('BOSS_SEC_APP_UUID', 'b0555ec4-e7a4-4000-b055-000000000001');

function clear_dialplan_cache($domain_name) {
    $cache_file = '/var/cache/fusionpbx/dialplan.' . $domain_name;
    if (file_exists($cache_file)) {
        @unlink($cache_file);
    }
}

function generate_boss_secretary_dialplan($database, $dialplan_uuid, $domain_uuid, $domain_name,
    $boss_ext, $secretary_ext, $mode, $vip_list, $ring_timeout, $cid_prefix, $boss_name) {

    // Build dialplan XML as multiple extensions
    $xml = '';

    // Extension 1: VIP bypass (if VIP list exists)
    if (!empty($vip_list) && $mode !== 'off') {
        $vip_numbers = array_filter(array_map('trim', explode(',', $vip_list)));
        if (!empty($vip_numbers)) {
            $vip_regex = '^(' . implode('|', array_map(function($n) { return preg_quote($n, '/'); }, $vip_numbers)) . ')$';
            $xml .= '<extension name="Boss-Secretary VIP: ' . $boss_ext . '" continue="true">' . "\n";
            $xml .= '	<condition field="destination_number" expression="^' . $boss_ext . '$"/>' . "\n";
            $xml .= '	<condition field="caller_id_number" expression="' . $vip_regex . '">' . "\n";
            $xml .= '		<action application="set" data="boss_secretary_screened=true" inline="true"/>' . "\n";
            $xml .= '	</condition>' . "\n";
            $xml .= '</extension>' . "\n";
        }
    }

    if ($mode !== 'off') {
        // Extension 2: Screened call - Lua checks busy + bridges or routes back
        $xml .= '<extension name="Boss-Secretary Bridge: ' . $boss_ext . '" continue="false">' . "\n";
        $xml .= '	<condition field="destination_number" expression="^' . $boss_ext . '$"/>' . "\n";
        $xml .= '	<condition field="${boss_secretary_screened}" expression="^true$">' . "\n";
        // Lua checks busy and either bridges to boss or transfers back to secretary
        $xml .= '		<action application="lua" data="boss-secretary-busy-check.lua ' . $boss_ext . ' ' . $domain_name . ' ' . $secretary_ext . ' ' . $cid_prefix . '"/>' . "\n";
        $xml .= '	</condition>' . "\n";
        $xml .= '</extension>' . "\n";

        // Extension 4: First time call - route to secretary
        $xml .= '<extension name="Boss-Secretary: ' . $boss_ext . '" continue="false" uuid="' . $dialplan_uuid . '">' . "\n";
        $xml .= '	<condition field="destination_number" expression="^' . $boss_ext . '$">' . "\n";
        $xml .= '		<action application="export" data="boss_secretary_screened=true"/>' . "\n";
        $xml .= '		<action application="set" data="effective_caller_id_name=' . $cid_prefix . '${caller_id_name}"/>' . "\n";
        $xml .= '		<action application="set" data="call_timeout=' . $ring_timeout . '"/>' . "\n";
        $xml .= '		<action application="transfer" data="' . $secretary_ext . ' XML ' . $domain_name . '"/>' . "\n";
        $xml .= '	</condition>' . "\n";
        $xml .= '</extension>';
    }

    // Insert dialplan entry WITH dialplan_xml
    $sql = "INSERT INTO v_dialplans (
        dialplan_uuid, domain_uuid, app_uuid, dialplan_name, dialplan_number,
        dialplan_context, dialplan_continue, dialplan_order, dialplan_enabled,
        dialplan_description, dialplan_xml, insert_date
    ) VALUES (
        :uuid, :domain, :app_uuid, :name, :number,
        :context, 'false', '295', 'true',
        :desc, :xml, NOW()
    )";
    $database->execute($sql, array(
        "uuid" => $dialplan_uuid, "domain" => $domain_uuid,
        "app_uuid" => BOSS_SEC_APP_UUID,
        "name" => "Boss-Secretary: $boss_ext",
        "number" => $boss_ext,
        "context" => $domain_name,
        "desc" => "Boss-Secretary filter for extension $boss_ext" . ($boss_name ? " ($boss_name)" : ""),
        "xml" => $xml
    ));

    // Also store details for our own API reference
    $group = 100;
    if (!empty($vip_list) && $mode !== 'off') {
        $vip_numbers = array_filter(array_map('trim', explode(',', $vip_list)));
        if (!empty($vip_numbers)) {
            $vip_regex = '^(' . implode('|', array_map(function($n) { return preg_quote($n, '/'); }, $vip_numbers)) . ')$';
            bs_insert_detail($database, $domain_uuid, $dialplan_uuid, 'condition', 'destination_number', '^' . $boss_ext . '$', 10, $group);
            bs_insert_detail($database, $domain_uuid, $dialplan_uuid, 'condition', 'caller_id_number', $vip_regex, 20, $group);
            bs_insert_detail($database, $domain_uuid, $dialplan_uuid, 'action', 'transfer', "$boss_ext XML $domain_name", 30, $group);
            $group += 10;
        }
    }
    if ($mode !== 'off') {
        bs_insert_detail($database, $domain_uuid, $dialplan_uuid, 'condition', 'destination_number', '^' . $boss_ext . '$', 10, $group);
        bs_insert_detail($database, $domain_uuid, $dialplan_uuid, 'action', 'set', "effective_caller_id_name=${cid_prefix}\${caller_id_name}", 20, $group);
        bs_insert_detail($database, $domain_uuid, $dialplan_uuid, 'action', 'set', "call_timeout=$ring_timeout", 30, $group);
        bs_insert_detail($database, $domain_uuid, $dialplan_uuid, 'action', 'transfer', "$secretary_ext XML $domain_name", 40, $group);
    }
}

function bs_insert_detail($database, $domain_uuid, $dialplan_uuid, $tag, $type, $data, $order, $group) {
    $sql = "INSERT INTO v_dialplan_details (
        dialplan_detail_uuid, domain_uuid, dialplan_uuid,
        dialplan_detail_tag, dialplan_detail_type, dialplan_detail_data,
        dialplan_detail_order, dialplan_detail_group
    ) VALUES (:uuid, :domain, :dialplan, :tag, :type, :data, :order, :group)";
    $database->execute($sql, array(
        "uuid" => uuid(), "domain" => $domain_uuid, "dialplan" => $dialplan_uuid,
        "tag" => $tag, "type" => $type, "data" => $data, "order" => $order, "group" => $group
    ));
}
