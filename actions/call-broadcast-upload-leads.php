<?php

$required_params = array("callBroadcastUuid", "phoneNumbers");

function do_action($body) {
    global $domain_uuid;

    // Use domain_uuid from request if provided, otherwise use global
    $db_domain_uuid = isset($body->domain_uuid) ? $body->domain_uuid : $domain_uuid;

    $call_broadcast_uuid = isset($body->callBroadcastUuid) ? $body->callBroadcastUuid :
                          (isset($body->call_broadcast_uuid) ? $body->call_broadcast_uuid : null);

    $phone_numbers = isset($body->phoneNumbers) ? $body->phoneNumbers :
                    (isset($body->phone_numbers) ? $body->phone_numbers : null);

    $append = isset($body->append) ? $body->append : true; // Default to append

    if (empty($call_broadcast_uuid)) {
        return array(
            "success" => false,
            "error" => "callBroadcastUuid is required"
        );
    }

    if (empty($phone_numbers)) {
        return array(
            "success" => false,
            "error" => "phoneNumbers is required"
        );
    }

    $database = new database;

    // Check if broadcast exists and get current numbers
    $sql = "SELECT broadcast_name, broadcast_phone_numbers FROM v_call_broadcasts
            WHERE call_broadcast_uuid = :call_broadcast_uuid
            AND domain_uuid = :domain_uuid";

    $broadcast = $database->select($sql, array(
        "call_broadcast_uuid" => $call_broadcast_uuid,
        "domain_uuid" => $db_domain_uuid
    ), "row");

    if (empty($broadcast)) {
        return array(
            "success" => false,
            "error" => "Broadcast not found"
        );
    }

    // Process phone numbers
    $new_numbers = array();

    if (is_array($phone_numbers)) {
        // Array of numbers
        $new_numbers = $phone_numbers;
    } else {
        // String - could be newline or comma separated
        $phone_numbers = str_replace(",", "\n", $phone_numbers);
        $new_numbers = array_filter(array_map('trim', explode("\n", $phone_numbers)));
    }

    // Clean phone numbers (remove non-numeric except + at start)
    $cleaned_numbers = array();
    foreach ($new_numbers as $number) {
        $number = trim($number);
        if (!empty($number)) {
            // Keep only the number part if there's additional data
            $parts = preg_split('/[|;]/', $number);
            $cleaned = preg_replace('/[^\d+]/', '', $parts[0]);
            if (!empty($cleaned) && strlen($cleaned) >= 6) {
                $cleaned_numbers[] = $number; // Keep original format
            }
        }
    }

    if (empty($cleaned_numbers)) {
        return array(
            "success" => false,
            "error" => "No valid phone numbers provided"
        );
    }

    // Prepare final phone numbers
    if ($append && !empty($broadcast['broadcast_phone_numbers'])) {
        $existing_numbers = array_filter(explode("\n", trim($broadcast['broadcast_phone_numbers'])));
        $final_numbers = array_merge($existing_numbers, $cleaned_numbers);
    } else {
        $final_numbers = $cleaned_numbers;
    }

    // Remove duplicates
    $final_numbers = array_unique($final_numbers);
    $final_numbers_string = implode("\n", $final_numbers);

    // Update broadcast
    $sql = "UPDATE v_call_broadcasts SET
                broadcast_phone_numbers = :broadcast_phone_numbers,
                update_date = NOW()
            WHERE call_broadcast_uuid = :call_broadcast_uuid
            AND domain_uuid = :domain_uuid";

    $database->execute($sql, array(
        "broadcast_phone_numbers" => $final_numbers_string,
        "call_broadcast_uuid" => $call_broadcast_uuid,
        "domain_uuid" => $db_domain_uuid
    ));

    return array(
        "success" => true,
        "message" => "Phone numbers uploaded successfully",
        "callBroadcastUuid" => $call_broadcast_uuid,
        "broadcastName" => $broadcast['broadcast_name'],
        "numbersAdded" => count($cleaned_numbers),
        "totalNumbers" => count($final_numbers),
        "append" => $append
    );
}
