<?php

$required_params = array("speedDialUuid");

function do_action($body) {
    global $domain_uuid;

    $sd_uuid = isset($body->speedDialUuid) ? $body->speedDialUuid : $body->speed_dial_uuid;

    $database = new database;

    $existing = $database->select("SELECT speed_dial_code FROM v_speed_dials WHERE speed_dial_uuid = :uuid",
        array("uuid" => $sd_uuid), "row");
    if (!$existing) return array("error" => "Speed dial not found");

    $database->execute("DELETE FROM v_speed_dials WHERE speed_dial_uuid = :uuid", array("uuid" => $sd_uuid));

    return array(
        "success" => true,
        "speedDialUuid" => $sd_uuid,
        "speedDialCode" => $existing['speed_dial_code'],
        "message" => "Speed dial deleted"
    );
}
