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

    // Get recordings directory path
    $settings = new settings(["domain_uuid" => $rec_domain_uuid]);
    $switch_recordings = $settings->get('switch', 'recordings', '/var/lib/freeswitch/recordings');

    // Create domain recordings directory if not exists
    $recording_dir = $switch_recordings . '/' . $domain_name;
    if (!is_dir($recording_dir)) {
        mkdir($recording_dir, 0770, true);
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
        return array("error" => "Failed to save recording file");
    }

    // Set proper permissions
    chmod($file_path, 0664);

    // Insert into database
    $sql = "INSERT INTO v_recordings (
                recording_uuid,
                domain_uuid,
                recording_filename,
                recording_name,
                recording_description,
                insert_date,
                insert_user
            ) VALUES (
                :recording_uuid,
                :domain_uuid,
                :recording_filename,
                :recording_name,
                :recording_description,
                NOW(),
                :insert_user
            )";

    $parameters = array(
        "recording_uuid" => $recording_uuid,
        "domain_uuid" => $rec_domain_uuid,
        "recording_filename" => $recording_filename,
        "recording_name" => $body->recording_name,
        "recording_description" => isset($body->recording_description) ? $body->recording_description : null,
        "insert_user" => isset($_SESSION['username']) ? $_SESSION['username'] : 'api'
    );

    $database = new database;
    $database->execute($sql, $parameters);

    // Return success with recording info
    return array(
        "success" => true,
        "message" => "Recording created successfully",
        "recording_uuid" => $recording_uuid,
        "recording_filename" => $recording_filename,
        "recording_name" => $body->recording_name,
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
