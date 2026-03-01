<?php

$required_params = array("call_recording_uuids");

function do_action($body) {
    global $domain_uuid;

    $uuids = $body->call_recording_uuids;
    $max_size = 500 * 1024 * 1024; // 500MB limit per zip

    if (!is_array($uuids) || count($uuids) === 0) {
        return array("error" => "call_recording_uuids must be a non-empty array");
    }

    // Get recordings info from database
    $database = new database;
    $recordings = array();
    $total_size = 0;

    foreach ($uuids as $uuid) {
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
                $file_size = filesize($full_path);
                $recordings[] = array(
                    "uuid" => $record["call_recording_uuid"],
                    "path" => $full_path,
                    "name" => $record["call_recording_name"],
                    "size" => $file_size
                );
                $total_size += $file_size;
            }
        }
    }

    if (count($recordings) === 0) {
        return array("error" => "No valid recordings found");
    }

    // Create temp directory for zip files
    $temp_dir = "/tmp/call_recordings_" . uniqid();
    if (!mkdir($temp_dir, 0755, true)) {
        return array("error" => "Failed to create temp directory");
    }

    // Determine if we need multiple zips
    $need_split = $total_size > $max_size;

    // Group recordings into batches for zip files
    $batches = array();
    $current_batch = array();
    $current_batch_size = 0;
    $batch_index = 0;

    foreach ($recordings as $recording) {
        if ($need_split && ($current_batch_size + $recording["size"]) > $max_size && count($current_batch) > 0) {
            $batches[] = $current_batch;
            $current_batch = array();
            $current_batch_size = 0;
        }
        $current_batch[] = $recording;
        $current_batch_size += $recording["size"];
    }
    if (count($current_batch) > 0) {
        $batches[] = $current_batch;
    }

    $zip_files = array();

    // Create zip files using command line zip tool
    foreach ($batches as $index => $batch) {
        if ($need_split) {
            $zip_path = $temp_dir . "/recordings_part" . ($index + 1) . ".zip";
        } else {
            $zip_path = $temp_dir . "/recordings.zip";
        }

        // Build file list for zip command
        $file_args = "";
        foreach ($batch as $recording) {
            $file_args .= " " . escapeshellarg($recording["path"]);
        }

        // Create zip using command line (more portable than ZipArchive)
        $cmd = "cd " . escapeshellarg($temp_dir) . " && zip -j " . escapeshellarg(basename($zip_path)) . $file_args . " 2>&1";
        $output = shell_exec($cmd);

        if (file_exists($zip_path)) {
            $zip_files[] = array(
                "path" => $zip_path,
                "name" => basename($zip_path),
                "size" => filesize($zip_path)
            );
        } else {
            // Cleanup on error
            array_map('unlink', glob("$temp_dir/*"));
            rmdir($temp_dir);
            return array("error" => "Failed to create zip file: " . $output);
        }
    }

    // Convert zip files to base64
    $result_files = array();
    foreach ($zip_files as $zip_file) {
        $zip_size = filesize($zip_file["path"]);

        // Only encode if under 100MB (base64 will be ~133% larger)
        if ($zip_size <= 100 * 1024 * 1024) {
            $base64 = base64_encode(file_get_contents($zip_file["path"]));
            $result_files[] = array(
                "fileName" => $zip_file["name"],
                "fileSize" => $zip_size,
                "mimeType" => "application/zip",
                "base64Content" => $base64
            );
        } else {
            $result_files[] = array(
                "fileName" => $zip_file["name"],
                "fileSize" => $zip_size,
                "mimeType" => "application/zip",
                "error" => "File too large for base64 transfer"
            );
        }

        // Delete temp zip file
        unlink($zip_file["path"]);
    }

    // Cleanup temp directory
    rmdir($temp_dir);

    return array(
        "success" => true,
        "totalRecordings" => count($recordings),
        "totalSize" => $total_size,
        "totalSizeFormatted" => format_size($total_size),
        "zipFiles" => $result_files,
        "zipCount" => count($result_files)
    );
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
