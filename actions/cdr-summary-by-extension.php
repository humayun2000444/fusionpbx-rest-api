<?php
/**
 * cdr-summary-by-extension - per-extension call summary from v_xml_cdr.
 *
 * Aggregates in SQL (GROUP BY) rather than returning rows, so a wide date
 * range costs one query instead of shipping every CDR to the client.
 *
 * Request body:
 *   domain_uuid      required - the PBX domain to report on
 *   start_date       optional - inclusive, 'YYYY-MM-DD' or full timestamp
 *   end_date         optional - inclusive
 *   did              optional - outbound caller ID number (exact, or a
 *                               trailing "*" for prefix match). Alias: caller_id_number
 *   group_interval   optional - hourly|daily|weekly|monthly|yearly.
 *                               Omit for a single total row per extension.
 *   direction        optional - inbound|outbound|local
 *   extension        optional - restrict to one extension number
 *   limit            optional - max rows returned (default 1000, cap 10000)
 *
 * Response: { success, count, group_interval, rows: [ {
 *   extension, extension_name, period, total_calls, answered, no_answer,
 *   busy, failed, inbound, outbound, total_duration, total_billsec,
 *   avg_duration, avg_billsec, asr } ] }
 *
 * asr = answer-seizure ratio (answered / total, as a percentage).
 */

if (!function_exists('cdr_sum_ext_bucket')) {
/** Map the requested interval to a Postgres date_trunc unit (whitelisted). */
function cdr_sum_ext_bucket($interval) {
    switch (strtolower(trim((string) $interval))) {
        case 'hourly':  return 'hour';
        case 'daily':   return 'day';
        case 'weekly':  return 'week';
        case 'monthly': return 'month';
        case 'yearly':  return 'year';
        default:        return null;   // no period grouping
    }
}
}

function do_action($body) {
    global $database;

    if (empty($body->domain_uuid)) {
        return array("error" => "domain_uuid is required");
    }
    $domain_uuid = trim($body->domain_uuid);
    if (!preg_match('/^[0-9a-fA-F-]{36}$/', $domain_uuid)) {
        return array("error" => "invalid domain_uuid");
    }

    $where = array("x.domain_uuid = :domain_uuid");
    $params = array("domain_uuid" => $domain_uuid);

    // ---- date range -------------------------------------------------------
    if (!empty($body->start_date)) {
        $where[] = "x.start_stamp >= :start_date";
        $params["start_date"] = $body->start_date;
    }
    if (!empty($body->end_date)) {
        // a bare date means "through the end of that day"
        $end = trim($body->end_date);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)) {
            $end .= ' 23:59:59';
        }
        $where[] = "x.start_stamp <= :end_date";
        $params["end_date"] = $end;
    }

    // ---- DID / outbound caller id ----------------------------------------
    $did = '';
    if (!empty($body->did)) {
        $did = trim($body->did);
    } elseif (!empty($body->caller_id_number)) {
        $did = trim($body->caller_id_number);
    }
    if ($did !== '') {
        if (substr($did, -1) === '*') {
            $where[] = "x.caller_id_number LIKE :did";
            $params["did"] = str_replace('*', '%', $did);
        } else {
            $where[] = "x.caller_id_number = :did";
            $params["did"] = $did;
        }
    }

    // ---- optional direction / single extension ---------------------------
    if (!empty($body->direction)) {
        $dir = strtolower(trim($body->direction));
        if (in_array($dir, array('inbound', 'outbound', 'local'), true)) {
            $where[] = "lower(x.direction) = :direction";
            $params["direction"] = $dir;
        }
    }
    if (!empty($body->extension)) {
        $where[] = "e.extension = :extension_number";
        $params["extension_number"] = trim($body->extension);
    }

    $limit = isset($body->limit) ? (int) $body->limit : 1000;
    if ($limit < 1) { $limit = 1000; }
    if ($limit > 10000) { $limit = 10000; }

    // ---- period bucket (whitelisted -> safe to interpolate) --------------
    $unit = cdr_sum_ext_bucket(isset($body->group_interval) ? $body->group_interval : null);
    if ($unit !== null) {
        $period_select = "to_char(date_trunc('" . $unit . "', x.start_stamp), 'YYYY-MM-DD HH24:MI:SS')";
        // NB: group by the bucket expression, never by a bare NULL - Postgres
        // rejects "GROUP BY 1, NULL" and the failure is otherwise silent.
        $group_by      = "1, date_trunc('" . $unit . "', x.start_stamp)";
        $order_by      = "1, 3";
    } else {
        $period_select = "NULL::text";
        $group_by      = "1";
        $order_by      = "1";
    }

    // This is an EXTENSION report: by default only count calls that actually
    // resolve to an extension, otherwise every external caller id shows up as
    // its own "extension" row. Pass include_unassigned=true to keep them.
    $include_unassigned = !empty($body->include_unassigned)
        && !in_array(strtolower((string) $body->include_unassigned), array('false','0','no'), true);
    if (!$include_unassigned) {
        $where[] = "x.extension_uuid IS NOT NULL";
    }

    // Answered / failed classification mirrors FusionPBX's own xml_cdr logic:
    // billsec > 0 is answered; the rest is split by hangup cause.
    $sql = "
        SELECT
            coalesce(e.extension, x.caller_id_number, '(unknown)') AS extension,
            coalesce(max(e.description), max(x.caller_id_name), '')  AS extension_name,
            " . $period_select . " AS period,
            count(*)                                                  AS total_calls,
            count(*) FILTER (WHERE coalesce(x.billsec,0) > 0)         AS answered,
            count(*) FILTER (WHERE coalesce(x.billsec,0) = 0
                             AND (x.hangup_cause IS NULL
                                  OR x.hangup_cause IN ('NO_ANSWER','NO_USER_RESPONSE',
                                      'ORIGINATOR_CANCEL','ALLOTTED_TIMEOUT'))) AS no_answer,
            count(*) FILTER (WHERE x.hangup_cause = 'USER_BUSY')      AS busy,
            count(*) FILTER (WHERE coalesce(x.billsec,0) = 0
                             AND x.hangup_cause IS NOT NULL
                             AND x.hangup_cause NOT IN ('NO_ANSWER','NO_USER_RESPONSE',
                                 'ORIGINATOR_CANCEL','ALLOTTED_TIMEOUT','USER_BUSY')) AS failed,
            count(*) FILTER (WHERE lower(x.direction) = 'inbound')    AS inbound,
            count(*) FILTER (WHERE lower(x.direction) = 'outbound')   AS outbound,
            coalesce(sum(x.duration),0)                               AS total_duration,
            coalesce(sum(x.billsec),0)                                AS total_billsec,
            round(coalesce(avg(x.duration),0)::numeric, 1)            AS avg_duration,
            round(coalesce(avg(x.billsec),0)::numeric, 1)             AS avg_billsec
        FROM v_xml_cdr x
        LEFT JOIN v_extensions e ON e.extension_uuid = x.extension_uuid
        WHERE " . implode(' AND ', $where) . "
        GROUP BY " . $group_by . "
        ORDER BY " . $order_by . "
        LIMIT " . $limit;

    $rows = $database->select($sql, $params, 'all');
    if (!is_array($rows)) { $rows = array(); }

    // numeric-ify + derive ASR so the client does not have to
    foreach ($rows as $i => $r) {
        foreach (array('total_calls','answered','no_answer','busy','failed',
                       'inbound','outbound','total_duration','total_billsec') as $k) {
            $rows[$i][$k] = isset($r[$k]) ? (int) $r[$k] : 0;
        }
        $rows[$i]['avg_duration'] = isset($r['avg_duration']) ? (float) $r['avg_duration'] : 0;
        $rows[$i]['avg_billsec']  = isset($r['avg_billsec'])  ? (float) $r['avg_billsec']  : 0;
        $rows[$i]['asr'] = $rows[$i]['total_calls'] > 0
            ? round($rows[$i]['answered'] * 100 / $rows[$i]['total_calls'], 1) : 0;
    }

    return array(
        "success"        => true,
        "count"          => count($rows),
        "group_interval" => $unit === null ? '' : strtolower(trim($body->group_interval)),
        "rows"           => $rows,
    );
}
