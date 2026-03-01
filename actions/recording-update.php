<?php
$required_params = array("recording_uuid");

function do_action($body) {
    // Verify recording exists
    $sql = "SELECT * FROM v_recordings WHERE recording_uuid = :recording_uuid";
    $parameters = array("recording_uuid" => $body->recording_uuid);
    $database = new database;
    $recording = $database->select($sql, $parameters, "row");

    if (!$recording) {
        return array("error" => "Recording not found");
    }

    // Build update query dynamically based on provided fields
    $updates = array();
    $parameters = array("recording_uuid" => $body->recording_uuid);

    if (isset($body->recording_name)) {
        $updates[] = "recording_name = :recording_name";
        $parameters["recording_name"] = $body->recording_name;
    }

    if (isset($body->recording_description)) {
        $updates[] = "recording_description = :recording_description";
        $parameters["recording_description"] = $body->recording_description;
    }

    if (count($updates) == 0) {
        return array("error" => "No fields to update");
    }

    $updates[] = "update_date = NOW()";
    $sql = "UPDATE v_recordings SET " . implode(", ", $updates) . " WHERE recording_uuid = :recording_uuid";

    $database = new database;
    $database->execute($sql, $parameters);

    // Return updated recording
    $sql = "SELECT r.*, d.domain_name
            FROM v_recordings r
            LEFT JOIN v_domains d ON r.domain_uuid = d.domain_uuid
            WHERE r.recording_uuid = :recording_uuid";
    $parameters = array("recording_uuid" => $body->recording_uuid);

    $database = new database;
    $result = $database->select($sql, $parameters, "row");

    // Remove base64 from response
    unset($result['recording_base64']);

    $result["success"] = true;
    $result["message"] = "Recording updated successfully";

    return $result;
}
