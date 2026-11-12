<?php
/**
 * event-guard-log-unblock.php
 *
 * Unblock an address that Event Guard blocked.
 *
 * Parameters:
 *   event_guard_log_uuid - the log entry to unblock
 *   ip_address           - (alternative) the address: every entry of it still 'blocked'
 *
 * Either way, every entry of that address still 'blocked' on this host is unblocked
 * with it, so the address is cleared from both chains (sip-auth-fail, sip-auth-ip),
 * not just the one row that was clicked.
 *
 * Who removes the firewall rule
 * -----------------------------
 * Only root can delete an iptables rule, and php-fpm runs this action as www-data.
 * The Event Guard service (app/event_guard/resources/service/event_guard.php) runs as
 * root and removes the rule. This action follows the same steps as the FusionPBX
 * GUI:
 *
 *   1. set the log rows to 'pending';
 *   2. send CUSTOM event_guard:unblock;
 *   3. the service removes the rule of every 'pending' row for its host, then marks
 *      those rows 'unblocked' itself.
 *
 * Until 2026-11-12 this action also marked the rows 'unblocked' itself, right after
 * step 2, whether or not anything had been removed (its own iptables call always
 * fails as www-data). The service reads its queue only when the event reaches it.
 * On a busy box that usually came after the overwrite, so it found nothing pending,
 * the rule stayed, and every UI showed "unblocked". BTCL had 191 addresses in that
 * state (jobs/event-guard-sweep.php clears them).
 *
 * Now only the service marks a row 'unblocked'. The action waits a few seconds and
 * reports what actually happened:
 *   logStatus 'unblocked' - the service has removed the rule;
 *   logStatus 'pending'   - the service has not finished yet, and will on its own.
 */

$required_params = array();

if (!function_exists('event_guard_unblock_send_event')) {

    // Send CUSTOM event_guard:unblock to FreeSWITCH. True when it was accepted.
    function event_guard_unblock_send_event() {
        if (!class_exists('event_socket')) {
            return false;
        }
        $cmd = "sendevent CUSTOM\nEvent-Name: CUSTOM\nEvent-Subclass: event_guard:unblock";
        try {
            $reply = event_socket::command($cmd);
        }
        catch (\Throwable $e) {
            return false;
        }
        if ($reply === false || $reply === null) {
            return false;
        }
        // A command reply comes back as its headers; anything else as a string.
        $text = is_array($reply) ? (isset($reply['Reply-Text']) ? (string) $reply['Reply-Text'] : '') : (string) $reply;
        return strpos($text, '-ERR') === false;
    }

    // Where each row stands, by uuid: 'pending', 'done' (unblocked since $since), or
    // its plain status when neither (the 'pending' write did not land).
    function event_guard_unblock_states($database, $uuids, $since) {
        $params = array('since' => $since);
        $names = array();
        foreach (array_values($uuids) as $i => $uuid) {
            $params['u' . $i] = $uuid;
            $names[] = ':u' . $i;
        }
        $sql = "SELECT event_guard_log_uuid, log_status, "
             . "CASE WHEN update_date >= CAST(:since AS timestamptz) THEN 1 ELSE 0 END AS fresh "
             . "FROM v_event_guard_logs WHERE event_guard_log_uuid IN (" . implode(', ', $names) . ")";
        $rows = $database->select($sql, $params, 'all');
        $states = array();
        if (is_array($rows)) {
            foreach ($rows as $row) {
                $status = (string) $row['log_status'];
                if ($status === 'unblocked' && intval($row['fresh']) === 1) {
                    $status = 'done';
                }
                $states[(string) $row['event_guard_log_uuid']] = $status;
            }
        }
        return $states;
    }
}

function do_action($body) {
    $log_uuid = isset($body->event_guard_log_uuid) ? trim((string) $body->event_guard_log_uuid) : '';
    $ip_address = isset($body->ip_address) ? trim((string) $body->ip_address) : '';

    if ($log_uuid === '' && $ip_address === '') {
        return ['error' => 'event_guard_log_uuid or ip_address is required'];
    }

    $database = new database;
    $hostname = gethostname();
    $uuids = [];

    if ($log_uuid !== '') {
        if (function_exists('is_uuid') && !is_uuid($log_uuid)) {
            return ['error' => 'Invalid event_guard_log_uuid'];
        }
        $sql = "SELECT event_guard_log_uuid, ip_address FROM v_event_guard_logs WHERE event_guard_log_uuid = :uuid";
        $row = $database->select($sql, ['uuid' => $log_uuid], 'row');
        if (!$row) {
            return ['error' => 'Log entry not found'];
        }
        $uuids[] = (string) $row['event_guard_log_uuid'];
        $ip_address = (string) $row['ip_address'];
    }
    elseif (!filter_var($ip_address, FILTER_VALIDATE_IP)) {
        return ['error' => 'Invalid ip_address'];
    }

    // Every entry of the address still 'blocked' on this host goes with it.
    $sql = "SELECT event_guard_log_uuid FROM v_event_guard_logs "
         . "WHERE ip_address = :ip AND log_status = 'blocked' AND hostname = :hostname";
    $rows = $database->select($sql, ['ip' => $ip_address, 'hostname' => $hostname], 'all');
    if (is_array($rows)) {
        foreach ($rows as $r) {
            $uuids[] = (string) $r['event_guard_log_uuid'];
        }
    }
    $uuids = array_values(array_unique($uuids));

    if (empty($uuids)) {
        return ['error' => 'No blocked entries found'];
    }

    // Step 1: hand the rows to the Event Guard service.
    $since = (string) $database->select("SELECT CAST(now() AS text)", null, 'column');

    $p = permissions::new();
    $p->add('event_guard_log_edit', 'temp');
    $array = [];
    foreach ($uuids as $x => $uuid) {
        $array['event_guard_logs'][$x]['event_guard_log_uuid'] = $uuid;
        $array['event_guard_logs'][$x]['log_status'] = 'pending';
    }
    $database->app_name = 'event_guard';
    $database->app_uuid = 'c5b86612-1514-40cb-8e2c-3f01a8f6f637';
    $database->save($array, false);
    $p->delete('event_guard_log_edit', 'temp');

    // The save can be refused without an error, so check it landed.
    foreach (event_guard_unblock_states($database, $uuids, $since) as $state) {
        if ($state !== 'pending' && $state !== 'done') {
            return ['error' => 'Could not queue the unblock (the log entries were not updated)'];
        }
    }

    // Step 2: wake the service. It only looks at its queue when this event arrives.
    $esl_sent = event_guard_unblock_send_event();

    // Step 3: give it a few seconds, then report what it actually did.
    $states = event_guard_unblock_states($database, $uuids, $since);
    if ($esl_sent) {
        $deadline = microtime(true) + 6;
        while (in_array('pending', $states, true) && microtime(true) < $deadline) {
            usleep(250000);
            $states = event_guard_unblock_states($database, $uuids, $since);
        }
    }
    $done = !empty($states) && count(array_filter($states, function ($s) { return $s !== 'done'; })) === 0;

    if ($done) {
        $message = 'Unblocked: the Event Guard service removed the firewall rule.';
    }
    elseif ($esl_sent) {
        $message = 'Queued: the Event Guard service has not finished yet and will remove the firewall rule on its own.';
    }
    else {
        $message = 'Queued, but the Event Guard service could not be reached; the address stays blocked until the service handles its next unblock request.';
    }

    return [
        'status' => 'success',
        'ipAddress' => $ip_address,
        'logStatus' => $done ? 'unblocked' : 'pending',
        'eslEventSent' => $esl_sent,
        'firewallRemoved' => $done,
        'entriesUpdated' => count($uuids),
        'message' => $message,
    ];
}
