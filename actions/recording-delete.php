<?php
$required_params = array("recording_uuid");

function do_action($body) {
    // Get recording details first
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

    // Delete the file
    $file_path = $switch_recordings . '/' . $recording['domain_name'] . '/' . $recording['recording_filename'];
    $file_deleted = false;

    if (file_exists($file_path)) {
        $file_deleted = unlink($file_path);
    }

    // Delete from database
    $sql = "DELETE FROM v_recordings WHERE recording_uuid = :recording_uuid";
    $parameters = array("recording_uuid" => $body->recording_uuid);

    $database = new database;
    $database->execute($sql, $parameters);

    return array(
        "success" => true,
        "message" => "Recording deleted successfully",
        "recording_uuid" => $body->recording_uuid,
        "recording_filename" => $recording['recording_filename'],
        "file_deleted" => $file_deleted
    );
}
