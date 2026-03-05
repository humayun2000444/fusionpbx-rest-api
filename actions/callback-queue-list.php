<?php
require_once(__DIR__ . '/callback-helper.php');

$required_params = array();

function do_action($body) {
    global $domain_uuid;

    // Auto-install tables if needed
    ensure_callback_tables_exist();

    $database = new database;

    // Get domain UUID
    $req_domain_uuid = isset($body->domainUuid) ? $body->domainUuid : $domain_uuid;

    if (empty($req_domain_uuid)) {
        return array(
            "success" => false,
            "error" => "Domain UUID is required"
        );
    }

    // Build query
    $sql = "SELECT q.*, c.config_name
            FROM v_callback_queue q
            LEFT JOIN v_callback_configs c ON q.callback_config_uuid = c.callback_config_uuid
            WHERE q.domain_uuid = :domain_uuid";

    $params = array("domain_uuid" => $req_domain_uuid);

    // Filter by status
    if (isset($body->status) && !empty($body->status)) {
        $sql .= " AND q.status = :status";
        $params["status"] = $body->status;
    }

    // Filter by queue
    if (isset($body->queueUuid) && !empty($body->queueUuid)) {
        $sql .= " AND q.queue_uuid = :queue_uuid";
        $params["queue_uuid"] = $body->queueUuid;
    }

    // Filter by caller number
    if (isset($body->callerIdNumber) && !empty($body->callerIdNumber)) {
        $sql .= " AND q.caller_id_number LIKE :caller_id_number";
        $params["caller_id_number"] = "%" . $body->callerIdNumber . "%";
    }

    // Filter by date range
    if (isset($body->startDate) && !empty($body->startDate)) {
        $sql .= " AND q.created_date >= :start_date";
        $params["start_date"] = $body->startDate;
    }

    if (isset($body->endDate) && !empty($body->endDate)) {
        $sql .= " AND q.created_date <= :end_date";
        $params["end_date"] = $body->endDate;
    }

    // Pagination
    $limit = isset($body->limit) ? (int)$body->limit : 50;
    $offset = isset($body->offset) ? (int)$body->offset : 0;

    // Get total count
    $count_sql = str_replace("SELECT q.*, c.config_name", "SELECT COUNT(*) as total", $sql);
    $count_result = $database->select($count_sql, $params, 'row');
    $total_count = $count_result ? (int)$count_result['total'] : 0;

    // Add order and pagination
    $sql .= " ORDER BY q.priority DESC, q.next_attempt_time ASC LIMIT :limit OFFSET :offset";
    $params["limit"] = $limit;
    $params["offset"] = $offset;

    $callbacks = $database->select($sql, $params, 'all');

    if (!$callbacks) {
        $callbacks = array();
    }

    // Format response
    $result = array();
    foreach ($callbacks as $callback) {
        $result[] = format_callback_response($callback);
    }

    return array(
        "success" => true,
        "callbacks" => $result,
        "count" => count($result),
        "totalCount" => $total_count,
        "limit" => $limit,
        "offset" => $offset
    );
}

function format_callback_response($callback) {
    return array(
        "callbackUuid" => $callback['callback_uuid'],
        "domainUuid" => $callback['domain_uuid'],
        "configName" => isset($callback['config_name']) ? $callback['config_name'] : null,
        "callerIdName" => $callback['caller_id_name'],
        "callerIdNumber" => $callback['caller_id_number'],
        "destinationNumber" => $callback['destination_number'],
        "queueUuid" => $callback['queue_uuid'],
        "queueName" => $callback['queue_name'],
        "originalCallUuid" => $callback['original_call_uuid'],
        "originalCallTime" => $callback['original_call_time'],
        "hangupCause" => $callback['hangup_cause'],
        "status" => $callback['status'],
        "priority" => (int)$callback['priority'],
        "attempts" => (int)$callback['attempts'],
        "maxAttempts" => (int)$callback['max_attempts'],
        "lastAttemptTime" => $callback['last_attempt_time'],
        "nextAttemptTime" => $callback['next_attempt_time'],
        "scheduledTime" => $callback['scheduled_time'],
        "callbackCallUuid" => $callback['callback_call_uuid'],
        "callbackStartTime" => $callback['callback_start_time'],
        "callbackAnswerTime" => $callback['callback_answer_time'],
        "callbackEndTime" => $callback['callback_end_time'],
        "callbackResult" => $callback['callback_result'],
        "notes" => $callback['notes'],
        "createdDate" => $callback['created_date'],
        "completedDate" => $callback['completed_date']
    );
}
?>
