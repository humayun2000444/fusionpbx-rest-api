<?php

$required_params = array("bossSecretaryUuid");

function do_action($body) {
    global $domain_uuid;

    $bs_uuid = isset($body->bossSecretaryUuid) ? $body->bossSecretaryUuid : $body->boss_secretary_uuid;

    $database = new database;

    $sql = "SELECT bs.*, d.domain_name
            FROM v_boss_secretary bs
            LEFT JOIN v_domains d ON bs.domain_uuid = d.domain_uuid
            WHERE bs.boss_secretary_uuid = :uuid";

    $pair = $database->select($sql, array("uuid" => $bs_uuid), "row");

    if (!$pair) return array("error" => "Boss-Secretary pair not found");

    return array(
        "success" => true,
        "pair" => $pair
    );
}
