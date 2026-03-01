<?php

$required_params = array("callBlockNumber");

function do_action($body) {
    global $domain_uuid, $domain_name;

    // Use domain_uuid from request if provided, otherwise use global
    $db_domain_uuid = isset($body->domain_uuid) ? $body->domain_uuid :
                     (isset($body->domainUuid) ? $body->domainUuid : $domain_uuid);

    // Required: call_block_number (the number to block)
    $call_block_number = isset($body->callBlockNumber) ? $body->callBlockNumber :
                        (isset($body->call_block_number) ? $body->call_block_number : null);

    if (empty($call_block_number)) {
        return array(
            "success" => false,
            "error" => "callBlockNumber is required"
        );
    }

    // Optional parameters
    $call_block_name = isset($body->callBlockName) ? $body->callBlockName :
                      (isset($body->call_block_name) ? $body->call_block_name : $call_block_number);

    $call_block_direction = isset($body->callBlockDirection) ? $body->callBlockDirection :
                           (isset($body->call_block_direction) ? $body->call_block_direction : 'inbound');

    $extension_uuid = isset($body->extensionUuid) ? $body->extensionUuid :
                     (isset($body->extension_uuid) ? $body->extension_uuid : null);

    $call_block_country_code = isset($body->callBlockCountryCode) ? $body->callBlockCountryCode :
                              (isset($body->call_block_country_code) ? $body->call_block_country_code : null);

    $call_block_action = isset($body->callBlockAction) ? $body->callBlockAction :
                        (isset($body->call_block_action) ? $body->call_block_action : 'reject');

    $call_block_app = isset($body->callBlockApp) ? $body->callBlockApp :
                     (isset($body->call_block_app) ? $body->call_block_app : 'hangup');

    $call_block_data = isset($body->callBlockData) ? $body->callBlockData :
                      (isset($body->call_block_data) ? $body->call_block_data : '');

    $call_block_enabled = isset($body->callBlockEnabled) ? $body->callBlockEnabled :
                         (isset($body->call_block_enabled) ? $body->call_block_enabled : 'true');

    $call_block_description = isset($body->callBlockDescription) ? $body->callBlockDescription :
                             (isset($body->call_block_description) ? $body->call_block_description : '');

    // Normalize enabled value
    if ($call_block_enabled === true || $call_block_enabled === 'true' || $call_block_enabled === '1') {
        $call_block_enabled = 'true';
    } else {
        $call_block_enabled = 'false';
    }

    // Generate UUID
    $call_block_uuid = uuid();
    $date_added = date('Y-m-d H:i:s');

    $database = new database;

    // Check if number already blocked for this domain/direction
    $check_sql = "SELECT call_block_uuid FROM v_call_block
                  WHERE domain_uuid = :domain_uuid
                  AND call_block_number = :call_block_number
                  AND call_block_direction = :direction";
    $check_params = array(
        "domain_uuid" => $db_domain_uuid,
        "call_block_number" => $call_block_number,
        "direction" => $call_block_direction
    );
    $existing = $database->select($check_sql, $check_params, "row");

    if (!empty($existing)) {
        return array(
            "success" => false,
            "error" => "This number is already blocked for {$call_block_direction} calls",
            "existingCallBlockUuid" => $existing['call_block_uuid']
        );
    }

    // Build insert
    $columns = array(
        "call_block_uuid", "domain_uuid", "call_block_direction",
        "call_block_name", "call_block_number", "call_block_action",
        "call_block_app", "call_block_enabled", "date_added",
        "call_block_count", "insert_date"
    );

    $values = array(
        ":call_block_uuid", ":domain_uuid", ":call_block_direction",
        ":call_block_name", ":call_block_number", ":call_block_action",
        ":call_block_app", ":call_block_enabled", ":date_added",
        "0", "NOW()"
    );

    $parameters = array(
        "call_block_uuid" => $call_block_uuid,
        "domain_uuid" => $db_domain_uuid,
        "call_block_direction" => $call_block_direction,
        "call_block_name" => $call_block_name,
        "call_block_number" => $call_block_number,
        "call_block_action" => $call_block_action,
        "call_block_app" => $call_block_app,
        "call_block_enabled" => $call_block_enabled,
        "date_added" => $date_added
    );

    // Add optional fields
    $optional_fields = array(
        "extension_uuid" => $extension_uuid,
        "call_block_country_code" => $call_block_country_code,
        "call_block_data" => $call_block_data,
        "call_block_description" => $call_block_description
    );

    foreach ($optional_fields as $field => $value) {
        if (!empty($value)) {
            $columns[] = $field;
            $values[] = ":" . $field;
            $parameters[$field] = $value;
        }
    }

    $sql = "INSERT INTO v_call_block (" . implode(", ", $columns) . ") VALUES (" . implode(", ", $values) . ")";

    try {
        $database->execute($sql, $parameters);
    } catch (Exception $e) {
        return array(
            "success" => false,
            "error" => "Failed to create call block: " . $e->getMessage()
        );
    }

    // Verify creation
    $verify_sql = "SELECT call_block_uuid FROM v_call_block WHERE call_block_uuid = :call_block_uuid";
    $verify_result = $database->select($verify_sql, array("call_block_uuid" => $call_block_uuid), "row");

    if (empty($verify_result)) {
        return array(
            "success" => false,
            "error" => "Call block creation failed - database insert did not succeed"
        );
    }

    return array(
        "success" => true,
        "message" => "Call block created successfully",
        "callBlockUuid" => $call_block_uuid,
        "callBlockNumber" => $call_block_number,
        "callBlockDirection" => $call_block_direction
    );
}
