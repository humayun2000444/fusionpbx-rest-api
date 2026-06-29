<?php

$required_params = array("deviceUuid");

function do_action($body) {
    global $domain_uuid;

    $db_domain_uuid = isset($body->domain_uuid) ? $body->domain_uuid :
                     (isset($body->domainUuid) ? $body->domainUuid : $domain_uuid);

    $device_uuid = isset($body->deviceUuid) ? $body->deviceUuid :
                  (isset($body->device_uuid) ? $body->device_uuid : null);

    if (empty($device_uuid)) {
        return array(
            "success" => false,
            "error" => "deviceUuid is required"
        );
    }

    $database = new database;

    // Verify device exists and belongs to domain
    $check_sql = "SELECT device_uuid FROM v_devices
                  WHERE device_uuid = :device_uuid AND domain_uuid = :domain_uuid";
    $check_result = $database->select($check_sql, array(
        "device_uuid" => $device_uuid,
        "domain_uuid" => $db_domain_uuid
    ), "row");

    if (empty($check_result)) {
        return array(
            "success" => false,
            "error" => "Device not found or access denied"
        );
    }

    // Build update fields
    $update_fields = array();
    $parameters = array(
        "device_uuid" => $device_uuid,
        "domain_uuid" => $db_domain_uuid
    );

    // Updateable fields mapping
    $field_mappings = array(
        "deviceAddress" => "device_address",
        "device_address" => "device_address",
        "deviceLabel" => "device_label",
        "device_label" => "device_label",
        "deviceVendor" => "device_vendor",
        "device_vendor" => "device_vendor",
        "deviceModel" => "device_model",
        "device_model" => "device_model",
        "deviceTemplate" => "device_template",
        "device_template" => "device_template",
        "deviceDescription" => "device_description",
        "device_description" => "device_description",
        "deviceEnabled" => "device_enabled",
        "device_enabled" => "device_enabled",
        "deviceFirmwareVersion" => "device_firmware_version",
        "device_firmware_version" => "device_firmware_version",
        "deviceProfileUuid" => "device_profile_uuid",
        "device_profile_uuid" => "device_profile_uuid",
        "deviceUsername" => "device_username",
        "device_username" => "device_username",
        "devicePassword" => "device_password",
        "device_password" => "device_password"
    );

    foreach ($field_mappings as $input_field => $db_field) {
        if (isset($body->$input_field)) {
            $value = $body->$input_field;

            // Normalize enabled value
            if ($db_field === 'device_enabled') {
                if ($value === true || $value === 'true' || $value === '1') {
                    $value = 'true';
                } else {
                    $value = 'false';
                }
            }

            // Normalize MAC address
            if ($db_field === 'device_address') {
                $value = strtolower(preg_replace('/[^a-fA-F0-9]/', '', $value));
                if (strlen($value) !== 12) {
                    return array(
                        "success" => false,
                        "error" => "Invalid MAC address. Must be 12 hex characters"
                    );
                }

                // Check for duplicate MAC in same domain (excluding current device)
                $dup_sql = "SELECT device_uuid FROM v_devices
                            WHERE domain_uuid = :dup_domain_uuid
                            AND device_address = :dup_address
                            AND device_uuid != :dup_device_uuid";
                $dup_result = $database->select($dup_sql, array(
                    "dup_domain_uuid" => $db_domain_uuid,
                    "dup_address" => $value,
                    "dup_device_uuid" => $device_uuid
                ), "row");

                if (!empty($dup_result)) {
                    return array(
                        "success" => false,
                        "error" => "Another device with this MAC address already exists in this domain",
                        "existingDeviceUuid" => $dup_result['device_uuid']
                    );
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
    $update_fields[] = "update_user = :update_user";
    $parameters["update_user"] = $db_domain_uuid;

    $sql = "UPDATE v_devices SET " . implode(", ", $update_fields) .
           " WHERE device_uuid = :device_uuid AND domain_uuid = :domain_uuid";

    try {
        $database->execute($sql, $parameters);
    } catch (Exception $e) {
        return array(
            "success" => false,
            "error" => "Failed to update device: " . $e->getMessage()
        );
    }

    return array(
        "success" => true,
        "message" => "Device updated successfully",
        "deviceUuid" => $device_uuid
    );
}
