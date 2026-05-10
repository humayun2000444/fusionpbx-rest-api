<?php

$required_params = array("bossSecretaryUuid");

function do_action($body) {
    global $domain_uuid;

    $bs_uuid = isset($body->bossSecretaryUuid) ? $body->bossSecretaryUuid : $body->boss_secretary_uuid;

    $database = new database;

    // Get existing
    $existing = $database->select(
        "SELECT boss_extension, dialplan_uuid FROM v_boss_secretary WHERE boss_secretary_uuid = :uuid",
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

    // Reload dialplan
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
