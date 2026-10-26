<?php
/**
 * Shared by the sip-profile-* actions. Not an action itself (rest.php resolves actions/<action>.php and needs
 * do_action there).
 *
 * A SIP profile is stored the way Advanced > SIP Profiles stores it: a row in v_sip_profiles, its parameters in
 * v_sip_profile_settings and its domains in v_sip_profile_domains. FusionPBX's sofia.conf.lua builds the profile
 * XML from those rows (and caches it), so nothing here writes an XML file.
 */

// FusionPBX created these; deleting them breaks every domain
const SIP_PROFILE_PROTECTED = array('internal', 'external', 'internal-ipv6', 'external-ipv6');

// request field (snake_case; camelCase is accepted too) => <param> name
const SIP_PROFILE_FIELDS = array(
    'sip_ip' => 'sip-ip',
    'rtp_ip' => 'rtp-ip',
    'sip_port' => 'sip-port',
    'context' => 'context',
    'dialplan' => 'dialplan',
    'ext_sip_ip' => 'ext-sip-ip',
    'ext_rtp_ip' => 'ext-rtp-ip',
    'inbound_codec_prefs' => 'inbound-codec-prefs',
    'outbound_codec_prefs' => 'outbound-codec-prefs',
    'auth_calls' => 'auth-calls',
);

function sip_profile_valid_name($name) {
    return is_string($name) && preg_match('/^[A-Za-z0-9_.-]{1,64}$/', $name) === 1;
}

function sip_profile_camel($snake) {
    return lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', $snake))));
}

/** A request field by its snake_case name, falling back to camelCase. */
function sip_profile_field($body, $snake) {
    if (isset($body->$snake)) return $body->$snake;
    $camel = sip_profile_camel($snake);
    return isset($body->$camel) ? $body->$camel : null;
}

/** Run one FreeSWITCH API command: the event socket, then fs_cli. Returns the response text or null. */
function sip_profile_esl($command) {
    if (class_exists('event_socket')) {
        $response = @event_socket::api($command);
        if (is_string($response) && $response !== '') {
            return trim($response);
        }
    }
    $output = @shell_exec('/usr/bin/fs_cli -x ' . escapeshellarg($command) . ' 2>&1');
    return $output === null ? null : trim($output);
}

/** FusionPBX caches the sofia.conf it builds, per FreeSWITCH host; without this a change is never read. */
function sip_profile_flush_cache() {
    $hostname = trim((string) sip_profile_esl('switchname'));
    if ($hostname !== '' && class_exists('cache')) {
        $cache = new cache;
        $cache->delete('configuration:sofia.conf:' . $hostname);
    }
    return $hostname;
}

/** name => RUNNING / DOWN... from `sofia status`; empty when FreeSWITCH cannot be reached. */
function sip_profile_states() {
    $states = array();
    foreach (preg_split('/\R/', (string) sip_profile_esl('sofia status')) as $line) {
        if (preg_match('/^\s*(\S+)\s+profile\s+\S+\s+(\S+)/', $line, $m)) {
            $states[$m[1]] = $m[2];
        }
    }
    return $states;
}

/**
 * Make FreeSWITCH pick up a change (the cache is flushed first). Modes, the same words TelcoREST's profile screen
 * used: RELOAD_ONLY = reloadxml, RESCAN = rescan (no call drop; does not re-bind an address or port),
 * RESTART = restart that profile (drops its calls), START = bring a new profile up, NONE = nothing.
 */
function sip_profile_apply($name, $mode) {
    $mode = strtoupper((string) $mode);
    if ($mode === '' || $mode === 'NONE') {
        return null;
    }
    sip_profile_flush_cache();
    switch ($mode) {
        case 'RELOAD_ONLY': return sip_profile_esl('reloadxml');
        case 'RESCAN':      return sip_profile_esl('sofia profile ' . $name . ' rescan reloadxml');
        case 'RESTART':     return sip_profile_esl('sofia profile ' . $name . ' restart reloadxml');
        case 'START':       return sip_profile_esl('sofia profile ' . $name . ' start');
    }
    return null;
}

/**
 * Wait until FreeSWITCH lists the profile (or, with $present = false, no longer lists it). A "start" or "stop" answers
 * before the profile's threads have moved, so the state read straight after it is often the old one.
 */
function sip_profile_wait($name, $present, $seconds = 10) {
    $deadline = microtime(true) + $seconds;
    do {
        $states = sip_profile_states();
        if (empty($states)) {
            return false;   // FreeSWITCH could not be asked
        }
        if (isset($states[$name]) === $present) {
            return true;
        }
        usleep(300000);
    } while (microtime(true) < $deadline);
    return false;
}

function sip_profile_row($database, $name) {
    return $database->select('SELECT * FROM v_sip_profiles WHERE sip_profile_name = :name',
        array('name' => $name), 'row');
}

function sip_profile_settings($database, $profile_uuid) {
    $rows = $database->select(
        'SELECT sip_profile_setting_name AS name, sip_profile_setting_value AS value, sip_profile_setting_enabled AS enabled,
                sip_profile_setting_description AS description
           FROM v_sip_profile_settings WHERE sip_profile_uuid = :uuid ORDER BY sip_profile_setting_name',
        array('uuid' => $profile_uuid), 'all');
    return is_array($rows) ? $rows : array();
}

/** Insert or update one <param>; the first row with that name wins when there are several. */
function sip_profile_put_setting($database, $profile_uuid, $name, $value, $enabled = 'true', $description = null) {
    $existing = $database->select(
        'SELECT sip_profile_setting_uuid FROM v_sip_profile_settings
          WHERE sip_profile_uuid = :uuid AND sip_profile_setting_name = :name ORDER BY sip_profile_setting_uuid LIMIT 1',
        array('uuid' => $profile_uuid, 'name' => $name), 'row');
    if (!empty($existing)) {
        $database->execute(
            'UPDATE v_sip_profile_settings SET sip_profile_setting_value = :value, sip_profile_setting_enabled = :enabled,
                    update_date = now() WHERE sip_profile_setting_uuid = :id',
            array('value' => $value, 'enabled' => $enabled, 'id' => $existing['sip_profile_setting_uuid']));
        return;
    }
    $database->execute(
        'INSERT INTO v_sip_profile_settings (sip_profile_setting_uuid, sip_profile_uuid, sip_profile_setting_name,
                sip_profile_setting_value, sip_profile_setting_enabled, sip_profile_setting_description, insert_date)
         VALUES (:id, :uuid, :name, :value, :enabled, :description, now())',
        array('id' => uuid(), 'uuid' => $profile_uuid, 'name' => $name, 'value' => $value, 'enabled' => $enabled,
              'description' => $description));
}

/** Named fields plus the free-form "params" object, as name => value. Values are strings; null/empty skips a field. */
function sip_profile_requested_params($body) {
    $params = array();
    foreach (SIP_PROFILE_FIELDS as $snake => $param) {
        $value = sip_profile_field($body, $snake);
        if ($value !== null && $value !== '') {
            $params[$param] = (string) $value;
        }
    }
    if (isset($body->params) && (is_object($body->params) || is_array($body->params))) {
        foreach ((array) $body->params as $name => $value) {
            if (is_string($name) && preg_match('/^[A-Za-z0-9_.-]{1,64}$/', $name) && (is_scalar($value) || $value === null)) {
                $params[$name] = $value === null ? '' : (string) $value;
            }
        }
    }
    return $params;
}

/** Same shape for list, details and the results of create and update. */
function sip_profile_view($database, $row, $with_settings, $states = null) {
    $settings = sip_profile_settings($database, $row['sip_profile_uuid']);
    $params = array();
    foreach ($settings as $setting) {
        if ($setting['enabled'] === 'true' && !isset($params[$setting['name']])) {
            $params[$setting['name']] = $setting['value'];
        }
    }
    if ($states === null) {
        $states = sip_profile_states();
    }
    $gateways = $database->select('SELECT count(*) AS n FROM v_gateways WHERE profile = :name',
        array('name' => $row['sip_profile_name']), 'row');
    $view = array(
        'sip_profile_uuid' => $row['sip_profile_uuid'],
        'name' => $row['sip_profile_name'],
        'enabled' => $row['sip_profile_enabled'],
        'description' => $row['sip_profile_description'],
        'sip_ip' => isset($params['sip-ip']) ? $params['sip-ip'] : null,
        'sip_port' => isset($params['sip-port']) ? $params['sip-port'] : null,
        'rtp_ip' => isset($params['rtp-ip']) ? $params['rtp-ip'] : null,
        'ext_sip_ip' => isset($params['ext-sip-ip']) ? $params['ext-sip-ip'] : null,
        'ext_rtp_ip' => isset($params['ext-rtp-ip']) ? $params['ext-rtp-ip'] : null,
        'context' => isset($params['context']) ? $params['context'] : null,
        'dialplan' => isset($params['dialplan']) ? $params['dialplan'] : null,
        'inbound_codec_prefs' => isset($params['inbound-codec-prefs']) ? $params['inbound-codec-prefs'] : null,
        'outbound_codec_prefs' => isset($params['outbound-codec-prefs']) ? $params['outbound-codec-prefs'] : null,
        'auth_calls' => isset($params['auth-calls']) ? $params['auth-calls'] : null,
        // RUNNING / DOWN from FreeSWITCH; NOT_LOADED for a profile it does not know; null when it cannot be asked
        'status' => empty($states) ? null : (isset($states[$row['sip_profile_name']]) ? $states[$row['sip_profile_name']] : 'NOT_LOADED'),
        'gateway_count' => (int) (isset($gateways['n']) ? $gateways['n'] : 0),
    );
    if ($with_settings) {
        $view['params'] = $params;
        $view['settings'] = $settings;
        $view['domains'] = $database->select(
            'SELECT sip_profile_domain_name AS name, sip_profile_domain_alias AS alias, sip_profile_domain_parse AS parse
               FROM v_sip_profile_domains WHERE sip_profile_uuid = :uuid ORDER BY sip_profile_domain_name',
            array('uuid' => $row['sip_profile_uuid']), 'all');
    }
    return $view;
}
