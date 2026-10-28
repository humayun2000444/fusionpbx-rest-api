<?php
/**
 * Agent gateways — the dialplan half, shared by crm-agent-gateway.php and
 * outbound-route-create.php.
 *
 * An administrator can send chosen agents' outbound calls out through a chosen
 * gateway (agents A and B by gateway X, C and D by gateway Y). Routes stay one
 * per dial pattern. In a domain that uses this, every outbound route gets ONE
 * extra action right before its bridge:
 *
 *   <action application="lua" data="crm_agent_gateway.lua <this route's uuid>"/>
 *
 * The script looks the calling extension up in v_crm_agent_gateways. No row:
 * it returns at once and the route bridges exactly as before. A row: it sends
 * the call to that gateway with the digits the route itself would send (read
 * from the route at call time, so a later pattern or prefix change applies to
 * both), and when that gateway is down or refuses the call it returns, so the
 * route's own bridge -- the company default -- runs next.
 *
 * The bridge row is never touched: outbound-route-update, outbound-route-list
 * and jobs/outbound-route-sync.php read it exactly as before. A domain without
 * a single assignment carries no extra rows at all.
 */

const CRM_AGW_SCRIPT = '/usr/share/freeswitch/scripts/crm_agent_gateway.lua';
const CRM_AGW_LUA = 'crm_agent_gateway.lua';
const CRM_AGW_OUTBOUND_APP_UUID = '8c914ec3-9fc0-8ab5-4cda-6c9288bdc9a3';

function crm_agw_table_exists($database) {
    return (int) $database->select(
        "SELECT count(*) FROM pg_class WHERE relname = 'v_crm_agent_gateways' AND relkind = 'r'",
        null, "column") > 0;
}

// Created by the first assignment, as the web server's own database user, so
// FreeSWITCH and this API both read it without any grants.
function crm_agw_ensure_table($database) {
    if (crm_agw_table_exists($database)) {
        return;
    }
    $database->execute("CREATE TABLE IF NOT EXISTS v_crm_agent_gateways (
        extension_uuid uuid        PRIMARY KEY,
        domain_uuid    uuid        NOT NULL,
        gateway_uuid   uuid        NOT NULL,
        update_date    timestamptz NOT NULL DEFAULT NOW()
    )", null);
    $database->execute("CREATE INDEX IF NOT EXISTS v_crm_agent_gateways_domain
        ON v_crm_agent_gateways (domain_uuid)", null);
}

function crm_agw_domain_in_use($database, $domain_uuid) {
    if (!crm_agw_table_exists($database)) {
        return false;
    }
    return (int) $database->select(
        "SELECT count(*) FROM v_crm_agent_gateways WHERE domain_uuid = :domain_uuid",
        array("domain_uuid" => $domain_uuid), "column") > 0;
}

function crm_agw_clear_cache($domain_name) {
    if (class_exists('cache')) {
        $cache = new cache;
        $cache->delete("dialplan:" . $domain_name);
    }
    $cache_file = '/var/cache/fusionpbx/dialplan.' . $domain_name;
    if (file_exists($cache_file)) {
        @unlink($cache_file);
    }
}

// Each outbound route of the domain that bridges to a gateway, with its first
// bridge row (the one the script must run before).
function crm_agw_routes($database, $domain_uuid) {
    $rows = $database->select(
        "SELECT DISTINCT ON (p.dialplan_uuid)
                p.dialplan_uuid, p.dialplan_name, p.dialplan_continue,
                b.dialplan_detail_uuid AS bridge_uuid,
                b.dialplan_detail_group AS bridge_group,
                b.dialplan_detail_order AS bridge_order
           FROM v_dialplans p
           JOIN v_dialplan_details b ON b.dialplan_uuid = p.dialplan_uuid
          WHERE p.domain_uuid = :domain_uuid
            AND p.app_uuid = :app_uuid
            AND b.dialplan_detail_tag = 'action'
            AND b.dialplan_detail_type = 'bridge'
            AND b.dialplan_detail_data LIKE 'sofia/gateway/%'
          ORDER BY p.dialplan_uuid, b.dialplan_detail_order",
        array("domain_uuid" => $domain_uuid, "app_uuid" => CRM_AGW_OUTBOUND_APP_UUID), "all");
    return is_array($rows) ? $rows : array();
}

function crm_agw_script_rows($database, $dialplan_uuid) {
    $rows = $database->select(
        "SELECT dialplan_detail_uuid, dialplan_detail_data, dialplan_detail_group, dialplan_detail_order
           FROM v_dialplan_details
          WHERE dialplan_uuid = :dialplan_uuid
            AND dialplan_detail_type = 'lua'
            AND dialplan_detail_data LIKE :script",
        array("dialplan_uuid" => $dialplan_uuid, "script" => CRM_AGW_LUA . '%'), "all");
    return is_array($rows) ? $rows : array();
}

// How many of the domain's gateway routes carry the script row.
function crm_agw_coverage($database, $domain_uuid) {
    $routes = crm_agw_routes($database, $domain_uuid);
    $following = 0;
    foreach ($routes as $r) {
        if (crm_agw_script_rows($database, $r['dialplan_uuid'])) {
            $following++;
        }
    }
    return array("total" => count($routes), "following" => $following);
}

/**
 * Put the script row on every gateway route of the domain when at least one
 * agent there has a gateway, and take it off every route when none has.
 * Idempotent: a route already right is left alone, so this runs after every
 * change and after every route created.
 */
function crm_agw_sync_routes($database, $domain_uuid, $domain_name) {
    if (!function_exists('generate_dialplan_xml')) {
        require_once(__DIR__ . '/outbound-route-xml-helper.php');
    }
    $changed = 0;
    $failed = array();
    if (!crm_agw_table_exists($database)) {
        return array("changed" => 0, "failed" => $failed);   // never used on this PBX
    }
    $want = crm_agw_domain_in_use($database, $domain_uuid);

    foreach (crm_agw_routes($database, $domain_uuid) as $r) {
        $uuid = $r['dialplan_uuid'];
        $data = CRM_AGW_LUA . ' ' . $uuid;
        $group = $r['bridge_group'];
        $bridge_order = (int) $r['bridge_order'];
        $have = crm_agw_script_rows($database, $uuid);
        if (!$want && !$have) {
            continue;
        }

        // The last row before the bridge in its group, not counting ours.
        $prev = $database->select(
            "SELECT max(dialplan_detail_order) FROM v_dialplan_details
              WHERE dialplan_uuid = :dialplan_uuid AND dialplan_detail_group = :grp
                AND dialplan_detail_order < :bridge_order
                AND NOT (dialplan_detail_type = 'lua' AND dialplan_detail_data LIKE :script)",
            array("dialplan_uuid" => $uuid, "grp" => $group, "bridge_order" => $bridge_order,
                  "script" => CRM_AGW_LUA . '%'), "column");
        $prev = $prev === null || $prev === false ? null : (int) $prev;

        $right = $want && count($have) === 1
            && $have[0]['dialplan_detail_data'] === $data
            && (string) $have[0]['dialplan_detail_group'] === (string) $group
            && (int) $have[0]['dialplan_detail_order'] < $bridge_order
            && ($prev === null || (int) $have[0]['dialplan_detail_order'] > $prev);
        if ($right) {
            continue;
        }

        foreach ($have as $row) {
            $database->execute("DELETE FROM v_dialplan_details WHERE dialplan_detail_uuid = :u",
                array("u" => $row['dialplan_detail_uuid']));
        }
        if ($want) {
            if ($prev !== null && $prev >= $bridge_order - 1) {
                // No room between the last action and the bridge: space the
                // group out by tens, keeping its order, then slot in before it.
                $rows = $database->select(
                    "SELECT dialplan_detail_uuid FROM v_dialplan_details
                      WHERE dialplan_uuid = :dialplan_uuid AND dialplan_detail_group = :grp
                      ORDER BY dialplan_detail_order, dialplan_detail_tag DESC, dialplan_detail_uuid",
                    array("dialplan_uuid" => $uuid, "grp" => $group), "all");
                $n = 10;
                foreach ((array) $rows as $row) {
                    $database->execute("UPDATE v_dialplan_details SET dialplan_detail_order = :o
                        WHERE dialplan_detail_uuid = :u", array("o" => $n, "u" => $row['dialplan_detail_uuid']));
                    if ($row['dialplan_detail_uuid'] === $r['bridge_uuid']) {
                        $bridge_order = $n;
                    }
                    $n += 10;
                }
            }
            $database->execute("INSERT INTO v_dialplan_details (dialplan_detail_uuid, domain_uuid, dialplan_uuid,
                dialplan_detail_tag, dialplan_detail_type, dialplan_detail_data,
                dialplan_detail_group, dialplan_detail_order, dialplan_detail_enabled)
                VALUES (:uuid, :domain_uuid, :dialplan_uuid, 'action', 'lua', :data, :grp, :ord, 'true')",
                array("uuid" => uuid(), "domain_uuid" => $domain_uuid, "dialplan_uuid" => $uuid,
                      "data" => $data, "grp" => $group, "ord" => $bridge_order - 1));
        }

        $xml = generate_dialplan_xml($uuid, $r['dialplan_name'],
            $r['dialplan_continue'] ? $r['dialplan_continue'] : 'false');
        if ($xml) {
            update_dialplan_xml($uuid, $xml);
            $changed++;
        } else {
            $failed[] = $r['dialplan_name'];
        }
    }

    if ($changed) {
        crm_agw_clear_cache($domain_name);
    }
    return array("changed" => $changed, "failed" => $failed);
}
