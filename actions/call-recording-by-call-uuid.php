<?php

$required_params = array("call_uuid");

function do_action($body) {
    global $domain_uuid;

    $call_uuid = $body->call_uuid;

    // Query v_xml_cdr to find the call and its recording
    $sql = "SELECT xml_cdr_uuid, domain_uuid, record_path, record_name,
                   caller_id_name, caller_id_number, destination_number,
                   direction, start_stamp, end_stamp, duration, billsec,
                   hangup_cause, bridge_uuid, call_uuid
            FROM v_xml_cdr
            WHERE (xml_cdr_uuid = :call_uuid OR bridge_uuid = :call_uuid OR call_uuid = :call_uuid)
            AND record_name IS NOT NULL
            AND record_name != ''
            ORDER BY start_stamp DESC
            LIMIT 1";

    $parameters = array("call_uuid" => $call_uuid);

    $database = new database;
    $cdr_record = $database->select($sql, $parameters, "row");

    if (!$cdr_record) {
        // Try searching in view_call_recordings
        $sql2 = "SELECT call_recording_uuid, domain_uuid, call_recording_path, call_recording_name,
                        caller_id_name, caller_id_number, destination_number,
                        call_direction, call_recording_date, call_recording_length
                 FROM view_call_recordings
                 WHERE call_recording_uuid = :call_uuid
                 LIMIT 1";

        $recording = $database->select($sql2, $parameters, "row");

        if (!$recording) {
            return array(
                "error" => "No call recording found for this call UUID",
                "searchedUuid" => $call_uuid
            );
        }

        // Return recording from view_call_recordings
        $result = array(
            "success" => true,
            "source" => "view_call_recordings",
            "callRecordingUuid" => $recording["call_recording_uuid"],
            "domainUuid" => $recording["domain_uuid"],
            "recordPath" => $recording["call_recording_path"],
            "recordName" => $recording["call_recording_name"],
            "callerIdName" => $recording["caller_id_name"],
            "callerIdNumber" => $recording["caller_id_number"],
            "destinationNumber" => $recording["destination_number"],
            "callDirection" => $recording["call_direction"],
            "callDate" => $recording["call_recording_date"],
            "duration" => $recording["call_recording_length"]
        );

        // Check if file exists and add file info
        $full_path = $recording["call_recording_path"] . "/" . $recording["call_recording_name"];
        if (file_exists($full_path)) {
            $result["fileExists"] = true;
            $result["fileSize"] = filesize($full_path);
            $result["mimeType"] = mime_content_type($full_path) ?: "audio/wav";
        } else {
            $result["fileExists"] = false;
        }

        // Include base64 content if requested
        if (isset($body->return_base64) && $body->return_base64 === true) {
            if ($result["fileExists"] && $result["fileSize"] <= 10 * 1024 * 1024) {
                $file_content = file_get_contents($full_path);
                if ($file_content !== false) {
                    $result["base64Content"] = base64_encode($file_content);
                }
            }
        }

        return $result;
    }

    // Found in v_xml_cdr
    $record_path = $cdr_record["record_path"];
    $record_name = $cdr_record["record_name"];

    $result = array(
        "success" => true,
        "source" => "v_xml_cdr",
        "xmlCdrUuid" => $cdr_record["xml_cdr_uuid"],
        "callUuid" => $cdr_record["call_uuid"],
        "bridgeUuid" => $cdr_record["bridge_uuid"],
        "domainUuid" => $cdr_record["domain_uuid"],
        "recordPath" => $record_path,
        "recordName" => $record_name,
        "callerIdName" => $cdr_record["caller_id_name"],
        "callerIdNumber" => $cdr_record["caller_id_number"],
        "destinationNumber" => $cdr_record["destination_number"],
        "callDirection" => $cdr_record["direction"],
        "startStamp" => $cdr_record["start_stamp"],
        "endStamp" => $cdr_record["end_stamp"],
        "duration" => $cdr_record["duration"],
        "billsec" => $cdr_record["billsec"],
        "hangupCause" => $cdr_record["hangup_cause"]
    );

    // Check if recording file exists
    if (!empty($record_path) && !empty($record_name)) {
        $full_path = $record_path . "/" . $record_name;

        if (file_exists($full_path)) {
            $result["fileExists"] = true;
            $result["fileSize"] = filesize($full_path);
            $result["filePath"] = $full_path;

            // Determine MIME type
            $mime_type = mime_content_type($full_path);
            if (!$mime_type || $mime_type == 'application/octet-stream') {
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
            $result["mimeType"] = $mime_type;

            // Include base64 content if requested
            if (isset($body->return_base64) && $body->return_base64 === true) {
                $file_size = filesize($full_path);
                if ($file_size <= 10 * 1024 * 1024) {
                    $file_content = file_get_contents($full_path);
                    if ($file_content !== false) {
                        $result["base64Content"] = base64_encode($file_content);
                    } else {
                        $result["base64Error"] = "Could not read file content";
                    }
                } else {
                    $result["base64Error"] = "File too large for base64 encoding (max 10MB)";
                }
            }
        } else {
            $result["fileExists"] = false;
            $result["fileError"] = "Recording file not found on server";
            $result["expectedPath"] = $full_path;
        }
    } else {
        $result["fileExists"] = false;
        $result["fileError"] = "Recording path or name is empty";
    }

    return $result;
}
