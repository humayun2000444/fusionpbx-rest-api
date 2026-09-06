<?php

$required_params = array("callBroadcastUuid");

function do_action($body) {
    global $domain_uuid;

    $db_domain_uuid = isset($body->domainUuid) ? $body->domainUuid :
                     (isset($body->domain_uuid) ? $body->domain_uuid : $domain_uuid);

    $source_uuid = isset($body->callBroadcastUuid) ? $body->callBroadcastUuid :
                  (isset($body->call_broadcast_uuid) ? $body->call_broadcast_uuid : null);

    if (empty($source_uuid)) {
        return array("success" => false, "error" => "callBroadcastUuid is required");
    }

    $database = new database;

    // Verify source exists
    $sql_check = "SELECT broadcast_name, broadcast_phone_numbers FROM v_call_broadcasts
                  WHERE call_broadcast_uuid = :uuid AND domain_uuid = :domain_uuid";
    $source = $database->select($sql_check, array(
        "uuid" => $source_uuid,
        "domain_uuid" => $db_domain_uuid
    ), "row");

    if (empty($source)) {
        return array("success" => false, "error" => "Broadcast not found");
    }

    $new_uuid = uuid();
    $new_name = isset($body->newName) ? $body->newName : ($source['broadcast_name'] . ' (Copy)');

    // Clone using INSERT INTO ... SELECT to preserve all column types correctly
    $sql_clone = "INSERT INTO v_call_broadcasts (
        call_broadcast_uuid, domain_uuid, broadcast_name, broadcast_description,
        broadcast_start_time, broadcast_timeout, broadcast_concurrent_limit,
        recording_uuid, broadcast_caller_id_name, broadcast_caller_id_number,
        broadcast_destination_type, broadcast_destination_data,
        broadcast_phone_numbers, broadcast_avmd, broadcast_accountcode, broadcast_toll_allow,
        broadcast_schedule_enabled, broadcast_schedule_type, broadcast_schedule_date,
        broadcast_schedule_time, broadcast_schedule_days, broadcast_schedule_end_date,
        broadcast_retry_enabled, broadcast_retry_max, broadcast_retry_interval, broadcast_retry_causes,
        broadcast_pacing_mode, broadcast_dial_ratio, broadcast_max_abandon_rate,
        broadcast_status, broadcast_current_dial_ratio,
        broadcast_total_answered, broadcast_total_abandoned, broadcast_avg_talk_time,
        insert_date
    )
    SELECT
        :new_uuid::uuid, domain_uuid, :new_name, broadcast_description,
        broadcast_start_time, broadcast_timeout, broadcast_concurrent_limit,
        recording_uuid, broadcast_caller_id_name, broadcast_caller_id_number,
        broadcast_destination_type, broadcast_destination_data,
        broadcast_phone_numbers, broadcast_avmd, broadcast_accountcode, broadcast_toll_allow,
        broadcast_schedule_enabled, broadcast_schedule_type, NULL,
        broadcast_schedule_time, broadcast_schedule_days, NULL,
        broadcast_retry_enabled, broadcast_retry_max, broadcast_retry_interval, broadcast_retry_causes,
        broadcast_pacing_mode, broadcast_dial_ratio, broadcast_max_abandon_rate,
        'idle', broadcast_dial_ratio,
        0, 0, 0,
        NOW()
    FROM v_call_broadcasts
    WHERE call_broadcast_uuid = :source_uuid AND domain_uuid = :domain_uuid";

    $result = $database->execute($sql_clone, array(
        "new_uuid" => $new_uuid,
        "new_name" => $new_name,
        "source_uuid" => $source_uuid,
        "domain_uuid" => $db_domain_uuid
    ));

    if ($result === false) {
        return array("success" => false, "error" => "Failed to clone broadcast record");
    }

    // The SELECT above carries broadcast_phone_numbers across, so the copy is
    // startable as it stands. What is NOT copied is v_call_broadcast_leads:
    // those rows are per-run bookkeeping and get created from the numbers when
    // the campaign starts. `leadCount` counts those rows, so it is honestly 0
    // here - which reads as "the copy is empty" unless the numbers are reported
    // separately.
    $phone_numbers = isset($source['broadcast_phone_numbers']) ? trim((string) $source['broadcast_phone_numbers']) : '';
    $phone_count = $phone_numbers === '' ? 0 : count(array_filter(array_map('trim', explode("\n", $phone_numbers))));

    return array(
        "success" => true,
        "callBroadcastUuid" => $new_uuid,
        "name" => $new_name,
        "leadCount" => 0,
        "phoneNumberCount" => $phone_count,
        "clonedFrom" => $source_uuid,
        "message" => "Campaign cloned with the same settings and numbers. "
                   . "It starts idle, and a one-time schedule date is not carried over."
    );
}
