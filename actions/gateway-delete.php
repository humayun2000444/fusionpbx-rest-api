<?php
$required_params = array("gateway_uuid");

function do_action($body) {
    // Check if gateway exists and get profile
    $sql = "SELECT gateway_uuid, gateway, profile FROM v_gateways WHERE gateway_uuid = :gateway_uuid";
    $parameters["gateway_uuid"] = $body->gateway_uuid;
    $database = new database;
    $existing = $database->select($sql, $parameters, "row");

    if(!$existing) {
        return array("error" => "Gateway not found");
    }
    unset($parameters);

    // Get profile for reload
    $profile = isset($existing["profile"]) ? $existing["profile"] : "external";
    $gateway_name = $existing["gateway"];

    // Delete the gateway
    $sql = "DELETE FROM v_gateways WHERE gateway_uuid = :gateway_uuid";
    $parameters["gateway_uuid"] = $body->gateway_uuid;
    $database = new database;
    $database->execute($sql, $parameters);

    $reload_output = "";
    $reload_success = false;

    // Use event socket like FusionPBX does
    if (class_exists('event_socket')) {
        $esl = event_socket::create();
        if ($esl) {
            // Get hostname for cache key
            $hostname = trim(event_socket::api('switchname'));

            // Clear the cache (same as FusionPBX)
            if (class_exists('cache')) {
                $cache = new cache;
                $cache->delete("configuration:sofia.conf:" . $hostname);
            }

            // Rescan the profile
            $reload_output = event_socket::api('sofia profile ' . $profile . ' rescan');
            usleep(500000); // Wait 0.5 seconds

            // Reload XML as well
            event_socket::api('reloadxml');

            $reload_success = true;
        }
    }

    // Fallback to fs_cli if event socket failed
    if (!$reload_success) {
        $reload_output = shell_exec("/usr/bin/fs_cli -x 'sofia profile " . $profile . " rescan' 2>&1");
        usleep(500000);
        shell_exec("/usr/bin/fs_cli -x 'reloadxml' 2>&1");
        $reload_success = ($reload_output !== null && strpos($reload_output, 'err') === false);
    }

    return array(
        "success" => true,
        "message" => "Gateway '" . $gateway_name . "' deleted successfully",
        "gateway_uuid" => $body->gateway_uuid,
        "reloaded" => $reload_success,
        "reload_output" => trim($reload_output)
    );
}
