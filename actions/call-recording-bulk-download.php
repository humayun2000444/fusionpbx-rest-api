<?php

$required_params = array("call_recording_uuids");

function do_action($body) {
    global $domain_uuid;

    $uuids = $body->call_recording_uuids;
    $max_size = 500 * 1024 * 1024; // 500MB limit per zip

    if (!is_array($uuids) || count($uuids) === 0) {
        return array("error" => "call_recording_uuids must be a non-empty array");
    }

    // Hashing re-reads every byte - a 500 MB batch means 500 MB of extra I/O -
    // so it is opt-in, same contract as call-recording-bulk-info.
    $want_checksums = !empty($body->include_checksum);

    // Get recordings info from database
    $database = new database;
    $recordings = array();
    $total_size = 0;

    foreach ($uuids as $uuid) {
        $sql = "SELECT
                    call_recording_uuid, call_recording_path, call_recording_name,
                    caller_id_number, destination_number, call_recording_date,
                    call_direction, call_recording_length
                FROM view_call_recordings
                WHERE call_recording_uuid = :uuid";

        $record = $database->select($sql, array("uuid" => $uuid), "row");

        if (!$record) {
            // Try v_xml_cdr
            $sql2 = "SELECT
                        xml_cdr_uuid as call_recording_uuid,
                        record_path as call_recording_path,
                        record_name as call_recording_name,
                        caller_id_number, destination_number,
                        start_stamp as call_recording_date,
                        direction as call_direction,
                        duration as call_recording_length
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
                    "size" => $file_size,
                    "caller" => isset($record["caller_id_number"]) ? $record["caller_id_number"] : null,
                    "destination" => isset($record["destination_number"]) ? $record["destination_number"] : null,
                    "date" => isset($record["call_recording_date"]) ? $record["call_recording_date"] : null,
                    "direction" => isset($record["call_direction"]) ? $record["call_direction"] : null,
                    "seconds" => isset($record["call_recording_length"]) ? $record["call_recording_length"] : null
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

        // A manifest travels with every archive. Two reasons it matters:
        //  - the files are named after a uuid, which says nothing about the
        //    call once the zip is sitting in someone's archive. The manifest is
        //    what makes 3f2a....mp3 mean "01712345678 called extension 1001 at
        //    18:37 on 17 Sep".
        //  - with include_checksum, the receiver can PROVE the transfer was
        //    complete before anything is marked exported or deleted.
        $manifest = array(
            "generated_at" => date('c'),
            "part"         => $index + 1,
            "parts"        => count($batches),
            "count"        => count($batch),
            "recordings"   => array(),
        );
        foreach ($batch as $recording) {
            $manifest["recordings"][] = array(
                "file"        => basename($recording["path"]),
                "uuid"        => $recording["uuid"],
                "caller"      => $recording["caller"],
                "destination" => $recording["destination"],
                "direction"   => $recording["direction"],
                "date"        => $recording["date"],
                "seconds"     => $recording["seconds"],
                "size"        => $recording["size"],
                "sha256"      => $want_checksums ? hash_file('sha256', $recording["path"]) : null,
            );
        }
        $manifest_path = $temp_dir . "/manifest.json";
        file_put_contents($manifest_path, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $file_args .= " " . escapeshellarg($manifest_path);

        // Create zip using command line (more portable than ZipArchive)
        $cmd = "cd " . escapeshellarg($temp_dir) . " && zip -j " . escapeshellarg(basename($zip_path)) . $file_args . " 2>&1";
        $output = shell_exec($cmd);

        if (file_exists($zip_path)) {
            $zip_files[] = array(
                "path" => $zip_path,
                "name" => basename($zip_path),
                "size" => filesize($zip_path),
                // Kept so the recordings in THIS archive can be marked exported
                // once the archive is actually delivered -- not before.
                "uuids" => array_map(function ($r) { return $r["uuid"]; }, $batch)
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
    // Only archives actually returned as bytes count as exported. A zip too
    // large to encode is handed back as a path; the customer has not received
    // it yet and must not be recorded as having done so.
    $delivered_uuids = array();
    foreach ($zip_files as $zip_file) {
        $zip_size = filesize($zip_file["path"]);

        // Only encode if under 100MB (base64 will be ~133% larger)
        if ($zip_size <= 100 * 1024 * 1024) {
            $base64 = base64_encode(file_get_contents($zip_file["path"]));
            // Delivered as bytes -> these are now in the customer's hands.
            $delivered_uuids = array_merge($delivered_uuids, $zip_file["uuids"]);
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

    // Mark after every archive is built and encoded, never per-file mid-loop:
    // a failure halfway through would otherwise leave recordings flagged as
    // collected that the customer never saw.
    if (!empty($delivered_uuids)) {
        $delivered_uuids = array_values(array_unique($delivered_uuids));
        foreach (array_chunk($delivered_uuids, 500) as $chunk) {
            $ph = array(); $params = array();
            foreach ($chunk as $i => $u) { $ph[] = ":u$i"; $params["u$i"] = $u; }
            $database->execute(
                "UPDATE v_xml_cdr SET exported_at = NOW()
                  WHERE xml_cdr_uuid IN (" . implode(",", $ph) . ") AND exported_at IS NULL",
                $params);
        }
    }

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
