<?php
$required_params = array("domain_uuid");

/**
 * cdr-agent-attempts — how often each agent was RUNG by a queue, per day.
 *
 * A queue caller's own record names only the agent who ANSWERED. Every time an
 * agent was rung and did not answer is an agent-side record instead
 * (cc_side = 'agent': cancelled when someone else answered, refused when the
 * phone declined). Calls offered to an agent = answered + these.
 *
 *   start_date / end_date   as cdr-inbound-details
 *   tz_minutes              the viewer's offset from UTC, so "day" is theirs
 *
 * Split by the CALLER's direction: 'outbound' is a dialer/campaign call that
 * was queued to agents (PD), 'inbound' an ordinary inbound one.
 */

if (!function_exists('cdr_attempts_stamp')) {
    function cdr_attempts_stamp($value, $end = false) {
        $v = trim((string) $value);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
            return $v . ($end ? ' 23:59:59' : ' 00:00:00');
        }
        return $v;
    }
}

function do_action($body) {
    $tz = isset($body->tz_minutes) ? (int) $body->tz_minutes : 0;
    if ($tz < -840 || $tz > 840) { $tz = 0; }
    $parameters = array(
        'domain_uuid' => $body->domain_uuid,
        'start_date' => !empty($body->start_date) ? cdr_attempts_stamp($body->start_date, false) : date('Y-m-d') . ' 00:00:00',
        'end_date' => !empty($body->end_date) ? cdr_attempts_stamp($body->end_date, true) : date('Y-m-d') . ' 23:59:59',
        'tz' => $tz,
    );
    // The caller's record is joined on its primary key (xml_cdr_uuid), cast
    // on the attempt's side so the index is usable on a very large table.
    $sql = "SELECT to_char(a.start_stamp AT TIME ZONE 'UTC' + make_interval(mins => :tz), 'YYYY-MM-DD') AS day,
                   a.cc_agent AS agent_uuid, ag.agent_name, ag.agent_contact,
                   coalesce(m.direction, 'inbound') AS call_direction,
                   count(*) AS rung
              FROM v_xml_cdr a
              LEFT JOIN v_xml_cdr m
                     ON m.xml_cdr_uuid = nullif(a.cc_member_session_uuid::text, '')::uuid
              LEFT JOIN v_call_center_agents ag
                     ON ag.call_center_agent_uuid::text = a.cc_agent
             WHERE a.domain_uuid = :domain_uuid AND a.cc_side = 'agent'
               AND a.start_stamp >= :start_date AND a.start_stamp <= :end_date
             GROUP BY 1, 2, 3, 4, 5
             ORDER BY 1, 3";
    $database = new database;
    $rows = $database->select($sql, $parameters, 'all');
    if (!is_array($rows)) { $rows = array(); }
    $out = array();
    foreach ($rows as $r) {
        $ext = '';
        if (preg_match('/(?:user|sofia\/[^\/]+)\/([^@\/]+)@/', (string) $r['agent_contact'], $m)) {
            $ext = $m[1];
        }
        $out[] = array(
            'day' => $r['day'],
            'agent_uuid' => $r['agent_uuid'],
            'agent_name' => $r['agent_name'],
            'agent_extension' => $ext,
            'call_direction' => $r['call_direction'],
            'rung_unanswered' => (int) $r['rung'],
        );
    }
    return array("success" => true, "count" => count($out), "attempts" => $out);
}
