<?php
/**
 * click-to-call.php
 * Originates an outbound call: rings the agent's extension first,
 * then bridges to the destination number via the XML dialplan.
 */

$required_params = array("agent_extension", "destination_number");

function do_action($body) {
    $agent_extension    = isset($body->agent_extension) ? $body->agent_extension : null;
    $destination_number = isset($body->destination_number) ? $body->destination_number : null;
    $caller_id_number   = isset($body->caller_id_number) ? $body->caller_id_number : $agent_extension;
    $domain_uuid        = isset($body->domain_uuid) ? $body->domain_uuid : "";
    $domain_name        = isset($body->domain_name) ? $body->domain_name : "";

    if (empty($agent_extension)) {
        return array("error" => "agent_extension is required");
    }
    if (empty($destination_number)) {
        return array("error" => "destination_number is required");
    }

    // Resolve domain_name if not provided
    if (empty($domain_name) && !empty($domain_uuid)) {
        global $db;
        if (isset($db)) {
            $sql = "SELECT domain_name FROM v_domains WHERE domain_uuid = :domain_uuid";
            $parameters = array();
            $parameters['domain_uuid'] = $domain_uuid;
            $row = $db->select($sql, $parameters, 'row');
            if (!empty($row['domain_name'])) {
                $domain_name = $row['domain_name'];
            }
        }
        if (empty($domain_name)) {
            $domain_name = "hippbx.btcliptelephony.gov.bd";
        }
    }

    // Build originate command: ring agent first, then connect to destination via dialplan
    $originate_cmd = "originate {origination_caller_id_number=" . $caller_id_number
        . ",origination_caller_id_name=" . $caller_id_number
        . ",domain_uuid=" . $domain_uuid
        . ",domain_name=" . $domain_name
        . ",ignore_early_media=true"
        . "}sofia/internal/" . $agent_extension . "@" . $domain_name
        . " " . $destination_number . " XML " . $domain_name;

    try {
        $event_socket_ip = isset($_SESSION["event_socket_ip_address"]) ? $_SESSION["event_socket_ip_address"] : "127.0.0.1";
        $event_socket_port = isset($_SESSION["event_socket_port"]) ? $_SESSION["event_socket_port"] : "8021";
        $event_socket_password = isset($_SESSION["event_socket_password"]) ? $_SESSION["event_socket_password"] : "ClueCon";

        $result = null;

        // Try ESL first
        if (function_exists("event_socket_create")) {
            $fp = @event_socket_create($event_socket_ip, $event_socket_port, $event_socket_password);

            if ($fp && is_resource($fp)) {
                $result = event_socket_request($fp, "api " . $originate_cmd);
                fclose($fp);
            }
        }

        // Fallback to fs_cli
        if ($result === null) {
            $result = @shell_exec("/usr/bin/fs_cli -x \"" . str_replace('"', '\\"', $originate_cmd) . "\" 2>&1");
        }

        if ($result !== null && strpos($result, "+OK") !== false) {
            $call_uuid = trim(str_replace("+OK ", "", $result));
            return array(
                "success" => true,
                "message" => "Call originated successfully",
                "call_uuid" => $call_uuid,
                "agent_extension" => $agent_extension,
                "destination" => $destination_number
            );
        }

        if ($result === false || $result === null || strpos($result, "-ERR") !== false) {
            return array("error" => "Failed to originate call: " . trim($result));
        }

        return array(
            "success" => true,
            "message" => "Originate command sent",
            "result" => trim($result)
        );

    } catch (Exception $e) {
        return array("error" => "Error originating call: " . $e->getMessage());
    }
}
?>
