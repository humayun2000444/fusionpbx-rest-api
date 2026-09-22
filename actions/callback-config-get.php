<?php
/**
 * Fetch one callback configuration.
 *
 * TelcoREST's CallbackController has always exposed /config/get, but the action
 * behind it was never written, so the endpoint returned an error. Same for
 * /config/update, /config/delete, /queue/get and /queue/retry.
 *
 * Scoped by domain. The older actions in this family look a row up by uuid
 * alone, which lets any caller who knows a uuid read another tenant's config;
 * a uuid is not a secret. Where the caller names a domain we require the row to
 * belong to it.
 */
require_once(__DIR__ . '/callback-helper.php');

$required_params = array("callbackConfigUuid");

function do_action($body) {
    global $domain_uuid;
    ensure_callback_tables_exist();
    $database = new database;

    $req_domain_uuid = isset($body->domainUuid) ? $body->domainUuid : $domain_uuid;

    $sql = "SELECT c.*, q.queue_name
              FROM v_callback_configs c
              LEFT JOIN v_call_center_queues q ON c.queue_uuid = q.call_center_queue_uuid
             WHERE c.callback_config_uuid = :uuid";
    $params = array("uuid" => $body->callbackConfigUuid);
    if (!empty($req_domain_uuid)) {
        $sql .= " AND c.domain_uuid = :domain_uuid";
        $params["domain_uuid"] = $req_domain_uuid;
    }

    $config = $database->select($sql, $params, 'row');
    if (!$config) {
        return array("success" => false, "error" => "Callback configuration not found");
    }
    return array("success" => true, "callbackConfig" => format_config_response($config));
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
