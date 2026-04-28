<?php

$required_params = array("callBroadcastUuid");

function do_action($body) {
    global $domain_uuid;

    $db_domain_uuid = isset($body->domain_uuid) ? $body->domain_uuid :
                     (isset($body->domainUuid) ? $body->domainUuid : $domain_uuid);

    $call_broadcast_uuid = isset($body->callBroadcastUuid) ? $body->callBroadcastUuid :
                          (isset($body->call_broadcast_uuid) ? $body->call_broadcast_uuid : null);

    if (empty($call_broadcast_uuid)) {
        return array("success" => false, "error" => "callBroadcastUuid is required");
    }

    $status_filter = isset($body->statusFilter) ? $body->statusFilter :
                    (isset($body->status_filter) ? $body->status_filter : null);

    $limit = isset($body->limit) ? intval($body->limit) : 100;
    $offset = isset($body->offset) ? intval($body->offset) : 0;

    $database = new database;

    // Get summary counts
    $summary_sql = "SELECT lead_status, COUNT(*) as count
                    FROM v_call_broadcast_leads
                    WHERE call_broadcast_uuid = :broadcast_uuid AND domain_uuid = :domain_uuid
                    GROUP BY lead_status";
    $summary = $database->select($summary_sql, array(
        "broadcast_uuid" => $call_broadcast_uuid,
        "domain_uuid" => $db_domain_uuid
    ), "all");

    $counts = array(
        "total" => 0, "pending" => 0, "calling" => 0, "answered" => 0,
        "no_answer" => 0, "busy" => 0, "failed" => 0, "retry_pending" => 0,
        "completed" => 0, "skipped" => 0
    );
    if (is_array($summary)) {
        foreach ($summary as $row) {
            $counts[$row['lead_status']] = intval($row['count']);
            $counts['total'] += intval($row['count']);
        }
    }

    // Get leads with optional filter
    $sql = "SELECT call_broadcast_lead_uuid, phone_number, lead_status, hangup_cause,
                   attempts, max_attempts, next_retry_at, last_attempt_at,
                   call_duration, billsec, insert_date, update_date
            FROM v_call_broadcast_leads
            WHERE call_broadcast_uuid = :broadcast_uuid AND domain_uuid = :domain_uuid";
    $params = array(
        "broadcast_uuid" => $call_broadcast_uuid,
        "domain_uuid" => $db_domain_uuid
    );

    if (!empty($status_filter)) {
        $sql .= " AND lead_status = :status_filter";
        $params["status_filter"] = $status_filter;
    }

    $sql .= " ORDER BY insert_date ASC LIMIT :limit OFFSET :offset";
    $params["limit"] = $limit;
    $params["offset"] = $offset;

    $leads = $database->select($sql, $params, "all");
    if (!is_array($leads)) $leads = array();

    return array(
        "success" => true,
        "callBroadcastUuid" => $call_broadcast_uuid,
        "summary" => $counts,
        "leads" => $leads,
        "limit" => $limit,
        "offset" => $offset
    );
}
