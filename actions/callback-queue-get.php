<?php
/**
 * Fetch one queued callback.
 *
 * Scoped by domain: a callback row carries a customer's phone number and the
 * reason their call failed, so a uuid alone must not be enough to read it.
 */
require_once(__DIR__ . '/callback-helper.php');

$required_params = array("callbackUuid");

function do_action($body) {
    global $domain_uuid;
    ensure_callback_tables_exist();
    $database = new database;

    $req_domain_uuid = isset($body->domainUuid) ? $body->domainUuid : $domain_uuid;

    $sql = "SELECT * FROM v_callback_queue WHERE callback_uuid = :uuid";
    $params = array("uuid" => $body->callbackUuid);
    if (!empty($req_domain_uuid)) {
        $sql .= " AND domain_uuid = :domain_uuid";
        $params["domain_uuid"] = $req_domain_uuid;
    }

    $callback = $database->select($sql, $params, 'row');
    if (!$callback) {
        return array("success" => false, "error" => "Callback not found");
    }
    return array("success" => true, "callback" => format_callback_response($callback));
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
