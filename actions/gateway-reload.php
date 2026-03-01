<?php
$required_params = array();

function do_action($body) {
    global $config;

    // Get the profile (default to external)
    $profile = isset($body->profile) ? $body->profile : "external";

    // Determine which command to run
    $command = "sofia profile " . $profile . " rescan reloadxml";

    // Try using FusionPBX's event socket functions
    if (function_exists('event_socket_create')) {
        // Get event socket settings from session or config
        $event_socket_ip = isset($_SESSION['event_socket_ip_address']) ? $_SESSION['event_socket_ip_address'] : '127.0.0.1';
        $event_socket_port = isset($_SESSION['event_socket_port']) ? $_SESSION['event_socket_port'] : '8021';
        $event_socket_password = isset($_SESSION['event_socket_password']) ? $_SESSION['event_socket_password'] : 'ClueCon';

        $fp = @event_socket_create($event_socket_ip, $event_socket_port, $event_socket_password);

        if ($fp && is_resource($fp)) {
            $response = event_socket_request($fp, 'api ' . $command);
            fclose($fp);

            return array(
                "success" => true,
                "message" => "Gateway profile '" . $profile . "' reloaded successfully",
                "command" => $command,
                "response" => trim($response)
            );
        }
    }

    // Fallback: Try using fs_cli directly
    $output = @shell_exec("/usr/bin/fs_cli -x '" . $command . "' 2>&1");

    if ($output !== null && !empty($output)) {
        return array(
            "success" => true,
            "message" => "Gateway profile '" . $profile . "' reloaded via fs_cli",
            "command" => $command,
            "response" => trim($output)
        );
    }

    // Another fallback: try standard fs_cli path
    $output = @shell_exec("fs_cli -x '" . $command . "' 2>&1");

    if ($output !== null && !empty($output)) {
        return array(
            "success" => true,
            "message" => "Gateway profile '" . $profile . "' reloaded via fs_cli",
            "command" => $command,
            "response" => trim($output)
        );
    }

    return array(
        "error" => "Failed to reload gateway profile. Event socket and fs_cli both failed.",
        "suggestion" => "Please manually run: sofia profile external rescan reloadxml"
    );
}
