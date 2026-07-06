<?php
/**
 * order-confirm-call-list.php
 * Lists order confirmation calls for a domain with stats, filtering and paging.
 *
 * Optional body: status, search (order_id/phone/name), page, rowsPerPage
 */

$required_params = array();

function do_action($body) {
    global $domain_uuid;
    $d = isset($body->domain_uuid) ? $body->domain_uuid :
         (isset($body->domainUuid) ? $body->domainUuid : $domain_uuid);
    if (empty($d)) return array("success" => false, "error" => "domain_uuid is required");

    $status = isset($body->status) ? $body->status : null;
    $search = isset($body->search) ? trim($body->search) : '';
    $page   = isset($body->page) ? max(0, intval($body->page)) : 0;
    $rows_per_page = isset($body->rowsPerPage) ? intval($body->rowsPerPage) :
                     (isset($body->rows_per_page) ? intval($body->rows_per_page) : 25);
    if ($rows_per_page <= 0 || $rows_per_page > 200) $rows_per_page = 25;
    $offset = $page * $rows_per_page;

    $database = new database;

    $where = "WHERE domain_uuid = :d";
    $p = array('d' => $d);
    if (!empty($status) && $status !== 'all') { $where .= " AND status = :st"; $p['st'] = $status; }
    if ($search !== '') {
        $where .= " AND (order_id ILIKE :q OR phone ILIKE :q OR customer_name ILIKE :q)";
        $p['q'] = '%' . $search . '%';
    }

    try {
        $total = $database->select("SELECT COUNT(*) AS c FROM v_order_confirm_calls $where", $p, 'row');

        // stats by status (unfiltered by search/status, whole domain)
        $stat_rows = $database->select(
            "SELECT status, COUNT(*) AS c FROM v_order_confirm_calls WHERE domain_uuid = :d GROUP BY status",
            array('d' => $d), 'all');
    } catch (Exception $e) {
        return array("success" => false, "error" => "Schema not installed. Run order-confirm-install.", "schemaInstalled" => false);
    }

    $stats = array('total' => 0, 'confirmed' => 0, 'cancelled' => 0, 'transferred' => 0,
                   'no_answer' => 0, 'busy' => 0, 'failed' => 0, 'pending' => 0);
    if ($stat_rows) foreach ($stat_rows as $s) {
        $stats['total'] += intval($s['c']);
        if (isset($stats[$s['status']])) $stats[$s['status']] += intval($s['c']);
        if (in_array($s['status'], array('calling', 'answered', 'done', 'voicemail'))) {
            if ($s['status'] === 'voicemail') $stats['no_answer'] += 0; // shown separately if desired
        }
    }

    $list = $database->select(
        "SELECT call_uuid, order_id, customer_name, phone, language, status, dtmf_pressed,
                disposition, hangup_cause, attempts, max_attempts,
                callback_status, callback_http_code, callback_attempts,
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
            'insertDate'      => $r['insert_date'],
            'answeredDate'    => $r['answered_date'],
            'completeDate'    => $r['complete_date'],
        );
    }

    return array(
        "success" => true,
        "schemaInstalled" => true,
        "logs" => $out,
        "totalCount" => intval($total['c']),
        "page" => $page,
        "rowsPerPage" => $rows_per_page,
        "stats" => $stats,
    );
}
