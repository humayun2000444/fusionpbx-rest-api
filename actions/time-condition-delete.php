<?php

$required_params = array("dialplanUuid");

function do_action($body) {
    global $domain_uuid;

    $dialplan_uuid = isset($body->dialplanUuid) ? $body->dialplanUuid : $body->dialplan_uuid;

    $database = new database;

    // Verify it exists
    $sql_check = "SELECT d.dialplan_name, d.dialplan_number, dom.domain_name
                  FROM v_dialplans d
                  LEFT JOIN v_domains dom ON d.domain_uuid = dom.domain_uuid
                  WHERE d.dialplan_uuid = :dialplan_uuid
                  AND d.app_uuid = '4b821450-926b-175a-af93-a03c441818b1'";
    $existing = $database->select($sql_check, array("dialplan_uuid" => $dialplan_uuid), "row");
    if (!$existing) {
        return array("error" => "Time condition not found");
    }

    $name = $existing['dialplan_name'];
    $extension = $existing['dialplan_number'];

    // Delete dialplan details
    $sql_delete_details = "DELETE FROM v_dialplan_details WHERE dialplan_uuid = :dialplan_uuid";
    $database->execute($sql_delete_details, array("dialplan_uuid" => $dialplan_uuid));

    // Delete dialplan
    $sql_delete = "DELETE FROM v_dialplans WHERE dialplan_uuid = :dialplan_uuid";
    $database->execute($sql_delete, array("dialplan_uuid" => $dialplan_uuid));

    // Reload dialplan
    require_once "resources/switch.php";
    $esl = event_socket::create();
    $esl_result = null;
    if ($esl) {
        event_socket::api("reloadxml");
        $esl_result = "Dialplan reloaded";
    }

    return array(
        "success" => true,
        "dialplanUuid" => $dialplan_uuid,
        "name" => $name,
        "extension" => $extension,
        "message" => "Time condition deleted successfully",
        "eslResult" => $esl_result ?: "Event socket not available"
    );
}
