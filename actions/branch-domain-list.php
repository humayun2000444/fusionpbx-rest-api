<?php

// List all available domains (for the dropdown when adding branch members)

$required_params = array();

function do_action($body) {
    $database = new database;

    $sql = "SELECT domain_uuid, domain_name, domain_description, domain_enabled
            FROM v_domains
            WHERE domain_enabled = 'true'
            ORDER BY domain_name";

    $domains = $database->select($sql, null, "all");

    if (!is_array($domains)) {
        $domains = array();
    }

    // For each domain, get extension count
    foreach ($domains as &$domain) {
        $ext_count = $database->select(
            "SELECT COUNT(*) as cnt FROM v_extensions WHERE domain_uuid = :domain_uuid AND enabled = 'true'",
            array("domain_uuid" => $domain['domain_uuid']), "row"
        );
        $domain['extension_count'] = $ext_count ? (int)$ext_count['cnt'] : 0;
    }

    return array(
        "success" => true,
        "total" => count($domains),
        "domains" => $domains
    );
}
