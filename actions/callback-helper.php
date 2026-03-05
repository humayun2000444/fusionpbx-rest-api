<?php
// Callback System Helper Functions

/**
 * Auto-install callback tables if they don't exist
 * Called automatically by all callback actions
 */
function ensure_callback_tables_exist() {
    global $database;

    static $checked = false;

    // Only check once per request
    if ($checked) {
        return true;
    }

    try {
        // Quick check if tables exist
        $sql = "SELECT EXISTS (
            SELECT FROM information_schema.tables
            WHERE table_schema = 'public'
            AND table_name = 'v_callback_configs'
        )";

        $exists = $database->select($sql, null, 'column');

        if ($exists === 't' || $exists === true) {
            $checked = true;
            return true;
        }

        // Tables don't exist - create them
        $sql_file = __DIR__ . '/callback-install.sql';

        if (!file_exists($sql_file)) {
            error_log("Callback install SQL file not found");
            return false;
        }

        $sql = file_get_contents($sql_file);
        $database->execute($sql, null);

        error_log("Callback tables created automatically");
        $checked = true;
        return true;

    } catch (Exception $e) {
        error_log("Failed to create callback tables: " . $e->getMessage());
        return false;
    }
}

/**
 * Check if current time is within allowed schedules
 */
function is_in_schedule($schedules_json) {
    if (empty($schedules_json)) {
        return true;  // No schedule = always allowed
    }

    $schedules = json_decode($schedules_json, true);
    if (!is_array($schedules) || empty($schedules)) {
        return true;
    }

    $now = new DateTime();
    $current_day = (int)$now->format('N');  // 1=Monday, 7=Sunday
    $current_time = $now->format('H:i');

    foreach ($schedules as $schedule) {
        $days = isset($schedule['days']) ? $schedule['days'] : array();
        $start_time = isset($schedule['start_time']) ? $schedule['start_time'] : '00:00';
        $end_time = isset($schedule['end_time']) ? $schedule['end_time'] : '23:59';

        // Check if today is in allowed days
        if (!empty($days) && !in_array($current_day, $days)) {
            continue;
        }

        // Check if current time is in range
        if ($current_time >= $start_time && $current_time <= $end_time) {
            return true;
        }
    }

    return false;
}

/**
 * Calculate next attempt time based on retry settings
 */
function calculate_next_attempt($attempts, $retry_interval, $use_exponential = false) {
    if ($use_exponential) {
        // Exponential backoff: 5min, 7.5min, 11.25min
        $multiplier = pow(1.5, $attempts);
        $seconds = $retry_interval * $multiplier;
    } else {
        // Fixed interval
        $seconds = $retry_interval;
    }

    $next_time = new DateTime();
    $next_time->modify("+{$seconds} seconds");

    return $next_time->format('Y-m-d H:i:s');
}

/**
 * Get callback configuration for domain or queue
 */
function get_callback_config($domain_uuid, $queue_uuid = null) {
    global $database;

    // Try to get queue-specific config first
    if ($queue_uuid) {
        $sql = "SELECT * FROM v_callback_configs
                WHERE domain_uuid = :domain_uuid
                AND queue_uuid = :queue_uuid
                AND enabled = true
                LIMIT 1";

        $params = array(
            "domain_uuid" => $domain_uuid,
            "queue_uuid" => $queue_uuid
        );

        $config = $database->select($sql, $params, 'row');
        if ($config) {
            return $config;
        }
    }

    // Fall back to domain-wide default
    $sql = "SELECT * FROM v_callback_configs
            WHERE domain_uuid = :domain_uuid
            AND queue_uuid IS NULL
            AND enabled = true
            LIMIT 1";

    $params = array("domain_uuid" => $domain_uuid);

    return $database->select($sql, $params, 'row');
}

/**
 * Check if number is in blacklist
 */
function is_number_blacklisted($caller_number, $domain_uuid) {
    // TODO: Implement blacklist check
    // For now, return false (no blacklist)
    return false;
}

/**
 * Check rate limits
 */
function check_rate_limit($domain_uuid, $callback_config_uuid, $max_per_hour, $max_per_day) {
    global $database;

    // Check hourly limit
    $sql = "SELECT COUNT(*) as count FROM v_callback_queue
            WHERE domain_uuid = :domain_uuid
            AND callback_config_uuid = :config_uuid
            AND created_date >= NOW() - INTERVAL '1 hour'";

    $params = array(
        "domain_uuid" => $domain_uuid,
        "config_uuid" => $callback_config_uuid
    );

    $result = $database->select($sql, $params, 'row');
    $hourly_count = $result ? (int)$result['count'] : 0;

    if ($hourly_count >= $max_per_hour) {
        return array(
            "allowed" => false,
            "reason" => "Hourly rate limit exceeded ({$hourly_count}/{$max_per_hour})"
        );
    }

    // Check daily limit
    $sql = "SELECT COUNT(*) as count FROM v_callback_queue
            WHERE domain_uuid = :domain_uuid
            AND callback_config_uuid = :config_uuid
            AND created_date >= CURRENT_DATE";

    $result = $database->select($sql, $params, 'row');
    $daily_count = $result ? (int)$result['count'] : 0;

    if ($daily_count >= $max_per_day) {
        return array(
            "allowed" => false,
            "reason" => "Daily rate limit exceeded ({$daily_count}/{$max_per_day})"
        );
    }

    return array(
        "allowed" => true,
        "hourly_count" => $hourly_count,
        "daily_count" => $daily_count
    );
}

/**
 * Format schedule JSON for display
 */
function format_schedule_display($schedules_json) {
    if (empty($schedules_json)) {
        return "24/7";
    }

    $schedules = json_decode($schedules_json, true);
    if (!is_array($schedules) || empty($schedules)) {
        return "24/7";
    }

    $day_names = array(1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun');
    $result = array();

    foreach ($schedules as $schedule) {
        $days = isset($schedule['days']) ? $schedule['days'] : array();
        $start = isset($schedule['start_time']) ? $schedule['start_time'] : '00:00';
        $end = isset($schedule['end_time']) ? $schedule['end_time'] : '23:59';

        $day_str = empty($days) ? "Every day" : implode(', ', array_map(function($d) use ($day_names) {
            return $day_names[$d];
        }, $days));

        $result[] = "{$day_str} {$start}-{$end}";
    }

    return implode("; ", $result);
}
?>
