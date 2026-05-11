<?php

$required_params = array("speedDialUuid");

function do_action($body) {
    global $domain_uuid;

    $sd_uuid = isset($body->speedDialUuid) ? $body->speedDialUuid : $body->speed_dial_uuid;

    $database = new database;

    $existing = $database->select("SELECT * FROM v_speed_dials WHERE speed_dial_uuid = :uuid",
        array("uuid" => $sd_uuid), "row");
    if (!$existing) return array("error" => "Speed dial not found");

    $updates = array();
    $params = array("uuid" => $sd_uuid);

    $field_map = array(
        "speedDialNumber" => "speed_dial_number",
        "speedDialLabel" => "speed_dial_label",
        "speedDialCode" => "speed_dial_code",
        "enabled" => "enabled"
    );

    foreach ($field_map as $api_key => $db_key) {
        if (isset($body->$api_key)) {
            $updates[] = "$db_key = :$db_key";
            $params[$db_key] = $body->$api_key;
        }
    }

    if (!empty($updates)) {
        $updates[] = "update_date = NOW()";
        $sql = "UPDATE v_speed_dials SET " . implode(", ", $updates) . " WHERE speed_dial_uuid = :uuid";
        $database->execute($sql, $params);
    }

    return array(
        "success" => true,
        "speedDialUuid" => $sd_uuid,
        "message" => "Speed dial updated"
    );
}
