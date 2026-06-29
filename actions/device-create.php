<?php

$required_params = array("deviceAddress", "extensionUuid");

function do_action($body) {
    global $domain_uuid, $domain_name;

    // Use domain_uuid from request if provided, otherwise use global
    $db_domain_uuid = isset($body->domain_uuid) ? $body->domain_uuid :
                     (isset($body->domainUuid) ? $body->domainUuid : $domain_uuid);

    // Required: deviceAddress (MAC address)
    $device_address = isset($body->deviceAddress) ? $body->deviceAddress :
                     (isset($body->device_address) ? $body->device_address : null);

    // Required: extensionUuid
    $extension_uuid = isset($body->extensionUuid) ? $body->extensionUuid :
                     (isset($body->extension_uuid) ? $body->extension_uuid : null);

    if (empty($device_address)) {
        return array(
            "success" => false,
            "error" => "deviceAddress (MAC address) is required"
        );
    }

    if (empty($extension_uuid)) {
        return array(
            "success" => false,
            "error" => "extensionUuid is required"
        );
    }

    // Normalize MAC address: remove colons/dashes/dots, lowercase
    $device_address = strtolower(preg_replace('/[^a-fA-F0-9]/', '', $device_address));

    if (strlen($device_address) !== 12) {
        return array(
            "success" => false,
            "error" => "Invalid MAC address. Must be 12 hex characters (e.g., 001122334455 or 00:11:22:33:44:55)"
        );
    }

    // Optional fields
    $device_label = isset($body->deviceLabel) ? $body->deviceLabel :
                   (isset($body->device_label) ? $body->device_label : null);
    $device_vendor = isset($body->deviceVendor) ? $body->deviceVendor :
                    (isset($body->device_vendor) ? $body->device_vendor : null);
    $device_model = isset($body->deviceModel) ? $body->deviceModel :
                   (isset($body->device_model) ? $body->device_model : null);
    $device_template = isset($body->deviceTemplate) ? $body->deviceTemplate :
                      (isset($body->device_template) ? $body->device_template : null);
    $device_description = isset($body->deviceDescription) ? $body->deviceDescription :
                         (isset($body->device_description) ? $body->device_description : null);
    $device_enabled = isset($body->deviceEnabled) ? $body->deviceEnabled :
                     (isset($body->device_enabled) ? $body->device_enabled : 'true');

    // Normalize enabled value
    if ($device_enabled === true || $device_enabled === 'true' || $device_enabled === '1') {
        $device_enabled = 'true';
    } else {
        $device_enabled = 'false';
    }

    $database = new database;

    // Check for duplicate MAC address in same domain
    $check_sql = "SELECT device_uuid FROM v_devices
                  WHERE domain_uuid = :domain_uuid
                  AND device_address = :device_address";
    $check_params = array(
        "domain_uuid" => $db_domain_uuid,
        "device_address" => $device_address
    );
    $existing = $database->select($check_sql, $check_params, "row");

    if (!empty($existing)) {
        return array(
            "success" => false,
            "error" => "A device with this MAC address already exists in this domain",
            "existingDeviceUuid" => $existing['device_uuid']
        );
    }

    // Lookup extension details
    $ext_sql = "SELECT extension, password, effective_caller_id_name, effective_caller_id_number
                FROM v_extensions
                WHERE extension_uuid = :extension_uuid
                AND domain_uuid = :domain_uuid";
    $ext_result = $database->select($ext_sql, array(
        "extension_uuid" => $extension_uuid,
        "domain_uuid" => $db_domain_uuid
    ), "row");

    if (empty($ext_result)) {
        return array(
            "success" => false,
            "error" => "Extension not found or does not belong to this domain"
        );
    }

    $extension_number = $ext_result['extension'];
    $extension_password = $ext_result['password'];
    $caller_id_name = $ext_result['effective_caller_id_name'];

    // Lookup domain_name from v_domains
    $domain_result = $database->select(
        "SELECT domain_name FROM v_domains WHERE domain_uuid = :domain_uuid",
        array("domain_uuid" => $db_domain_uuid),
        "row"
    );

    if (empty($domain_result)) {
        return array(
            "success" => false,
            "error" => "Domain not found"
        );
    }

    $db_domain_name = $domain_result['domain_name'];

    // Generate UUIDs
    $device_uuid = uuid();
    $device_line_uuid = uuid();

    // If no label provided, use extension number
    if (empty($device_label)) {
        $device_label = $extension_number;
    }

    // Build device insert
    $device_columns = array(
        "device_uuid", "domain_uuid", "device_address", "device_label",
        "device_enabled", "insert_date", "insert_user"
    );
    $device_values = array(
        ":device_uuid", ":domain_uuid", ":device_address", ":device_label",
        ":device_enabled", "NOW()", ":insert_user"
    );
    $device_params = array(
        "device_uuid" => $device_uuid,
        "domain_uuid" => $db_domain_uuid,
        "device_address" => $device_address,
        "device_label" => $device_label,
        "device_enabled" => $device_enabled,
        "insert_user" => $db_domain_uuid
    );

    // Auto-detect device_template from vendor/model if not provided
    if (empty($device_template) && !empty($device_vendor)) {
        $vendor = strtolower($device_vendor);
        $model = strtolower($device_model ?? '');

        // Check multiple possible template directories
        $template_dirs = array(
            '/var/www/fusionpbx/resources/templates/provision/',
            '/usr/share/fusionpbx/templates/provision/',
            '/etc/fusionpbx/resources/templates/provision/',
        );
        $template_base = '/var/www/fusionpbx/resources/templates/provision/';
        foreach ($template_dirs as $dir) {
            if (is_dir($dir)) { $template_base = $dir; break; }
        }

        // Try exact model match first, then generic patterns
        $candidates = array();
        if (!empty($model)) {
            $candidates[] = $vendor . '/' . $model;
            // Try without last digits (e.g., gxp1610 -> gxp16xx)
            $generic = preg_replace('/\d{2}$/', 'xx', $model);
            if ($generic !== $model) $candidates[] = $vendor . '/' . $generic;
            // Try without last 3 digits
            $generic2 = preg_replace('/\d{3}$/', 'xx', $model);
            if ($generic2 !== $model) $candidates[] = $vendor . '/' . $generic2;
        }

        foreach ($candidates as $candidate) {
            if (is_dir($template_base . $candidate)) {
                $device_template = $candidate;
                break;
            }
        }

        // Fallback: scan vendor directory for any matching subdirectory
        if (empty($device_template) && is_dir($template_base . $vendor)) {
            $entries = scandir($template_base . $vendor);
            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') continue;
                if (is_dir($template_base . $vendor . '/' . $entry) && !empty($model) && strpos($entry, substr($model, 0, 3)) !== false) {
                    $device_template = $vendor . '/' . $entry;
                    break;
                }
            }
        }
    }

    // Add optional device fields
    $optional_device_fields = array(
        "device_vendor" => $device_vendor,
        "device_model" => $device_model,
        "device_template" => $device_template,
        "device_description" => $device_description
    );

    foreach ($optional_device_fields as $field => $value) {
        if (!empty($value)) {
            $device_columns[] = $field;
            $device_values[] = ":" . $field;
            $device_params[$field] = $value;
        }
    }

    $device_sql = "INSERT INTO v_devices (" . implode(", ", $device_columns) . ") VALUES (" . implode(", ", $device_values) . ")";

    try {
        $database->execute($device_sql, $device_params);
    } catch (Exception $e) {
        return array(
            "success" => false,
            "error" => "Failed to create device: " . $e->getMessage()
        );
    }

    // Verify device creation
    $verify_sql = "SELECT device_uuid FROM v_devices WHERE device_uuid = :device_uuid";
    $verify_result = $database->select($verify_sql, array("device_uuid" => $device_uuid), "row");

    if (empty($verify_result)) {
        return array(
            "success" => false,
            "error" => "Device creation failed - database insert did not succeed"
        );
    }

    // Determine outbound proxy (server IP for SIP registration)
    // Use external_sip_ip or server IP as outbound proxy
    $outbound_proxy = '';
    $proxy_sql = "SELECT default_setting_value FROM v_default_settings
                  WHERE default_setting_category = 'provision'
                  AND default_setting_subcategory = 'outbound_proxy'
                  AND default_setting_enabled = 'true' LIMIT 1";
    $proxy_result = $database->select($proxy_sql, null, "row");
    if (!empty($proxy_result['default_setting_value'])) {
        $outbound_proxy = $proxy_result['default_setting_value'];
    } else {
        // Fallback: use server's external IP
        $outbound_proxy = $_SERVER['SERVER_ADDR'] ?? $_SERVER['HTTP_HOST'] ?? '';
    }

    // Create device line
    $line_sql = "INSERT INTO v_device_lines (
                    device_line_uuid, device_uuid, domain_uuid,
                    line_number, server_address, server_address_primary,
                    outbound_proxy_primary,
                    user_id, auth_id, password, display_name, label,
                    sip_port, sip_transport, register_expires, enabled
                ) VALUES (
                    :device_line_uuid, :device_uuid, :domain_uuid,
                    :line_number, :server_address, :server_address_primary,
                    :outbound_proxy_primary,
                    :user_id, :auth_id, :password, :display_name, :label,
                    :sip_port, :sip_transport, :register_expires, :enabled
                )";

    $line_params = array(
        "device_line_uuid" => $device_line_uuid,
        "device_uuid" => $device_uuid,
        "domain_uuid" => $db_domain_uuid,
        "line_number" => "1",
        "server_address" => $db_domain_name,
        "server_address_primary" => $db_domain_name,
        "outbound_proxy_primary" => $outbound_proxy,
        "user_id" => $extension_number,
        "auth_id" => $extension_number,
        "password" => $extension_password,
        "display_name" => $caller_id_name,
        "label" => $extension_number,
        "sip_port" => "5060",
        "sip_transport" => "udp",
        "register_expires" => "120",
        "enabled" => "true"
    );

    try {
        $database->execute($line_sql, $line_params);
    } catch (Exception $e) {
        // Cleanup device if line creation fails
        $database->execute("DELETE FROM v_devices WHERE device_uuid = :device_uuid", array("device_uuid" => $device_uuid));
        return array(
            "success" => false,
            "error" => "Failed to create device line: " . $e->getMessage()
        );
    }

    return array(
        "success" => true,
        "message" => "Device created successfully",
        "deviceUuid" => $device_uuid,
        "deviceLineUuid" => $device_line_uuid,
        "deviceAddress" => $device_address,
        "deviceLabel" => $device_label,
        "extension" => $extension_number,
        "serverAddress" => $db_domain_name
    );
}
