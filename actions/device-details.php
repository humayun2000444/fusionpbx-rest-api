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

    // Get device details
    $sql = "SELECT
                d.device_uuid,
                d.domain_uuid,
                d.device_profile_uuid,
                d.device_address,
                d.device_label,
                d.device_vendor,
                d.device_model,
                d.device_firmware_version,
                d.device_enabled,
                d.device_template,
                d.device_user_uuid,
                d.device_username,
                d.device_password,
                d.device_description,
                d.device_provisioned_date,
                d.device_provisioned_method,
                d.device_provisioned_ip,
                d.insert_date,
                d.insert_user,
                d.update_date,
                d.update_user
            FROM v_devices d
            WHERE d.device_uuid = :device_uuid
            AND d.domain_uuid = :domain_uuid";

    $device = $database->select($sql, array(
        "device_uuid" => $device_uuid,
        "domain_uuid" => $db_domain_uuid
    ), "row");

    if (empty($device)) {
        return array(
            "success" => false,
            "error" => "Device not found or access denied"
        );
    }

    // Get device lines
    $lines_sql = "SELECT
                    device_line_uuid,
                    line_number,
                    server_address,
                    server_address_primary,
                    server_address_secondary,
                    outbound_proxy_primary,
                    outbound_proxy_secondary,
                    label,
                    display_name,
                    user_id,
                    auth_id,
                    password,
                    sip_port,
                    sip_transport,
                    register_expires,
                    shared_line,
                    enabled
                FROM v_device_lines
                WHERE device_uuid = :device_uuid
                AND domain_uuid = :domain_uuid
                ORDER BY line_number ASC";

    $lines = $database->select($lines_sql, array(
        "device_uuid" => $device_uuid,
        "domain_uuid" => $db_domain_uuid
    ), "all");

    // Get device keys
    $keys_sql = "SELECT
                    device_key_uuid,
                    device_key_id,
                    device_key_category,
                    device_key_vendor,
                    device_key_type,
                    device_key_subtype,
                    device_key_line,
                    device_key_value,
                    device_key_extension,
                    device_key_protected,
                    device_key_label,
                    device_key_icon
                FROM v_device_keys
                WHERE device_uuid = :device_uuid
                AND domain_uuid = :domain_uuid
                ORDER BY device_key_id ASC";

    $keys = $database->select($keys_sql, array(
        "device_uuid" => $device_uuid,
        "domain_uuid" => $db_domain_uuid
    ), "all");

    // Get device settings
    $settings_sql = "SELECT
                        device_setting_uuid,
                        device_setting_category,
                        device_setting_subcategory,
                        device_setting_name,
                        device_setting_value,
                        device_setting_enabled,
                        device_setting_description
                    FROM v_device_settings
                    WHERE device_uuid = :device_uuid
                    AND domain_uuid = :domain_uuid
                    ORDER BY device_setting_category ASC, device_setting_subcategory ASC";

    $settings = $database->select($settings_sql, array(
        "device_uuid" => $device_uuid,
        "domain_uuid" => $db_domain_uuid
    ), "all");

    $device["lines"] = $lines ?: array();
    $device["keys"] = $keys ?: array();
    $device["settings"] = $settings ?: array();

    return array(
        "success" => true,
        "device" => $device
    );
}
