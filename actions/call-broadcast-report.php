<?php

$required_params = array("callBroadcastUuid");

function do_action($body) {
    global $domain_uuid;

    $db_domain_uuid = isset($body->domainUuid) ? $body->domainUuid :
                     (isset($body->domain_uuid) ? $body->domain_uuid : $domain_uuid);

    $call_broadcast_uuid = isset($body->callBroadcastUuid) ? $body->callBroadcastUuid :
                          (isset($body->call_broadcast_uuid) ? $body->call_broadcast_uuid : null);

    if (empty($call_broadcast_uuid)) {
        return array("success" => false, "error" => "callBroadcastUuid is required");
    }

    $database = new database;

    // 1. Get broadcast details
    $sql_broadcast = "SELECT
                call_broadcast_uuid,
                broadcast_name,
                broadcast_description,
                broadcast_status,
                broadcast_start_time,
                broadcast_timeout,
                broadcast_concurrent_limit,
                broadcast_caller_id_name,
                broadcast_caller_id_number,
                broadcast_destination_type,
                broadcast_destination_data,
                broadcast_avmd,
                broadcast_pacing_mode,
                broadcast_dial_ratio,
                broadcast_max_abandon_rate,
                broadcast_current_dial_ratio,
                broadcast_total_answered,
                broadcast_total_abandoned,
                broadcast_avg_talk_time,
                broadcast_retry_enabled,
                broadcast_retry_max,
                broadcast_retry_interval,
                broadcast_schedule_enabled,
                broadcast_schedule_type,
                broadcast_last_run,
                insert_date,
                update_date
            FROM v_call_broadcasts
            WHERE call_broadcast_uuid = :broadcast_uuid AND domain_uuid = :domain_uuid";

    $broadcast = $database->select($sql_broadcast, array(
        "broadcast_uuid" => $call_broadcast_uuid,
        "domain_uuid" => $db_domain_uuid
    ), "row");

    if (empty($broadcast)) {
        return array("success" => false, "error" => "Broadcast not found");
    }

    // 2. Lead status summary counts
    $sql_summary = "SELECT lead_status, COUNT(*) as count
                    FROM v_call_broadcast_leads
                    WHERE call_broadcast_uuid = :broadcast_uuid AND domain_uuid = :domain_uuid
                    GROUP BY lead_status";
    $summary_rows = $database->select($sql_summary, array(
        "broadcast_uuid" => $call_broadcast_uuid,
        "domain_uuid" => $db_domain_uuid
    ), "all");

    $lead_counts = array(
        "total" => 0, "pending" => 0, "calling" => 0, "answered" => 0,
        "no_answer" => 0, "busy" => 0, "failed" => 0, "retry_pending" => 0,
        "completed" => 0, "skipped" => 0
    );
    if (is_array($summary_rows)) {
        foreach ($summary_rows as $row) {
            $status = $row['lead_status'];
            $cnt = intval($row['count']);
            if (isset($lead_counts[$status])) {
                $lead_counts[$status] = $cnt;
            }
            $lead_counts['total'] += $cnt;
        }
    }

    // 3. CDR stats from v_call_broadcast_leads (calls that have duration data)
    $sql_cdr_stats = "SELECT
                COUNT(*) as total_dialed,
                COUNT(CASE WHEN lead_status = 'answered' OR lead_status = 'completed' THEN 1 END) as total_answered,
                COUNT(CASE WHEN lead_status = 'no_answer' THEN 1 END) as total_no_answer,
                COUNT(CASE WHEN lead_status = 'busy' THEN 1 END) as total_busy,
                COUNT(CASE WHEN lead_status = 'failed' THEN 1 END) as total_failed,
                COALESCE(SUM(CASE WHEN billsec > 0 THEN billsec ELSE 0 END), 0) as total_talk_seconds,
                COALESCE(AVG(CASE WHEN billsec > 0 THEN billsec END), 0) as avg_talk_seconds,
                COALESCE(MAX(CASE WHEN billsec > 0 THEN billsec END), 0) as max_talk_seconds,
                COALESCE(MIN(CASE WHEN billsec > 0 THEN billsec ELSE NULL END), 0) as min_talk_seconds,
                COALESCE(SUM(CASE WHEN call_duration > 0 THEN call_duration ELSE 0 END), 0) as total_call_seconds,
                COALESCE(AVG(CASE WHEN call_duration > 0 THEN call_duration END), 0) as avg_call_seconds
            FROM v_call_broadcast_leads
            WHERE call_broadcast_uuid = :broadcast_uuid
            AND domain_uuid = :domain_uuid
            AND lead_status NOT IN ('pending', 'retry_pending', 'skipped')";

    $cdr_stats = $database->select($sql_cdr_stats, array(
        "broadcast_uuid" => $call_broadcast_uuid,
        "domain_uuid" => $db_domain_uuid
    ), "row");

    // 4. Currently active calls (calling state)
    $sql_active = "SELECT phone_number, last_attempt_at
                   FROM v_call_broadcast_leads
                   WHERE call_broadcast_uuid = :broadcast_uuid
                   AND domain_uuid = :domain_uuid
                   AND lead_status = 'calling'
                   ORDER BY last_attempt_at DESC";
    $active_calls = $database->select($sql_active, array(
        "broadcast_uuid" => $call_broadcast_uuid,
        "domain_uuid" => $db_domain_uuid
    ), "all");
    if (!is_array($active_calls)) $active_calls = array();

    // 5. Hangup cause breakdown
    $sql_hangup = "SELECT hangup_cause, COUNT(*) as count
                   FROM v_call_broadcast_leads
                   WHERE call_broadcast_uuid = :broadcast_uuid
                   AND domain_uuid = :domain_uuid
                   AND hangup_cause IS NOT NULL AND hangup_cause != ''
                   GROUP BY hangup_cause
                   ORDER BY count DESC";
    $hangup_causes = $database->select($sql_hangup, array(
        "broadcast_uuid" => $call_broadcast_uuid,
        "domain_uuid" => $db_domain_uuid
    ), "all");
    if (!is_array($hangup_causes)) $hangup_causes = array();

    // 6. Retry stats
    $sql_retry = "SELECT
                COUNT(CASE WHEN attempts > 1 THEN 1 END) as retried_leads,
                COALESCE(SUM(attempts), 0) as total_attempts,
                COALESCE(MAX(attempts), 0) as max_attempts_used
            FROM v_call_broadcast_leads
            WHERE call_broadcast_uuid = :broadcast_uuid
            AND domain_uuid = :domain_uuid";
    $retry_stats = $database->select($sql_retry, array(
        "broadcast_uuid" => $call_broadcast_uuid,
        "domain_uuid" => $db_domain_uuid
    ), "row");

    // 7. Timeline: calls per minute (last 60 minutes or since campaign started)
    $sql_timeline = "SELECT
                date_trunc('minute', last_attempt_at) as minute_bucket,
                COUNT(*) as calls,
                COUNT(CASE WHEN lead_status IN ('answered', 'completed') THEN 1 END) as answered
            FROM v_call_broadcast_leads
            WHERE call_broadcast_uuid = :broadcast_uuid
            AND domain_uuid = :domain_uuid
            AND last_attempt_at IS NOT NULL
            GROUP BY date_trunc('minute', last_attempt_at)
            ORDER BY minute_bucket DESC
            LIMIT 60";
    $timeline = $database->select($sql_timeline, array(
        "broadcast_uuid" => $call_broadcast_uuid,
        "domain_uuid" => $db_domain_uuid
    ), "all");
    if (!is_array($timeline)) $timeline = array();

    // Calculate derived metrics
    $total_dialed = intval($cdr_stats['total_dialed'] ?? 0);
    $total_answered = intval($cdr_stats['total_answered'] ?? 0);
    $answer_rate = $total_dialed > 0 ? round(($total_answered / $total_dialed) * 100, 1) : 0;

    $remaining = $lead_counts['pending'] + $lead_counts['retry_pending'];
    $progress_pct = $lead_counts['total'] > 0 ? round((($lead_counts['total'] - $remaining) / $lead_counts['total']) * 100, 1) : 0;

    $abandon_rate = $total_answered > 0 ? round((intval($broadcast['broadcast_total_abandoned'] ?? 0) / $total_answered) * 100, 1) : 0;

    return array(
        "success" => true,
        "report" => array(
            // Campaign info
            "campaign" => array(
                "uuid" => $broadcast['call_broadcast_uuid'],
                "name" => $broadcast['broadcast_name'],
                "description" => $broadcast['broadcast_description'],
                "status" => $broadcast['broadcast_status'],
                "pacingMode" => $broadcast['broadcast_pacing_mode'],
                "dialRatio" => $broadcast['broadcast_dial_ratio'],
                "currentDialRatio" => $broadcast['broadcast_current_dial_ratio'],
                "maxAbandonRate" => $broadcast['broadcast_max_abandon_rate'],
                "concurrentLimit" => $broadcast['broadcast_concurrent_limit'],
                "retryEnabled" => $broadcast['broadcast_retry_enabled'],
                "retryMax" => $broadcast['broadcast_retry_max'],
                "callerIdName" => $broadcast['broadcast_caller_id_name'],
                "callerIdNumber" => $broadcast['broadcast_caller_id_number'],
                "destinationType" => $broadcast['broadcast_destination_type'],
                "destinationData" => $broadcast['broadcast_destination_data'],
                "lastRun" => $broadcast['broadcast_last_run'],
                "createdAt" => $broadcast['insert_date'],
            ),

            // Live status
            "liveStatus" => array(
                "currentStatus" => $broadcast['broadcast_status'],
                "activeCallsCount" => count($active_calls),
                "activeCalls" => $active_calls,
                "remainingLeads" => $remaining,
                "progressPercent" => $progress_pct,
            ),

            // Lead counts by status
            "leadCounts" => $lead_counts,

            // CDR statistics
            "callStats" => array(
                "totalDialed" => $total_dialed,
                "totalAnswered" => $total_answered,
                "totalNoAnswer" => intval($cdr_stats['total_no_answer'] ?? 0),
                "totalBusy" => intval($cdr_stats['total_busy'] ?? 0),
                "totalFailed" => intval($cdr_stats['total_failed'] ?? 0),
                "answerRate" => $answer_rate,
                "abandonRate" => $abandon_rate,
                "totalTalkSeconds" => intval($cdr_stats['total_talk_seconds'] ?? 0),
                "avgTalkSeconds" => round(floatval($cdr_stats['avg_talk_seconds'] ?? 0)),
                "maxTalkSeconds" => intval($cdr_stats['max_talk_seconds'] ?? 0),
                "minTalkSeconds" => intval($cdr_stats['min_talk_seconds'] ?? 0),
                "totalCallSeconds" => intval($cdr_stats['total_call_seconds'] ?? 0),
                "avgCallSeconds" => round(floatval($cdr_stats['avg_call_seconds'] ?? 0)),
            ),

            // Retry stats
            "retryStats" => array(
                "retriedLeads" => intval($retry_stats['retried_leads'] ?? 0),
                "totalAttempts" => intval($retry_stats['total_attempts'] ?? 0),
                "maxAttemptsUsed" => intval($retry_stats['max_attempts_used'] ?? 0),
            ),

            // Hangup cause breakdown
            "hangupCauses" => $hangup_causes,

            // Timeline (calls per minute)
            "timeline" => $timeline,
        )
    );
}
