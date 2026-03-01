<?php
/**
 * call-recording-bulk-info.php
 * Returns recording file info for bulk download (Java will create the zip)
 * Supports both UUID list and date range queries
 */

$required_params = array();

function do_action($body) {
    global $domain_uuid;

    $database = new database;
    $recordings = array();

    // Get domain_uuid from request or use global
    $req_domain_uuid = isset($body->domain_uuid) ? $body->domain_uuid : $domain_uuid;

    // Option 1: Download by UUID list
    if (isset($body->call_recording_uuids) && is_array($body->call_recording_uuids)) {
        $uuids = $body->call_recording_uuids;

        foreach ($uuids as $uuid) {
            $record = get_recording_by_uuid($database, $uuid);
            if ($record) {
                $recordings[] = $record;
            }
        }
    }
    // Option 2: Download by date range
    else if (isset($body->start_date) && isset($body->end_date)) {
        $start_date = $body->start_date;
        $end_date = $body->end_date;

        // Validate dates
        if (!preg_match('/^\d{4}-\d{2}-\d{2}/', $start_date) || !preg_match('/^\d{4}-\d{2}-\d{2}/', $end_date)) {
            return array("error" => "Invalid date format. Use YYYY-MM-DD");
        }

        // Query recordings by date range
        $sql = "SELECT
                    xml_cdr_uuid as call_recording_uuid,
                    record_path as call_recording_path,
                    record_name as call_recording_name,
                    start_stamp,
                    caller_id_name,
                    caller_id_number,
                    destination_number,
                    duration
                FROM v_xml_cdr
                WHERE domain_uuid = :domain_uuid
                AND start_stamp >= :start_date
                AND start_stamp < :end_date::date + interval '1 day'
                AND record_name IS NOT NULL
                AND record_name != ''
                ORDER BY start_stamp DESC";

        $params = array(
            "domain_uuid" => $req_domain_uuid,
            "start_date" => $start_date,
            "end_date" => $end_date
        );

        $results = $database->select($sql, $params, "all");

        if ($results && is_array($results)) {
            foreach ($results as $row) {
                $full_path = $row["call_recording_path"] . "/" . $row["call_recording_name"];
                if (file_exists($full_path)) {
                    $recordings[] = array(
                        "uuid" => $row["call_recording_uuid"],
                        "path" => $full_path,
                        "name" => $row["call_recording_name"],
                        "size" => filesize($full_path),
                        "date" => $row["start_stamp"],
                        "caller" => $row["caller_id_number"],
                        "destination" => $row["destination_number"],
                        "duration" => $row["duration"]
                    );
                }
            }
        }
    }
    else {
        return array("error" => "Either call_recording_uuids array or start_date/end_date is required");
    }

    if (count($recordings) === 0) {
        return array("error" => "No valid recordings found");
    }

    // Calculate totals
    $total_size = array_sum(array_column($recordings, 'size'));

    // Split into batches (max 100MB per batch for practical download)
    $max_batch_size = 100 * 1024 * 1024; // 100MB
    $batches = array();
    $current_batch = array();
    $current_batch_size = 0;

    foreach ($recordings as $recording) {
        if ($current_batch_size + $recording["size"] > $max_batch_size && count($current_batch) > 0) {
            $batches[] = array(
                "recordings" => $current_batch,
                "totalSize" => $current_batch_size,
                "count" => count($current_batch)
            );
            $current_batch = array();
            $current_batch_size = 0;
        }
        $current_batch[] = $recording;
        $current_batch_size += $recording["size"];
    }

    if (count($current_batch) > 0) {
        $batches[] = array(
            "recordings" => $current_batch,
            "totalSize" => $current_batch_size,
            "count" => count($current_batch)
        );
    }

    return array(
        "success" => true,
        "totalRecordings" => count($recordings),
        "totalSize" => $total_size,
        "totalSizeFormatted" => format_size($total_size),
        "batchCount" => count($batches),
        "batches" => $batches
    );
}

function get_recording_by_uuid($database, $uuid) {
    // Try view_call_recordings first
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

    if ($record) {
        $full_path = $record["call_recording_path"] . "/" . $record["call_recording_name"];
        if (file_exists($full_path)) {
            return array(
                "uuid" => $record["call_recording_uuid"],
                "path" => $full_path,
                "name" => $record["call_recording_name"],
                "size" => filesize($full_path)
            );
        }
    }

    return null;
}

function format_size($bytes) {
    $units = array('B', 'KB', 'MB', 'GB');
    $i = 0;
    while ($bytes >= 1024 && $i < count($units) - 1) {
        $bytes /= 1024;
        $i++;
    }
    return round($bytes, 2) . ' ' . $units[$i];
}
