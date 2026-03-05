<?php
require_once(__DIR__ . '/callback-helper.php');

$required_params = array("configName");

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

    // Prepare configuration data
    $callback_config_uuid = uuid();
    $config_name = $body->configName;
    $queue_uuid = isset($body->queueUuid) ? $body->queueUuid : null;
    $enabled = isset($body->enabled) ? ($body->enabled === true || $body->enabled === 'true') : true;

    // Trigger settings
    $trigger_on_timeout = isset($body->triggerOnTimeout) ? ($body->triggerOnTimeout === true || $body->triggerOnTimeout === 'true') : true;
    $trigger_on_abandoned = isset($body->triggerOnAbandoned) ? ($body->triggerOnAbandoned === true || $body->triggerOnAbandoned === 'true') : true;
    $trigger_on_no_answer = isset($body->triggerOnNoAnswer) ? ($body->triggerOnNoAnswer === true || $body->triggerOnNoAnswer === 'true') : false;
    $trigger_on_busy = isset($body->triggerOnBusy) ? ($body->triggerOnBusy === true || $body->triggerOnBusy === 'true') : false;
    $trigger_after_hours = isset($body->triggerAfterHours) ? ($body->triggerAfterHours === true || $body->triggerAfterHours === 'true') : false;

    // Retry settings
    $max_attempts = isset($body->maxAttempts) ? (int)$body->maxAttempts : 3;
    $retry_interval = isset($body->retryInterval) ? (int)$body->retryInterval : 300;

    // Callback timing
    $immediate_callback = isset($body->immediateCallback) ? ($body->immediateCallback === true || $body->immediateCallback === 'true') : false;
    $wait_for_agent = isset($body->waitForAgent) ? ($body->waitForAgent === true || $body->waitForAgent === 'true') : true;

    // Schedules (JSON array)
    $schedules = isset($body->schedules) ? json_encode($body->schedules) : '[]';

    // Customer experience
    $play_announcement = isset($body->playAnnouncement) ? ($body->playAnnouncement === true || $body->playAnnouncement === 'true') : true;
    $announcement_text = isset($body->announcementText) ? $body->announcementText : 'Thank you for calling. We are connecting you to an agent.';

    // Priority
    $default_priority = isset($body->defaultPriority) ? (int)$body->defaultPriority : 5;

    // Limits
    $max_callbacks_per_hour = isset($body->maxCallbacksPerHour) ? (int)$body->maxCallbacksPerHour : 100;
    $max_callbacks_per_day = isset($body->maxCallbacksPerDay) ? (int)$body->maxCallbacksPerDay : 500;

    // Insert configuration
    $sql = "INSERT INTO v_callback_configs (
        callback_config_uuid,
        domain_uuid,
        queue_uuid,
        config_name,
        enabled,
        trigger_on_timeout,
        trigger_on_abandoned,
        trigger_on_no_answer,
        trigger_on_busy,
        trigger_after_hours,
        max_attempts,
        retry_interval,
        immediate_callback,
        wait_for_agent,
        schedules,
        play_announcement,
        announcement_text,
        default_priority,
        max_callbacks_per_hour,
        max_callbacks_per_day,
        insert_date
    ) VALUES (
        :callback_config_uuid,
        :domain_uuid,
        :queue_uuid,
        :config_name,
        :enabled,
        :trigger_on_timeout,
        :trigger_on_abandoned,
        :trigger_on_no_answer,
        :trigger_on_busy,
        :trigger_after_hours,
        :max_attempts,
        :retry_interval,
        :immediate_callback,
        :wait_for_agent,
        :schedules,
        :play_announcement,
        :announcement_text,
        :default_priority,
        :max_callbacks_per_hour,
        :max_callbacks_per_day,
        NOW()
    )";

    $params = array(
        "callback_config_uuid" => $callback_config_uuid,
        "domain_uuid" => $req_domain_uuid,
        "queue_uuid" => $queue_uuid,
        "config_name" => $config_name,
        "enabled" => $enabled ? 'true' : 'false',
        "trigger_on_timeout" => $trigger_on_timeout ? 'true' : 'false',
        "trigger_on_abandoned" => $trigger_on_abandoned ? 'true' : 'false',
        "trigger_on_no_answer" => $trigger_on_no_answer ? 'true' : 'false',
        "trigger_on_busy" => $trigger_on_busy ? 'true' : 'false',
        "trigger_after_hours" => $trigger_after_hours ? 'true' : 'false',
        "max_attempts" => $max_attempts,
        "retry_interval" => $retry_interval,
        "immediate_callback" => $immediate_callback ? 'true' : 'false',
        "wait_for_agent" => $wait_for_agent ? 'true' : 'false',
        "schedules" => $schedules,
        "play_announcement" => $play_announcement ? 'true' : 'false',
        "announcement_text" => $announcement_text,
        "default_priority" => $default_priority,
        "max_callbacks_per_hour" => $max_callbacks_per_hour,
        "max_callbacks_per_day" => $max_callbacks_per_day
    );

    $database->execute($sql, $params);

    // Fetch the created config
    $sql = "SELECT * FROM v_callback_configs WHERE callback_config_uuid = :uuid";
    $config = $database->select($sql, array("uuid" => $callback_config_uuid), 'row');

    if ($config) {
        return array(
            "success" => true,
            "message" => "Callback configuration created successfully",
            "callbackConfig" => format_config_response($config)
        );
    } else {
        return array(
            "success" => false,
            "error" => "Failed to create callback configuration"
        );
    }
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
        "insertDate" => $config['insert_date']
    );
}
?>
