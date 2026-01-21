<?php
$required_params = array("destination_uuid");

function do_action($body) {
    // Verify destination exists
    $sql = "SELECT destination_uuid, dialplan_uuid, destination_context FROM v_destinations WHERE destination_uuid = :destination_uuid";
    $parameters = array("destination_uuid" => $body->destination_uuid);
    $database = new database;
    $destination = $database->select($sql, $parameters, "row");

    if (!$destination) {
        return array("error" => "Destination not found");
    }

    $context = $destination['destination_context'];
    $dialplan_uuid = $destination['dialplan_uuid'];

    // Delete dialplan details if dialplan exists
    if ($dialplan_uuid) {
        $sql = "DELETE FROM v_dialplan_details WHERE dialplan_uuid = :dialplan_uuid";
        $parameters = array("dialplan_uuid" => $dialplan_uuid);
        $database = new database;
        $database->execute($sql, $parameters);

        // Delete dialplan
        $sql = "DELETE FROM v_dialplans WHERE dialplan_uuid = :dialplan_uuid";
        $database = new database;
        $database->execute($sql, $parameters);
    }

    // Delete destination
    $sql = "DELETE FROM v_destinations WHERE destination_uuid = :destination_uuid";
    $parameters = array("destination_uuid" => $body->destination_uuid);
    $database = new database;
    $database->execute($sql, $parameters);

    // Clear cache and reload dialplan
    $reload_output = "";
    $reload_success = false;

    if (class_exists('event_socket')) {
        $esl = event_socket::create();
        if ($esl) {
            if (class_exists('cache')) {
                $cache = new cache;
                $cache->delete("dialplan:" . $context);
            }
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
        "message" => "Destination deleted successfully",
        "destination_uuid" => $body->destination_uuid,
        "reloaded" => $reload_success,
        "reload_output" => trim($reload_output)
    );
}
