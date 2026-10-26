<?php
/**
 * sip-profile-details: one profile with all of its settings (also the disabled ones), its domains and live state.
 *   name   (required)
 */
require_once __DIR__ . '/sip-profile-helper.php';

$required_params = array("name");

function do_action($body) {
    if (!sip_profile_valid_name($body->name)) {
        return array("error" => "invalid profile name", "code" => 400);
    }
    $database = new database;
    $row = sip_profile_row($database, $body->name);
    if (empty($row)) {
        return array("error" => "sip profile not found", "code" => 404);
    }
    return sip_profile_view($database, $row, true);
}
