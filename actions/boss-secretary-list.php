<?php

$required_params = array();

function do_action($body) {
    global $domain_uuid;

    $db_domain_uuid = isset($body->domainUuid) ? $body->domainUuid : (isset($body->domain_uuid) ? $body->domain_uuid : $domain_uuid);

    $database = new database;

    $sql = "SELECT bs.*, d.domain_name
            FROM v_boss_secretary bs
            LEFT JOIN v_domains d ON bs.domain_uuid = d.domain_uuid
            WHERE bs.domain_uuid = :domain
            ORDER BY bs.boss_extension ASC";

    $pairs = $database->select($sql, array("domain" => $db_domain_uuid), "all");

    return array(
        "success" => true,
        "total" => is_array($pairs) ? count($pairs) : 0,
        "pairs" => is_array($pairs) ? $pairs : array()
    );
}
