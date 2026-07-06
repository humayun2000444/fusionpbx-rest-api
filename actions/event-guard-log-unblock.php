<?php
/**
 * event-guard-log-unblock.php
 * Unblock an IP - does both:
 *   1. Sets log_status to 'pending' and sends ESL event (FusionPBX daemon flow)
 *   2. Directly removes iptables rules as a fallback if daemon is slow
 *
 * Parameters:
 *   event_guard_log_uuid - UUID of the log entry to unblock
 *   ip_address           - (alternative) IP address to unblock directly
 */

$required_params = array();

function do_action($body) {
    $log_uuid = isset($body->event_guard_log_uuid) ? $body->event_guard_log_uuid : null;
    $ip_address = isset($body->ip_address) ? $body->ip_address : null;

    if (empty($log_uuid) && empty($ip_address)) {
        return ['error' => 'event_guard_log_uuid or ip_address is required'];
    }

    $uuids_to_update = [];
    $filter = 'sip-auth-fail';

    if (!empty($log_uuid)) {
        $sql = "SELECT event_guard_log_uuid, ip_address, filter, log_status FROM v_event_guard_logs WHERE event_guard_log_uuid = :uuid";
        $database = new database;
        $row = $database->select($sql, ['uuid' => $log_uuid], 'row');

        if (!$row) {
            return ['error' => 'Log entry not found'];
        }

        $ip_address = $row['ip_address'];
        $filter = $row['filter'];
        $uuids_to_update[] = $log_uuid;
    } else {
        $sql = "SELECT event_guard_log_uuid, filter FROM v_event_guard_logs WHERE ip_address = :ip AND log_status = 'blocked' ORDER BY log_date DESC";
        $database = new database;
        $rows = $database->select($sql, ['ip' => $ip_address], 'all');
        if ($rows && is_array($rows)) {
            foreach ($rows as $r) {
                $uuids_to_update[] = $r['event_guard_log_uuid'];
                $filter = $r['filter'];
            }
        }
    }

    if (empty($uuids_to_update)) {
        return ['error' => 'No blocked entries found'];
    }

    // Step 1: Set log_status to 'pending' via FusionPBX database class
    $p = permissions::new();
    $p->add('event_guard_log_edit', 'temp');

    $array = [];
    $x = 0;
    foreach ($uuids_to_update as $uuid) {
        $array['event_guard_logs'][$x]['event_guard_log_uuid'] = $uuid;
        $array['event_guard_logs'][$x]['log_status'] = 'pending';
        $x++;
    }

    $database = new database;
    $database->app_name = 'event_guard';
    $database->app_uuid = 'c5b86612-1514-40cb-8e2c-3f01a8f6f637';
    $database->save($array, false);

    $p->delete('event_guard_log_edit', 'temp');

    // Step 2: Send ESL event for daemon
    $esl_sent = false;
    if (class_exists('event_socket')) {
        $esl = event_socket::create();
        if ($esl) {
            $cmd = "sendevent CUSTOM\nEvent-Name: CUSTOM\nEvent-Subclass: event_guard:unblock";
            $switch_result = event_socket::command($cmd);
            $esl_sent = true;
        }
    }

    // Step 3: Directly remove iptables rules as fallback
    $firewall_removed = false;
    if (!empty($ip_address) && filter_var($ip_address, FILTER_VALIDATE_IP)) {
        $firewall_path = file_exists('/usr/sbin/iptables') ? '/usr/sbin' : (file_exists('/sbin/iptables') ? '/sbin' : '');
        if (!empty($firewall_path)) {
            // Check both chains: sip-auth-ip and sip-auth-fail
            $chains = ['sip-auth-ip', 'sip-auth-fail'];
            foreach ($chains as $chain) {
                $command = $firewall_path . '/iptables -L ' . escapeshellarg($chain) . ' -n --line-numbers 2>/dev/null | grep "' . $ip_address . ' " | cut -d " " -f1';
                $line_number = trim(shell_exec($command));
                if (is_numeric($line_number)) {
                    shell_exec($firewall_path . '/iptables -D ' . escapeshellarg($chain) . ' ' . $line_number . ' 2>/dev/null');
                    $firewall_removed = true;
                }
            }
        }

        // Also update status to 'unblocked' directly since we removed the rule
        if ($firewall_removed || !$firewall_removed) {
            $p2 = permissions::new();
            $p2->add('event_guard_log_edit', 'temp');

            $array2 = [];
            $x = 0;
            foreach ($uuids_to_update as $uuid) {
                $array2['event_guard_logs'][$x]['event_guard_log_uuid'] = $uuid;
                $array2['event_guard_logs'][$x]['log_status'] = 'unblocked';
                $array2['event_guard_logs'][$x]['log_date'] = 'now()';
                $x++;
            }

            $database2 = new database;
            $database2->app_name = 'event_guard';
            $database2->app_uuid = 'c5b86612-1514-40cb-8e2c-3f01a8f6f637';
            $database2->save($array2, false);

            $p2->delete('event_guard_log_edit', 'temp');
        }
    }

    return [
        'status' => 'success',
        'ipAddress' => $ip_address,
        'logStatus' => 'unblocked',
        'eslEventSent' => $esl_sent,
        'firewallRemoved' => $firewall_removed,
        'entriesUpdated' => count($uuids_to_update)
    ];
}
