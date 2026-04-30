<?php
/**
 * Call Broadcast Retry
 *
 * Checks CDR for completed broadcast calls, updates lead statuses,
 * and re-queues retryable leads via FreeSWITCH.
 *
 * Can be called manually via API or by the scheduler.
 */

$required_params = array("callBroadcastUuid");

function do_action($body) {
    global $domain_uuid, $domain_name;

    $db_domain_uuid = isset($body->domain_uuid) ? $body->domain_uuid :
                     (isset($body->domainUuid) ? $body->domainUuid : $domain_uuid);

    $call_broadcast_uuid = isset($body->callBroadcastUuid) ? $body->callBroadcastUuid :
                          (isset($body->call_broadcast_uuid) ? $body->call_broadcast_uuid : null);

    if (empty($call_broadcast_uuid)) {
        return array("success" => false, "error" => "callBroadcastUuid is required");
    }

    $database = new database;

    // Get broadcast with retry config
    $sql = "SELECT * FROM v_call_broadcasts
            WHERE call_broadcast_uuid = :uuid AND domain_uuid = :domain_uuid";
    $broadcast = $database->select($sql, array(
        "uuid" => $call_broadcast_uuid,
        "domain_uuid" => $db_domain_uuid
    ), "row");

    if (empty($broadcast)) {
        return array("success" => false, "error" => "Broadcast not found");
    }

    $retry_enabled = isset($broadcast['broadcast_retry_enabled']) && $broadcast['broadcast_retry_enabled'] === 'true';
    $retry_max = isset($broadcast['broadcast_retry_max']) ? intval($broadcast['broadcast_retry_max']) : 0;
    $retry_interval = isset($broadcast['broadcast_retry_interval']) ? intval($broadcast['broadcast_retry_interval']) : 300;
    $retry_causes_str = isset($broadcast['broadcast_retry_causes']) ? $broadcast['broadcast_retry_causes'] :
                        'NO_ANSWER,ORIGINATOR_CANCEL,USER_BUSY,NO_USER_RESPONSE,CALL_REJECTED,NORMAL_TEMPORARY_FAILURE';
    $retry_causes = array_map('trim', explode(',', $retry_causes_str));

    if (!$retry_enabled || $retry_max <= 0) {
        return array("success" => true, "message" => "Retry not enabled for this broadcast", "retried" => 0);
    }

    // Get domain name
    $db_domain_name = $domain_name;
    $domain_row = $database->select("SELECT domain_name FROM v_domains WHERE domain_uuid = :uuid",
        array("uuid" => $db_domain_uuid), "row");
    if ($domain_row) $db_domain_name = $domain_row['domain_name'];

    // Step 1: Sync lead statuses from CDR for leads still in 'calling' state
    $calling_leads = $database->select(
        "SELECT call_broadcast_lead_uuid, phone_number FROM v_call_broadcast_leads
         WHERE call_broadcast_uuid = :uuid AND lead_status = 'calling'",
        array("uuid" => $call_broadcast_uuid), "all"
    );

    $synced = 0;
    if (is_array($calling_leads)) {
        foreach ($calling_leads as $lead) {
            // Check CDR for this number in this broadcast
            $cdr_sql = "SELECT xml_cdr_uuid, hangup_cause, billsec, duration
                        FROM v_xml_cdr
                        WHERE domain_uuid = :domain_uuid
                        AND (destination_number = :phone OR caller_id_number = :phone2)
                        AND start_stamp >= (NOW() - INTERVAL '24 hours')
                        ORDER BY billsec DESC, start_stamp DESC LIMIT 1";
            $cdr = $database->select($cdr_sql, array(
                "domain_uuid" => $db_domain_uuid,
                "phone" => $lead['phone_number'],
                "phone2" => $lead['phone_number']
            ), "row");

            if (!empty($cdr)) {
                $hangup = $cdr['hangup_cause'];
                $billsec = intval($cdr['billsec']);
                $duration = intval($cdr['duration']);
                $is_answered = ($billsec > 0 && $hangup === 'NORMAL_CLEARING');
                // NORMAL_CLEARING with billsec=0 means cancelled before answer - treat as retryable
                $is_retryable = in_array($hangup, $retry_causes) ||
                                ($hangup === 'NORMAL_CLEARING' && $billsec == 0);

                if ($is_answered) {
                    $new_status = 'answered';
                } elseif ($is_retryable) {
                    // Check if we can retry
                    $lead_detail = $database->select(
                        "SELECT attempts, max_attempts FROM v_call_broadcast_leads WHERE call_broadcast_lead_uuid = :uuid",
                        array("uuid" => $lead['call_broadcast_lead_uuid']), "row"
                    );
                    if ($lead_detail && intval($lead_detail['attempts']) < intval($lead_detail['max_attempts'])) {
                        $new_status = 'retry_pending';
                    } else {
                        $new_status = 'skipped'; // max retries reached
                    }
                } else {
                    $new_status = 'failed';
                }

                $update_sql = "UPDATE v_call_broadcast_leads SET
                    lead_status = :status, hangup_cause = :hangup, billsec = :billsec,
                    call_duration = :duration, xml_cdr_uuid = :cdr_uuid,
                    next_retry_at = " . ($new_status === 'retry_pending' ? "NOW() + INTERVAL '$retry_interval seconds'" : "NULL") . ",
                    update_date = NOW()
                    WHERE call_broadcast_lead_uuid = :lead_uuid";
                $database->execute($update_sql, array(
                    "status" => $new_status,
                    "hangup" => $hangup,
                    "billsec" => $billsec,
                    "duration" => $duration,
                    "cdr_uuid" => $cdr['xml_cdr_uuid'],
                    "lead_uuid" => $lead['call_broadcast_lead_uuid']
                ));
                $synced++;
            }
        }
    }

    // Step 2: Re-queue retry_pending leads whose next_retry_at has passed
    $retry_leads = $database->select(
        "SELECT call_broadcast_lead_uuid, phone_number FROM v_call_broadcast_leads
         WHERE call_broadcast_uuid = :uuid AND lead_status = 'retry_pending' AND next_retry_at <= NOW()",
        array("uuid" => $call_broadcast_uuid), "all"
    );

    $retried = 0;
    if (is_array($retry_leads) && count($retry_leads) > 0) {
        // Connect to FreeSWITCH
        $fp = null;
        $esl_available = false;
        if (class_exists('event_socket')) {
            $fp = @event_socket::create();
            $esl_available = ($fp !== false && $fp !== null);
        }

        if (!$esl_available) {
            return array(
                "success" => false,
                "error" => "Cannot connect to FreeSWITCH for retry",
                "synced" => $synced
            );
        }

        $broadcast_caller_id_name = $broadcast['broadcast_caller_id_name'] ?: 'Call Broadcast';
        // Support multiple caller IDs (comma-separated) - random pick per retry call
        $broadcast_caller_id_pool = array_filter(array_map('trim', explode(',', $broadcast['broadcast_caller_id_number'] ?: '0000000000')));
        if (empty($broadcast_caller_id_pool)) $broadcast_caller_id_pool = array('0000000000');
        $broadcast_destination_data = $broadcast['broadcast_destination_data'];
        $broadcast_avmd = $broadcast['broadcast_avmd'];
        $broadcast_accountcode = $broadcast['broadcast_accountcode'] ?: $db_domain_name;
        $broadcast_toll_allow = isset($broadcast['broadcast_toll_allow']) ? $broadcast['broadcast_toll_allow'] : '';
        $broadcast_concurrent_limit = intval($broadcast['broadcast_concurrent_limit']) ?: 5;
        $broadcast_timeout = intval($broadcast['broadcast_timeout']) ?: 30;

        $count = 1;
        $sched_seconds = 3; // start retries after 3 seconds

        foreach ($retry_leads as $lead) {
            $phone_number = $lead['phone_number'];

            // Randomly pick a caller ID from the pool for this retry call
            $broadcast_caller_id_number = $broadcast_caller_id_pool[array_rand($broadcast_caller_id_pool)];

            // Build channel variables (same as start)
            $channel_variables = "^^:ignore_early_media=true:ignore_display_updates=true:sip_cid_type=none";
            $channel_variables .= ":origination_number=" . $phone_number;
            $channel_variables .= ":destination_number=" . $phone_number;
            $channel_variables .= ":origination_caller_id_name='" . $broadcast_caller_id_name . "'";
            $channel_variables .= ":origination_caller_id_number=" . $broadcast_caller_id_number;
            $channel_variables .= ":caller_id_number=" . $phone_number;
            $channel_variables .= ":caller_id_name=" . $phone_number;
            $channel_variables .= ":effective_caller_id_number=" . $phone_number;
            $channel_variables .= ":effective_caller_id_name=" . $phone_number;
            $channel_variables .= ":domain_uuid=" . $db_domain_uuid;
            $channel_variables .= ":domain=" . $db_domain_name;
            $channel_variables .= ":domain_name=" . $db_domain_name;
            $channel_variables .= ":accountcode='" . $broadcast_accountcode . "'";
            $channel_variables .= ":call_broadcast_uuid=" . $call_broadcast_uuid;

            if (!empty($broadcast_toll_allow)) {
                $channel_variables .= ":toll_allow='" . $broadcast_toll_allow . "'";
            }

            if ($broadcast_avmd == "true") {
                $channel_variables .= ":execute_on_answer='wait_for_silence 200 25 3 4000'";
            }

            $origination_url = "{" . $channel_variables . "}loopback/" . $phone_number . "/" . $db_domain_name;
            $context = $db_domain_name;
            $cmd = "bgapi sched_api +" . $sched_seconds . " retry_" . $call_broadcast_uuid . " bgapi originate " .
                   $origination_url . " " . $broadcast_destination_data . " XML " . $context;

            @event_socket::command($cmd);

            // Update lead
            $database->execute(
                "UPDATE v_call_broadcast_leads SET lead_status = 'calling', attempts = attempts + 1,
                 last_attempt_at = NOW(), next_retry_at = NULL, update_date = NOW()
                 WHERE call_broadcast_lead_uuid = :uuid",
                array("uuid" => $lead['call_broadcast_lead_uuid'])
            );

            $retried++;

            // Spread calls based on concurrent limit
            if ($broadcast_concurrent_limit > 0 && $broadcast_timeout > 0) {
                if ($count >= $broadcast_concurrent_limit) {
                    $sched_seconds += $broadcast_timeout;
                    $count = 0;
                }
            }
            $count++;
        }
    }

    return array(
        "success" => true,
        "message" => "Retry processing complete",
        "callBroadcastUuid" => $call_broadcast_uuid,
        "synced" => $synced,
        "retried" => $retried,
        "retryEnabled" => $retry_enabled,
        "retryMax" => $retry_max,
        "retryInterval" => $retry_interval
    );
}
