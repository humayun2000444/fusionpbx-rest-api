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

    $sql = "SELECT " . implode(", ", $fields) . " FROM v_xml_cdr"
         . " WHERE " . implode(" AND ", $where)
         . " ORDER BY end_stamp DESC LIMIT " . $limit . " OFFSET " . $offset;

    $database = new database;
    return $database->select($sql, $parameters, 'all');
}
