<?php
require_once(__DIR__ . '/callback-helper.php');

$required_params = array("callerIdNumber");

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

    // Get caller information
    $caller_id_number = $body->callerIdNumber;
    $caller_id_name = isset($body->callerIdName) ? $body->callerIdName : null;
    $destination_number = isset($body->destinationNumber) ? $body->destinationNumber : null;

    // Get queue information (optional)
    $queue_uuid = isset($body->queueUuid) ? $body->queueUuid : null;
    $queue_name = isset($body->queueName) ? $body->queueName : null;

    // Get original call information (optional)
    $original_call_uuid = isset($body->originalCallUuid) ? $body->originalCallUuid : null;
    $original_call_time = isset($body->originalCallTime) ? $body->originalCallTime : null;
    $hangup_cause = isset($body->hangupCause) ? $body->hangupCause : null;

    // Priority (optional)
    $priority = isset($body->priority) ? (int)$body->priority : null;

    // Get callback configuration
    $config = get_callback_config($req_domain_uuid, $queue_uuid);

    if (!$config) {
        return array(
            "success" => false,
            "error" => "No callback configuration found for this domain/queue"
        );
    }

    if ($config['enabled'] !== 't' && $config['enabled'] !== true) {
        return array(
            "success" => false,
            "error" => "Callback system is disabled for this configuration"
        );
    }

    // Check blacklist
    if (is_number_blacklisted($caller_id_number, $req_domain_uuid)) {
        return array(
            "success" => false,
            "error" => "Caller number is blacklisted"
        );
    }

    // Check rate limits
    $rate_check = check_rate_limit(
        $req_domain_uuid,
        $config['callback_config_uuid'],
        (int)$config['max_callbacks_per_hour'],
        (int)$config['max_callbacks_per_day']
    );

    if (!$rate_check['allowed']) {
        return array(
            "success" => false,
            "error" => $rate_check['reason']
        );
    }

    // Use config priority if not specified
    if ($priority === null) {
        $priority = (int)$config['default_priority'];
    }

    // Calculate next attempt time
    if ($config['immediate_callback'] === 't' || $config['immediate_callback'] === true) {
        // Immediate callback - check schedule
        if (is_in_schedule($config['schedules'])) {
            $next_attempt_time = date('Y-m-d H:i:s');
            $status = 'pending';
        } else {
            // Schedule for next allowed time
            $next_attempt_time = calculate_next_scheduled_time($config['schedules']);
            $status = 'scheduled';
        }
    } else {
        // Delayed callback
        $next_attempt_time = calculate_next_scheduled_time($config['schedules']);
        $status = 'scheduled';
    }

    // Create callback record
    $callback_uuid = uuid();

    $sql = "INSERT INTO v_callback_queue (
        callback_uuid,
        domain_uuid,
        callback_config_uuid,
        caller_id_name,
        caller_id_number,
        destination_number,
        queue_uuid,
        queue_name,
        original_call_uuid,
        original_call_time,
        hangup_cause,
        status,
        priority,
        attempts,
        max_attempts,
        next_attempt_time,
        scheduled_time,
        created_date
    ) VALUES (
        :callback_uuid,
        :domain_uuid,
        :callback_config_uuid,
        :caller_id_name,
        :caller_id_number,
        :destination_number,
        :queue_uuid,
        :queue_name,
        :original_call_uuid,
        :original_call_time,
        :hangup_cause,
        :status,
        :priority,
        0,
        :max_attempts,
        :next_attempt_time,
        :next_attempt_time,
        NOW()
    )";

    $params = array(
        "callback_uuid" => $callback_uuid,
        "domain_uuid" => $req_domain_uuid,
        "callback_config_uuid" => $config['callback_config_uuid'],
        "caller_id_name" => $caller_id_name,
        "caller_id_number" => $caller_id_number,
        "destination_number" => $destination_number,
        "queue_uuid" => $queue_uuid,
        "queue_name" => $queue_name,
        "original_call_uuid" => $original_call_uuid,
        "original_call_time" => $original_call_time,
        "hangup_cause" => $hangup_cause,
        "status" => $status,
        "priority" => $priority,
        "max_attempts" => (int)$config['max_attempts'],
        "next_attempt_time" => $next_attempt_time
    );

    $database->execute($sql, $params);

    // Fetch created callback
    $sql = "SELECT * FROM v_callback_queue WHERE callback_uuid = :uuid";
    $callback = $database->select($sql, array("uuid" => $callback_uuid), 'row');

    return array(
        "success" => true,
        "message" => "Callback created successfully",
        "callback" => format_callback_response($callback)
    );
}

function calculate_next_scheduled_time($schedules_json) {
    // For now, return now + 5 minutes
    // TODO: Implement proper schedule-aware calculation
    $next_time = new DateTime();
    $next_time->modify("+5 minutes");
    return $next_time->format('Y-m-d H:i:s');
}

function format_callback_response($callback) {
    return array(
        "callbackUuid" => $callback['callback_uuid'],
        "domainUuid" => $callback['domain_uuid'],
        "callerIdName" => $callback['caller_id_name'],
        "callerIdNumber" => $callback['caller_id_number'],
        "destinationNumber" => $callback['destination_number'],
        "queueUuid" => $callback['queue_uuid'],
        "queueName" => $callback['queue_name'],
        "status" => $callback['status'],
        "priority" => (int)$callback['priority'],
        "attempts" => (int)$callback['attempts'],
        "maxAttempts" => (int)$callback['max_attempts'],
        "nextAttemptTime" => $callback['next_attempt_time'],
        "scheduledTime" => $callback['scheduled_time'],
        "createdDate" => $callback['created_date']
    );
}
?>
