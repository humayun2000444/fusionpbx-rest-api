<?php
$required_params = array("user", "profile");

function do_action($body) {
    $user = $body->user;
    $profile = $body->profile;

    // Sanitize inputs
    $user = preg_replace('#[^a-zA-Z0-9_\-\.\@]#', '', $user);
    $profile = preg_replace('#[^a-zA-Z0-9_\-\.]#', '', $profile);

    if (empty($user) || empty($profile)) {
        return array("error" => "Invalid user or profile");
    }

    // Validate profile exists
    $sql = "SELECT sip_profile_name FROM v_sip_profiles WHERE sip_profile_name = :profile AND sip_profile_enabled = 'true'";
    $database = new database;
    $result = $database->select($sql, array("profile" => $profile), "row");

    if (!$result) {
        return array("error" => "Invalid SIP profile: " . $profile);
    }

    // Build the unregister command
    $command = "sofia profile " . $profile . " flush_inbound_reg " . $user . " reboot";

    $output = "";
    $success = false;

    // Try event socket first
    if (class_exists('event_socket')) {
        $esl = event_socket::create();
        if ($esl) {
            $output = event_socket::api($command);
            $success = true;
        }
    }

    // Fallback to fs_cli
    if (!$success) {
        $output = shell_exec("/usr/bin/fs_cli -x '" . $command . "' 2>&1");
        $success = ($output !== null);
    }

    return array(
        "success" => $success,
        "user" => $user,
        "profile" => $profile,
        "command" => $command,
        "output" => trim($output),
        "message" => $success ? "Registration unregistered successfully" : "Failed to unregister"
    );
}
