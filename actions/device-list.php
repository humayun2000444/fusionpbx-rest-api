<?php

$required_params = array();

function do_action($body) {
    global $domain_uuid;

    $db_domain_uuid = isset($body->domain_uuid) ? $body->domain_uuid :
                     (isset($body->domainUuid) ? $body->domainUuid : $domain_uuid);
    $limit = isset($body->limit) ? intval($body->limit) : 100;
    $offset = isset($body->offset) ? intval($body->offset) : 0;

    // Optional filters
    $device_vendor = isset($body->deviceVendor) ? $body->deviceVendor :
                    (isset($body->device_vendor) ? $body->device_vendor : null);
    $device_enabled = isset($body->deviceEnabled) ? $body->deviceEnabled :
                     (isset($body->device_enabled) ? $body->device_enabled : null);

    $database = new database;

    // Build WHERE clause
    $where_clauses = array("d.domain_uuid = :domain_uuid");
    $parameters = array("domain_uuid" => $db_domain_uuid);

    if (!empty($device_vendor)) {
        $where_clauses[] = "d.device_vendor = :device_vendor";
        $parameters["device_vendor"] = $device_vendor;
    }

    if ($device_enabled !== null) {
        $where_clauses[] = "d.device_enabled = :device_enabled";
        $parameters["device_enabled"] = ($device_enabled === true || $device_enabled === 'true') ? 'true' : 'false';
    }

    $where_sql = implode(" AND ", $where_clauses);

    // Get devices with their first line info
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
                d.device_description,
                d.device_provisioned_date,
                d.device_provisioned_method,
                d.device_provisioned_ip,
                d.insert_date,
                d.update_date,
                dl.device_line_uuid,
                dl.line_number,
                dl.server_address,
                dl.user_id,
                dl.auth_id,
                dl.display_name,
                dl.label as line_label,
                dl.sip_port,
                dl.sip_transport,
                dl.register_expires,
                dl.enabled as line_enabled
            FROM v_devices d
            LEFT JOIN v_device_lines dl ON d.device_uuid = dl.device_uuid AND dl.line_number = '1'
            WHERE {$where_sql}
            ORDER BY d.device_label ASC, d.device_address ASC
            LIMIT :limit OFFSET :offset";

    $parameters["limit"] = $limit;
    $parameters["offset"] = $offset;

    $devices = $database->select($sql, $parameters, "all");

    // Get total count
    $sql_count = "SELECT COUNT(*) as total FROM v_devices d WHERE {$where_sql}";
    unset($parameters["limit"], $parameters["offset"]);
    $count_result = $database->select($sql_count, $parameters, "row");
    $total = $count_result ? intval($count_result['total']) : 0;

    return array(
        "success" => true,
        "total" => $total,
        "limit" => $limit,
        "offset" => $offset,
        "devices" => $devices ?: array()
    );
}
