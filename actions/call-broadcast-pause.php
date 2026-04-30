<?php

$required_params = array("callBroadcastUuid");

function do_action($body) {
    global $domain_uuid;

    $db_domain_uuid = isset($body->domain_uuid) ? $body->domain_uuid :
                     (isset($body->domainUuid) ? $body->domainUuid : $domain_uuid);

    $call_broadcast_uuid = isset($body->callBroadcastUuid) ? $body->callBroadcastUuid :
                          (isset($body->call_broadcast_uuid) ? $body->call_broadcast_uuid : null);

    if (empty($call_broadcast_uuid)) {
        return array("success" => false, "error" => "callBroadcastUuid is required");
    }

    $database = new database;

    // Get current status
    $sql = "SELECT broadcast_name, broadcast_status FROM v_call_broadcasts
            WHERE call_broadcast_uuid = :call_broadcast_uuid AND domain_uuid = :domain_uuid";
    $broadcast = $database->select($sql, array(
        "call_broadcast_uuid" => $call_broadcast_uuid,
        "domain_uuid" => $db_domain_uuid
    ), "row");

    if (empty($broadcast)) {
        return array("success" => false, "error" => "Broadcast not found");
    }

    $current_status = $broadcast['broadcast_status'];

    // Toggle: running -> paused, paused -> running
    if ($current_status === 'running') {
        $new_status = 'paused';
    } elseif ($current_status === 'paused') {
        $new_status = 'running';

        // If predictive mode, ensure dialer daemon is running
        $check_sql = "SELECT broadcast_pacing_mode FROM v_call_broadcasts WHERE call_broadcast_uuid = :uuid";
        $mode_row = $database->select($check_sql, array("uuid" => $call_broadcast_uuid), "row");
        if ($mode_row && $mode_row['broadcast_pacing_mode'] === 'predictive') {
            $pid_file = '/var/run/fusionpbx/dialer.pid';
            $dialer_running = false;
            if (file_exists($pid_file)) {
                $pid = trim(file_get_contents($pid_file));
                if ($pid && file_exists("/proc/$pid")) $dialer_running = true;
            }
            if (!$dialer_running) {
                $script = '/var/www/fusionpbx/app/rest_api/actions/call-broadcast-dialer.php';
                exec("nohup php $script > /dev/null 2>&1 &");
            }
        }
    } else {
        return array(
            "success" => false,
            "error" => "Cannot pause/resume broadcast in '$current_status' state. Must be 'running' or 'paused'."
        );
    }

    $update_sql = "UPDATE v_call_broadcasts SET broadcast_status = :status, update_date = NOW()
                   WHERE call_broadcast_uuid = :uuid AND domain_uuid = :domain_uuid";
    $result = $database->execute($update_sql, array(
        "status" => $new_status,
        "uuid" => $call_broadcast_uuid,
        "domain_uuid" => $db_domain_uuid
    ));

    if ($result === false) {
        return array("success" => false, "error" => "Failed to update broadcast status");
    }

    return array(
        "success" => true,
        "message" => "Broadcast " . ($new_status === 'paused' ? 'paused' : 'resumed') . " successfully",
        "callBroadcastUuid" => $call_broadcast_uuid,
        "broadcastName" => $broadcast['broadcast_name'],
        "previousStatus" => $current_status,
        "status" => $new_status
    );
}
