<?php
$required_params = array("domain_uuid");

/**
 * cdr-list — CDRs for a domain, newest first.
 *
 * Every filter below is OPTIONAL and every default reproduces the original
 * behaviour (newest 100 for the domain), so existing callers are unaffected.
 *
 *   limit           int, default 100, capped at 1000
 *   offset          int, default 0
 *   start_date      "YYYY-MM-DD" (start of day) or a full timestamp
 *   end_date        "YYYY-MM-DD" (END of that day) or a full timestamp
 *   direction       "inbound" | "outbound"
 *   extension_uuid  exact match; ignored unless it looks like a uuid
 *   number          exact match against ANY of the four number columns
 *
 * Markers: BILLSEC_FIELDS (billsec/answer_stamp), CDR_FILTERS (this block).
 *
 * Field notes for anyone reading these rows:
 *   duration     start->end, RING TIME INCLUDED — not talk time
 *   billsec      answer->end, the real talk time
 *   answer_stamp epoch zero ("1970-01-01…"), not null, when never answered
 */

if (!function_exists('cdr_list_stamp')) {
    /** A bare date means the whole day; $end picks which edge of it. */
    function cdr_list_stamp($value, $end = false) {
        $v = trim((string) $value);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
            return $v . ($end ? ' 23:59:59' : ' 00:00:00');
        }
        return $v;
    }
}

if (!function_exists('cdr_cursor_encode')) {
    /**
     * Keyset pagination for bulk export.
     *
     * LIMIT/OFFSET is unsafe on this table: it takes ~500,000 new rows a day,
     * and the list is ordered by time. Rows inserted while an export is walking
     * the pages shift every later row along, so an OFFSET-based reader SKIPS
     * records it has never seen and re-reads ones it has. Silent data loss,
     * exactly what a migration must not do.
     *
     * A keyset cursor is anchored to the last row actually returned -
     * (start_stamp, xml_cdr_uuid), the uuid breaking ties on identical stamps -
     * so concurrent inserts cannot disturb it and a failed run resumes exactly
     * where it stopped.
     *
     * Ascending order is the right choice for a backfill: the cursor then moves
     * AWAY from where new rows arrive, so the set behind it never changes.
     */
    function cdr_cursor_encode($stamp, $uuid) {
        return rtrim(strtr(base64_encode($stamp . '|' . $uuid), '+/', '-_'), '=');
    }
    function cdr_cursor_decode($cursor) {
        $raw = base64_decode(strtr($cursor, '-_', '+/'), false);
        if ($raw === false || strpos($raw, '|') === false) { return null; }
        list($stamp, $uuid) = explode('|', $raw, 2);
        if (!preg_match('/^[0-9a-fA-F-]{36}$/', $uuid)) { return null; }
        return array('stamp' => $stamp, 'uuid' => $uuid);
    }
}

function do_action($body) {
    $fields = array(
        "xml_cdr_uuid",
        "extension_uuid",
        "direction",
        "start_stamp",
        "answer_stamp",
        "end_stamp",
        "hangup_cause",
        "duration",
        "billsec",
        "waitsec",
        "missed_call",
        "record_name",
        "bridge_uuid",
        "caller_id_name",
        "caller_id_number",
        "caller_destination",
        "source_number",
        "destination_number",
        "leg",
    );

    $where = array("domain_uuid = :domain_uuid");
    $parameters = array();
    $parameters['domain_uuid'] = $body->domain_uuid;

    if (!empty($body->start_date)) {
        $where[] = "start_stamp >= :start_date";
        $parameters['start_date'] = cdr_list_stamp($body->start_date, false);
    }
    if (!empty($body->end_date)) {
        $where[] = "start_stamp <= :end_date";
        $parameters['end_date'] = cdr_list_stamp($body->end_date, true);
    }
    if (!empty($body->direction)) {
        // Whitelisted: anything else would just return nothing, confusingly.
        $direction = strtolower(trim($body->direction));
        if ($direction === 'inbound' || $direction === 'outbound' || $direction === 'local') {
            $where[] = "direction = :direction";
            $parameters['direction'] = $direction;
        }
    }
    if (!empty($body->extension_uuid)) {
        // A non-uuid here is a Postgres type error, not an empty result, so
        // check the shape before letting it near the query.
        $extension_uuid = trim($body->extension_uuid);
        if (preg_match('/^[0-9a-fA-F-]{36}$/', $extension_uuid)) {
            $where[] = "extension_uuid = :extension_uuid";
            $parameters['extension_uuid'] = $extension_uuid;
        }
    }
    if (!empty($body->number)) {
        $where[] = "(caller_id_number = :number OR destination_number = :number"
                 . " OR caller_destination = :number OR source_number = :number)";
        $parameters['number'] = trim($body->number);
    }

    // LIMIT/OFFSET are cast to int and inlined: PDO binds parameters as
    // strings, and PostgreSQL rejects LIMIT '100'. The int cast is what makes
    // this safe — no caller-supplied text reaches the SQL.
    $limit = isset($body->limit) ? (int) $body->limit : 100;
    if ($limit < 1) { $limit = 1; }
    if ($limit > 1000) { $limit = 1000; }
    $offset = isset($body->offset) ? (int) $body->offset : 0;
    if ($offset < 0) { $offset = 0; }

    // Export mode is opt-in: supplying `cursor` or `order` switches to keyset
    // paging AND to an enveloped response. Callers that pass neither get the
    // bare array and the exact ordering they always did.
    $export_mode = isset($body->cursor) || isset($body->order);

    // Only rows not yet confirmed archived. Lets a puller resume without
    // re-fetching, and lets retention delete strictly what has been exported.
    if (!empty($body->unexported_only)) {
        $where[] = "exported_at IS NULL";
    }

    if (!$export_mode) {
        $sql = "SELECT " . implode(", ", $fields) . " FROM v_xml_cdr"
             . " WHERE " . implode(" AND ", $where)
             . " ORDER BY end_stamp DESC LIMIT " . $limit . " OFFSET " . $offset;
        $database = new database;
        return $database->select($sql, $parameters, 'all');
    }

    $asc = !isset($body->order) || strtolower(trim((string) $body->order)) !== 'desc';
    $dir = $asc ? 'ASC' : 'DESC';

    if (!empty($body->cursor)) {
        $cur = cdr_cursor_decode((string) $body->cursor);
        if ($cur === null) {
            return array("success" => false, "error" => "invalid cursor");
        }
        // Row-value comparison, so the uuid tie-breaks identical timestamps.
        $where[] = "(start_stamp, xml_cdr_uuid) " . ($asc ? '>' : '<')
                 . " (:cursor_stamp::timestamptz, :cursor_uuid::uuid)";
        $parameters['cursor_stamp'] = $cur['stamp'];
        $parameters['cursor_uuid']  = $cur['uuid'];
    }

    $sql = "SELECT " . implode(", ", $fields) . ", exported_at FROM v_xml_cdr"
         . " WHERE " . implode(" AND ", $where)
         . " ORDER BY start_stamp " . $dir . ", xml_cdr_uuid " . $dir
         . " LIMIT " . $limit;

    $database = new database;
    $rows = $database->select($sql, $parameters, 'all');
    if (!is_array($rows)) { $rows = array(); }

    // Only offer a cursor when the page was full; a short page is the end.
    $next = null;
    if (count($rows) === $limit) {
        $last = $rows[count($rows) - 1];
        $next = cdr_cursor_encode($last['start_stamp'], $last['xml_cdr_uuid']);
    }

    return array(
        "success"     => true,
        "count"       => count($rows),
        "order"       => strtolower($dir),
        "next_cursor" => $next,
        "rows"        => $rows,
    );
}
