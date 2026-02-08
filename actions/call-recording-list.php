<?php

$required_params = array();

function do_action($body) {
    global $domain_uuid;

    // Get domain_uuid - use provided or global
    $cr_domain_uuid = isset($body->domain_uuid) ? $body->domain_uuid : $domain_uuid;

    // Build the SQL query using the view_call_recordings view
    $sql = "SELECT * FROM view_call_recordings WHERE 1=1 ";
    $parameters = array();

    // Filter by domain if provided
    if (!empty($cr_domain_uuid)) {
        $sql .= "AND domain_uuid = :domain_uuid ";
        $parameters["domain_uuid"] = $cr_domain_uuid;
    }

    // Filter by date range
    if (!empty($body->start_date)) {
        $sql .= "AND call_recording_date >= :start_date ";
        $parameters["start_date"] = $body->start_date;
    }

    if (!empty($body->end_date)) {
        $sql .= "AND call_recording_date <= :end_date ";
        $parameters["end_date"] = $body->end_date;
    }

    // Filter by caller
    if (!empty($body->caller_id_number)) {
        $sql .= "AND caller_id_number LIKE :caller_id_number ";
        $parameters["caller_id_number"] = "%" . $body->caller_id_number . "%";
    }

    // Filter by destination
    if (!empty($body->destination_number)) {
        $sql .= "AND destination_number LIKE :destination_number ";
        $parameters["destination_number"] = "%" . $body->destination_number . "%";
    }

    // Filter by call direction
    if (!empty($body->call_direction)) {
        $sql .= "AND call_direction = :call_direction ";
        $parameters["call_direction"] = $body->call_direction;
    }

    // Search across multiple fields
    if (!empty($body->search)) {
        $sql .= "AND (LOWER(caller_id_name) LIKE :search
                 OR caller_id_number LIKE :search
                 OR destination_number LIKE :search
                 OR call_recording_name LIKE :search) ";
        $parameters["search"] = "%" . strtolower($body->search) . "%";
    }

    // Order by date descending (most recent first)
    $sql .= "ORDER BY call_recording_date DESC ";

    // Pagination
    $limit = isset($body->limit) ? (int)$body->limit : 50;
    $offset = isset($body->offset) ? (int)$body->offset : 0;

    // Cap limit to prevent excessive queries
    if ($limit > 500) {
        $limit = 500;
    }

    $sql .= "LIMIT :limit OFFSET :offset";
    $parameters["limit"] = $limit;
    $parameters["offset"] = $offset;

    $database = new database;
    $recordings = $database->select($sql, $parameters, "all");

    if (!$recordings) {
        $recordings = array();
    }

    // Get total count for pagination
    $count_sql = "SELECT COUNT(*) as total FROM view_call_recordings WHERE 1=1 ";
    $count_params = array();

    if (!empty($cr_domain_uuid)) {
        $count_sql .= "AND domain_uuid = :domain_uuid ";
        $count_params["domain_uuid"] = $cr_domain_uuid;
    }

    if (!empty($body->start_date)) {
        $count_sql .= "AND call_recording_date >= :start_date ";
        $count_params["start_date"] = $body->start_date;
    }

    if (!empty($body->end_date)) {
        $count_sql .= "AND call_recording_date <= :end_date ";
        $count_params["end_date"] = $body->end_date;
    }

    if (!empty($body->caller_id_number)) {
        $count_sql .= "AND caller_id_number LIKE :caller_id_number ";
        $count_params["caller_id_number"] = "%" . $body->caller_id_number . "%";
    }

    if (!empty($body->destination_number)) {
        $count_sql .= "AND destination_number LIKE :destination_number ";
        $count_params["destination_number"] = "%" . $body->destination_number . "%";
    }

    if (!empty($body->call_direction)) {
        $count_sql .= "AND call_direction = :call_direction ";
        $count_params["call_direction"] = $body->call_direction;
    }

    if (!empty($body->search)) {
        $count_sql .= "AND (LOWER(caller_id_name) LIKE :search
                 OR caller_id_number LIKE :search
                 OR destination_number LIKE :search
                 OR call_recording_name LIKE :search) ";
        $count_params["search"] = "%" . strtolower($body->search) . "%";
    }

    $database = new database;
    $count_result = $database->select($count_sql, $count_params, "row");
    $total_count = $count_result ? (int)$count_result["total"] : 0;

    // Format the results
    $result = array();
    foreach ($recordings as $rec) {
        $result[] = array(
            "callRecordingUuid" => $rec["call_recording_uuid"],
            "domainUuid" => $rec["domain_uuid"],
            "callerIdName" => $rec["caller_id_name"],
            "callerIdNumber" => $rec["caller_id_number"],
            "callerDestination" => $rec["caller_destination"],
            "destinationNumber" => $rec["destination_number"],
            "callRecordingName" => $rec["call_recording_name"],
            "callRecordingPath" => $rec["call_recording_path"],
            "callRecordingTranscription" => $rec["call_recording_transcription"],
            "callRecordingLength" => $rec["call_recording_length"],
            "callRecordingDate" => $rec["call_recording_date"],
            "callDirection" => $rec["call_direction"]
        );
    }

    return array(
        "success" => true,
        "callRecordings" => $result,
        "count" => count($result),
        "totalCount" => $total_count,
        "limit" => $limit,
        "offset" => $offset
    );
}
