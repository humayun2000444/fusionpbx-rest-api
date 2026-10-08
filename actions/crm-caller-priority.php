<?php
/*
 * crm-caller-priority — VIP callers answered first, for one domain.
 *
 * The CRM marks contacts Normal / Priority / VIP. With this switched on, every
 * inbound call into the domain runs crm_caller_priority.lua first, which asks
 * the CRM how important the caller is and sets cc_base_score. mod_callcenter
 * answers the waiting caller with the highest (seconds waited + base score)
 * first, so a VIP gets a head start in every queue of the domain without any
 * queue being changed.
 *
 * It is ONE dialplan entry in the domain's own context, order 85: after
 * domain-variables (20), before the menus (100+) and queues (210). continue=
 * true, so the call carries on exactly as before; the lookup only sets
 * variables. Only calls with call_direction=inbound are looked up, once
 * (crm_priority_checked), so an internal call or a transfer from a menu into
 * a queue costs nothing.
 *
 * ops:
 *   status   {enabled, scriptInstalled}
 *   enable   url = the CRM's lookup URL for this company (signed, from the CRM
 *            gateway; the caller's number is appended by the script)
 *   disable  removes the entry
 *
 * Reached only through the CRM gateway's admin-checked door, never its generic
 * telephony proxy: this writes the dialplan.
 */

$required_params = array("op");

const CRM_PRIORITY_NAME = 'crm-caller-priority';
const CRM_PRIORITY_SCRIPT = '/usr/share/freeswitch/scripts/crm_caller_priority.lua';
// The application uuid FusionPBX's dialplan manager uses for hand-made entries.
const CRM_PRIORITY_APP_UUID = '742714e5-8cdf-32fd-462c-cbe7e3d655db';

function crm_priority_clear_cache($domain_name) {
    if (class_exists('cache')) {
        $cache = new cache;
        $cache->delete("dialplan:" . $domain_name);
    }
    $cache_file = '/var/cache/fusionpbx/dialplan.' . $domain_name;
    if (file_exists($cache_file)) {
        @unlink($cache_file);
    }
}

function crm_priority_remove($database, $domain_uuid) {
    $rows = $database->select(
        "SELECT dialplan_uuid FROM v_dialplans WHERE domain_uuid = :domain_uuid AND dialplan_name = :name",
        array("domain_uuid" => $domain_uuid, "name" => CRM_PRIORITY_NAME), "all");
    foreach ((array) $rows as $row) {
        $p = array("dialplan_uuid" => $row['dialplan_uuid']);
        $database->execute("DELETE FROM v_dialplan_details WHERE dialplan_uuid = :dialplan_uuid", $p);
        $database->execute("DELETE FROM v_dialplans WHERE dialplan_uuid = :dialplan_uuid", $p);
    }
    return is_array($rows) ? count($rows) : 0;
}

function do_action($body) {
    global $domain_uuid;
    $db_domain_uuid = isset($body->domainUuid) ? $body->domainUuid : (isset($body->domain_uuid) ? $body->domain_uuid : $domain_uuid);
    $op = strtolower(trim((string) $body->op));

    $database = new database;
    $domain = $database->select("SELECT domain_name FROM v_domains WHERE domain_uuid = :domain_uuid",
        array("domain_uuid" => $db_domain_uuid), "row");
    if (!$domain) {
        return array("error" => "Domain not found", "code" => 404);
    }
    $domain_name = $domain['domain_name'];
    $script_installed = file_exists(CRM_PRIORITY_SCRIPT);

    $existing = $database->select(
        "SELECT dialplan_uuid FROM v_dialplans WHERE domain_uuid = :domain_uuid AND dialplan_name = :name AND dialplan_enabled = 'true'",
        array("domain_uuid" => $db_domain_uuid, "name" => CRM_PRIORITY_NAME), "column");

    if ($op === 'status') {
        return array("success" => true, "enabled" => (bool) $existing, "scriptInstalled" => $script_installed,
                     "domainName" => $domain_name);
    }

    if ($op === 'disable') {
        $removed = crm_priority_remove($database, $db_domain_uuid);
        crm_priority_clear_cache($domain_name);
        return array("success" => true, "enabled" => false, "removed" => $removed,
                     "scriptInstalled" => $script_installed);
    }

    if ($op !== 'enable') {
        return array("error" => "op must be status, enable or disable", "code" => 400);
    }
    if (!$script_installed) {
        // An entry that runs a missing script logs an error on every inbound
        // call; refuse rather than install half of it.
        return array("error" => "crm_caller_priority.lua is not installed on this PBX", "code" => 409,
                     "scriptInstalled" => false);
    }
    $url = isset($body->url) ? trim((string) $body->url) : '';
    // The URL goes into a dialplan argument and from there into a shell
    // command, so it is held to a plain http(s) URL with no quotes or spaces.
    if (!preg_match('#^https?://[A-Za-z0-9.\-:\[\]]+(/[A-Za-z0-9._~\-/]*)?\?[A-Za-z0-9._~\-=&%]+$#', $url)) {
        return array("error" => "url is not a plain http(s) lookup URL", "code" => 400);
    }

    // Replace, never duplicate: two entries would look the caller up twice.
    crm_priority_remove($database, $db_domain_uuid);

    $dialplan_uuid = uuid();
    $data = 'crm_caller_priority.lua ' . $url;
    $xml  = '<extension name="' . CRM_PRIORITY_NAME . '" continue="true" uuid="' . $dialplan_uuid . '">' . "\n";
    $xml .= '	<condition field="${call_direction}" expression="^inbound$"/>' . "\n";
    $xml .= '	<condition field="${crm_priority_checked}" expression="^$">' . "\n";
    $xml .= '		<action application="lua" data="' . htmlspecialchars($data, ENT_QUOTES) . '"/>' . "\n";
    $xml .= '	</condition>' . "\n";
    $xml .= '</extension>';

    $database->execute("INSERT INTO v_dialplans (
        dialplan_uuid, domain_uuid, app_uuid, dialplan_name, dialplan_number,
        dialplan_context, dialplan_continue, dialplan_order, dialplan_enabled,
        dialplan_description, dialplan_xml, insert_date
    ) VALUES (
        :dialplan_uuid, :domain_uuid, :app_uuid, :name, '',
        :context, 'true', '85', 'true',
        :description, :xml, NOW()
    )", array(
        "dialplan_uuid" => $dialplan_uuid,
        "domain_uuid" => $db_domain_uuid,
        "app_uuid" => CRM_PRIORITY_APP_UUID,
        "name" => CRM_PRIORITY_NAME,
        "context" => $domain_name,
        "description" => "CRM caller priority: VIP callers get a head start in every queue. Managed by the CRM (Settings > Caller priority).",
        "xml" => $xml,
    ));

    // Details too, so the entry reads correctly in FusionPBX's dialplan
    // manager and survives being opened and saved there.
    $details = array(
        array('condition', '${call_direction}', '^inbound$', '1'),
        array('condition', '${crm_priority_checked}', '^$', '1'),
        array('action', 'lua', $data, '1'),
    );
    $order = 10;
    foreach ($details as $d) {
        $database->execute("INSERT INTO v_dialplan_details (dialplan_detail_uuid, domain_uuid, dialplan_uuid,
            dialplan_detail_tag, dialplan_detail_type, dialplan_detail_data,
            dialplan_detail_group, dialplan_detail_order)
            VALUES (:uuid, :domain_uuid, :dialplan_uuid, :tag, :type, :data, :grp, :ord)",
            array("uuid" => uuid(), "domain_uuid" => $db_domain_uuid, "dialplan_uuid" => $dialplan_uuid,
                  "tag" => $d[0], "type" => $d[1], "data" => $d[2], "grp" => $d[3], "ord" => $order));
        $order += 10;
    }

    crm_priority_clear_cache($domain_name);
    return array("success" => true, "enabled" => true, "scriptInstalled" => true,
                 "dialplanUuid" => $dialplan_uuid, "domainName" => $domain_name);
}
