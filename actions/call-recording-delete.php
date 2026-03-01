<?php

$required_params = array("call_recording_uuid");

function do_action($body) {
    global $domain_uuid;

    $call_recording_uuid = $body->call_recording_uuid;

    // Get recording details first to get the file path
    $sql = "SELECT xml_cdr_uuid, domain_uuid, record_path, record_name
            FROM v_xml_cdr
            WHERE xml_cdr_uuid = :call_recording_uuid";

    $parameters = array("call_recording_uuid" => $call_recording_uuid);

    $database = new database;
    $recording = $database->select($sql, $parameters, "row");

    if (!$recording) {
        return array("error" => "Call recording not found");
    }

    $record_path = $recording["record_path"];
    $record_name = $recording["record_name"];
    $deleted_file = false;

    // Delete the recording file if it exists
    if (!empty($record_path) && !empty($record_name)) {
        $full_path = $record_path . "/" . $record_name;
        if (file_exists($full_path)) {
            if (unlink($full_path)) {
                $deleted_file = true;
            }
        }
    }

    // Update the CDR record to remove recording reference
    // We don't delete the CDR record itself, just clear the recording fields
    $sql = "UPDATE v_xml_cdr SET
                record_path = NULL,
                record_name = NULL,
                record_length = NULL,
                record_transcription = NULL
            WHERE xml_cdr_uuid = :call_recording_uuid";

    $parameters = array("call_recording_uuid" => $call_recording_uuid);

    $database = new database;
    $database->execute($sql, $parameters);

    return array(
        "success" => true,
        "message" => "Call recording deleted successfully",
        "callRecordingUuid" => $call_recording_uuid,
        "fileDeleted" => $deleted_file
    );
}
