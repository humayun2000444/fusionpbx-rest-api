<?php
/**
 * active-call-details.php
 * Gets detailed information about a specific active call by UUID
 */

$required_params = array('call_uuid');

function do_action($body) {
    global $config;

    // Get call UUID (required)
    $call_uuid = isset($body->call_uuid) ? $body->call_uuid : (isset($body->uuid) ? $body->uuid : null);

    if (empty($call_uuid)) {
        return ['error' => 'Call UUID is required'];
    }

    // Validate UUID format
    if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $call_uuid)) {
        return ['error' => 'Invalid call UUID format'];
    }

    try {
        // Get event socket settings
        $event_socket_ip = isset($_SESSION['event_socket_ip_address']) ? $_SESSION['event_socket_ip_address'] : '127.0.0.1';
        $event_socket_port = isset($_SESSION['event_socket_port']) ? $_SESSION['event_socket_port'] : '8021';
        $event_socket_password = isset($_SESSION['event_socket_password']) ? $_SESSION['event_socket_password'] : 'ClueCon';

        // Connect to FreeSWITCH event socket
        $call_data = null;

        if (function_exists('event_socket_create')) {
            $fp = @event_socket_create($event_socket_ip, $event_socket_port, $event_socket_password);

            if ($fp && is_resource($fp)) {
                // Get channel variables using uuid_dump
                $cmd = "uuid_dump " . $call_uuid . " json";
                $result = event_socket_request($fp, 'api ' . $cmd);
                fclose($fp);

                if (!$result || strpos($result, '-ERR') !== false) {
                    return ['error' => 'Call not found or no longer active', 'uuid' => $call_uuid];
                }

                $call_data = json_decode($result, true);
            }
        }

        // Fallback to fs_cli
        if (!$call_data) {
            $result = @shell_exec("/usr/bin/fs_cli -x 'uuid_dump " . escapeshellarg($call_uuid) . " json' 2>&1");
            if (!$result) {
                $result = @shell_exec("fs_cli -x 'uuid_dump " . escapeshellarg($call_uuid) . " json' 2>&1");
            }

            if (!$result || strpos($result, '-ERR') !== false) {
                return ['error' => 'Call not found or no longer active', 'uuid' => $call_uuid];
            }

            $call_data = json_decode($result, true);
        }

        if (!$call_data) {
            return ['error' => 'Failed to parse call data', 'uuid' => $call_uuid];
        }

        // Extract important call details
        $response = [
            'uuid' => $call_uuid,
            'channel_data' => [
                'state' => $call_data['Channel-State'] ?? $call_data['channel_state'] ?? null,
                'state_number' => $call_data['Channel-State-Number'] ?? $call_data['channel_state_number'] ?? null,
                'name' => $call_data['Channel-Name'] ?? $call_data['channel_name'] ?? null,
                'direction' => $call_data['Call-Direction'] ?? $call_data['direction'] ?? null,
                'answer_state' => $call_data['Answer-State'] ?? $call_data['answer_state'] ?? null,
                'read_codec' => $call_data['Channel-Read-Codec-Name'] ?? null,
                'write_codec' => $call_data['Channel-Write-Codec-Name'] ?? null
            ],
            'caller_info' => [
                'caller_id_name' => $call_data['Caller-Caller-ID-Name'] ?? $call_data['caller_id_name'] ?? null,
                'caller_id_number' => $call_data['Caller-Caller-ID-Number'] ?? $call_data['caller_id_number'] ?? null,
                'callee_id_name' => $call_data['Caller-Callee-ID-Name'] ?? $call_data['callee_id_name'] ?? null,
                'callee_id_number' => $call_data['Caller-Callee-ID-Number'] ?? $call_data['callee_id_number'] ?? null,
                'destination_number' => $call_data['Caller-Destination-Number'] ?? $call_data['destination_number'] ?? null,
                'network_addr' => $call_data['Caller-Network-Addr'] ?? $call_data['network_addr'] ?? null
            ],
            'timing' => [
                'created_time' => $call_data['Caller-Channel-Created-Time'] ?? null,
                'answered_time' => $call_data['Caller-Channel-Answered-Time'] ?? null,
                'progress_time' => $call_data['Caller-Channel-Progress-Time'] ?? null
            ],
            'context' => [
                'context' => $call_data['Caller-Context'] ?? $call_data['context'] ?? null,
                'dialplan' => $call_data['Caller-Dialplan'] ?? $call_data['dialplan'] ?? null
            ],
            'sip_info' => [
                'sip_from_user' => $call_data['variable_sip_from_user'] ?? $call_data['sip_from_user'] ?? null,
                'sip_from_host' => $call_data['variable_sip_from_host'] ?? $call_data['sip_from_host'] ?? null,
                'sip_to_user' => $call_data['variable_sip_to_user'] ?? $call_data['sip_to_user'] ?? null,
                'sip_to_host' => $call_data['variable_sip_to_host'] ?? $call_data['sip_to_host'] ?? null,
                'sip_call_id' => $call_data['variable_sip_call_id'] ?? $call_data['sip_call_id'] ?? null,
                'sip_user_agent' => $call_data['variable_sip_user_agent'] ?? $call_data['sip_user_agent'] ?? null
            ],
            'bridge_info' => [
                'bridge_uuid' => $call_data['Bridge-UUID'] ?? $call_data['bridge_uuid'] ?? $call_data['variable_bridge_uuid'] ?? null,
                'other_leg_uuid' => $call_data['Other-Leg-Unique-ID'] ?? $call_data['variable_other_leg_uuid'] ?? null
            ],
            'application' => [
                'current_application' => $call_data['variable_current_application'] ?? null,
                'current_application_data' => $call_data['variable_current_application_data'] ?? null
            ],
            'domain_info' => [
                'domain_uuid' => $call_data['variable_domain_uuid'] ?? null,
                'domain_name' => $call_data['variable_domain_name'] ?? null,
                'user_uuid' => $call_data['variable_user_uuid'] ?? null,
                'extension_uuid' => $call_data['variable_extension_uuid'] ?? null
            ]
        ];

        // Calculate duration if we have created time
        if (!empty($call_data['Caller-Channel-Created-Time'])) {
            $created_usec = intval($call_data['Caller-Channel-Created-Time']);
            $created_sec = $created_usec / 1000000;
            $duration = time() - $created_sec;
            $response['timing']['duration_seconds'] = round($duration);
            $response['timing']['duration_formatted'] = gmdate("H:i:s", round($duration));
        }

        return $response;

    } catch (Exception $e) {
        return ['error' => 'Error fetching call details: ' . $e->getMessage()];
    }
}
?>
