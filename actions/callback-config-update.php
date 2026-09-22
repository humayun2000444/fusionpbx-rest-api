<?php
/**
 * Update a callback configuration.
 *
 * The Configuration page sends the whole form back, so every column is written
 * from the request. Fields the caller omits keep their current value rather
 * than reverting to a column default -- a partial request must not silently
 * reset retry limits or schedules that someone set deliberately.
 *
 * Scoped by domain: a uuid is not a secret, and without this any caller holding
 * one could edit another tenant's configuration.
 */
require_once(__DIR__ . '/callback-helper.php');

$required_params = array("callbackConfigUuid");

function do_action($body) {
    global $domain_uuid;
    ensure_callback_tables_exist();
    $database = new database;

    $req_domain_uuid = isset($body->domainUuid) ? $body->domainUuid : $domain_uuid;
    $uuid = $body->callbackConfigUuid;

    $sql = "SELECT * FROM v_callback_configs WHERE callback_config_uuid = :uuid";
    $params = array("uuid" => $uuid);
    if (!empty($req_domain_uuid)) {
        $sql .= " AND domain_uuid = :domain_uuid";
        $params["domain_uuid"] = $req_domain_uuid;
    }
    $existing = $database->select($sql, $params, 'row');
    if (!$existing) {
        return array("success" => false, "error" => "Callback configuration not found");
    }

    // Current value unless the request carries a new one.
    $keep = function ($key, $column) use ($body, $existing) {
        return isset($body->$key) ? $body->$key : $existing[$column];
    };
    $bool = function ($v) {
        if (is_bool($v))   { return $v ? 'true' : 'false'; }
        if ($v === 't' || $v === 'true' || $v === 1 || $v === '1') { return 'true'; }
        return 'false';
    };

    $set = array(
        "config_name"            => $keep('configName', 'config_name'),
        "queue_uuid"             => $keep('queueUuid', 'queue_uuid'),
        "enabled"                => $bool($keep('enabled', 'enabled')),
        "trigger_on_timeout"     => $bool($keep('triggerOnTimeout', 'trigger_on_timeout')),
        "trigger_on_abandoned"   => $bool($keep('triggerOnAbandoned', 'trigger_on_abandoned')),
        "trigger_on_no_answer"   => $bool($keep('triggerOnNoAnswer', 'trigger_on_no_answer')),
        "trigger_on_busy"        => $bool($keep('triggerOnBusy', 'trigger_on_busy')),
        "trigger_after_hours"    => $bool($keep('triggerAfterHours', 'trigger_after_hours')),
        "max_attempts"           => (int) $keep('maxAttempts', 'max_attempts'),
        "retry_interval"         => (int) $keep('retryInterval', 'retry_interval'),
        "immediate_callback"     => $bool($keep('immediateCallback', 'immediate_callback')),
        "wait_for_agent"         => $bool($keep('waitForAgent', 'wait_for_agent')),
        "play_announcement"      => $bool($keep('playAnnouncement', 'play_announcement')),
        "announcement_text"      => $keep('announcementText', 'announcement_text'),
        "default_priority"       => (int) $keep('defaultPriority', 'default_priority'),
        "max_callbacks_per_hour" => (int) $keep('maxCallbacksPerHour', 'max_callbacks_per_hour'),
        "max_callbacks_per_day"  => (int) $keep('maxCallbacksPerDay', 'max_callbacks_per_day'),
    );

    // schedules is JSONB; the page sends an array, the column stores text.
    $schedules = isset($body->schedules)
        ? (is_string($body->schedules) ? $body->schedules : json_encode($body->schedules))
        : $existing['schedules'];

    // An empty queue_uuid means "domain-wide default", which is NULL, not ''.
    if ($set['queue_uuid'] === '' ) { $set['queue_uuid'] = null; }

    $database->execute(
        "UPDATE v_callback_configs SET
            config_name = :config_name,
            queue_uuid = :queue_uuid,
            enabled = :enabled,
            trigger_on_timeout = :trigger_on_timeout,
            trigger_on_abandoned = :trigger_on_abandoned,
            trigger_on_no_answer = :trigger_on_no_answer,
            trigger_on_busy = :trigger_on_busy,
            trigger_after_hours = :trigger_after_hours,
            max_attempts = :max_attempts,
            retry_interval = :retry_interval,
            immediate_callback = :immediate_callback,
            wait_for_agent = :wait_for_agent,
            play_announcement = :play_announcement,
            announcement_text = :announcement_text,
            default_priority = :default_priority,
            max_callbacks_per_hour = :max_callbacks_per_hour,
            max_callbacks_per_day = :max_callbacks_per_day,
            schedules = CAST(:schedules AS JSONB),
            update_date = NOW()
          WHERE callback_config_uuid = :uuid",
        array_merge($set, array("schedules" => $schedules, "uuid" => $uuid)));

    $config = $database->select(
        "SELECT c.*, q.queue_name FROM v_callback_configs c
           LEFT JOIN v_call_center_queues q ON c.queue_uuid = q.call_center_queue_uuid
          WHERE c.callback_config_uuid = :uuid", array("uuid" => $uuid), 'row');

    return array(
        "success" => true,
        "message" => "Callback configuration updated",
        "callbackConfig" => format_config_response($config));
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
