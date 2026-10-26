<?php
/**
 * sip-profile-update: change settings of a profile, keeping everything not named.
 *   name                 (required)
 *   sip_ip sip_port rtp_ip ext_sip_ip ext_rtp_ip context dialplan inbound_codec_prefs outbound_codec_prefs auth_calls
 *   params               {"param-name": "value"}; a value null removes the setting
 *   description, enabled
 *   apply_mode           RESCAN (default, no call drop) | RESTART (drops this profile's calls; needed to re-bind an
 *                        address or port) | RELOAD_ONLY | NONE
 */
require_once __DIR__ . '/sip-profile-helper.php';

$required_params = array("name");

function do_action($body) {
    $name = $body->name;
    if (!sip_profile_valid_name($name)) {
        return array("error" => "invalid profile name", "code" => 400);
    }
    $database = new database;
    $row = sip_profile_row($database, $name);
    if (empty($row)) {
        return array("error" => "sip profile not found", "code" => 404);
    }

    $requested = sip_profile_requested_params($body);
    foreach ($requested as $param => $value) {
        sip_profile_put_setting($database, $row['sip_profile_uuid'], $param, $value);
    }
    // a null in "params" removes the setting
    if (isset($body->params) && (is_object($body->params) || is_array($body->params))) {
        foreach ((array) $body->params as $param => $value) {
            if ($value === null && is_string($param) && preg_match('/^[A-Za-z0-9_.-]{1,64}$/', $param)) {
                $database->execute('DELETE FROM v_sip_profile_settings WHERE sip_profile_uuid = :uuid AND sip_profile_setting_name = :name',
                    array('uuid' => $row['sip_profile_uuid'], 'name' => $param));
            }
        }
    }
    if (isset($body->description)) {
        $database->execute('UPDATE v_sip_profiles SET sip_profile_description = :d, update_date = now() WHERE sip_profile_uuid = :uuid',
            array('d' => (string) $body->description, 'uuid' => $row['sip_profile_uuid']));
    }
    if (isset($body->enabled)) {
        $database->execute('UPDATE v_sip_profiles SET sip_profile_enabled = :e, update_date = now() WHERE sip_profile_uuid = :uuid',
            array('e' => ($body->enabled === true || $body->enabled === 'true') ? 'true' : 'false', 'uuid' => $row['sip_profile_uuid']));
    }

    $apply = isset($body->apply_mode) ? $body->apply_mode : (isset($body->applyMode) ? $body->applyMode : 'RESCAN');
    $response = sip_profile_apply($name, $apply);
    if (strtoupper((string) $apply) === 'RESTART') {
        sip_profile_wait($name, true, 15);
    }

    $result = sip_profile_view($database, sip_profile_row($database, $name), true);
    $result['success'] = true;
    $result['applied'] = $response;
    return $result;
}
