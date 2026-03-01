<?php
/**
 * active-call-hold.php
 * Places/unplaces an active call on hold by UUID
 */

$required_params = array("call_uuid");

function do_action($body) {
    $call_uuid = isset($body->call_uuid) ? $body->call_uuid : (isset($body->uuid) ? $body->uuid : null);
    $hold_action = isset($body->hold_action) ? strtolower($body->hold_action) : "toggle";

    if (empty($call_uuid)) {
        return array("error" => "Call UUID is required");
    }

    if (!preg_match("/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i", $call_uuid)) {
        return array("error" => "Invalid call UUID format");
    }

    try {
        $event_socket_ip = isset($_SESSION["event_socket_ip_address"]) ? $_SESSION["event_socket_ip_address"] : "127.0.0.1";
        $event_socket_port = isset($_SESSION["event_socket_port"]) ? $_SESSION["event_socket_port"] : "8021";
        $event_socket_password = isset($_SESSION["event_socket_password"]) ? $_SESSION["event_socket_password"] : "ClueCon";

        $result = null;

        if (function_exists("event_socket_create")) {
            $fp = @event_socket_create($event_socket_ip, $event_socket_port, $event_socket_password);

            if ($fp && is_resource($fp)) {
                $exists_result = event_socket_request($fp, "api uuid_exists " . $call_uuid);
                if (trim($exists_result) !== "true") {
                    fclose($fp);
                    return array("error" => "Call not found or no longer active", "uuid" => $call_uuid);
                }

                switch ($hold_action) {
                    case "hold":
                        $cmd = "uuid_hold " . $call_uuid;
                        break;
                    case "unhold":
                        $cmd = "uuid_hold off " . $call_uuid;
                        break;
                    default:
                        $cmd = "uuid_hold toggle " . $call_uuid;
                        break;
                }

                $result = event_socket_request($fp, "api " . $cmd);
                fclose($fp);
            }
        }

        if ($result === null) {
            $exists_result = @shell_exec("/usr/bin/fs_cli -x \"uuid_exists " . escapeshellarg($call_uuid) . "\" 2>&1");
            if (trim($exists_result) !== "true") {
                return array("error" => "Call not found or no longer active", "uuid" => $call_uuid);
            }

            switch ($hold_action) {
                case "hold": $cmd = "uuid_hold " . escapeshellarg($call_uuid); break;
                case "unhold": $cmd = "uuid_hold off " . escapeshellarg($call_uuid); break;
                default: $cmd = "uuid_hold toggle " . escapeshellarg($call_uuid); break;
            }

            $result = @shell_exec("/usr/bin/fs_cli -x \"" . $cmd . "\" 2>&1");
        }

        if ($result === false || strpos($result, "-ERR") !== false) {
            return array("error" => "Failed to toggle hold", "uuid" => $call_uuid, "result" => trim($result));
        }

        return array(
            "success" => true,
            "message" => "Hold toggled successfully",
            "uuid" => $call_uuid,
            "hold_action" => $hold_action,
            "result" => trim($result)
        );

    } catch (Exception $e) {
        return array("error" => "Error toggling hold: " . $e->getMessage());
    }
}
?>
