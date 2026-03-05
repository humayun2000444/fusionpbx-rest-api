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
    $sql = "SELECT c.*, q.queue_name
            FROM v_callback_configs c
            LEFT JOIN v_call_center_queues q ON c.queue_uuid = q.call_center_queue_uuid
            WHERE c.domain_uuid = :domain_uuid";

    $params = array("domain_uuid" => $req_domain_uuid);

    // Optional filter by queue
    if (isset($body->queueUuid) && !empty($body->queueUuid)) {
        $sql .= " AND c.queue_uuid = :queue_uuid";
        $params["queue_uuid"] = $body->queueUuid;
    }

    // Optional filter by enabled status
    if (isset($body->enabled)) {
        $enabled = ($body->enabled === true || $body->enabled === 'true');
        $sql .= " AND c.enabled = :enabled";
        $params["enabled"] = $enabled ? 'true' : 'false';
    }

    $sql .= " ORDER BY c.config_name ASC";

    $configs = $database->select($sql, $params, 'all');

    if (!$configs) {
        $configs = array();
    }

    // Format response
    $result = array();
    foreach ($configs as $config) {
        $result[] = format_config_response($config);
    }

    return array(
        "success" => true,
        "callbackConfigs" => $result,
        "count" => count($result)
    );
}

function format_config_response($config) {
    return array(
        "callbackConfigUuid" => $config['callback_config_uuid'],
        "domainUuid" => $config['domain_uuid'],
        "queueUuid" => $config['queue_uuid'],
        "queueName" => isset($config['queue_name']) ? $config['queue_name'] : null,
        "configName" => $config['config_name'],
        "enabled" => $config['enabled'] === 't' || $config['enabled'] === true,
        "triggerOnTimeout" => $config['trigger_on_timeout'] === 't' || $config['trigger_on_timeout'] === true,
        "triggerOnAbandoned" => $config['trigger_on_abandoned'] === 't' || $config['trigger_on_abandoned'] === true,
        "triggerOnNoAnswer" => $config['trigger_on_no_answer'] === 't' || $config['trigger_on_no_answer'] === true,
        "triggerOnBusy" => $config['trigger_on_busy'] === 't' || $config['trigger_on_busy'] === true,
        "triggerAfterHours" => $config['trigger_after_hours'] === 't' || $config['trigger_after_hours'] === true,
        "maxAttempts" => (int)$config['max_attempts'],
        "retryInterval" => (int)$config['retry_interval'],
        "immediateCallback" => $config['immediate_callback'] === 't' || $config['immediate_callback'] === true,
        "waitForAgent" => $config['wait_for_agent'] === 't' || $config['wait_for_agent'] === true,
        "schedules" => json_decode($config['schedules'], true),
        "schedulesDisplay" => format_schedule_display($config['schedules']),
        "playAnnouncement" => $config['play_announcement'] === 't' || $config['play_announcement'] === true,
        "announcementText" => $config['announcement_text'],
        "defaultPriority" => (int)$config['default_priority'],
        "maxCallbacksPerHour" => (int)$config['max_callbacks_per_hour'],
        "maxCallbacksPerDay" => (int)$config['max_callbacks_per_day'],
        "insertDate" => $config['insert_date'],
        "updateDate" => $config['update_date']
    );
}
?>
