<?php
require_once(__DIR__ . '/callback-helper.php');

$required_params = array("callbackConfigUuid");

function do_action($body) {
    // Auto-install tables if needed
    ensure_callback_tables_exist();

    $database = new database;

    $callback_config_uuid = $body->callbackConfigUuid;

    // Get current config
    $sql = "SELECT * FROM v_callback_configs WHERE callback_config_uuid = :uuid";
    $config = $database->select($sql, array("uuid" => $callback_config_uuid), 'row');

    if (!$config) {
        return array(
            "success" => false,
            "error" => "Callback configuration not found"
        );
    }

    // Toggle enabled status
    $current_enabled = $config['enabled'] === 't' || $config['enabled'] === true;
    $new_enabled = !$current_enabled;

    // Update
    $sql = "UPDATE v_callback_configs
            SET enabled = :enabled,
                update_date = NOW()
            WHERE callback_config_uuid = :uuid";

    $params = array(
        "enabled" => $new_enabled ? 'true' : 'false',
        "uuid" => $callback_config_uuid
    );

    $database->execute($sql, $params);

    // Fetch updated config
    $sql = "SELECT * FROM v_callback_configs WHERE callback_config_uuid = :uuid";
    $config = $database->select($sql, array("uuid" => $callback_config_uuid), 'row');

    return array(
        "success" => true,
        "message" => "Callback configuration " . ($new_enabled ? "enabled" : "disabled"),
        "callbackConfig" => format_config_response($config)
    );
}

function format_config_response($config) {
    return array(
        "callbackConfigUuid" => $config['callback_config_uuid'],
        "domainUuid" => $config['domain_uuid'],
        "queueUuid" => $config['queue_uuid'],
        "configName" => $config['config_name'],
        "enabled" => $config['enabled'] === 't' || $config['enabled'] === true,
        "triggerOnTimeout" => $config['trigger_on_timeout'] === 't' || $config['trigger_on_timeout'] === true,
        "triggerOnAbandoned" => $config['trigger_on_abandoned'] === 't' || $config['trigger_on_abandoned'] === true,
        "maxAttempts" => (int)$config['max_attempts'],
        "retryInterval" => (int)$config['retry_interval'],
        "schedules" => json_decode($config['schedules'], true),
        "schedulesDisplay" => format_schedule_display($config['schedules']),
        "defaultPriority" => (int)$config['default_priority']
    );
}
?>
