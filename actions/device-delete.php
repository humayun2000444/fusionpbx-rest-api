<?php

$required_params = array("deviceUuid");

function do_action($body) {
    global $domain_uuid;

    $db_domain_uuid = isset($body->domain_uuid) ? $body->domain_uuid :
                     (isset($body->domainUuid) ? $body->domainUuid : $domain_uuid);

    $device_uuid = isset($body->deviceUuid) ? $body->deviceUuid :
                  (isset($body->device_uuid) ? $body->device_uuid : null);

    // Support bulk delete with array of UUIDs
    $device_uuids = isset($body->deviceUuids) ? $body->deviceUuids :
                   (isset($body->device_uuids) ? $body->device_uuids : null);

    if (empty($device_uuid) && empty($device_uuids)) {
        return array(
            "success" => false,
            "error" => "deviceUuid or deviceUuids is required"
        );
    }

    $database = new database;

    // Handle single delete
    if (!empty($device_uuid) && empty($device_uuids)) {
        $device_uuids = array($device_uuid);
    }

    // Ensure array
    if (!is_array($device_uuids)) {
        $device_uuids = array($device_uuids);
    }

    $deleted_count = 0;
    $errors = array();

    foreach ($device_uuids as $uuid) {
        // Verify device exists and belongs to domain
        $check_sql = "SELECT device_uuid, device_address, device_label FROM v_devices
                      WHERE device_uuid = :device_uuid AND domain_uuid = :domain_uuid";
        $check_result = $database->select($check_sql, array(
            "device_uuid" => $uuid,
            "domain_uuid" => $db_domain_uuid
        ), "row");

        if (empty($check_result)) {
            $errors[] = "Device {$uuid} not found or access denied";
            continue;
        }

        try {
            // Delete device_keys first (FK dependency)
            $database->execute(
                "DELETE FROM v_device_keys WHERE device_uuid = :device_uuid AND domain_uuid = :domain_uuid",
                array("device_uuid" => $uuid, "domain_uuid" => $db_domain_uuid)
            );

            // Delete device_lines (FK dependency)
            $database->execute(
                "DELETE FROM v_device_lines WHERE device_uuid = :device_uuid AND domain_uuid = :domain_uuid",
                array("device_uuid" => $uuid, "domain_uuid" => $db_domain_uuid)
            );

            // Delete device_settings (FK dependency)
            $database->execute(
                "DELETE FROM v_device_settings WHERE device_uuid = :device_uuid AND domain_uuid = :domain_uuid",
                array("device_uuid" => $uuid, "domain_uuid" => $db_domain_uuid)
            );

            // Delete the device itself
            $database->execute(
                "DELETE FROM v_devices WHERE device_uuid = :device_uuid AND domain_uuid = :domain_uuid",
                array("device_uuid" => $uuid, "domain_uuid" => $db_domain_uuid)
            );

            $deleted_count++;
        } catch (Exception $e) {
            $errors[] = "Failed to delete device {$uuid}: " . $e->getMessage();
        }
    }

    if ($deleted_count === 0) {
        return array(
            "success" => false,
            "error" => "No devices were deleted",
            "errors" => $errors
        );
    }

    $result = array(
        "success" => true,
        "message" => "Deleted {$deleted_count} device(s)",
        "deletedCount" => $deleted_count
    );

    if (!empty($errors)) {
        $result["warnings"] = $errors;
    }

    return $result;
}
