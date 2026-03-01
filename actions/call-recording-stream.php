<?php
/**
 * call-recording-stream.php
 * Stream a recording file directly (for Java to download and zip)
 */

$required_params = array("call_recording_uuid");

function do_action($body) {
    global $domain_uuid;

    $uuid = $body->call_recording_uuid;
    $database = new database;

    // Get recording info
    $sql = "SELECT
                call_recording_uuid, call_recording_path, call_recording_name
            FROM view_call_recordings
            WHERE call_recording_uuid = :uuid";

    $record = $database->select($sql, array("uuid" => $uuid), "row");

    if (!$record) {
        // Try v_xml_cdr
        $sql2 = "SELECT
                    xml_cdr_uuid as call_recording_uuid,
                    record_path as call_recording_path,
                    record_name as call_recording_name
                FROM v_xml_cdr
                WHERE xml_cdr_uuid = :uuid
                AND record_name IS NOT NULL AND record_name != ''";
        $record = $database->select($sql2, array("uuid" => $uuid), "row");
    }

    if (!$record) {
        return array("error" => "Recording not found");
    }

    $full_path = $record["call_recording_path"] . "/" . $record["call_recording_name"];

    if (!file_exists($full_path)) {
        return array("error" => "Recording file not found on disk");
    }

    // Return file info with base64 content for single file
    $file_size = filesize($full_path);

    // For files under 50MB, return base64
    if ($file_size <= 50 * 1024 * 1024) {
        return array(
            "success" => true,
            "uuid" => $record["call_recording_uuid"],
            "fileName" => $record["call_recording_name"],
            "fileSize" => $file_size,
            "mimeType" => "audio/wav",
            "base64Content" => base64_encode(file_get_contents($full_path))
        );
    } else {
        return array(
            "error" => "File too large. Use direct download.",
            "uuid" => $record["call_recording_uuid"],
            "fileName" => $record["call_recording_name"],
            "fileSize" => $file_size
        );
    }
}
