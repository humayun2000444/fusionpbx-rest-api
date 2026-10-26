<?php
/**
 * sip-profile-reload: flush FusionPBX's sofia.conf cache and tell FreeSWITCH to re-read a profile.
 *   name         (required)
 *   apply_mode   RESCAN (default, no call drop) | RESTART (drops this profile's calls) | START | RELOAD_ONLY
 */
require_once __DIR__ . '/sip-profile-helper.php';

$required_params = array("name");

function do_action($body) {
    if (!sip_profile_valid_name($body->name)) {
        return array("error" => "invalid profile name", "code" => 400);
    }
    $database = new database;
    if (empty(sip_profile_row($database, $body->name))) {
        return array("error" => "sip profile not found", "code" => 404);
    }
    $mode = isset($body->apply_mode) ? $body->apply_mode : (isset($body->applyMode) ? $body->applyMode : 'RESCAN');
    $response = sip_profile_apply($body->name, $mode);
    return array("success" => true, "name" => $body->name, "apply_mode" => strtoupper((string) $mode), "applied" => $response);
}
