<?php
$required_params = array("domain_uuid");

/**
 * cdr-inbound-details — one row per caller who entered a call-center queue.
 *
 * cdr-list returns 19 columns and none of mod_callcenter's; this returns the
 * queue story of each inbound call: when the caller joined the queue, when (or
 * whether) an agent took it, who, how long it rang, talked and was held, and
 * who hung up. Built for the CRM's "Inbound Details" report.
 *
 *   start_date / end_date   "YYYY-MM-DD" (whole day) or a full timestamp,
 *                           e.g. "2026-09-24 00:00:00+06" for a local day
 *   limit                   default 100, capped at 1000
 *   offset                  default 0
 *   status                  optional: answered | abandoned
 *
 * WHAT THE TABLE HOLDS, verified on CCL 2026-09-24 (240 queue calls):
 *   * The CALLER's record (cc_side = 'member') carries the whole queue story:
 *     cc_queue ("<queue extension>@<domain name>"), joined / answered /
 *     canceled / terminated epochs, cc_cancel_reason (BREAK_OUT = the caller
 *     left, TIMEOUT = the queue gave up), cc_agent (the agent who ANSWERED,
 *     set on every answered call), hold_accum_seconds, sip_hangup_disposition.
 *   * The answering agent's own leg is NOT stored here. The agent legs that
 *     are (cc_side = 'agent', linked by cc_member_session_uuid) are the ring
 *     attempts that were cancelled or refused — and cc_agent_bridged is never
 *     set. So talk time comes from the caller's record (answered -> end), and
 *     ring time is measured from the first stored attempt to the answer; with
 *     no stored attempt (only the answering agent was rung) it is unknown and
 *     returned as null rather than guessed.
 *
 * PERFORMANCE: v_xml_cdr takes ~500,000 rows a day on the busiest switch and
 * cc_member_session_uuid is not indexed, so the attempts are fetched ONCE for
 * the page's callers, bounded by domain and time like the main query — never
 * as a per-row subquery.
 */

if (!function_exists('cdr_inbound_stamp')) {
    function cdr_inbound_stamp($value, $end = false) {
        $v = trim((string) $value);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
            return $v . ($end ? ' 23:59:59' : ' 00:00:00');
        }
        return $v;
    }
}

function do_action($body) {
    $domain_uuid = $body->domain_uuid;
    $where = array("m.domain_uuid = :domain_uuid", "m.cc_side = 'member'");
    $parameters = array('domain_uuid' => $domain_uuid);

    $start = !empty($body->start_date) ? cdr_inbound_stamp($body->start_date, false) : date('Y-m-d') . ' 00:00:00';
    $end = !empty($body->end_date) ? cdr_inbound_stamp($body->end_date, true) : date('Y-m-d') . ' 23:59:59';
    $where[] = "m.start_stamp >= :start_date";
    $where[] = "m.start_stamp <= :end_date";
    $parameters['start_date'] = $start;
    $parameters['end_date'] = $end;

    $status = isset($body->status) ? strtolower(trim((string) $body->status)) : '';
    if ($status === 'answered') {
        $where[] = "coalesce(m.cc_queue_answered_epoch, 0) > 0";
    } elseif ($status === 'abandoned') {
        $where[] = "coalesce(m.cc_queue_answered_epoch, 0) = 0";
    }

    $limit = isset($body->limit) ? (int) $body->limit : 100;
    if ($limit < 1) { $limit = 1; }
    if ($limit > 1000) { $limit = 1000; }
    $offset = isset($body->offset) ? (int) $body->offset : 0;
    if ($offset < 0) { $offset = 0; }

    $database = new database;
    $total = $database->select(
        "SELECT count(*) FROM v_xml_cdr m WHERE " . implode(" AND ", $where),
        $parameters, 'column');

    $sql = "SELECT m.xml_cdr_uuid, m.start_stamp, m.end_stamp, m.start_epoch, m.end_epoch,
                   m.caller_id_name, m.caller_id_number, m.caller_destination, m.destination_number,
                   m.cc_queue, m.cc_queue_joined_epoch, m.cc_queue_answered_epoch,
                   m.cc_queue_canceled_epoch, m.cc_queue_terminated_epoch,
                   m.cc_cancel_reason, m.cc_cause, m.cc_agent,
                   m.hold_accum_seconds, m.sip_hangup_disposition, m.hangup_cause,
                   m.record_name, m.record_length,
                   q.call_center_queue_uuid AS queue_uuid, q.queue_name, q.queue_extension,
                   a.agent_name, a.agent_contact
            FROM v_xml_cdr m
            LEFT JOIN v_domains d ON d.domain_uuid = m.domain_uuid
            LEFT JOIN v_call_center_queues q
                   ON q.domain_uuid = m.domain_uuid
                  AND (q.queue_extension || '@' || d.domain_name) = m.cc_queue
            LEFT JOIN v_call_center_agents a
                   ON a.call_center_agent_uuid::text = m.cc_agent
            WHERE " . implode(" AND ", $where) . "
            ORDER BY m.start_stamp DESC, m.xml_cdr_uuid DESC
            LIMIT " . $limit . " OFFSET " . $offset;
    $rows = $database->select($sql, $parameters, 'all');
    if (!is_array($rows)) { $rows = array(); }

    // Ring attempts for this page's callers only, bounded like the main query.
    $attempts = array();
    if ($rows) {
        $in = array();
        $p = array('domain_uuid' => $domain_uuid, 'start_date' => $start, 'end_date' => $end);
        foreach ($rows as $i => $r) {
            $in[] = ":u" . $i;
            $p['u' . $i] = $r['xml_cdr_uuid'];
        }
        $att = $database->select(
            "SELECT cc_member_session_uuid::text AS member, min(start_epoch) AS first_ring, count(*) AS n
               FROM v_xml_cdr
              WHERE domain_uuid = :domain_uuid AND cc_side = 'agent'
                AND start_stamp >= (:start_date::timestamptz - interval '1 hour')
                AND start_stamp <= (:end_date::timestamptz + interval '4 hours')
                AND cc_member_session_uuid::text IN (" . implode(", ", $in) . ")
              GROUP BY cc_member_session_uuid",
            $p, 'all');
        if (is_array($att)) {
            foreach ($att as $a) { $attempts[$a['member']] = $a; }
        }
    }

    $calls = array();
    foreach ($rows as $r) {
        $joined = (int) $r['cc_queue_joined_epoch'];
        $answered_at = (int) $r['cc_queue_answered_epoch'];
        $end_epoch = (int) $r['end_epoch'];
        $answered = $answered_at > 0;
        $left = 0;
        foreach (array('cc_queue_canceled_epoch', 'cc_queue_terminated_epoch') as $k) {
            if ((int) $r[$k] > 0) { $left = (int) $r[$k]; break; }
        }
        if (!$left) { $left = $end_epoch; }

        $hold = max(0, (int) $r['hold_accum_seconds']);
        $connected = $answered ? max(0, $end_epoch - $answered_at) : 0;
        $att = isset($attempts[$r['xml_cdr_uuid']]) ? $attempts[$r['xml_cdr_uuid']] : null;
        $ring = null;
        if ($answered && $att && (int) $att['first_ring'] > 0) {
            $ring = max(0, $answered_at - (int) $att['first_ring']);
        }

        $reason = strtoupper((string) $r['cc_cancel_reason']);
        if ($answered) { $status_out = 'answered'; }
        elseif ($reason === 'TIMEOUT') { $status_out = 'timeout'; }
        elseif ($reason === 'NO_AGENT_TIMEOUT') { $status_out = 'no_agent'; }
        else { $status_out = 'abandoned'; }

        // Seen from the caller's own channel: recv_* means the caller's side
        // ended it, send_* means ours did — the agent once answered, else the system.
        $disc = (string) $r['sip_hangup_disposition'];
        if (strpos($disc, 'recv_') === 0) { $initiator = 'customer'; }
        elseif (strpos($disc, 'send_') === 0) { $initiator = $answered ? 'agent' : 'system'; }
        else { $initiator = ''; }

        $agent_ext = '';
        if (preg_match('/(?:user|sofia\/[^\/]+)\/([^@\/]+)@/', (string) $r['agent_contact'], $m)) {
            $agent_ext = $m[1];
        }

        $calls[] = array(
            'call_id' => $r['xml_cdr_uuid'],
            'start_stamp' => $r['start_stamp'],
            'end_stamp' => $r['end_stamp'],
            'start_epoch' => (int) $r['start_epoch'],
            'end_epoch' => $end_epoch,
            'caller_number' => $r['caller_id_number'],
            'caller_name' => $r['caller_id_name'],
            'did' => $r['caller_destination'],
            'destination' => $r['destination_number'],
            'queue_uuid' => $r['queue_uuid'],
            'queue_name' => $r['queue_name'],
            'queue_extension' => $r['queue_extension'],
            'cc_queue' => $r['cc_queue'],
            'joined_epoch' => $joined,
            'answered_epoch' => $answered ? $answered_at : null,
            'abandoned' => !$answered,
            'status' => $status_out,
            'cancel_reason' => $r['cc_cancel_reason'],
            'agent_uuid' => $r['cc_agent'] ?: null,
            'agent_name' => $answered ? $r['agent_name'] : null,
            'agent_extension' => $answered ? $agent_ext : null,
            'time_in_queue' => $joined > 0 ? max(0, ($answered ? $answered_at : $left) - $joined) : null,
            'ring_time' => $ring,
            'ring_attempts' => $att ? (int) $att['n'] : 0,
            // Hold happens inside the connected time; talk is what is left.
            'talk_time' => $answered ? max(0, $connected - $hold) : 0,
            'hold_time' => $hold,
            'connected_time' => $connected,
            'disc_party' => $disc,
            'hangup_initiator' => $initiator,
            'hangup_cause' => $r['hangup_cause'],
            'has_recording' => !empty($r['record_name']),
            'record_length' => (int) $r['record_length'],
        );
    }

    return array(
        "success" => true,
        "total" => (int) $total,
        "count" => count($calls),
        "calls" => $calls,
    );
}
