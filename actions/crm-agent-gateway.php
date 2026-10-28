<?php
/*
 * crm-agent-gateway — which gateway each agent's outbound calls leave by.
 *
 * An agent with no assignment calls out exactly as before: by the gateway of
 * whichever route matches the number. An agent with one is sent by their own
 * gateway first, through every route, with the same digits; when that gateway
 * is down or refuses the call, the route's own gateway takes it. How the
 * dialplan does that is in crm-agent-gateway-helper.php and
 * crm_agent_gateway.lua.
 *
 * ops:
 *   list  {scriptInstalled, gateways, assignments, routes: {total, following}}
 *   set   extensions: ["1001", ...]   the domain's extension numbers
 *         gatewayUuid: "<uuid>"       one of the domain's own gateways, or ""
 *                                     to put them back on the company default
 *
 * Reached only through the CRM gateway's admin-checked door, never its generic
 * telephony proxy: this writes the dialplan.
 */

$required_params = array("op");

require_once(__DIR__ . '/crm-agent-gateway-helper.php');

function crm_agw_list($database, $domain_uuid) {
    $gateways = $database->select(
        "SELECT gateway_uuid, gateway, description, enabled FROM v_gateways
          WHERE domain_uuid = :domain_uuid ORDER BY gateway",
        array("domain_uuid" => $domain_uuid), "all");
    $assignments = array();
    if (crm_agw_table_exists($database)) {
        $assignments = $database->select(
            "SELECT e.extension, a.extension_uuid, a.gateway_uuid
               FROM v_crm_agent_gateways a
               JOIN v_extensions e ON e.extension_uuid = a.extension_uuid
              WHERE a.domain_uuid = :domain_uuid
              ORDER BY e.extension",
            array("domain_uuid" => $domain_uuid), "all");
    }
    $out_gateways = array();
    foreach ((array) $gateways as $g) {
        $out_gateways[] = array("gatewayUuid" => $g['gateway_uuid'], "name" => $g['gateway'],
            "description" => $g['description'], "enabled" => $g['enabled'] === 'true' || $g['enabled'] === true);
    }
    $out_assignments = array();
    foreach ((array) $assignments as $a) {
        $out_assignments[] = array("extension" => $a['extension'], "extensionUuid" => $a['extension_uuid'],
            "gatewayUuid" => $a['gateway_uuid']);
    }
    return array("success" => true, "scriptInstalled" => file_exists(CRM_AGW_SCRIPT),
        "gateways" => $out_gateways, "assignments" => $out_assignments,
        "routes" => crm_agw_coverage($database, $domain_uuid));
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

    if ($op === 'list') {
        return crm_agw_list($database, $db_domain_uuid);
    }
    if ($op !== 'set') {
        return array("error" => "op must be list or set", "code" => 400);
    }

    $gateway_uuid = isset($body->gatewayUuid) ? strtolower(trim((string) $body->gatewayUuid))
        : (isset($body->gateway_uuid) ? strtolower(trim((string) $body->gateway_uuid)) : '');
    $extensions = isset($body->extensions) && is_array($body->extensions) ? $body->extensions : array();
    $wanted = array();
    foreach ($extensions as $e) {
        $e = trim((string) $e);
        if (!preg_match('/^[0-9]{1,15}$/', $e)) {
            return array("error" => "extensions must be extension numbers", "code" => 400);
        }
        $wanted[$e] = true;
    }
    if (!$wanted) {
        return array("error" => "no extensions given", "code" => 400);
    }
    if (count($wanted) > 500) {
        return array("error" => "at most 500 extensions at a time", "code" => 400);
    }

    if ($gateway_uuid !== '') {
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $gateway_uuid)) {
            return array("error" => "gatewayUuid is not a gateway id", "code" => 400);
        }
        $own = $database->select("SELECT gateway_uuid FROM v_gateways
            WHERE gateway_uuid = :gateway_uuid AND domain_uuid = :domain_uuid",
            array("gateway_uuid" => $gateway_uuid, "domain_uuid" => $db_domain_uuid), "column");
        if (!$own) {
            return array("error" => "that gateway is not one of this company's", "code" => 403);
        }
        if (!file_exists(CRM_AGW_SCRIPT)) {
            // Routes that run a missing script log an error on every call.
            return array("error" => "crm_agent_gateway.lua is not installed on this PBX", "code" => 409,
                         "scriptInstalled" => false);
        }
    }

    // Only this domain's extensions; a number that is not one is reported, not guessed.
    $found = array();
    foreach (array_keys($wanted) as $ext) {
        $uuid = $database->select("SELECT extension_uuid FROM v_extensions
            WHERE domain_uuid = :domain_uuid AND extension = :extension",
            array("domain_uuid" => $db_domain_uuid, "extension" => (string) $ext), "column");
        if ($uuid) {
            $found[(string) $ext] = $uuid;
        }
    }
    $unknown = array_values(array_diff(array_map('strval', array_keys($wanted)), array_keys($found)));

    if ($gateway_uuid !== '') {
        crm_agw_ensure_table($database);
    }
    if (crm_agw_table_exists($database)) {
        foreach ($found as $ext => $ext_uuid) {
            if ($gateway_uuid === '') {
                $database->execute("DELETE FROM v_crm_agent_gateways WHERE extension_uuid = :u",
                    array("u" => $ext_uuid));
            } else {
                $database->execute("INSERT INTO v_crm_agent_gateways (extension_uuid, domain_uuid, gateway_uuid, update_date)
                    VALUES (:u, :d, :g, NOW())
                    ON CONFLICT (extension_uuid) DO UPDATE
                    SET domain_uuid = EXCLUDED.domain_uuid, gateway_uuid = EXCLUDED.gateway_uuid, update_date = NOW()",
                    array("u" => $ext_uuid, "d" => $db_domain_uuid, "g" => $gateway_uuid));
            }
        }
    }

    $sync = crm_agw_sync_routes($database, $db_domain_uuid, $domain_name);
    $out = crm_agw_list($database, $db_domain_uuid);
    $out["updated"] = count($found);
    $out["unknown"] = $unknown;
    $out["routesChanged"] = $sync["changed"];
    if ($sync["failed"]) {
        $out["warning"] = "These routes could not be rebuilt and still ignore agent gateways: "
            . implode(", ", $sync["failed"]);
    }
    return $out;
}
