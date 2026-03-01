<?php

$required_params = array("callBlockUuid");

function do_action($body) {
    global $domain_uuid;

    $db_domain_uuid = isset($body->domain_uuid) ? $body->domain_uuid :
                     (isset($body->domainUuid) ? $body->domainUuid : $domain_uuid);

    $call_block_uuid = isset($body->callBlockUuid) ? $body->callBlockUuid :
                      (isset($body->call_block_uuid) ? $body->call_block_uuid : null);

    if (empty($call_block_uuid)) {
        return array(
            "success" => false,
            "error" => "callBlockUuid is required"
        );
    }

    $database = new database;

    // Verify call block exists and belongs to domain
    $check_sql = "SELECT call_block_uuid FROM v_call_block
                  WHERE call_block_uuid = :call_block_uuid AND domain_uuid = :domain_uuid";
    $check_result = $database->select($check_sql, array(
        "call_block_uuid" => $call_block_uuid,
        "domain_uuid" => $db_domain_uuid
    ), "row");

    if (empty($check_result)) {
        return array(
            "success" => false,
            "error" => "Call block not found or access denied"
        );
    }

    // Build update fields
    $update_fields = array();
    $parameters = array(
        "call_block_uuid" => $call_block_uuid,
        "domain_uuid" => $db_domain_uuid
    );

    // Updateable fields
    $field_mappings = array(
        "callBlockName" => "call_block_name",
        "call_block_name" => "call_block_name",
        "callBlockNumber" => "call_block_number",
        "call_block_number" => "call_block_number",
        "callBlockDirection" => "call_block_direction",
        "call_block_direction" => "call_block_direction",
        "callBlockCountryCode" => "call_block_country_code",
        "call_block_country_code" => "call_block_country_code",
        "callBlockAction" => "call_block_action",
        "call_block_action" => "call_block_action",
        "callBlockApp" => "call_block_app",
        "call_block_app" => "call_block_app",
        "callBlockData" => "call_block_data",
        "call_block_data" => "call_block_data",
        "callBlockEnabled" => "call_block_enabled",
        "call_block_enabled" => "call_block_enabled",
        "callBlockDescription" => "call_block_description",
        "call_block_description" => "call_block_description",
        "extensionUuid" => "extension_uuid",
        "extension_uuid" => "extension_uuid"
    );

    foreach ($field_mappings as $input_field => $db_field) {
        if (isset($body->$input_field)) {
            $value = $body->$input_field;

            // Normalize enabled value
            if ($db_field === 'call_block_enabled') {
                if ($value === true || $value === 'true' || $value === '1') {
                    $value = 'true';
                } else {
                    $value = 'false';
                }
            }

            // Avoid duplicates
            if (!in_array("{$db_field} = :{$db_field}", $update_fields)) {
                $update_fields[] = "{$db_field} = :{$db_field}";
                $parameters[$db_field] = $value;
            }
        }
    }

    if (empty($update_fields)) {
        return array(
            "success" => false,
            "error" => "No fields to update"
        );
    }

    // Add update timestamp
    $update_fields[] = "update_date = NOW()";

    $sql = "UPDATE v_call_block SET " . implode(", ", $update_fields) .
           " WHERE call_block_uuid = :call_block_uuid AND domain_uuid = :domain_uuid";

    try {
        $database->execute($sql, $parameters);
    } catch (Exception $e) {
        return array(
            "success" => false,
            "error" => "Failed to update call block: " . $e->getMessage()
        );
    }

    return array(
        "success" => true,
        "message" => "Call block updated successfully",
        "callBlockUuid" => $call_block_uuid
    );
}
