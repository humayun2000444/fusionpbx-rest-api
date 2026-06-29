<?php

$required_params = array();

function do_action($body) {

    $database = new database;

    // Get all enabled vendors
    $sql = "SELECT
                device_vendor_uuid,
                name,
                enabled
            FROM v_device_vendors
            WHERE enabled = 'true'
            ORDER BY name ASC";

    $vendors = $database->select($sql, null, "all");

    if (empty($vendors)) {
        return array(
            "success" => true,
            "total" => 0,
            "vendors" => array()
        );
    }

    // For each vendor, get their available functions (types)
    foreach ($vendors as &$vendor) {
        $func_sql = "SELECT
                        device_vendor_function_uuid,
                        type,
                        subtype,
                        value,
                        enabled
                    FROM v_device_vendor_functions
                    WHERE device_vendor_uuid = :device_vendor_uuid
                    AND enabled = 'true'
                    ORDER BY type ASC, subtype ASC";

        $functions = $database->select($func_sql, array(
            "device_vendor_uuid" => $vendor['device_vendor_uuid']
        ), "all");

        $vendor["functions"] = $functions ?: array();
    }
    unset($vendor); // break reference

    return array(
        "success" => true,
        "total" => count($vendors),
        "vendors" => $vendors
    );
}
