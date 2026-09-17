<?php

$required_params = array();

if (!function_exists('rec_cursor_encode')) {
    /**
     * Keyset pagination, for the same reason cdr-list has it: this view sits on
     * v_xml_cdr, which takes ~500,000 rows a day. Paging a moving, time-ordered
     * table with LIMIT/OFFSET silently skips rows an exporter has never seen.
     * The cursor anchors to the last row returned - (call_recording_date,
     * call_recording_uuid) - so inserts cannot disturb it and a failed run
     * resumes exactly where it stopped.
     */
    function rec_cursor_encode($date, $uuid) {
        return rtrim(strtr(base64_encode($date . '|' . $uuid), '+/', '-_'), '=');
    }
    function rec_cursor_decode($cursor) {
        $raw = base64_decode(strtr($cursor, '-_', '+/'), false);
        if ($raw === false || strpos($raw, '|') === false) { return null; }
        list($date, $uuid) = explode('|', $raw, 2);
        if (!preg_match('/^[0-9a-fA-F-]{36}$/', $uuid)) { return null; }
        return array('date' => $date, 'uuid' => $uuid);
    }
}

function do_action($body) {
    global $domain_uuid;

    // Get domain_uuid - use provided or global
    $cr_domain_uuid = isset($body->domain_uuid) ? $body->domain_uuid : $domain_uuid;

    // An extension is only reliably identified by extension_uuid. The number
    // cannot be matched against caller_id_number/destination_number: on an
    // outbound call the caller id is rewritten to the trunk DID, so on domains
    // like pbx-stax-349 the extension appears in neither field and the filter
    // returned nothing. v_xml_cdr carries extension_uuid on every recorded row,
    // which is what the FusionPBX CDR screen itself filters on.
    $cr_extension_uuid = null;
    if (!empty($body->extension_uuid)) {
        $cr_extension_uuid = trim($body->extension_uuid);
    }

    // extension_uuid takes precedence over the number match below; ANDing both
    // returns nothing on any domain whose outbound caller id is rewritten.
    $cr_extension = null;
    $cr_search = isset($body->search) ? trim($body->search) : '';
    if (!empty($body->extension)) {
        $cr_extension = trim($body->extension);
    } elseif ($cr_search !== '' && preg_match('/^ext:([^:]+)(?:::(.*))?$/i', $cr_search, $m)) {
        $cr_extension = trim($m[1]);
        $cr_search = isset($m[2]) ? trim($m[2]) : '';
    }

    // A number on its own cannot identify an extension: on an outbound call the
    // caller id is rewritten to the trunk DID, so on domains like pbx-stax-349 it
    // appears in neither caller_id_number nor destination_number. Resolve the
    // number to a uuid here so callers that only know the number -- the dashboard
    // before its next deploy, and anything else already in the field -- filter the
    // same way the FusionPBX CDR screen does. Falls back to the old number match
    // if the extension cannot be resolved.
    if (!empty($cr_extension) && empty($cr_extension_uuid) && !empty($cr_domain_uuid)) {
        $lookup = new database;
        $found = $lookup->select(
            "SELECT extension_uuid FROM v_extensions "
            . "WHERE domain_uuid = :domain_uuid AND extension = :extension LIMIT 1",
            array("domain_uuid" => $cr_domain_uuid, "extension" => $cr_extension),
            "column");
        if (!empty($found)) {
            $cr_extension_uuid = $found;
        }
        unset($lookup);
    }

    // Build the SQL query using the view_call_recordings view
    $sql = "SELECT * FROM view_call_recordings WHERE 1=1 ";
    $parameters = array();

    // Filter by domain if provided
    if (!empty($cr_domain_uuid)) {
        $sql .= "AND domain_uuid = :domain_uuid ";
        $parameters["domain_uuid"] = $cr_domain_uuid;
    }

    // Filter by date range (handles both YYYY-MM-DD and YYYY-MM-DDTHH:mm formats)
    if (!empty($body->start_date)) {
        $start_date = str_replace('T', ' ', $body->start_date);
        // If only date provided, add start of day
        if (strlen($start_date) == 10) {
            $start_date .= ' 00:00:00';
        }
        $sql .= "AND call_recording_date >= :start_date ";
        $parameters["start_date"] = $start_date;
    }

    if (!empty($body->end_date)) {
        $end_date = str_replace('T', ' ', $body->end_date);
        // If only date provided, add end of day
        if (strlen($end_date) == 10) {
            $end_date .= ' 23:59:59';
        }
        $sql .= "AND call_recording_date <= :end_date ";
        $parameters["end_date"] = $end_date;
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

    // Filter by extension (exact match on either leg)
    if (!empty($cr_extension) && empty($cr_extension_uuid)) {
        $sql .= "AND (caller_id_number = :extension OR destination_number = :extension) ";
        $parameters["extension"] = $cr_extension;
    }
    if (!empty($cr_extension_uuid)) {
        $sql .= "AND extension_uuid = :extension_uuid ";
        $parameters["extension_uuid"] = $cr_extension_uuid;
    }

    // Search across multiple fields
    if ($cr_search !== '') {
        $sql .= "AND (LOWER(caller_id_name) LIKE :search
                 OR caller_id_number LIKE :search
                 OR destination_number LIKE :search
                 OR call_recording_name LIKE :search) ";
        $parameters["search"] = "%" . strtolower($cr_search) . "%";
    }

    // Export mode is opt-in: `cursor` or `order` switches to keyset paging.
    // Callers passing neither keep the exact behaviour they always had.
    $cr_export_mode = isset($body->cursor) || isset($body->order);
    $cr_asc = isset($body->order) && strtolower(trim((string) $body->order)) === 'asc';

    if (!empty($body->unexported_only)) {
        $sql .= "AND exported_at IS NULL ";
    }

    if ($cr_export_mode && !empty($body->cursor)) {
        $cr_cur = rec_cursor_decode((string) $body->cursor);
        if ($cr_cur === null) {
            return array("success" => false, "error" => "invalid cursor");
        }
        $sql .= "AND (call_recording_date, call_recording_uuid) "
              . ($cr_asc ? '>' : '<')
              . " (:cursor_date::timestamptz, :cursor_uuid::uuid) ";
        $parameters["cursor_date"] = $cr_cur['date'];
        $parameters["cursor_uuid"] = $cr_cur['uuid'];
    }

    if ($cr_export_mode) {
        $cr_dir = $cr_asc ? 'ASC' : 'DESC';
        $sql .= "ORDER BY call_recording_date " . $cr_dir . ", call_recording_uuid " . $cr_dir . " ";
    }
    else {
        // Order by date descending (most recent first)
        $sql .= "ORDER BY call_recording_date DESC ";
    }

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

    // Get total count for pagination.
    // Skipped in export mode: COUNT(*) over this view scans the whole CDR table
    // (1.85M rows on the larger cluster) and a keyset pager never uses a total.
    $total_count = 0;
    if (!$cr_export_mode) {
    $count_sql = "SELECT COUNT(*) as total FROM view_call_recordings WHERE 1=1 ";
    $count_params = array();

    if (!empty($cr_domain_uuid)) {
        $count_sql .= "AND domain_uuid = :domain_uuid ";
        $count_params["domain_uuid"] = $cr_domain_uuid;
    }

    if (!empty($body->start_date)) {
        $start_date = str_replace('T', ' ', $body->start_date);
        if (strlen($start_date) == 10) {
            $start_date .= ' 00:00:00';
        }
        $count_sql .= "AND call_recording_date >= :start_date ";
        $count_params["start_date"] = $start_date;
    }

    if (!empty($body->end_date)) {
        $end_date = str_replace('T', ' ', $body->end_date);
        if (strlen($end_date) == 10) {
            $end_date .= ' 23:59:59';
        }
        $count_sql .= "AND call_recording_date <= :end_date ";
        $count_params["end_date"] = $end_date;
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

    if (!empty($cr_extension) && empty($cr_extension_uuid)) {
        $count_sql .= "AND (caller_id_number = :extension OR destination_number = :extension) ";
        $count_params["extension"] = $cr_extension;
    }
    if (!empty($cr_extension_uuid)) {
        $count_sql .= "AND extension_uuid = :extension_uuid ";
        $count_params["extension_uuid"] = $cr_extension_uuid;
    }

    if ($cr_search !== '') {
        $count_sql .= "AND (LOWER(caller_id_name) LIKE :search
                 OR caller_id_number LIKE :search
                 OR destination_number LIKE :search
                 OR call_recording_name LIKE :search) ";
        $count_params["search"] = "%" . strtolower($cr_search) . "%";
    }

    $database = new database;
    $count_result = $database->select($count_sql, $count_params, "row");
    $total_count = $count_result ? (int)$count_result["total"] : 0;
    }

    // The recording list carries extension_uuid but not the extension NUMBER,
    // which is what a human needs in a filename. Resolve the uuids of this page
    // in one query rather than joining: every filter above uses bare column
    // names, so a joined table would make domain_uuid and extension_uuid
    // ambiguous and break searches that work today.
    $extension_numbers = array();
    $uuids_to_resolve = array();
    foreach ($recordings as $rec) {
        $eu = isset($rec["extension_uuid"]) ? trim((string) $rec["extension_uuid"]) : '';
        if ($eu !== '' && preg_match('/^[0-9a-fA-F-]{36}$/', $eu)) {
            $uuids_to_resolve[$eu] = true;
        }
    }
    if (!empty($uuids_to_resolve)) {
        $ph = array();
        $ext_params = array();
        foreach (array_keys($uuids_to_resolve) as $i => $eu) {
            $ph[] = ":eu" . $i;
            $ext_params["eu" . $i] = $eu;
        }
        $ext_rows = $database->select(
            "SELECT extension_uuid, extension, number_alias FROM v_extensions "
            . "WHERE extension_uuid IN (" . implode(", ", $ph) . ")",
            $ext_params, "all"
        );
        if (is_array($ext_rows)) {
            foreach ($ext_rows as $er) {
                // number_alias is what the outside world dials when it is set;
                // the extension is the internal number. Prefer the extension.
                $extension_numbers[$er["extension_uuid"]] =
                    $er["extension"] !== '' && $er["extension"] !== null
                        ? $er["extension"] : $er["number_alias"];
            }
        }
    }

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
            "callDirection" => $rec["call_direction"],
            "extensionUuid" => $rec["extension_uuid"],
            "extension" => isset($extension_numbers[$rec["extension_uuid"]])
                ? $extension_numbers[$rec["extension_uuid"]] : null,
            "exportedAt" => isset($rec["exported_at"]) ? $rec["exported_at"] : null
        );
    }

    // Only offer a cursor when the page was full; a short page is the end.
    $next_cursor = null;
    if ($cr_export_mode && count($recordings) === $limit) {
        $last = $recordings[count($recordings) - 1];
        $next_cursor = rec_cursor_encode($last["call_recording_date"], $last["call_recording_uuid"]);
    }

    return array(
        "success" => true,
        "callRecordings" => $result,
        "count" => count($result),
        "totalCount" => $total_count,
        "limit" => $limit,
        "offset" => $offset,
        "nextCursor" => $next_cursor
    );
}
