<?php
/**
 * event-guard-log-list.php
 * Lists event guard logs with optional domain filtering
 *
 * Parameters:
 *   domain_name  - (optional) filter by domain in extension field (e.g. "pbx-btcl-md.btcliptelephony.gov.bd")
 *   filter       - (optional) "sip-auth-ip" or "sip-auth-fail"
 *   log_status   - (optional) "blocked" or "unblocked"
 *   search       - (optional) search across ip_address, extension, user_agent
 *   page         - (optional) page number, default 0
 *   rows_per_page - (optional) default 50
 *   order_by     - (optional) column to sort by, default "log_date"
 *   order        - (optional) "asc" or "desc", default "desc"
 */

$required_params = array();

function do_action($body) {
    $domain_name = isset($body->domain_name) ? $body->domain_name : null;
    $domain_uuid = isset($body->domain_uuid) ? $body->domain_uuid : null;

    // Resolve domain_uuid to domain_name if provided
    if (!empty($domain_uuid) && empty($domain_name)) {
        $sql = "SELECT domain_name FROM v_domains WHERE domain_uuid = :domain_uuid";
        $database = new database;
        $domain_name = $database->select($sql, ['domain_uuid' => $domain_uuid], 'column');
    }

    $filter = isset($body->filter) ? $body->filter : null;
    $log_status = isset($body->log_status) ? $body->log_status : null;
    $search = isset($body->search) ? $body->search : null;
    $page = isset($body->page) ? intval($body->page) : 0;
    $rows_per_page = isset($body->rows_per_page) ? intval($body->rows_per_page) : 50;
    $order_by = isset($body->order_by) ? $body->order_by : 'log_date';
    $order = isset($body->order) ? $body->order : 'desc';

    $allowed_columns = ['log_date', 'ip_address', 'extension', 'filter', 'log_status', 'hostname'];
    if (!in_array($order_by, $allowed_columns)) {
        $order_by = 'log_date';
    }
    $order = strtolower($order) === 'asc' ? 'asc' : 'desc';

    $parameters = [];
    $where_clauses = ["true"];

    if (!empty($domain_name)) {
        $where_clauses[] = "extension LIKE :domain_pattern";
        $parameters['domain_pattern'] = '%@' . $domain_name;
    }

    if (!empty($filter)) {
        $where_clauses[] = "filter = :filter";
        $parameters['filter'] = $filter;
    }

    if (!empty($log_status)) {
        $where_clauses[] = "log_status = :log_status";
        $parameters['log_status'] = $log_status;
    }

    if (!empty($search)) {
        $where_clauses[] = "(lower(ip_address) LIKE :search OR lower(extension) LIKE :search OR lower(user_agent) LIKE :search OR lower(hostname) LIKE :search)";
        $parameters['search'] = '%' . strtolower($search) . '%';
    }

    $where_sql = implode(" AND ", $where_clauses);

    // Get count
    $sql = "SELECT count(event_guard_log_uuid) FROM v_event_guard_logs WHERE " . $where_sql;
    $database = new database;
    $total_count = $database->select($sql, !empty($parameters) ? $parameters : null, 'column');

    // Get data
    $offset = $rows_per_page * $page;
    $sql = "SELECT event_guard_log_uuid, hostname, log_date, filter, ip_address, extension, user_agent, log_status ";
    $sql .= "FROM v_event_guard_logs WHERE " . $where_sql . " ";
    $sql .= "ORDER BY " . $order_by . " " . $order . " ";
    $sql .= "LIMIT :limit OFFSET :offset";
    $parameters['limit'] = $rows_per_page;
    $parameters['offset'] = $offset;

    $database = new database;
    $rows = $database->select($sql, $parameters, 'all');

    $logs = [];
    if ($rows && is_array($rows)) {
        foreach ($rows as $row) {
            $ext_parts = explode('@', $row['extension']);
            $logs[] = [
                'eventGuardLogUuid' => $row['event_guard_log_uuid'],
                'hostname' => $row['hostname'],
                'logDate' => $row['log_date'],
                'filter' => $row['filter'],
                'ipAddress' => $row['ip_address'],
                'extension' => $ext_parts[0],
                'domain' => isset($ext_parts[1]) ? $ext_parts[1] : '',
                'userAgent' => $row['user_agent'],
                'logStatus' => $row['log_status']
            ];
        }
    }

    // Get summary stats
    $stats_sql = "SELECT log_status, count(*) as cnt FROM v_event_guard_logs WHERE " . implode(" AND ", array_slice($where_clauses, 0, count($where_clauses))) . " GROUP BY log_status";
    $stats_params = $parameters;
    unset($stats_params['limit'], $stats_params['offset']);
    $database = new database;
    $stats_rows = $database->select($stats_sql, !empty($stats_params) ? $stats_params : null, 'all');

    $stats = ['blocked' => 0, 'unblocked' => 0];
    if ($stats_rows && is_array($stats_rows)) {
        foreach ($stats_rows as $sr) {
            $stats[$sr['log_status']] = intval($sr['cnt']);
        }
    }

    return [
        'logs' => $logs,
        'totalCount' => intval($total_count),
        'page' => $page,
        'rowsPerPage' => $rows_per_page,
        'stats' => $stats
    ];
}
