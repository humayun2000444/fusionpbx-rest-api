<?php
require_once(__DIR__ . '/callback-helper.php');

$required_params = array("callbackUuid");

function do_action($body) {
    // Auto-install tables if needed
    ensure_callback_tables_exist();

    $database = new database;

    $callback_uuid = $body->callbackUuid;

    // Get callback
    $sql = "SELECT * FROM v_callback_queue WHERE callback_uuid = :uuid";
    $callback = $database->select($sql, array("uuid" => $callback_uuid), 'row');

    if (!$callback) {
        return array(
            "success" => false,
            "error" => "Callback not found"
        );
    }

    // Check if already completed or cancelled
    $status = $callback['status'];
    if ($status === 'completed' || $status === 'cancelled') {
        return array(
            "success" => false,
            "error" => "Callback is already " . $status
        );
    }

    // Update status to cancelled
    $sql = "UPDATE v_callback_queue
            SET status = 'cancelled',
                updated_date = NOW(),
                notes = COALESCE(notes || E'\\n', '') || 'Cancelled by user at ' || NOW()
            WHERE callback_uuid = :uuid";

    $database->execute($sql, array("uuid" => $callback_uuid));

    // Fetch updated callback
    $sql = "SELECT * FROM v_callback_queue WHERE callback_uuid = :uuid";
    $callback = $database->select($sql, array("uuid" => $callback_uuid), 'row');

    return array(
        "success" => true,
        "message" => "Callback cancelled successfully",
        "callback" => format_callback_response($callback)
    );
}

function format_callback_response($callback) {
    return array(
        "callbackUuid" => $callback['callback_uuid'],
        "domainUuid" => $callback['domain_uuid'],
        "callerIdName" => $callback['caller_id_name'],
        "callerIdNumber" => $callback['caller_id_number'],
        "status" => $callback['status'],
        "priority" => (int)$callback['priority'],
        "attempts" => (int)$callback['attempts'],
        "nextAttemptTime" => $callback['next_attempt_time'],
        "createdDate" => $callback['created_date'],
        "updatedDate" => $callback['updated_date'],
        "notes" => $callback['notes']
    );
}
?>
