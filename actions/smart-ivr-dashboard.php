<?php
/*
 * Smart IVR - Dashboard Statistics
 * Returns aggregated statistics for dashboard
 */

$required_params = array();

function do_action($body) {
    global $domain_uuid;

    // Get domain_uuid from request or use global
    $req_domain_uuid = isset($body->domain_uuid) ? $body->domain_uuid : $domain_uuid;

    if (empty($req_domain_uuid)) {
        return array('error' => 'domain_uuid is required');
    }

    $database = new database;
    $stats = array();

    // Get Smart IVR enabled status
    $sql = "SELECT enabled FROM v_smart_ivr_config WHERE domain_uuid = :domain_uuid LIMIT 1";
    $config = $database->select($sql, array(':domain_uuid' => $req_domain_uuid), 'row');
    $stats['enabled'] = $config ? ($config['enabled'] === 't' || $config['enabled'] === true) : false;

    // Calls today
    $sql = "SELECT COUNT(*) as count FROM v_smart_ivr_call_logs
            WHERE domain_uuid = :domain_uuid
            AND call_start_time >= CURRENT_DATE";
    $result = $database->select($sql, array(':domain_uuid' => $req_domain_uuid), 'row');
    $stats['calls_today'] = (int)($result['count'] ?? 0);

    // Calls yesterday (for trend)
    $sql = "SELECT COUNT(*) as count FROM v_smart_ivr_call_logs
            WHERE domain_uuid = :domain_uuid
            AND call_start_time >= CURRENT_DATE - INTERVAL '1 day'
            AND call_start_time < CURRENT_DATE";
    $result = $database->select($sql, array(':domain_uuid' => $req_domain_uuid), 'row');
    $calls_yesterday = (int)($result['count'] ?? 0);
    $stats['calls_today_change'] = $calls_yesterday > 0
        ? round((($stats['calls_today'] - $calls_yesterday) / $calls_yesterday) * 100, 1)
        : 0;

    // Inbound calls today
    $sql = "SELECT COUNT(*) as count FROM v_smart_ivr_call_logs
            WHERE domain_uuid = :domain_uuid
            AND call_direction = 'inbound'
            AND call_start_time >= CURRENT_DATE";
    $result = $database->select($sql, array(':domain_uuid' => $req_domain_uuid), 'row');
    $stats['inbound_calls'] = (int)($result['count'] ?? 0);

    // Outbound calls today
    $sql = "SELECT COUNT(*) as count FROM v_smart_ivr_call_logs
            WHERE domain_uuid = :domain_uuid
            AND call_direction = 'outbound'
            AND call_start_time >= CURRENT_DATE";
    $result = $database->select($sql, array(':domain_uuid' => $req_domain_uuid), 'row');
    $stats['outbound_calls'] = (int)($result['count'] ?? 0);

    // Average call duration today
    $sql = "SELECT AVG(call_duration) as avg_duration FROM v_smart_ivr_call_logs
            WHERE domain_uuid = :domain_uuid
            AND call_start_time >= CURRENT_DATE
            AND call_duration > 0";
    $result = $database->select($sql, array(':domain_uuid' => $req_domain_uuid), 'row');
    $stats['avg_duration'] = round($result['avg_duration'] ?? 0);

    // Success rate (calls with duration > 0)
    $sql = "SELECT
            COUNT(*) as total,
            SUM(CASE WHEN call_duration > 0 THEN 1 ELSE 0 END) as successful
            FROM v_smart_ivr_call_logs
            WHERE domain_uuid = :domain_uuid
            AND call_start_time >= CURRENT_DATE";
    $result = $database->select($sql, array(':domain_uuid' => $req_domain_uuid), 'row');
    $total = (int)($result['total'] ?? 0);
    $successful = (int)($result['successful'] ?? 0);
    $stats['success_rate'] = $total > 0 ? round(($successful / $total) * 100, 1) : 0;

    // Total campaigns
    $sql = "SELECT COUNT(*) as count FROM v_smart_ivr_campaigns
            WHERE domain_uuid = :domain_uuid";
    $result = $database->select($sql, array(':domain_uuid' => $req_domain_uuid), 'row');
    $stats['total_campaigns'] = (int)($result['count'] ?? 0);

    // Running campaigns
    $sql = "SELECT COUNT(*) as count FROM v_smart_ivr_campaigns
            WHERE domain_uuid = :domain_uuid
            AND status = 'running'";
    $result = $database->select($sql, array(':domain_uuid' => $req_domain_uuid), 'row');
    $stats['running_campaigns'] = (int)($result['count'] ?? 0);

    // Pending queue
    $sql = "SELECT COUNT(*) as count FROM v_smart_ivr_queue
            WHERE domain_uuid = :domain_uuid
            AND status = 'pending'";
    $result = $database->select($sql, array(':domain_uuid' => $req_domain_uuid), 'row');
    $stats['pending_queue'] = (int)($result['count'] ?? 0);

    // Top queries (from queries_made JSONB field)
    $sql = "SELECT queries_made FROM v_smart_ivr_call_logs
            WHERE domain_uuid = :domain_uuid
            AND call_start_time >= CURRENT_DATE
            AND queries_made IS NOT NULL
            AND queries_made != '[]'::jsonb";
    $results = $database->select($sql, array(':domain_uuid' => $req_domain_uuid), 'all');

    $query_counts = array();
    if ($results) {
        foreach ($results as $row) {
            $queries = json_decode($row['queries_made'], true);
            if (is_array($queries)) {
                foreach ($queries as $query_type => $timestamp) {
                    if (!isset($query_counts[$query_type])) {
                        $query_counts[$query_type] = 0;
                    }
                    $query_counts[$query_type]++;
                }
            }
        }
    }

    arsort($query_counts);
    $top_queries = array();
    $query_names = array(
        'payment' => 'Payment Status',
        'academic' => 'Academic Records',
        'attendance' => 'Attendance',
        'exam' => 'Exam Results',
        'schedule' => 'Class Schedule'
    );

    foreach (array_slice($query_counts, 0, 5) as $type => $count) {
        $top_queries[] = array(
            'name' => isset($query_names[$type]) ? $query_names[$type] : ucfirst($type),
            'count' => $count
        );
    }

    $stats['top_queries'] = $top_queries;
    $stats['success'] = true;

    return $stats;
}
