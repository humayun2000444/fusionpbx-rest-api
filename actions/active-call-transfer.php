<?php
/**
 * active-call-transfer.php
 * Transfers an active call to another destination
 */

$required_params = array('call_uuid', 'destination');

function do_action($body) {
    global $config;

    // Get call UUID (required)
    $call_uuid = isset($body->call_uuid) ? $body->call_uuid : (isset($body->uuid) ? $body->uuid : null);

    // Get transfer destination (required)
    $destination = isset($body->destination) ? $body->destination : (isset($body->transfer_to) ? $body->transfer_to : null);

    // Get dialplan and context (optional)
    $dialplan = isset($body->dialplan) ? $body->dialplan : 'XML';
    $context = isset($body->context) ? $body->context : null;

    if (empty($call_uuid)) {
        return ['error' => 'Call UUID is required'];
    }

    if (empty($destination)) {
        return ['error' => 'Transfer destination is required'];
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

        $result = null;

        if (function_exists('event_socket_create')) {
            $fp = @event_socket_create($event_socket_ip, $event_socket_port, $event_socket_password);

            if ($fp && is_resource($fp)) {
                // First check if the call exists
                $check_cmd = "uuid_exists " . $call_uuid;
                $exists_result = event_socket_request($fp, 'api ' . $check_cmd);

                if (trim($exists_result) !== 'true') {
                    fclose($fp);
                    return [
                        'error' => 'Call not found or no longer active',
                        'uuid' => $call_uuid
                    ];
                }

                // Get call context if not provided
                if (empty($context)) {
                    $ctx_cmd = "uuid_getvar " . $call_uuid . " context";
                    $context = trim(event_socket_request($fp, 'api ' . $ctx_cmd));
                    if (empty($context) || strpos($context, '-ERR') !== false) {
                        $context = 'default';
                    }
                }

                // Execute transfer command
                $cmd = "uuid_transfer " . $call_uuid . " " . $destination . " " . $dialplan . " " . $context;
                $result = event_socket_request($fp, 'api ' . $cmd);
                fclose($fp);
            }
        }

        // Fallback to fs_cli
        if ($result === null) {
            // Check if call exists
            $exists_result = @shell_exec("/usr/bin/fs_cli -x 'uuid_exists " . escapeshellarg($call_uuid) . "' 2>&1");
            if (!$exists_result) {
                $exists_result = @shell_exec("fs_cli -x 'uuid_exists " . escapeshellarg($call_uuid) . "' 2>&1");
            }

            if (trim($exists_result) !== 'true') {
                return [
                    'error' => 'Call not found or no longer active',
                    'uuid' => $call_uuid
                ];
            }

            // Get context if not provided
            if (empty($context)) {
                $context = 'default';
            }

            // Execute transfer
            $cmd = "uuid_transfer " . escapeshellarg($call_uuid) . " " . escapeshellarg($destination) . " " . $dialplan . " " . $context;
            $result = @shell_exec("/usr/bin/fs_cli -x '" . $cmd . "' 2>&1");
            if (!$result) {
                $result = @shell_exec("fs_cli -x '" . $cmd . "' 2>&1");
            }
        }

        // Check result
        if ($result === false || strpos($result, '-ERR') !== false) {
            return [
                'error' => 'Failed to transfer call',
                'uuid' => $call_uuid,
                'destination' => $destination,
                'result' => trim($result)
            ];
        }

        return [
            'success' => true,
            'message' => 'Call transferred successfully',
            'uuid' => $call_uuid,
            'destination' => $destination,
            'dialplan' => $dialplan,
            'context' => $context,
            'result' => trim($result)
        ];

    } catch (Exception $e) {
        return ['error' => 'Error transferring call: ' . $e->getMessage()];
    }
}
?>
