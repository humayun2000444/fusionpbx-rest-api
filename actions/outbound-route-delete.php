<?php
$required_params = array("dialplan_uuid");

function do_action($body) {
    // Check if route exists and get info
    $sql = "SELECT dialplan_uuid, dialplan_name, dialplan_context FROM v_dialplans WHERE dialplan_uuid = :dialplan_uuid";
    $parameters = array("dialplan_uuid" => $body->dialplan_uuid);
    $database = new database;
    $existing = $database->select($sql, $parameters, "row");

    if(!$existing) {
        return array("error" => "Outbound route not found");
    }

    $dialplan_name = $existing["dialplan_name"];
    $context = $existing["dialplan_context"];
    unset($parameters);

    // Delete dialplan details first
    $sql = "DELETE FROM v_dialplan_details WHERE dialplan_uuid = :dialplan_uuid";
    $parameters = array("dialplan_uuid" => $body->dialplan_uuid);
    $database = new database;
    $database->execute($sql, $parameters);
    unset($parameters);

    // Delete the dialplan
    $sql = "DELETE FROM v_dialplans WHERE dialplan_uuid = :dialplan_uuid";
    $parameters = array("dialplan_uuid" => $body->dialplan_uuid);
    $database = new database;
    $database->execute($sql, $parameters);

    // Clear cache and reload dialplan
    $reload_output = "";
    $reload_success = false;

    if (class_exists('event_socket')) {
        $esl = event_socket::create();
        if ($esl) {
            $hostname = trim(event_socket::api('switchname'));

            // Clear dialplan cache
            if (class_exists('cache')) {
                $cache = new cache;
                $cache->delete("dialplan:" . $context);
            }

            // Reload XML
            $reload_output = event_socket::api('reloadxml');
            $reload_success = true;
        }
    }

    if (!$reload_success) {
        $reload_output = shell_exec("/usr/bin/fs_cli -x 'reloadxml' 2>&1");
        $reload_success = ($reload_output !== null);
    }

    return array(
        "success" => true,
        "message" => "Outbound route '" . $dialplan_name . "' deleted successfully",
        "dialplan_uuid" => $body->dialplan_uuid,
        "reloaded" => $reload_success,
        "reload_output" => trim($reload_output)
    );
}
