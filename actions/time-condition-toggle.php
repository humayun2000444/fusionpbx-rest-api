<?php

$required_params = array("dialplanUuid");

function do_action($body) {
    global $domain_uuid;

    $dialplan_uuid = isset($body->dialplanUuid) ? $body->dialplanUuid : $body->dialplan_uuid;

    $database = new database;

    // Get current state
    $sql = "SELECT dialplan_enabled, dialplan_name, dialplan_number
            FROM v_dialplans
            WHERE dialplan_uuid = :dialplan_uuid
            AND app_uuid = '4b821450-926b-175a-af93-a03c441818b1'";
    $existing = $database->select($sql, array("dialplan_uuid" => $dialplan_uuid), "row");
    if (!$existing) {
        return array("error" => "Time condition not found");
    }

    $new_enabled = ($existing['dialplan_enabled'] === 'true') ? 'false' : 'true';

    $sql_update = "UPDATE v_dialplans SET dialplan_enabled = :enabled, update_date = NOW()
                   WHERE dialplan_uuid = :dialplan_uuid";
    $database->execute($sql_update, array(
        "enabled" => $new_enabled,
        "dialplan_uuid" => $dialplan_uuid
    ));

    // Reload dialplan
    require_once "resources/switch.php";
    $esl = event_socket::create();
    if ($esl) {
        event_socket::api("reloadxml");
    }

    return array(
        "success" => true,
        "dialplanUuid" => $dialplan_uuid,
        "name" => $existing['dialplan_name'],
        "enabled" => $new_enabled,
        "message" => "Time condition " . ($new_enabled === 'true' ? 'enabled' : 'disabled')
    );
}
