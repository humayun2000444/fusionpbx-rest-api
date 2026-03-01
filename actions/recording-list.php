<?php
$required_params = array();

function do_action($body) {
    global $domain_uuid;

    // Get domain_uuid - use provided or global
    $rec_domain_uuid = isset($body->domain_uuid) ? $body->domain_uuid : $domain_uuid;

    // Get domain name
    $sql = "SELECT domain_name FROM v_domains WHERE domain_uuid = :domain_uuid";
    $parameters = array("domain_uuid" => $rec_domain_uuid);
    $database = new database;
    $domain = $database->select($sql, $parameters, "row");
    $domain_name = $domain ? $domain["domain_name"] : "";

    // Build query
    $sql = "SELECT recording_uuid, domain_uuid, recording_filename, recording_name,
            recording_description, insert_date, update_date
            FROM v_recordings
            WHERE domain_uuid = :domain_uuid ";

    $parameters = array("domain_uuid" => $rec_domain_uuid);

    // Search filter
    if (isset($body->search) && !empty($body->search)) {
        $search = '%' . strtolower($body->search) . '%';
        $sql .= "AND (LOWER(recording_filename) LIKE :search
                 OR LOWER(recording_name) LIKE :search
                 OR LOWER(recording_description) LIKE :search) ";
        $parameters["search"] = $search;
    }

    $sql .= "ORDER BY recording_name ASC, recording_filename ASC";

    // Pagination
    if (isset($body->limit) && is_numeric($body->limit)) {
        $sql .= " LIMIT " . intval($body->limit);
        if (isset($body->offset) && is_numeric($body->offset)) {
            $sql .= " OFFSET " . intval($body->offset);
        }
    }

    $database = new database;
    $recordings = $database->select($sql, $parameters, "all");

    if (!$recordings) {
        $recordings = array();
    }

    // Get recordings directory path
    $settings = new settings(["domain_uuid" => $rec_domain_uuid]);
    $switch_recordings = $settings->get('switch', 'recordings', '/var/lib/freeswitch/recordings');

    // Add file info and URL for each recording
    foreach ($recordings as &$recording) {
        $file_path = $switch_recordings . '/' . $domain_name . '/' . $recording['recording_filename'];

        if (file_exists($file_path)) {
            $recording['file_exists'] = true;
            $recording['file_size'] = filesize($file_path);
            $recording['file_size_formatted'] = format_file_size(filesize($file_path));
        } else {
            $recording['file_exists'] = false;
            $recording['file_size'] = 0;
            $recording['file_size_formatted'] = '0 B';
        }

        $recording['domain_name'] = $domain_name;
    }

    // Get total count
    $sql_count = "SELECT COUNT(*) as total FROM v_recordings WHERE domain_uuid = :domain_uuid";
    $params_count = array("domain_uuid" => $rec_domain_uuid);
    $database = new database;
    $count_result = $database->select($sql_count, $params_count, "row");
    $total = $count_result ? $count_result['total'] : 0;

    return array(
        "count" => count($recordings),
        "total" => intval($total),
        "domain_name" => $domain_name,
        "recordings" => $recordings
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
