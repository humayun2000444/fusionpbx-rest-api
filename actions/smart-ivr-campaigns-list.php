<?php
/*
 * Smart IVR - List Campaigns
 * Returns list of outbound campaigns
 */

$required_params = array();

function do_action($body) {
    global $domain_uuid;

    // Get domain_uuid from request or use global
    $req_domain_uuid = isset($body->domain_uuid) ? $body->domain_uuid : $domain_uuid;

    if (empty($req_domain_uuid)) {
        return array('error' => 'domain_uuid is required');
    }

    $database = new database;

    // Get campaigns
    $sql = "SELECT * FROM v_smart_ivr_campaigns
            WHERE domain_uuid = :domain_uuid
            ORDER BY insert_date DESC";
    $params = array(':domain_uuid' => $req_domain_uuid);
    $campaigns = $database->select($sql, $params, 'all');

    return array(
        'success' => true,
        'campaigns' => $campaigns ? $campaigns : array()
    );
}
