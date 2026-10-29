<?php
/*
 * crm-selfservice-usage — a company's Self-service phone line, by month.
 *
 * The admin dashboard bills Self-service the way it bills Smart IVR, so it
 * needs the same monthly figures the CRM's own IVR page shows. That page
 * counts in the browser from the call list; this counts in the database, so
 * a year of a busy line is one indexed query instead of thousands of rows.
 *
 * THE SAME RULES AS THE CRM PAGE (services/crm/ivr-usage.ts):
 *   - only inbound calls (direction not 'outbound')
 *   - only calls to the company's Self-service numbers, a number matching
 *     when one's digits end with the other's (a DID in full, a short
 *     extension and a number without its country code all match), 3+ digits
 *   - answered = the switch metered it (billsec > 0) or recorded an answer;
 *     the rest abandoned
 *   - months in the company's own time zone
 *
 * params:
 *   domainUuid    the company's domain
 *   numbers       ["09646999999", "7001", ...] — the Self-service numbers
 *   dateFrom      "YYYY-MM-DD" (optional, default 12 months back)
 *   dateTo        "YYYY-MM-DD" (optional, default today), inclusive
 *   timeZone      e.g. "Asia/Dhaka" (default)
 *
 * -> {success, months: [{month: "2026-09", calls, answered, abandoned, billsec}]}
 */

$required_params = array("numbers");

function do_action($body) {
    global $domain_uuid;
    $db_domain_uuid = isset($body->domainUuid) ? $body->domainUuid : (isset($body->domain_uuid) ? $body->domain_uuid : $domain_uuid);
    if (!preg_match('/^[0-9a-fA-F-]{36}$/', (string) $db_domain_uuid)) {
        return array("error" => "domainUuid is required", "code" => 400);
    }
    $numbers = array();
    foreach ((array) $body->numbers as $n) {
        $d = preg_replace('/\D/', '', (string) $n);
        if (strlen($d) >= 3 && strlen($d) <= 20) {
            $numbers[$d] = true;
        }
    }
    $numbers = array_keys($numbers);
    if (!$numbers) {
        return array("success" => true, "months" => array(), "note" => "no Self-service numbers given");
    }
    if (count($numbers) > 20) {
        return array("error" => "at most 20 numbers", "code" => 400);
    }
    $tz = isset($body->timeZone) && preg_match('#^[A-Za-z_]+(/[A-Za-z_+-]+){0,2}$#', (string) $body->timeZone)
        ? (string) $body->timeZone : 'Asia/Dhaka';
    $from = isset($body->dateFrom) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $body->dateFrom)
        ? (string) $body->dateFrom : date('Y-m-d', strtotime('-12 months'));
    $to = isset($body->dateTo) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $body->dateTo)
        ? (string) $body->dateTo : date('Y-m-d');

    // One OR per number: either side's digits end with the other's.
    $match = array();
    $params = array("d" => $db_domain_uuid, "tz" => $tz, "f" => $from, "t" => $to);
    foreach ($numbers as $i => $n) {
        $match[] = "(dest LIKE :n$i OR (length(dest) >= 3 AND :m$i LIKE '%' || dest))";
        $params["n$i"] = '%' . $n;
        $params["m$i"] = $n;
    }
    $sql = "SELECT to_char(local_start, 'YYYY-MM') AS month,
                   count(*) AS calls,
                   count(*) FILTER (WHERE billsec > 0 OR answer_stamp IS NOT NULL) AS answered,
                   coalesce(sum(billsec), 0) AS billsec
              FROM (SELECT start_stamp AT TIME ZONE :tz AS local_start, billsec, answer_stamp,
                           regexp_replace(coalesce(destination_number, ''), '\\D', '', 'g') AS dest
                      FROM v_xml_cdr
                     WHERE domain_uuid = :d
                       AND start_stamp >= (:f::date)::timestamp AT TIME ZONE :tz
                       AND start_stamp < ((:t::date + 1)::timestamp AT TIME ZONE :tz)
                       AND coalesce(direction, '') <> 'outbound') c
             WHERE " . implode(" OR ", $match) . "
             GROUP BY 1 ORDER BY 1 DESC";
    $database = new database;
    $rows = $database->select($sql, $params, "all");
    if ($rows === false) {
        return array("error" => "the call records could not be read", "code" => 500);
    }
    $months = array();
    foreach ((array) $rows as $r) {
        $months[] = array("month" => $r['month'], "calls" => (int) $r['calls'],
            "answered" => (int) $r['answered'], "abandoned" => (int) $r['calls'] - (int) $r['answered'],
            "billsec" => (int) $r['billsec']);
    }
    return array("success" => true, "months" => $months, "from" => $from, "to" => $to, "timeZone" => $tz);
}
