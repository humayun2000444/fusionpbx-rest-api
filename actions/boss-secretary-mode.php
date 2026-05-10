<?php

$required_params = array("bossSecretaryUuid", "mode");

require_once dirname(__FILE__) . '/boss-secretary-dialplan.php';

function do_action($body) {
    global $domain_uuid;

    $bs_uuid = isset($body->bossSecretaryUuid) ? $body->bossSecretaryUuid : $body->boss_secretary_uuid;
    $new_mode = $body->mode; // filter_all, vip_only, off

    if (!in_array($new_mode, array('filter_all', 'vip_only', 'off'))) {
        return array("error" => "Invalid mode. Must be: filter_all, vip_only, or off");
    }

    $database = new database;

    // Get existing
    $existing = $database->select(
        "SELECT bs.*, d.domain_name FROM v_boss_secretary bs
         LEFT JOIN v_domains d ON bs.domain_uuid = d.domain_uuid
         WHERE bs.boss_secretary_uuid = :uuid",
        array("uuid" => $bs_uuid), "row");
    if (!$existing) return array("error" => "Boss-Secretary pair not found");

    $db_domain_uuid = $existing['domain_uuid'];
    $domain_name = $existing['domain_name'];
    $dialplan_uuid = $existing['dialplan_uuid'];

    // Update mode
    $database->execute(
        "UPDATE v_boss_secretary SET mode = :mode, update_date = NOW() WHERE boss_secretary_uuid = :uuid",
        array("mode" => $new_mode, "uuid" => $bs_uuid));

    // Delete old dialplan
    if ($dialplan_uuid) {
        $database->execute("DELETE FROM v_dialplan_details WHERE dialplan_uuid = :uuid", array("uuid" => $dialplan_uuid));
        $database->execute("DELETE FROM v_dialplans WHERE dialplan_uuid = :uuid", array("uuid" => $dialplan_uuid));
    }

    // Regenerate dialplan if not off
    if ($new_mode !== 'off' && $existing['enabled'] === 'true') {
        if (!$dialplan_uuid) {
            $dialplan_uuid = uuid();
            $database->execute("UPDATE v_boss_secretary SET dialplan_uuid = :dp WHERE boss_secretary_uuid = :uuid",
                array("dp" => $dialplan_uuid, "uuid" => $bs_uuid));
        }
        generate_boss_secretary_dialplan($database, $dialplan_uuid, $db_domain_uuid, $domain_name,
            $existing['boss_extension'], $existing['secretary_extension'], $new_mode,
            $existing['vip_list'], intval($existing['ring_timeout']),
            $existing['cid_prefix'], $existing['boss_name']);
    }

    // Clear cache and reload dialplan
    clear_dialplan_cache($domain_name);
    require_once "resources/switch.php";
    $esl = event_socket::create();
    if ($esl) event_socket::api("reloadxml");

    return array(
        "success" => true,
        "bossSecretaryUuid" => $bs_uuid,
        "mode" => $new_mode,
        "message" => "Mode changed to $new_mode"
    );
}
