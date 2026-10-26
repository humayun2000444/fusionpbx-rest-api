<?php
/**
 * sip-profile-delete: remove a profile, its settings and domains, and stop it.
 *   name    (required)
 *   force   optional; needed when gateways still use the profile (they are left without a profile)
 * The four profiles FusionPBX creates cannot be deleted.
 */
require_once __DIR__ . '/sip-profile-helper.php';

$required_params = array("name");

function do_action($body) {
    $name = $body->name;
    if (!sip_profile_valid_name($name)) {
        return array("error" => "invalid profile name", "code" => 400);
    }
    if (in_array($name, SIP_PROFILE_PROTECTED, true)) {
        return array("error" => "'" . $name . "' is a FusionPBX profile and cannot be deleted", "code" => 403);
    }
    $database = new database;
    $row = sip_profile_row($database, $name);
    if (empty($row)) {
        return array("error" => "sip profile not found", "code" => 404);
    }
    $force = isset($body->force) && ($body->force === true || $body->force === 'true');
    $gateways = $database->select('SELECT count(*) AS n FROM v_gateways WHERE profile = :name', array('name' => $name), 'row');
    if (!$force && !empty($gateways) && (int) $gateways['n'] > 0) {
        return array("error" => $gateways['n'] . " gateway(s) use this profile; delete them first or pass force", "code" => 409);
    }

    // Stop it first and make sure it is gone: a profile deleted from the database but still bound to its port is
    // invisible in the GUI and cannot be stopped from there. If it will not stop, nothing is deleted.
    $states = sip_profile_states();
    if (isset($states[$name])) {
        sip_profile_esl('sofia profile ' . $name . ' stop');
        if (!sip_profile_wait($name, false, 10)) {
            sip_profile_esl('sofia profile ' . $name . ' stop');
            if (!sip_profile_wait($name, false, 15)) {
                return array("error" => "profile '" . $name . "' is still running after a stop; nothing was deleted", "code" => 500);
            }
        }
    }
    $uuid = $row['sip_profile_uuid'];
    $database->execute('DELETE FROM v_sip_profile_settings WHERE sip_profile_uuid = :uuid', array('uuid' => $uuid));
    $database->execute('DELETE FROM v_sip_profile_domains WHERE sip_profile_uuid = :uuid', array('uuid' => $uuid));
    $database->execute('DELETE FROM v_sip_profiles WHERE sip_profile_uuid = :uuid', array('uuid' => $uuid));
    sip_profile_flush_cache();
    sip_profile_esl('reloadxml');
    return array("success" => true, "message" => "SIP profile deleted", "name" => $name);
}
