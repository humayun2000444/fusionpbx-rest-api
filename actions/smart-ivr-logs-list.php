<?php
/*
 * Smart IVR - List Call Logs
 * Returns list of call logs with optional date filtering
 */

$required_params = array();

function do_action($body) {
    global $domain_uuid;

    // Get parameters
    $req_domain_uuid = isset($body->domain_uuid) ? $body->domain_uuid : $domain_uuid;
    $date_filter = isset($body->date_filter) ? $body->date_filter : 'today';

    if (empty($req_domain_uuid)) {
        return array('error' => 'domain_uuid is required');
    }

    $database = new database;

    // Build date filter
    $where = "domain_uuid = :domain_uuid";
    $params = array(':domain_uuid' => $req_domain_uuid);

    switch ($date_filter) {
        case 'today':
            $where .= " AND call_start_time >= CURRENT_DATE";
            break;
        case 'yesterday':
            $where .= " AND call_start_time >= CURRENT_DATE - INTERVAL '1 day' AND call_start_time < CURRENT_DATE";
            break;
        case 'last_7_days':
            $where .= " AND call_start_time >= CURRENT_DATE - INTERVAL '7 days'";
            break;
        case 'last_30_days':
            $where .= " AND call_start_time >= CURRENT_DATE - INTERVAL '30 days'";
            break;
        case 'all':
            // No additional filter
            break;
        default:
            $where .= " AND call_start_time >= CURRENT_DATE";
    }

    // Get logs
    $sql = "SELECT * FROM v_smart_ivr_call_logs
            WHERE $where
            ORDER BY call_start_time DESC
            LIMIT 1000";
    $logs = $database->select($sql, $params, 'all');

    return array(
        'success' => true,
        'logs' => $logs ? $logs : array()
    );
}
