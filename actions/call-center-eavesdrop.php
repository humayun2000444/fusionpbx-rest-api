<?php
/**
 * call-center-eavesdrop.php
 * Eavesdrop on an active call: listen, whisper, or barge in
 *
 * Modes:
 *   listen  - listen to both legs silently (default)
 *   whisper - listen + DTMF: 1=whisper agent, 2=whisper caller, 3=barge, 0=listen-only
 *   barge   - full three-way conference with caller and agent
 *
 * Note: Call center session_uuid is a loopback channel — uuid_exists returns false for it.
 * We resolve the actual SIP bridge UUID via uuid_getvar bridge_uuid.
 */

$required_params = array('uuid', 'listenExtension');

function do_action($body) {
    global $domain_uuid;

    $call_uuid       = isset($body->uuid) ? $body->uuid : (isset($body->call_uuid) ? $body->call_uuid : null);
    $listen_ext      = isset($body->listenExtension) ? $body->listenExtension : (isset($body->listen_extension) ? $body->listen_extension : null);
    $mode            = isset($body->mode) ? strtolower(trim($body->mode)) : 'listen';
    $domain_uuid_req = isset($body->domain_uuid) ? $body->domain_uuid : $domain_uuid;

    if (!$call_uuid) {
        return array('error' => 'Call UUID (uuid) is required');
    }
    if (!$listen_ext) {
        return array('error' => 'Listen extension (listenExtension) is required');
    }
    if (!in_array($mode, array('listen', 'whisper', 'barge'))) {
        return array('error' => 'Invalid mode. Use: listen, whisper, or barge');
    }
    if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $call_uuid)) {
        return array('error' => 'Invalid call UUID format');
    }

    // Get domain name
    $database = new database;
    $sql = "SELECT domain_name FROM v_domains WHERE domain_uuid = :domain_uuid";
    $domain = $database->select($sql, array('domain_uuid' => $domain_uuid_req), 'row');
    if (!$domain) {
        return array('error' => 'Domain not found for domain_uuid: ' . $domain_uuid_req);
    }
    $domain_name = $domain['domain_name'];

    // Connect ESL
    $esl = event_socket::create();
    if (!$esl) {
        return array('error' => 'Event socket connection failed');
    }

    // Resolve the actual SIP channel UUID.
    // Call center session_uuid is a loopback channel — uuid_exists returns false for it.
    // Use uuid_getvar bridge_uuid to find the real bridged SIP channel.
    $eavesdrop_uuid = resolve_eavesdrop_uuid($call_uuid);

    if (!$eavesdrop_uuid) {
        return array(
            'error'         => 'Call not found or not yet bridged',
            'uuid'          => $call_uuid,
            'hint'          => 'Call may still be waiting/ringing. Try when state is Answered.',
        );
    }

    // Build originate command
    switch ($mode) {
        case 'whisper':
            $vars    = "origination_caller_id_name='Whisper',origination_caller_id_number='*88',eavesdrop_enable_dtmf=true";
            $app     = "&eavesdrop($eavesdrop_uuid)";
            $message = 'Whisper mode: answer your phone. Press 1=whisper to agent, 2=whisper to caller, 3=barge, 0=listen.';
            break;
        case 'barge':
            $vars    = "origination_caller_id_name='Barge',origination_caller_id_number='*88'";
            $app     = "&three_way($eavesdrop_uuid)";
            $message = 'Barge initiated. Answer your phone to join the call.';
            break;
        default: // listen
            $vars    = "origination_caller_id_name='Listen',origination_caller_id_number='*88',eavesdrop_enable_dtmf=false";
            $app     = "&eavesdrop($eavesdrop_uuid)";
            $message = 'Listen mode: answer your phone to listen silently.';
    }

    $originate_cmd = "originate {{$vars}}user/{$listen_ext}@{$domain_name} {$app}";
    $result = trim(event_socket::api($originate_cmd));

    if (strpos($result, '-ERR') !== false) {
        return array(
            'error'        => 'FreeSWITCH rejected eavesdrop command',
            'details'      => $result,
            'mode'         => $mode,
            'eavesdropUuid'=> $eavesdrop_uuid,
        );
    }

    return array(
        'success'         => true,
        'message'         => $message,
        'mode'            => $mode,
        'callUuid'        => $call_uuid,
        'eavesdropUuid'   => $eavesdrop_uuid,
        'listenExtension' => $listen_ext,
        'eslResult'       => $result,
    );
}

/**
 * Resolve the UUID to eavesdrop on.
 *
 * Call center session_uuid is a loopback channel.
 * uuid_exists and uuid_getvar both return false/_undef_ for loopback channels.
 * The only reliable way to get the bridge target is via "show channels" —
 * which lists the loopback channel with application=uuid_bridge and
 * application_data=<real SIP channel UUID>.
 */
function resolve_eavesdrop_uuid($uuid) {
    // Direct check: works for normal SIP channels
    $exists = trim(event_socket::api("uuid_exists $uuid"));
    if ($exists === 'true') {
        return $uuid;
    }

    // Loopback channel: parse "show channels" to find application_data of uuid_bridge
    $channels_raw = trim(event_socket::api("show channels"));
    if (!empty($channels_raw)) {
        $lines = explode("\n", $channels_raw);

        // First line is the CSV header
        if (count($lines) < 2) {
            return $uuid;
        }

        $headers = str_getcsv($lines[0]);
        $uuid_col     = array_search('uuid',             $headers);
        $app_col      = array_search('application',      $headers);
        $app_data_col = array_search('application_data', $headers);

        if ($uuid_col === false || $app_col === false || $app_data_col === false) {
            return $uuid;
        }

        foreach ($lines as $i => $line) {
            if ($i === 0) continue;           // skip header
            $line = trim($line);
            if (empty($line)) continue;
            if (strpos($line, 'total') !== false) continue; // skip "N total." summary line

            $cols = str_getcsv($line);
            if (!isset($cols[$uuid_col])) continue;

            if (trim($cols[$uuid_col]) === $uuid) {
                // Found our loopback channel
                $app      = isset($cols[$app_col])      ? trim($cols[$app_col])      : '';
                $app_data = isset($cols[$app_data_col]) ? trim($cols[$app_data_col]) : '';

                if ($app === 'uuid_bridge' &&
                    preg_match('/^([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})$/i', $app_data, $m)) {

                    $bridge_uuid = $m[1];
                    $bridge_exists = trim(event_socket::api("uuid_exists $bridge_uuid"));
                    if ($bridge_exists === 'true') {
                        return $bridge_uuid;
                    }
                }
            }
        }
    }

    // Nothing found — return original and let FreeSWITCH report the error
    return $uuid;
}
?>
