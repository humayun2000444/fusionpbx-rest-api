<?php
/**
 * sip-profile-create: a new SIP profile, stored the way the GUI stores it.
 *   name                 (required) letters, digits, . _ -
 *   copy_from            optional  profile whose settings and domains are copied first (the GUI's Copy button)
 *   sip_ip sip_port rtp_ip ext_sip_ip ext_rtp_ip context dialplan inbound_codec_prefs outbound_codec_prefs auth_calls
 *   params               optional  {"param-name": "value"} for anything else; applied over copy_from
 *   description, enabled ("true"/"false", default true)
 *   apply_mode           NONE | START (default: bring it up) | RELOAD_ONLY
 * Without copy_from, sip_ip and sip_port are required and the rest default to a plain profile.
 */
require_once __DIR__ . '/sip-profile-helper.php';

$required_params = array("name");

function do_action($body) {
    $name = $body->name;
    if (!sip_profile_valid_name($name)) {
        return array("error" => "invalid profile name (letters, digits, . _ - only)", "code" => 400);
    }
    $database = new database;
    if (!empty(sip_profile_row($database, $name))) {
        return array("error" => "sip profile '" . $name . "' already exists", "code" => 409);
    }

    $requested = sip_profile_requested_params($body);
    $copy_from = isset($body->copy_from) ? $body->copy_from : (isset($body->copyFrom) ? $body->copyFrom : null);
    $source = null;
    if (!empty($copy_from)) {
        $source = sip_profile_row($database, $copy_from);
        if (empty($source)) {
            return array("error" => "copy_from profile '" . $copy_from . "' not found", "code" => 404);
        }
    } elseif (empty($requested['sip-ip']) || empty($requested['sip-port'])) {
        return array("error" => "sip_ip and sip_port are required (or pass copy_from)", "code" => 400);
    }
    // two profiles on one address:port cannot both start; say so now instead of leaving a profile that never runs
    $ip = isset($requested['sip-ip']) ? $requested['sip-ip'] : null;
    $port = isset($requested['sip-port']) ? $requested['sip-port'] : null;
    if ($source && ($ip === null || $port === null)) {
        return array("error" => "a copy needs its own sip_ip and sip_port: '" . $copy_from . "' is already bound to its address", "code" => 400);
    }
    if ($ip !== null && $port !== null) {
        $clash = $database->select(
            "SELECT p.sip_profile_name FROM v_sip_profiles p
               JOIN v_sip_profile_settings a ON a.sip_profile_uuid = p.sip_profile_uuid AND a.sip_profile_setting_name = 'sip-ip' AND a.sip_profile_setting_enabled = 'true'
               JOIN v_sip_profile_settings b ON b.sip_profile_uuid = p.sip_profile_uuid AND b.sip_profile_setting_name = 'sip-port' AND b.sip_profile_setting_enabled = 'true'
              WHERE a.sip_profile_setting_value = :ip AND b.sip_profile_setting_value = :port LIMIT 1",
            array('ip' => $ip, 'port' => $port), 'row');
        if (!empty($clash)) {
            return array("error" => "profile '" . $clash['sip_profile_name'] . "' already uses " . $ip . ":" . $port, "code" => 409);
        }
    }

    $enabled = isset($body->enabled) ? (($body->enabled === true || $body->enabled === 'true') ? 'true' : 'false') : 'true';
    $description = isset($body->description) ? (string) $body->description : null;
    $profile_uuid = uuid();
    $database->execute(
        'INSERT INTO v_sip_profiles (sip_profile_uuid, sip_profile_name, sip_profile_hostname, sip_profile_enabled,
                sip_profile_description, insert_date) VALUES (:uuid, :name, :hostname, :enabled, :description, now())',
        array('uuid' => $profile_uuid, 'name' => $name, 'hostname' => '', 'enabled' => $enabled, 'description' => $description));

    if ($source) {
        foreach (sip_profile_settings($database, $source['sip_profile_uuid']) as $setting) {
            sip_profile_put_setting($database, $profile_uuid, $setting['name'], $setting['value'], $setting['enabled'], $setting['description']);
        }
        $domains = $database->select('SELECT * FROM v_sip_profile_domains WHERE sip_profile_uuid = :uuid',
            array('uuid' => $source['sip_profile_uuid']), 'all');
        foreach (is_array($domains) ? $domains : array() as $domain) {
            $database->execute(
                'INSERT INTO v_sip_profile_domains (sip_profile_domain_uuid, sip_profile_uuid, sip_profile_domain_name,
                        sip_profile_domain_alias, sip_profile_domain_parse, insert_date) VALUES (:id, :uuid, :name, :alias, :parse, now())',
                array('id' => uuid(), 'uuid' => $profile_uuid, 'name' => $domain['sip_profile_domain_name'],
                      'alias' => $domain['sip_profile_domain_alias'], 'parse' => $domain['sip_profile_domain_parse']));
        }
    } else {
        // a plain profile: what a carrier- or phone-facing profile needs, nothing else
        $defaults = array(
            'user-agent-string' => 'FreeSWITCH',
            'rtp-ip' => $ip,
            'ext-sip-ip' => $ip,
            'ext-rtp-ip' => $ip,
            'context' => 'public',
            'dialplan' => 'XML',
            'auth-calls' => 'true',
            'inbound-codec-prefs' => '$${global_codec_prefs}',
            'outbound-codec-prefs' => '$${outbound_codec_prefs}',
            'manage-presence' => 'false',
            'rtp-timer-name' => 'soft',
        );
        foreach ($defaults as $param => $value) {
            if (!isset($requested[$param])) {
                sip_profile_put_setting($database, $profile_uuid, $param, $value);
            }
        }
    }
    foreach ($requested as $param => $value) {
        sip_profile_put_setting($database, $profile_uuid, $param, $value);
    }

    $apply = isset($body->apply_mode) ? $body->apply_mode : (isset($body->applyMode) ? $body->applyMode : 'START');
    $response = $enabled === 'true' ? sip_profile_apply($name, $apply) : null;
    if ($enabled === 'true' && strtoupper((string) $apply) === 'START') {
        sip_profile_wait($name, true, 10);
    }

    $result = sip_profile_view($database, sip_profile_row($database, $name), true);
    $result['success'] = true;
    $result['applied'] = $response;
    return $result;
}
