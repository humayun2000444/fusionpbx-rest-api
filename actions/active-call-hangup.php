<?php
/**
 * active-call-hangup.php
 * Hangs up an active call by UUID
 */

$required_params = array('call_uuid');

function do_action($body) {
    global $config;

    // Get call UUID (required)
    $call_uuid = isset($body->call_uuid) ? $body->call_uuid : (isset($body->uuid) ? $body->uuid : null);

    // Get optional hangup cause
    $hangup_cause = isset($body->hangup_cause) ? strtoupper($body->hangup_cause) : 'NORMAL_CLEARING';

    if (empty($call_uuid)) {
        return ['error' => 'Call UUID is required'];
    }

    // Validate UUID format
    if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $call_uuid)) {
        return ['error' => 'Invalid call UUID format'];
    }

    // Valid hangup causes
    $valid_causes = [
        'NORMAL_CLEARING',
        'USER_BUSY',
        'NO_USER_RESPONSE',
        'NO_ANSWER',
        'CALL_REJECTED',
        'DESTINATION_OUT_OF_ORDER',
        'INVALID_NUMBER_FORMAT',
        'ORIGINATOR_CANCEL',
        'NORMAL_UNSPECIFIED',
        'MANAGER_REQUEST'
    ];

    if (!in_array($hangup_cause, $valid_causes)) {
        $hangup_cause = 'NORMAL_CLEARING';
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

                // Execute hangup command
                $cmd = "uuid_kill " . $call_uuid . " " . $hangup_cause;
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

            // Execute hangup
            $result = @shell_exec("/usr/bin/fs_cli -x 'uuid_kill " . escapeshellarg($call_uuid) . " " . $hangup_cause . "' 2>&1");
            if (!$result) {
                $result = @shell_exec("fs_cli -x 'uuid_kill " . escapeshellarg($call_uuid) . " " . $hangup_cause . "' 2>&1");
            }
        }

        // Check result
        if ($result === false || strpos($result, '-ERR') !== false) {
            return [
                'error' => 'Failed to hangup call',
                'uuid' => $call_uuid,
                'result' => trim($result)
            ];
        }

        return [
            'success' => true,
            'message' => 'Call terminated successfully',
            'uuid' => $call_uuid,
            'hangup_cause' => $hangup_cause,
            'result' => trim($result)
        ];

    } catch (Exception $e) {
        return ['error' => 'Error hanging up call: ' . $e->getMessage()];
    }
}
?>
