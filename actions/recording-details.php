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

    if (file_exists($file_path)) {
        $recording['file_exists'] = true;
        $recording['file_size'] = filesize($file_path);
        $recording['file_size_formatted'] = format_file_size(filesize($file_path));
        $recording['file_path'] = $file_path;
    } else {
        $recording['file_exists'] = false;
        $recording['file_size'] = 0;
        $recording['file_size_formatted'] = '0 B';
    }

    // Remove base64 from response (too large)
    unset($recording['recording_base64']);

    return $recording;
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
