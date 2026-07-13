<?php
/**
 * order-confirm-call-list.php
 * Lists order confirmation calls for a domain with stats, filtering and paging.
 *
 * Optional body:
 *   status        filter by call status ('all' or empty = no filter)
 *   search        matches order_id / phone / customer_name (ILIKE)
 *   language      filter by language code (e.g. 'en', 'bn')
 *   dateFrom      inclusive lower bound on insert_date (YYYY-MM-DD or full timestamp)
 *   dateTo        inclusive upper bound on insert_date (YYYY-MM-DD -> end of that day)
 *   page          zero-based page index
 *   rowsPerPage   page size (1..200, default 25)
 *   report        'monthly' -> also return a per-month status breakdown (monthlyReport)
 *
 * Notes:
 *   - stats and monthlyReport honour the date/language/search filters but NOT the
 *     status filter (they break results down *by* status).
 *   - the paged list + totalCount honour every filter including status.
 */

$required_params = array();

function do_action($body) {
    global $domain_uuid;
    $d = isset($body->domain_uuid) ? $body->domain_uuid :
         (isset($body->domainUuid) ? $body->domainUuid : $domain_uuid);

    // Admin/portal path: a partner's domain comes through as a name (from the
    // route table's RouteName) rather than a uuid. Resolve name -> uuid here so
    // callers that only know the domain name (e.g. the SOFTSWITCH_DASHBOARD
    // per-partner report) can query without first looking up the uuid.
    $domain_name = isset($body->domainName) ? trim($body->domainName) :
                   (isset($body->domain_name) ? trim($body->domain_name) : '');
    if (empty($d) && $domain_name !== '') {
        $dbn = new database;
        $row = $dbn->select("SELECT domain_uuid FROM v_domains WHERE domain_name = :n LIMIT 1",
            array('n' => $domain_name), 'row');
        if ($row && !empty($row['domain_uuid'])) $d = $row['domain_uuid'];
        else return array("success" => false, "error" => "Unknown domain: " . $domain_name, "domainResolved" => false);
    }
    if (empty($d)) return array("success" => false, "error" => "domain_uuid or domainName is required");

    $status = isset($body->status) ? $body->status : null;
    $search = isset($body->search) ? trim($body->search) : '';
    $language = isset($body->language) ? trim($body->language) : '';
    $date_from = isset($body->dateFrom) ? trim($body->dateFrom) :
                 (isset($body->date_from) ? trim($body->date_from) : '');
    $date_to   = isset($body->dateTo) ? trim($body->dateTo) :
                 (isset($body->date_to) ? trim($body->date_to) : '');
    $report = isset($body->report) ? trim($body->report) : '';
    $page   = isset($body->page) ? max(0, intval($body->page)) : 0;
    $rows_per_page = isset($body->rowsPerPage) ? intval($body->rowsPerPage) :
                     (isset($body->rows_per_page) ? intval($body->rows_per_page) : 25);
    if ($rows_per_page <= 0 || $rows_per_page > 200) $rows_per_page = 25;
    $offset = $page * $rows_per_page;

    // normalise a bare date (YYYY-MM-DD) for dateTo to include the whole day
    if ($date_to !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to)) {
        $date_to .= ' 23:59:59';
    }

    $database = new database;

    // ---- base filters: date range + language + search (shared by list, count, stats, monthly)
    $base = "WHERE domain_uuid = :d";
    $bp = array('d' => $d);
    if ($date_from !== '') { $base .= " AND insert_date >= :df"; $bp['df'] = $date_from; }
    if ($date_to   !== '') { $base .= " AND insert_date <= :dt"; $bp['dt'] = $date_to; }
    if ($language  !== '') { $base .= " AND language = :lang"; $bp['lang'] = $language; }
    if ($search    !== '') {
        $base .= " AND (order_id ILIKE :q OR phone ILIKE :q OR customer_name ILIKE :q)";
        $bp['q'] = '%' . $search . '%';
    }

    // ---- list filters: base + status
    $where = $base;
    $p = $bp;
    if (!empty($status) && $status !== 'all') { $where .= " AND status = :st"; $p['st'] = $status; }

    try {
        $total = $database->select("SELECT COUNT(*) AS c FROM v_order_confirm_calls $where", $p, 'row');

        // stats by status honour base filters (date/language/search) but not status.
        // Also roll up billing: voice_units is >0 only for calls a human actually
        // heard (answered + spoken), so SUM(voice_units) is the answered-only bill
        // and COUNT(voice_units>0) is the billable-call count.
        $stat_rows = $database->select(
            "SELECT status, COUNT(*) AS c,
                    COALESCE(SUM(voice_units),0) AS units,
                    COUNT(*) FILTER (WHERE voice_units > 0) AS billable
               FROM v_order_confirm_calls $base GROUP BY status",
            $bp, 'all');
    } catch (Exception $e) {
        return array("success" => false, "error" => "Schema not installed. Run order-confirm-install.", "schemaInstalled" => false);
    }

    $stats = array('total' => 0, 'confirmed' => 0, 'cancelled' => 0, 'transferred' => 0,
                   'no_answer' => 0, 'busy' => 0, 'voicemail' => 0, 'failed' => 0,
                   'pending' => 0, 'calling' => 0, 'ringing' => 0, 'answered' => 0, 'done' => 0, 'responded' => 0,
                   'billable' => 0, 'voiceUnits' => 0);
    if ($stat_rows) foreach ($stat_rows as $s) {
        $stats['total']      += intval($s['c']);
        $stats['billable']   += intval($s['billable']);
        $stats['voiceUnits'] += intval($s['units']);
        if (isset($stats[$s['status']])) $stats[$s['status']] += intval($s['c']);
    }

    // ---- optional monthly report: per-month status breakdown
    $monthly = null;
    if ($report === 'monthly') {
        $mrows = $database->select(
            "SELECT to_char(date_trunc('month', insert_date), 'YYYY-MM') AS ym,
                    status, COUNT(*) AS c,
                    COALESCE(SUM(voice_units),0) AS units,
                    COUNT(*) FILTER (WHERE voice_units > 0) AS billable
               FROM v_order_confirm_calls $base
              GROUP BY 1, status
              ORDER BY 1 DESC", $bp, 'all');
        $by_month = array();
        if ($mrows) foreach ($mrows as $r) {
            $ym = $r['ym'];
            if (!isset($by_month[$ym])) {
                $by_month[$ym] = array('month' => $ym, 'total' => 0,
                    'confirmed' => 0, 'cancelled' => 0, 'transferred' => 0, 'no_answer' => 0,
                    'busy' => 0, 'voicemail' => 0, 'failed' => 0, 'pending' => 0,
                    'calling' => 0, 'ringing' => 0, 'answered' => 0, 'done' => 0, 'responded' => 0,
                    'billable' => 0, 'voiceUnits' => 0);
            }
            $c = intval($r['c']);
            $by_month[$ym]['total']      += $c;
            $by_month[$ym]['billable']   += intval($r['billable']);
            $by_month[$ym]['voiceUnits'] += intval($r['units']);
            if (isset($by_month[$ym][$r['status']])) $by_month[$ym][$r['status']] += $c;
        }
        $monthly = array_values($by_month);
    }

    $list = $database->select(
        "SELECT call_uuid, order_id, customer_name, phone, language, status, dtmf_pressed,
                disposition, hangup_cause, attempts, max_attempts,
                callback_status, callback_http_code, callback_attempts,
                char_count, voice_units,
                insert_date, answered_date, complete_date
           FROM v_order_confirm_calls $where
          ORDER BY insert_date DESC
          LIMIT $rows_per_page OFFSET $offset", $p, 'all');

    $out = array();
    if ($list) foreach ($list as $r) {
        $out[] = array(
            'callUuid'        => $r['call_uuid'],
            'orderId'         => $r['order_id'],
            'customerName'    => $r['customer_name'],
            'phone'           => $r['phone'],
            'language'        => $r['language'],
            'status'          => $r['status'],
            'dtmf'            => $r['dtmf_pressed'],
            'disposition'     => $r['disposition'],
            'hangupCause'     => $r['hangup_cause'],
            'attempts'        => intval($r['attempts']),
            'maxAttempts'     => intval($r['max_attempts']),
            'callbackStatus'  => $r['callback_status'],
            'callbackHttpCode'=> $r['callback_http_code'] !== null ? intval($r['callback_http_code']) : null,
            'callbackAttempts'=> intval($r['callback_attempts']),
            'charCount'       => intval($r['char_count']),
            'voiceUnits'      => intval($r['voice_units']),
            'insertDate'      => $r['insert_date'],
            'answeredDate'    => $r['answered_date'],
            'completeDate'    => $r['complete_date'],
        );
    }

    $resp = array(
        "success" => true,
        "schemaInstalled" => true,
        "logs" => $out,
        "totalCount" => intval($total['c']),
        "page" => $page,
        "rowsPerPage" => $rows_per_page,
        "stats" => $stats,
    );
    if ($monthly !== null) $resp['monthlyReport'] = $monthly;
    return $resp;
}
