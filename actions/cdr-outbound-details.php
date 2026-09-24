<?php
$required_params = array("domain_uuid");

/**
 * cdr-outbound-details — one row per outbound call, shaped like
 * cdr-inbound-details so one report can show either direction.
 *
 *   start_date / end_date   "YYYY-MM-DD" (whole day) or a full timestamp
 *   limit / offset          default 100 / 0, limit capped at 1000
 *   status                  optional: answered | unanswered
 *
 * WHICH LEG THE ROW IS, and why it matters (verified on CCL 2026-09-24,
 * 20,033 outbound records in 60 days):
 *   * An AGENT-DIALLED call is stored as the agent's own leg — extension_uuid
 *     is the agent, caller_id_number the outbound caller ID (the DID shown to
 *     the customer), destination_number the customer. On THAT leg, recv_bye
 *     means the agent's phone hung up; send_bye means the far side did.
 *   * A CAMPAIGN/DIALER call (no extension_uuid) is the leg towards the
 *     customer, where recv_bye means the customer hung up.
 *   * answer_epoch is 0, not null, when the call was never answered — so ring
 *     time is answer - start only when answered, else the whole attempt.
 */

if (!function_exists('cdr_outbound_stamp')) {
    function cdr_outbound_stamp($value, $end = false) {
        $v = trim((string) $value);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
            return $v . ($end ? ' 23:59:59' : ' 00:00:00');
        }
        return $v;
    }
}

function do_action($body) {
    $where = array("c.domain_uuid = :domain_uuid", "c.direction = 'outbound'");
    $parameters = array('domain_uuid' => $body->domain_uuid);

    $start = !empty($body->start_date) ? cdr_outbound_stamp($body->start_date, false) : date('Y-m-d') . ' 00:00:00';
    $end = !empty($body->end_date) ? cdr_outbound_stamp($body->end_date, true) : date('Y-m-d') . ' 23:59:59';
    $where[] = "c.start_stamp >= :start_date";
    $where[] = "c.start_stamp <= :end_date";
    $parameters['start_date'] = $start;
    $parameters['end_date'] = $end;

    $status = isset($body->status) ? strtolower(trim((string) $body->status)) : '';
    if ($status === 'answered') {
        $where[] = "coalesce(c.billsec, 0) > 0";
    } elseif ($status === 'unanswered') {
        $where[] = "coalesce(c.billsec, 0) = 0";
    }

    $limit = isset($body->limit) ? (int) $body->limit : 100;
    if ($limit < 1) { $limit = 1; }
    if ($limit > 1000) { $limit = 1000; }
    $offset = isset($body->offset) ? (int) $body->offset : 0;
    if ($offset < 0) { $offset = 0; }

    $database = new database;
    $total = $database->select(
        "SELECT count(*) FROM v_xml_cdr c WHERE " . implode(" AND ", $where),
        $parameters, 'column');

    $rows = $database->select(
        "SELECT c.xml_cdr_uuid, c.start_stamp, c.end_stamp, c.start_epoch, c.answer_epoch, c.end_epoch,
                c.caller_id_number, c.caller_id_name, c.destination_number,
                c.billsec, c.duration, c.hold_accum_seconds, c.sip_hangup_disposition,
                c.hangup_cause, c.record_name, c.record_length,
                c.extension_uuid, e.extension, e.effective_caller_id_name
           FROM v_xml_cdr c
           LEFT JOIN v_extensions e ON e.extension_uuid = c.extension_uuid
          WHERE " . implode(" AND ", $where) . "
          ORDER BY c.start_stamp DESC, c.xml_cdr_uuid DESC
          LIMIT " . $limit . " OFFSET " . $offset,
        $parameters, 'all');
    if (!is_array($rows)) { $rows = array(); }

    $calls = array();
    foreach ($rows as $r) {
        $start_epoch = (int) $r['start_epoch'];
        $answer_epoch = (int) $r['answer_epoch'];
        $end_epoch = (int) $r['end_epoch'];
        $billsec = max(0, (int) $r['billsec']);
        $answered = $billsec > 0 && $answer_epoch > 0;
        $hold = max(0, (int) $r['hold_accum_seconds']);
        $agent_leg = !empty($r['extension_uuid']);

        $cause = strtoupper((string) $r['hangup_cause']);
        if ($answered) { $status_out = 'answered'; }
        elseif ($cause === 'USER_BUSY') { $status_out = 'busy'; }
        elseif ($cause === 'ORIGINATOR_CANCEL') { $status_out = 'cancelled'; }
        elseif (in_array($cause, array('NO_ANSWER', 'NO_USER_RESPONSE', 'ALLOTTED_TIMEOUT', 'RECOVERY_ON_TIMER_EXPIRE'))) { $status_out = 'no_answer'; }
        elseif ($cause === 'CALL_REJECTED') { $status_out = 'rejected'; }
        else { $status_out = 'failed'; }

        $disc = (string) $r['sip_hangup_disposition'];
        $recv = strpos($disc, 'recv_') === 0;
        $send = strpos($disc, 'send_') === 0;
        if (!$recv && !$send) { $initiator = ''; }
        elseif ($agent_leg) { $initiator = $recv ? 'agent' : ($answered ? 'customer' : 'system'); }
        else { $initiator = $recv ? 'customer' : 'system'; }

        $calls[] = array(
            'call_id' => $r['xml_cdr_uuid'],
            'start_stamp' => $r['start_stamp'],
            'end_stamp' => $r['end_stamp'],
            'start_epoch' => $start_epoch,
            'end_epoch' => $end_epoch,
            // For an outbound call the "DID" is the caller ID the customer saw.
            'did' => $r['caller_id_number'],
            'destination' => $r['destination_number'],
            'customer_number' => $r['destination_number'],
            'answered' => $answered,
            'abandoned' => !$answered,
            'status' => $status_out,
            'hangup_cause' => $r['hangup_cause'],
            'agent_extension' => $agent_leg ? $r['extension'] : null,
            'agent_name' => $agent_leg ? $r['effective_caller_id_name'] : null,
            'campaign' => !$agent_leg,
            'time_in_queue' => null,
            'ring_time' => $answered ? max(0, $answer_epoch - $start_epoch) : max(0, $end_epoch - $start_epoch),
            'talk_time' => $answered ? max(0, $billsec - $hold) : 0,
            'hold_time' => $hold,
            'connected_time' => $billsec,
            'disc_party' => $disc,
            'hangup_initiator' => $initiator,
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
