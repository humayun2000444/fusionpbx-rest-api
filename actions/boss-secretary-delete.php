<?php

$required_params = array("bossSecretaryUuid");

require_once dirname(__FILE__) . '/boss-secretary-dialplan.php';

function do_action($body) {
    global $domain_uuid;

    $bs_uuid = isset($body->bossSecretaryUuid) ? $body->bossSecretaryUuid : $body->boss_secretary_uuid;

    $database = new database;

    // Get existing
    $existing = $database->select(
        "SELECT bs.boss_extension, bs.dialplan_uuid, bs.domain_uuid, d.domain_name FROM v_boss_secretary bs LEFT JOIN v_domains d ON bs.domain_uuid = d.domain_uuid WHERE bs.boss_secretary_uuid = :uuid",
        array("uuid" => $bs_uuid), "row");
    if (!$existing) return array("error" => "Boss-Secretary pair not found");

    $dialplan_uuid = $existing['dialplan_uuid'];
    $boss_ext = $existing['boss_extension'];

    // Delete dialplan details + dialplan
    if ($dialplan_uuid) {
        $database->execute("DELETE FROM v_dialplan_details WHERE dialplan_uuid = :uuid", array("uuid" => $dialplan_uuid));
        $database->execute("DELETE FROM v_dialplans WHERE dialplan_uuid = :uuid", array("uuid" => $dialplan_uuid));
    }

    // Delete boss-secretary record
    $database->execute("DELETE FROM v_boss_secretary WHERE boss_secretary_uuid = :uuid", array("uuid" => $bs_uuid));

    // Clear cache and reload dialplan
    if (!empty($existing['domain_name'])) clear_dialplan_cache($existing['domain_name']);
    require_once "resources/switch.php";
    $esl = event_socket::create();
    if ($esl) event_socket::api("reloadxml");

    return array(
        "success" => true,
        "bossSecretaryUuid" => $bs_uuid,
        "bossExtension" => $boss_ext,
        "message" => "Boss-Secretary pair deleted"
    );
}
