<?php

$required_params = array("bossExtension", "secretaryExtension");

require_once dirname(__FILE__) . '/boss-secretary-dialplan.php';

function do_action($body) {
    global $domain_uuid;

    $db_domain_uuid = isset($body->domainUuid) ? $body->domainUuid : (isset($body->domain_uuid) ? $body->domain_uuid : $domain_uuid);

    $boss_ext = isset($body->bossExtension) ? $body->bossExtension : $body->boss_extension;
    $secretary_ext = isset($body->secretaryExtension) ? $body->secretaryExtension : $body->secretary_extension;
    $boss_name = isset($body->bossName) ? $body->bossName : (isset($body->boss_name) ? $body->boss_name : '');
    $secretary_name = isset($body->secretaryName) ? $body->secretaryName : (isset($body->secretary_name) ? $body->secretary_name : '');
    $mode = isset($body->mode) ? $body->mode : 'filter_all';
    $vip_list = isset($body->vipList) ? $body->vipList : (isset($body->vip_list) ? $body->vip_list : '');
    $ring_timeout = isset($body->ringTimeout) ? intval($body->ringTimeout) : 20;
    $cid_prefix = isset($body->cidPrefix) ? $body->cidPrefix : (isset($body->cid_prefix) ? $body->cid_prefix : 'Boss: ');
    $enabled = isset($body->enabled) ? $body->enabled : 'true';

    $database = new database;

    // Get domain name
    $domain_result = $database->select("SELECT domain_name FROM v_domains WHERE domain_uuid = :uuid",
        array("uuid" => $db_domain_uuid), "row");
    if (!$domain_result) return array("error" => "Domain not found");
    $domain_name = $domain_result['domain_name'];

    // Check duplicate
    $existing = $database->select(
        "SELECT boss_secretary_uuid FROM v_boss_secretary WHERE domain_uuid = :domain AND boss_extension = :ext",
        array("domain" => $db_domain_uuid, "ext" => $boss_ext), "row");
    if ($existing) return array("error" => "Boss extension $boss_ext already has a secretary configured");

    // Generate UUIDs
    $bs_uuid = uuid();
    $dialplan_uuid = uuid();

    // Insert boss-secretary record
    $sql = "INSERT INTO v_boss_secretary (
        boss_secretary_uuid, domain_uuid, boss_extension, boss_name,
        secretary_extension, secretary_name, mode, vip_list,
        ring_timeout, cid_prefix, enabled, dialplan_uuid, insert_date
    ) VALUES (
        :uuid, :domain, :boss_ext, :boss_name,
        :sec_ext, :sec_name, :mode, :vip_list,
        :timeout, :prefix, :enabled, :dialplan_uuid, NOW()
    )";
    $result = $database->execute($sql, array(
        "uuid" => $bs_uuid, "domain" => $db_domain_uuid,
        "boss_ext" => $boss_ext, "boss_name" => $boss_name,
        "sec_ext" => $secretary_ext, "sec_name" => $secretary_name,
        "mode" => $mode, "vip_list" => $vip_list,
        "timeout" => $ring_timeout, "prefix" => $cid_prefix,
        "enabled" => $enabled, "dialplan_uuid" => $dialplan_uuid
    ));
    if ($result === false) return array("error" => "Failed to create boss-secretary record");

    // Generate dialplan
    if ($enabled === 'true' && $mode !== 'off') {
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
        "dialplanUuid" => $dialplan_uuid,
        "bossExtension" => $boss_ext,
        "secretaryExtension" => $secretary_ext,
        "mode" => $mode,
        "message" => "Boss-Secretary pair created"
    );
}
