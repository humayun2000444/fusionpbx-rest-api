<?php

$required_params = array("broadcastName");

function do_action($body) {
    global $domain_uuid, $domain_name;

    // Use domain_uuid from request if provided, otherwise use global
    $db_domain_uuid = isset($body->domain_uuid) ? $body->domain_uuid :
                     (isset($body->domainUuid) ? $body->domainUuid : $domain_uuid);

    $broadcast_name = isset($body->broadcastName) ? $body->broadcastName :
                     (isset($body->broadcast_name) ? $body->broadcast_name : null);

    if (empty($broadcast_name)) {
        return array(
            "success" => false,
            "error" => "broadcastName is required"
        );
    }

    // Get optional parameters
    $broadcast_description = isset($body->broadcastDescription) ? $body->broadcastDescription :
                            (isset($body->broadcast_description) ? $body->broadcast_description : '');

    $broadcast_start_time = isset($body->broadcastStartTime) ? $body->broadcastStartTime :
                           (isset($body->broadcast_start_time) ? $body->broadcast_start_time : 3);

    $broadcast_timeout = isset($body->broadcastTimeout) ? $body->broadcastTimeout :
                        (isset($body->broadcast_timeout) ? $body->broadcast_timeout : 30);

    $broadcast_concurrent_limit = isset($body->broadcastConcurrentLimit) ? $body->broadcastConcurrentLimit :
                                 (isset($body->broadcast_concurrent_limit) ? $body->broadcast_concurrent_limit : 5);

    $broadcast_caller_id_name = isset($body->broadcastCallerIdName) ? $body->broadcastCallerIdName :
                               (isset($body->broadcast_caller_id_name) ? $body->broadcast_caller_id_name : 'Call Broadcast');

    $broadcast_caller_id_number = isset($body->broadcastCallerIdNumber) ? $body->broadcastCallerIdNumber :
                                 (isset($body->broadcast_caller_id_number) ? $body->broadcast_caller_id_number : '0000000000');

    $broadcast_destination_type = isset($body->broadcastDestinationType) ? $body->broadcastDestinationType :
                                 (isset($body->broadcast_destination_type) ? $body->broadcast_destination_type : 'extension');

    $broadcast_destination_data = isset($body->broadcastDestinationData) ? $body->broadcastDestinationData :
                                 (isset($body->broadcast_destination_data) ? $body->broadcast_destination_data : '');

    $broadcast_phone_numbers = isset($body->broadcastPhoneNumbers) ? $body->broadcastPhoneNumbers :
                              (isset($body->broadcast_phone_numbers) ? $body->broadcast_phone_numbers : '');

    $broadcast_avmd = isset($body->broadcastAvmd) ? $body->broadcastAvmd :
                     (isset($body->broadcast_avmd) ? $body->broadcast_avmd : 'false');

    $broadcast_accountcode = isset($body->broadcastAccountcode) ? $body->broadcastAccountcode :
                            (isset($body->broadcast_accountcode) ? $body->broadcast_accountcode : $domain_name);

    // Handle phone numbers as array or string
    if (is_array($broadcast_phone_numbers)) {
        $broadcast_phone_numbers = implode("\n", $broadcast_phone_numbers);
    }

    // Generate UUID
    $call_broadcast_uuid = uuid();

    $database = new database;

    // Insert broadcast
    $sql = "INSERT INTO v_call_broadcasts (
                call_broadcast_uuid,
                domain_uuid,
                broadcast_name,
                broadcast_description,
                broadcast_start_time,
                broadcast_timeout,
                broadcast_concurrent_limit,
                broadcast_caller_id_name,
                broadcast_caller_id_number,
                broadcast_destination_type,
                broadcast_destination_data,
                broadcast_phone_numbers,
                broadcast_avmd,
                broadcast_accountcode,
                insert_date
            ) VALUES (
                :call_broadcast_uuid,
                :domain_uuid,
                :broadcast_name,
                :broadcast_description,
                :broadcast_start_time,
                :broadcast_timeout,
                :broadcast_concurrent_limit,
                :broadcast_caller_id_name,
                :broadcast_caller_id_number,
                :broadcast_destination_type,
                :broadcast_destination_data,
                :broadcast_phone_numbers,
                :broadcast_avmd,
                :broadcast_accountcode,
                NOW()
            )";

    $parameters = array(
        "call_broadcast_uuid" => $call_broadcast_uuid,
        "domain_uuid" => $db_domain_uuid,
        "broadcast_name" => $broadcast_name,
        "broadcast_description" => $broadcast_description,
        "broadcast_start_time" => $broadcast_start_time,
        "broadcast_timeout" => $broadcast_timeout,
        "broadcast_concurrent_limit" => $broadcast_concurrent_limit,
        "broadcast_caller_id_name" => $broadcast_caller_id_name,
        "broadcast_caller_id_number" => $broadcast_caller_id_number,
        "broadcast_destination_type" => $broadcast_destination_type,
        "broadcast_destination_data" => $broadcast_destination_data,
        "broadcast_phone_numbers" => $broadcast_phone_numbers,
        "broadcast_avmd" => $broadcast_avmd,
        "broadcast_accountcode" => $broadcast_accountcode
    );

    $database->execute($sql, $parameters);

    return array(
        "success" => true,
        "message" => "Broadcast created successfully",
        "callBroadcastUuid" => $call_broadcast_uuid
    );
}
