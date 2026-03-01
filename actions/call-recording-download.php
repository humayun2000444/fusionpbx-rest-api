<?php

$required_params = array("call_recording_uuid");

function do_action($body) {
    global $domain_uuid;

    $call_recording_uuid = $body->call_recording_uuid;

    // First try view_call_recordings (for call recordings from the recordings view)
    $sql = "SELECT call_recording_uuid, domain_uuid, call_recording_path, call_recording_name,
                   caller_id_name, caller_id_number, destination_number, call_direction, call_recording_date
            FROM view_call_recordings
            WHERE call_recording_uuid = :call_recording_uuid";

    $parameters = array("call_recording_uuid" => $call_recording_uuid);

    $database = new database;
    $recording = $database->select($sql, $parameters, "row");

    // If not found in view_call_recordings, try v_xml_cdr (for CDR recordings)
    if (!$recording) {
        $sql = "SELECT xml_cdr_uuid as call_recording_uuid, domain_uuid, record_path as call_recording_path,
                       record_name as call_recording_name, caller_id_name, caller_id_number,
                       destination_number, direction as call_direction, start_stamp as call_recording_date
                FROM v_xml_cdr
                WHERE xml_cdr_uuid = :call_recording_uuid
                AND record_name IS NOT NULL
                AND record_name != ''";

        $recording = $database->select($sql, $parameters, "row");
    }

    if (!$recording) {
        return array("error" => "Call recording not found");
    }

    $record_path = $recording["call_recording_path"];
    $record_name = $recording["call_recording_name"];

    if (empty($record_path) || empty($record_name)) {
        return array("error" => "Recording file path not available");
    }

    $full_path = $record_path . "/" . $record_name;

    // Debug: Log the path being accessed
    error_log("Call recording download - UUID: " . $call_recording_uuid . ", Path: " . $full_path);

    if (!file_exists($full_path)) {
        return array(
            "error" => "Recording file not found on server",
            "debug_path" => $full_path,
            "debug_record_path" => $record_path,
            "debug_record_name" => $record_name
        );
    }

    // Check if base64 content is requested
    $return_base64 = isset($body->return_base64) && $body->return_base64 === true;

    // Determine MIME type
    $mime_type = mime_content_type($full_path);
    if (!$mime_type || $mime_type == 'application/octet-stream') {
        // Fallback based on extension
        $ext = strtolower(pathinfo($record_name, PATHINFO_EXTENSION));
        switch ($ext) {
            case 'wav':
                $mime_type = 'audio/wav';
                break;
            case 'mp3':
                $mime_type = 'audio/mpeg';
                break;
            case 'ogg':
                $mime_type = 'audio/ogg';
                break;
            default:
                $mime_type = 'audio/wav';
        }
    }

    $result = array(
        "success" => true,
        "callRecordingUuid" => $call_recording_uuid,
        "fileName" => $record_name,
        "filePath" => $full_path,
        "fileSize" => filesize($full_path),
        "mimeType" => $mime_type,
        "callerIdName" => $recording["caller_id_name"],
        "callerIdNumber" => $recording["caller_id_number"],
        "destinationNumber" => $recording["destination_number"],
        "callDirection" => $recording["call_direction"],
        "callDate" => $recording["call_recording_date"]
    );

    // Include base64 encoded content if requested (for small files only)
    if ($return_base64) {
        $file_size = filesize($full_path);
        // Limit base64 to files under 10MB to avoid memory issues
        if ($file_size > 10 * 1024 * 1024) {
            $result["base64Error"] = "File too large for base64 encoding (max 10MB)";
        } else {
            $file_content = file_get_contents($full_path);
            if ($file_content === false) {
                $result["base64Error"] = "Could not read file content";
            } else {
                $result["base64Content"] = base64_encode($file_content);
            }
        }
    }

    // Build a download URL
    $result["downloadUrl"] = "/app/call_recordings/call_recording_play.php?id=" . $call_recording_uuid;

    return $result;
}
