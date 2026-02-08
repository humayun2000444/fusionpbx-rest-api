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

    $zip_files = array();
    $current_zip_size = 0;
    $current_zip_index = 1;
    $current_zip = null;
    $current_zip_path = null;

    // Determine if we need multiple zips
    $need_split = $total_size > $max_size;

    foreach ($recordings as $recording) {
        // Check if we need to start a new zip
        if ($current_zip === null || ($need_split && ($current_zip_size + $recording["size"]) > $max_size)) {
            // Close previous zip if exists
            if ($current_zip !== null) {
                $current_zip->close();
                $zip_files[] = array(
                    "path" => $current_zip_path,
                    "name" => basename($current_zip_path),
                    "size" => filesize($current_zip_path)
                );
            }

            // Create new zip
            if ($need_split) {
                $current_zip_path = $temp_dir . "/recordings_part" . $current_zip_index . ".zip";
            } else {
                $current_zip_path = $temp_dir . "/recordings.zip";
            }

            $current_zip = new ZipArchive();
            if ($current_zip->open($current_zip_path, ZipArchive::CREATE) !== true) {
                // Cleanup
                array_map('unlink', glob("$temp_dir/*"));
                rmdir($temp_dir);
                return array("error" => "Failed to create zip file");
            }

            $current_zip_size = 0;
            $current_zip_index++;
        }

        // Add file to current zip
        $current_zip->addFile($recording["path"], $recording["name"]);
        $current_zip_size += $recording["size"];
    }

    // Close the last zip
    if ($current_zip !== null) {
        $current_zip->close();
        $zip_files[] = array(
            "path" => $current_zip_path,
            "name" => basename($current_zip_path),
            "size" => filesize($current_zip_path)
        );
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
