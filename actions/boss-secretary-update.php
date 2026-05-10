<?php

$required_params = array("bossSecretaryUuid");

require_once dirname(__FILE__) . '/boss-secretary-dialplan.php';

function do_action($body) {
    global $domain_uuid;

    $bs_uuid = isset($body->bossSecretaryUuid) ? $body->bossSecretaryUuid : $body->boss_secretary_uuid;

    $database = new database;

    // Get existing record
    $existing = $database->select(
        "SELECT bs.*, d.domain_name FROM v_boss_secretary bs
         LEFT JOIN v_domains d ON bs.domain_uuid = d.domain_uuid
         WHERE bs.boss_secretary_uuid = :uuid",
        array("uuid" => $bs_uuid), "row");
    if (!$existing) return array("error" => "Boss-Secretary pair not found");

    $domain_name = $existing['domain_name'];
    $db_domain_uuid = $existing['domain_uuid'];
    $dialplan_uuid = $existing['dialplan_uuid'];

    // Update fields
    $updates = array();
    $params = array("uuid" => $bs_uuid);

    $field_map = array(
        "secretaryExtension" => "secretary_extension",
        "secretaryName" => "secretary_name",
        "bossName" => "boss_name",
        "mode" => "mode",
        "vipList" => "vip_list",
        "ringTimeout" => "ring_timeout",
        "cidPrefix" => "cid_prefix",
        "enabled" => "enabled"
    );

    foreach ($field_map as $api_key => $db_key) {
        if (isset($body->$api_key)) {
            $updates[] = "$db_key = :$db_key";
            $params[$db_key] = $body->$api_key;
        }
    }

    if (!empty($updates)) {
        $updates[] = "update_date = NOW()";
        $sql = "UPDATE v_boss_secretary SET " . implode(", ", $updates) . " WHERE boss_secretary_uuid = :uuid";
        $database->execute($sql, $params);
    }

    // Get updated record
    $updated = $database->select(
        "SELECT * FROM v_boss_secretary WHERE boss_secretary_uuid = :uuid",
        array("uuid" => $bs_uuid), "row");

    // Regenerate dialplan
    $boss_ext = $updated['boss_extension'];
    $secretary_ext = $updated['secretary_extension'];
    $mode = $updated['mode'];
    $vip_list = $updated['vip_list'];
    $ring_timeout = intval($updated['ring_timeout']);
    $cid_prefix = $updated['cid_prefix'];
    $enabled = $updated['enabled'];
    $boss_name = $updated['boss_name'];

    // Delete old dialplan details
    if ($dialplan_uuid) {
        $database->execute("DELETE FROM v_dialplan_details WHERE dialplan_uuid = :uuid", array("uuid" => $dialplan_uuid));
        $database->execute("DELETE FROM v_dialplans WHERE dialplan_uuid = :uuid", array("uuid" => $dialplan_uuid));
    }

    // Regenerate if enabled
    if ($enabled === 'true' && $mode !== 'off') {
        if (!$dialplan_uuid) {
            $dialplan_uuid = uuid();
            $database->execute("UPDATE v_boss_secretary SET dialplan_uuid = :dp WHERE boss_secretary_uuid = :uuid",
                array("dp" => $dialplan_uuid, "uuid" => $bs_uuid));
        }
        generate_boss_secretary_dialplan($database, $dialplan_uuid, $db_domain_uuid, $domain_name,
            $boss_ext, $secretary_ext, $mode, $vip_list, $ring_timeout, $cid_prefix, $boss_name);
    }

    // Clear cache and reload dialplan
    clear_dialplan_cache($domain_name);
    require_once "resources/switch.php";
    $esl = event_socket::create();
    if ($esl) event_socket::api("reloadxml");

    return array(
        "success" => true,
        "bossSecretaryUuid" => $bs_uuid,
        "message" => "Boss-Secretary pair updated"
    );
}
