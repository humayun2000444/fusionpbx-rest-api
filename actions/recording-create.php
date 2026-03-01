<?php
$required_params = array("recording_name", "recording_filename", "recording_base64");

function do_action($body) {
    global $domain_uuid;

    // Get domain_uuid - use provided or global
    $rec_domain_uuid = isset($body->domain_uuid) ? $body->domain_uuid : $domain_uuid;

    if (empty($rec_domain_uuid)) {
        return array("error" => "Domain UUID is required");
    }

    // Get domain name
    $sql = "SELECT domain_name FROM v_domains WHERE domain_uuid = :domain_uuid";
    $parameters = array("domain_uuid" => $rec_domain_uuid);
    $database = new database;
    $domain = $database->select($sql, $parameters, "row");

    if (!$domain) {
        return array("error" => "Domain not found");
    }

    $domain_name = $domain["domain_name"];

    // Generate new UUID for recording
    $recording_uuid = uuid();

    // Get recordings directory path from FusionPBX settings
    $settings = new settings(["domain_uuid" => $rec_domain_uuid]);
    $switch_recordings = $settings->get('switch', 'recordings', '/var/lib/freeswitch/recordings');

    // Create domain recordings directory if not exists
    $recording_dir = $switch_recordings . '/' . $domain_name;
    if (!is_dir($recording_dir)) {
        if (!mkdir($recording_dir, 0770, true)) {
            return array("error" => "Failed to create recordings directory: " . $recording_dir);
        }
    }

    // Sanitize filename
    $recording_filename = basename($body->recording_filename);
    $recording_filename = preg_replace('/[^a-zA-Z0-9._-]/', '_', $recording_filename);

    // Full file path
    $file_path = $recording_dir . '/' . $recording_filename;

    // Check if file already exists
    if (file_exists($file_path)) {
        // Add timestamp to make unique
        $path_parts = pathinfo($recording_filename);
        $recording_filename = $path_parts['filename'] . '_' . time() . '.' . $path_parts['extension'];
        $file_path = $recording_dir . '/' . $recording_filename;
    }

    // Decode and save the file
    $file_content = base64_decode($body->recording_base64);
    if ($file_content === false) {
        return array("error" => "Invalid base64 content");
    }

    if (file_put_contents($file_path, $file_content) === false) {
        return array("error" => "Failed to save recording file to: " . $file_path);
    }

    // Set proper permissions
    chmod($file_path, 0664);

    // Get recording name and description
    $recording_name = $body->recording_name;
    $recording_description = isset($body->recording_description) ? $body->recording_description : '';

    // Insert into database using FusionPBX ORM (same as recording_edit.php)
    $array['recordings'][0]['domain_uuid'] = $rec_domain_uuid;
    $array['recordings'][0]['recording_uuid'] = $recording_uuid;
    $array['recordings'][0]['recording_filename'] = $recording_filename;
    $array['recordings'][0]['recording_name'] = $recording_name;
    $array['recordings'][0]['recording_description'] = $recording_description;

    // Execute insert using database class
    $database = new database;
    $database->app_name = 'recordings';
    $database->app_uuid = '83913217-c7a2-9e90-925d-a866eb40b60e';
    $database->save($array);
    unset($array);

    // Verify the insert worked
    $sql = "SELECT recording_uuid FROM v_recordings WHERE recording_uuid = :recording_uuid";
    $parameters = array("recording_uuid" => $recording_uuid);
    $database = new database;
    $verify = $database->select($sql, $parameters, "row");

    if (!$verify) {
        // Try direct SQL insert as fallback
        $sql = "INSERT INTO v_recordings (
                    recording_uuid,
                    domain_uuid,
                    recording_filename,
                    recording_name,
                    recording_description
                ) VALUES (
                    :recording_uuid,
                    :domain_uuid,
                    :recording_filename,
                    :recording_name,
                    :recording_description
                )";
        $parameters = array(
            "recording_uuid" => $recording_uuid,
            "domain_uuid" => $rec_domain_uuid,
            "recording_filename" => $recording_filename,
            "recording_name" => $recording_name,
            "recording_description" => $recording_description
        );
        $database = new database;
        $database->execute($sql, $parameters);

        // Verify again
        $sql = "SELECT recording_uuid FROM v_recordings WHERE recording_uuid = :recording_uuid";
        $parameters = array("recording_uuid" => $recording_uuid);
        $database = new database;
        $verify = $database->select($sql, $parameters, "row");

        if (!$verify) {
            // Clean up the file if DB insert failed
            if (file_exists($file_path)) {
                unlink($file_path);
            }
            return array("error" => "Failed to insert recording into database");
        }
    }

    // Return success with recording info
    return array(
        "success" => true,
        "message" => "Recording created successfully",
        "recording_uuid" => $recording_uuid,
        "recording_filename" => $recording_filename,
        "recording_name" => $recording_name,
        "domain_uuid" => $rec_domain_uuid,
        "domain_name" => $domain_name,
        "file_path" => $file_path,
        "file_size" => filesize($file_path),
        "file_size_formatted" => format_file_size(filesize($file_path))
    );
}

function format_file_size($bytes) {
    if ($bytes >= 1073741824) {
        return number_format($bytes / 1073741824, 2) . ' GB';
    } elseif ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 2) . ' MB';
    } elseif ($bytes >= 1024) {
        return number_format($bytes / 1024, 2) . ' KB';
    } else {
        return $bytes . ' B';
    }
}
