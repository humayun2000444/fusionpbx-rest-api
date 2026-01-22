<?php
$required_params = array("recording_uuid");

function do_action($body) {
    // Get recording details
    $sql = "SELECT r.*, d.domain_name
            FROM v_recordings r
            LEFT JOIN v_domains d ON r.domain_uuid = d.domain_uuid
            WHERE r.recording_uuid = :recording_uuid";
    $parameters = array("recording_uuid" => $body->recording_uuid);

    $database = new database;
    $recording = $database->select($sql, $parameters, "row");

    if (!$recording) {
        return array("error" => "Recording not found");
    }

    // Get recordings directory path
    $settings = new settings(["domain_uuid" => $recording['domain_uuid']]);
    $switch_recordings = $settings->get('switch', 'recordings', '/var/lib/freeswitch/recordings');

    $file_path = $switch_recordings . '/' . $recording['domain_name'] . '/' . $recording['recording_filename'];

    if (!file_exists($file_path)) {
        return array(
            "error" => "Recording file not found",
            "file_path" => $file_path,
            "recording_filename" => $recording['recording_filename']
        );
    }

    // Read file and encode to base64
    $file_content = file_get_contents($file_path);
    if ($file_content === false) {
        return array("error" => "Failed to read recording file");
    }

    $base64_content = base64_encode($file_content);

    // Determine mime type
    $extension = strtolower(pathinfo($recording['recording_filename'], PATHINFO_EXTENSION));
    $mime_type = 'audio/wav';
    if ($extension === 'mp3') {
        $mime_type = 'audio/mpeg';
    } elseif ($extension === 'ogg') {
        $mime_type = 'audio/ogg';
    }

    return array(
        "recording_uuid" => $recording['recording_uuid'],
        "recording_name" => $recording['recording_name'],
        "recording_filename" => $recording['recording_filename'],
        "mime_type" => $mime_type,
        "file_size" => filesize($file_path),
        "base64_content" => $base64_content
    );
}
