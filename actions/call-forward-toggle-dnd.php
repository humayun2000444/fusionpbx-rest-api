<?php

$required_params = array("extension_uuid");

function do_action($body) {
    global $domain_uuid;

    $extension_uuid = $body->extension_uuid;

    // Get current extension details
    $sql = "SELECT extension_uuid, extension, number_alias, domain_uuid, do_not_disturb
            FROM v_extensions
            WHERE extension_uuid = :extension_uuid";
    $parameters = array("extension_uuid" => $extension_uuid);

    $database = new database;
    $ext = $database->select($sql, $parameters, "row");

    if (!$ext) {
        return array("error" => "Extension not found");
    }

    $ext_domain_uuid = $ext["domain_uuid"];
    $extension = $ext["extension"];
    $number_alias = $ext["number_alias"];
    $current_dnd = $ext["do_not_disturb"];

    // Toggle DND
    $new_dnd = ($current_dnd === "true") ? "false" : "true";

    // Get domain name for cache clearing
    $sql = "SELECT domain_name FROM v_domains WHERE domain_uuid = :domain_uuid";
    $parameters = array("domain_uuid" => $ext_domain_uuid);
    $domain = $database->select($sql, $parameters, "row");
    $domain_name = $domain ? $domain["domain_name"] : "";

    // Build update array
    $array['extensions'][0]['extension_uuid'] = $extension_uuid;
    $array['extensions'][0]['do_not_disturb'] = $new_dnd;

    // If enabling DND, disable forward all and follow me
    if ($new_dnd === "true") {
        $array['extensions'][0]['forward_all_enabled'] = "false";
        $array['extensions'][0]['follow_me_enabled'] = "false";
    }

    // Execute update using FusionPBX ORM
    $database = new database;
    $database->app_name = 'calls';
    $database->app_uuid = '19806921-e8ed-dcff-b325-dd3e5da4959d';
    $database->save($array);
    unset($array);

    // Clear the cache
    if (class_exists('cache') && !empty($domain_name)) {
        $cache = new cache;
        $cache->delete("directory:" . $extension . "@" . $domain_name);
        if (!empty($number_alias)) {
            $cache->delete("directory:" . $number_alias . "@" . $domain_name);
        }
    }

    return array(
        "success" => true,
        "message" => "Do Not Disturb " . ($new_dnd === "true" ? "enabled" : "disabled"),
        "extensionUuid" => $extension_uuid,
        "extension" => $extension,
        "doNotDisturb" => $new_dnd === "true"
    );
}
