<?php
/**
 * active-call-list.php
 * Lists all active calls/channels on the FreeSWITCH server
 */

$required_params = array();

function do_action($body) {
    global $config, $database;

    // Get domain_uuid filter (optional)
    $domain_uuid = isset($body->domain_uuid) ? $body->domain_uuid : null;

    // Initialize response
    $response = [
        'calls' => [],
        'total_count' => 0
    ];

    try {
        // Get event socket settings
        $event_socket_ip = isset($_SESSION['event_socket_ip_address']) ? $_SESSION['event_socket_ip_address'] : '127.0.0.1';
        $event_socket_port = isset($_SESSION['event_socket_port']) ? $_SESSION['event_socket_port'] : '8021';
        $event_socket_password = isset($_SESSION['event_socket_password']) ? $_SESSION['event_socket_password'] : 'ClueCon';

        // Connect to FreeSWITCH event socket
        if (function_exists('event_socket_create')) {
            $fp = @event_socket_create($event_socket_ip, $event_socket_port, $event_socket_password);
        } else {
            return ['error' => 'event_socket_create function not available'];
        }

        if (!$fp || !is_resource($fp)) {
            // Fallback: try using fs_cli
            $result = @shell_exec("/usr/bin/fs_cli -x 'show channels as json' 2>&1");
            if (!$result) {
                $result = @shell_exec("fs_cli -x 'show channels as json' 2>&1");
            }

            if (!$result) {
                return ['error' => 'Failed to connect to FreeSWITCH event socket and fs_cli failed'];
            }

            $channels_data = json_decode($result, true);
        } else {
            // Get active channels using 'show channels as json'
            $cmd = "show channels as json";
            $result = event_socket_request($fp, 'api ' . $cmd);

            if ($result) {
                $channels_data = json_decode($result, true);
            }

            // Also get bridged calls info
            $calls_cmd = "show calls as json";
            $calls_result = event_socket_request($fp, 'api ' . $calls_cmd);

            if ($calls_result) {
                $bridged_calls_data = json_decode($calls_result, true);
                if (isset($bridged_calls_data['rows'])) {
                    $response['bridged_calls'] = $bridged_calls_data['rows'];
                    $response['bridged_call_count'] = $bridged_calls_data['row_count'] ?? count($bridged_calls_data['rows']);
                }
            }

            fclose($fp);
        }

        // Process channels data
        if (isset($channels_data['rows']) && is_array($channels_data['rows'])) {
            $calls = [];
            $domain_name = null;

            // Get domain name if filtering
            if ($domain_uuid && $database) {
                $sql = "SELECT domain_name FROM v_domains WHERE domain_uuid = :domain_uuid";
                $parameters = ['domain_uuid' => $domain_uuid];
                $domain_name = $database->select($sql, $parameters, 'column');
            }

            foreach ($channels_data['rows'] as $channel) {
                // Extract domain from presence_id or context
                $channel_domain = null;
                if (!empty($channel['presence_id'])) {
                    $parts = explode('@', $channel['presence_id']);
                    if (count($parts) > 1) {
                        $channel_domain = $parts[1];
                    }
                }

                // If domain filter is provided, skip non-matching
                if ($domain_name && $channel_domain && $channel_domain !== $domain_name) {
                    continue;
                }

                $call = [
                    'uuid' => $channel['uuid'] ?? null,
                    'direction' => $channel['direction'] ?? null,
                    'created' => $channel['created'] ?? null,
                    'created_epoch' => $channel['created_epoch'] ?? null,
                    'name' => $channel['name'] ?? null,
                    'state' => $channel['state'] ?? null,
                    'cid_name' => $channel['cid_name'] ?? null,
                    'cid_num' => $channel['cid_num'] ?? null,
                    'ip_addr' => $channel['ip_addr'] ?? null,
                    'dest' => $channel['dest'] ?? null,
                    'application' => $channel['application'] ?? null,
                    'application_data' => $channel['application_data'] ?? null,
                    'dialplan' => $channel['dialplan'] ?? null,
                    'context' => $channel['context'] ?? null,
                    'read_codec' => $channel['read_codec'] ?? null,
                    'write_codec' => $channel['write_codec'] ?? null,
                    'secure' => $channel['secure'] ?? null,
                    'hostname' => $channel['hostname'] ?? null,
                    'presence_id' => $channel['presence_id'] ?? null,
                    'callstate' => $channel['callstate'] ?? null,
                    'callee_name' => $channel['callee_name'] ?? null,
                    'callee_num' => $channel['callee_num'] ?? null,
                    'call_uuid' => $channel['call_uuid'] ?? null,
                    'initial_cid_name' => $channel['initial_cid_name'] ?? null,
                    'initial_cid_num' => $channel['initial_cid_num'] ?? null,
                    'initial_dest' => $channel['initial_dest'] ?? null
                ];

                // Calculate call duration
                if (!empty($channel['created_epoch'])) {
                    $call['duration_seconds'] = time() - intval($channel['created_epoch']);
                    $call['duration_formatted'] = gmdate("H:i:s", $call['duration_seconds']);
                }

                $calls[] = $call;
            }

            $response['calls'] = $calls;
            $response['total_count'] = count($calls);
            $response['row_count'] = $channels_data['row_count'] ?? count($calls);
        }

        return $response;

    } catch (Exception $e) {
        return ['error' => 'Error fetching active calls: ' . $e->getMessage()];
    }
}
?>
