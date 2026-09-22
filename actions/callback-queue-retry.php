<?php
/**
 * Put a failed or cancelled callback back in the queue.
 *
 * Resets it to pending and schedules the next attempt using the config's retry
 * interval, so a retry behaves like a normal attempt rather than firing
 * immediately at whatever hour someone happened to press the button.
 *
 * The attempt counter is NOT reset. It is the record of how many times this
 * customer has already been rung, and clearing it would let a number be dialled
 * past max_attempts indefinitely, one manual retry at a time. Instead, a
 * callback that has used up its attempts gets one more by raising its own
 * ceiling by one -- deliberate, visible in the row, and bounded.
 *
 * Refuses a callback that is already pending, calling or completed: the first
 * two are in flight and the third has nothing to retry.
 */
require_once(__DIR__ . '/callback-helper.php');

$required_params = array("callbackUuid");

function do_action($body) {
    global $domain_uuid;
    ensure_callback_tables_exist();
    $database = new database;

    $req_domain_uuid = isset($body->domainUuid) ? $body->domainUuid : $domain_uuid;
    $uuid = $body->callbackUuid;

    $sql = "SELECT * FROM v_callback_queue WHERE callback_uuid = :uuid";
    $params = array("uuid" => $uuid);
    if (!empty($req_domain_uuid)) {
        $sql .= " AND domain_uuid = :domain_uuid";
        $params["domain_uuid"] = $req_domain_uuid;
    }
    $callback = $database->select($sql, $params, 'row');
    if (!$callback) {
        return array("success" => false, "error" => "Callback not found");
    }

    $status = $callback['status'];
    if ($status === 'pending' || $status === 'calling') {
        return array("success" => false,
                     "error" => "Callback is already $status - nothing to retry");
    }
    if ($status === 'completed') {
        return array("success" => false,
                     "error" => "Callback already completed; create a new one instead");
    }

    $retry_interval = 300;
    if (!empty($callback['callback_config_uuid'])) {
        $cfg = $database->select(
            "SELECT retry_interval FROM v_callback_configs WHERE callback_config_uuid = :c",
            array("c" => $callback['callback_config_uuid']), 'row');
        if (!empty($cfg['retry_interval'])) { $retry_interval = (int) $cfg['retry_interval']; }
    }

    $attempts     = (int) $callback['attempts'];
    $max_attempts = (int) $callback['max_attempts'];
    if ($attempts >= $max_attempts) { $max_attempts = $attempts + 1; }

    $database->execute(
        "UPDATE v_callback_queue
            SET status = 'pending',
                max_attempts = :max_attempts,
                next_attempt_time = NOW() + (:secs || ' seconds')::interval,
                callback_result = NULL,
                updated_date = NOW()
          WHERE callback_uuid = :uuid",
        array("max_attempts" => $max_attempts, "secs" => $retry_interval, "uuid" => $uuid));

    $callback = $database->select(
        "SELECT * FROM v_callback_queue WHERE callback_uuid = :uuid",
        array("uuid" => $uuid), 'row');

    return array(
        "success" => true,
        "message" => "Callback requeued; next attempt in " . $retry_interval . " seconds",
        "callback" => format_callback_response($callback));
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
