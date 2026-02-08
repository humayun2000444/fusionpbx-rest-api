<?php

$required_params = array();

function do_action($body) {
    global $domain_uuid;

    $sip_call_id = isset($body->sip_call_id) ? $body->sip_call_id : null;
    $fallback = isset($body->fallback) ? $body->fallback : null;
    $return_base64 = isset($body->return_base64) && $body->return_base64 === true;

    // Validate: need either sip_call_id or fallback
    if (empty($sip_call_id) && empty($fallback)) {
        return array("error" => "Either sip_call_id or fallback parameters required");
    }

    $database = new database;
    $recording = null;

    // Try 1: Search by sip_call_id
    if (!empty($sip_call_id)) {
        $sql = "SELECT
                    xml_cdr_uuid as call_recording_uuid,
                    domain_uuid,
                    sip_call_id,
                    direction as call_direction,
                    caller_id_name,
                    caller_id_number,
                    caller_destination,
                    destination_number,
                    start_stamp as call_recording_date,
                    duration as call_recording_length,
                    record_path as call_recording_path,
                    record_name as call_recording_name
                FROM v_xml_cdr
                WHERE sip_call_id = :sip_call_id
                AND record_name IS NOT NULL
                AND record_name != ''
                ORDER BY start_stamp DESC
                LIMIT 1";

        $recording = $database->select($sql, array("sip_call_id" => $sip_call_id), "row");
    }

    // Try 2: Fallback to caller_id_number + time window
    if (!$recording && !empty($fallback)) {
        $caller_id_number = isset($fallback->caller_id_number) ? $fallback->caller_id_number : null;
        $call_time = isset($fallback->call_time) ? $fallback->call_time : null;
        $time_window_seconds = isset($fallback->time_window_seconds) ? intval($fallback->time_window_seconds) : 30;

        if (empty($caller_id_number) || empty($call_time)) {
            return array("error" => "Fallback requires caller_id_number and call_time");
        }

        // Parse the call_time and create time window
        $call_timestamp = strtotime($call_time);
        if ($call_timestamp === false) {
            return array("error" => "Invalid call_time format. Use ISO 8601 format (e.g., 2026-02-03T09:43:43Z)");
        }

        $start_time = date('Y-m-d H:i:s', $call_timestamp - $time_window_seconds);
        $end_time = date('Y-m-d H:i:s', $call_timestamp + $time_window_seconds);

        // Clean caller number (remove country code prefix if present)
        $clean_caller = preg_replace('/^(\+?880|880)/', '', $caller_id_number);

        $sql = "SELECT
                    xml_cdr_uuid as call_recording_uuid,
                    domain_uuid,
                    sip_call_id,
                    direction as call_direction,
                    caller_id_name,
                    caller_id_number,
                    caller_destination,
                    destination_number,
                    start_stamp as call_recording_date,
                    duration as call_recording_length,
                    record_path as call_recording_path,
                    record_name as call_recording_name
                FROM v_xml_cdr
                WHERE (
                    caller_id_number LIKE :caller_pattern1
                    OR caller_id_number LIKE :caller_pattern2
                    OR caller_id_number = :caller_exact
                    OR destination_number LIKE :caller_pattern1
                    OR destination_number LIKE :caller_pattern2
                    OR destination_number = :caller_exact
                )
                AND start_stamp >= :start_time
                AND start_stamp <= :end_time
                AND record_name IS NOT NULL
                AND record_name != ''
                ORDER BY ABS(EXTRACT(EPOCH FROM (start_stamp - :call_time_ts::timestamp))) ASC
                LIMIT 1";

        $parameters = array(
            "caller_pattern1" => '%' . $clean_caller,
            "caller_pattern2" => '%' . $caller_id_number,
            "caller_exact" => $caller_id_number,
            "start_time" => $start_time,
            "end_time" => $end_time,
            "call_time_ts" => date('Y-m-d H:i:s', $call_timestamp)
        );

        $recording = $database->select($sql, $parameters, "row");
    }

    if (!$recording) {
        $search_info = array();
        if (!empty($sip_call_id)) {
            $search_info["sipCallId"] = $sip_call_id;
        }
        if (!empty($fallback)) {
            $search_info["fallback"] = array(
                "callerIdNumber" => isset($fallback->caller_id_number) ? $fallback->caller_id_number : null,
                "callTime" => isset($fallback->call_time) ? $fallback->call_time : null,
                "timeWindowSeconds" => isset($fallback->time_window_seconds) ? $fallback->time_window_seconds : 30
            );
        }
        return array(
            "error" => "Recording not found",
            "searchedWith" => $search_info
        );
    }

    // Build result in same format as call-recording-list
    $result = array(
        "success" => true,
        "callRecordingUuid" => $recording["call_recording_uuid"],
        "domainUuid" => $recording["domain_uuid"],
        "sipCallId" => $recording["sip_call_id"],
        "callerIdName" => $recording["caller_id_name"],
        "callerIdNumber" => $recording["caller_id_number"],
        "callerDestination" => $recording["caller_destination"],
        "destinationNumber" => $recording["destination_number"],
        "callRecordingName" => $recording["call_recording_name"],
        "callRecordingPath" => $recording["call_recording_path"],
        "callRecordingTranscription" => null,
        "callRecordingLength" => $recording["call_recording_length"],
        "callRecordingDate" => $recording["call_recording_date"],
        "callDirection" => $recording["call_direction"],
        "matchedBy" => !empty($sip_call_id) && $recording["sip_call_id"] == $sip_call_id ? "sipCallId" : "fallback"
    );

    // Check if file exists
    $full_path = $recording["call_recording_path"] . "/" . $recording["call_recording_name"];
    if (file_exists($full_path)) {
        $result["fileExists"] = true;
        $result["fileSize"] = filesize($full_path);

        // Determine MIME type
        $ext = strtolower(pathinfo($recording["call_recording_name"], PATHINFO_EXTENSION));
        switch ($ext) {
            case 'mp3':
                $result["mimeType"] = "audio/mpeg";
                break;
            case 'wav':
                $result["mimeType"] = "audio/wav";
                break;
            case 'ogg':
                $result["mimeType"] = "audio/ogg";
                break;
            default:
                $result["mimeType"] = "audio/mpeg";
        }

        // Include base64 if requested
        if ($return_base64) {
            $file_size = filesize($full_path);
            if ($file_size <= 10 * 1024 * 1024) {
                $result["base64Content"] = base64_encode(file_get_contents($full_path));
            } else {
                $result["base64Error"] = "File too large for base64 (max 10MB)";
            }
        }
    } else {
        $result["fileExists"] = false;
        $result["fileError"] = "Recording file not found on disk";
    }

    return $result;
}
