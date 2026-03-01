<?php
$required_params = array("extension_uuid");

function do_action($body) {
    global $domain_uuid;

    // Get extension_uuid - support both camelCase and snake_case
    $extension_uuid = isset($body->extensionUuid) ? $body->extensionUuid :
                     (isset($body->extension_uuid) ? $body->extension_uuid : null);

    if (empty($extension_uuid)) {
        return array(
            "success" => false,
            "error" => "extension_uuid is required"
        );
    }

    $database = new database;

    // Get extension details
    $sql = "SELECT e.*, d.domain_name FROM v_extensions e
            LEFT JOIN v_domains d ON e.domain_uuid = d.domain_uuid
            WHERE e.extension_uuid = :extension_uuid";
    $extension = $database->select($sql, array("extension_uuid" => $extension_uuid), "row");

    if (empty($extension)) {
        return array(
            "success" => false,
            "error" => "Extension not found"
        );
    }

    // Toggle enabled status
    $current_status = $extension['enabled'];
    $new_status = ($current_status == 'true') ? 'false' : 'true';

    // Update extension
    $sql = "UPDATE v_extensions SET enabled = :enabled, update_date = NOW() WHERE extension_uuid = :extension_uuid";
    try {
        $database->execute($sql, array(
            "enabled" => $new_status,
            "extension_uuid" => $extension_uuid
        ));
    } catch (Exception $e) {
        return array(
            "success" => false,
            "error" => "Failed to toggle extension: " . $e->getMessage()
        );
    }

    // Get user_context for cache clearing
    $user_context = $extension['user_context'];
    $domain_name = $extension['domain_name'];
    if (empty($user_context)) {
        $user_context = $domain_name;
    }

    $ext_number = $extension['extension'];
    $number_alias = $extension['number_alias'];

    // Clear directory cache
    $cache_cleared = array();
    if (class_exists('cache')) {
        $cache = new cache;

        $cache->delete("directory:" . $ext_number . "@" . $user_context);
        $cache_cleared[] = "directory:" . $ext_number . "@" . $user_context;

        if (!empty($number_alias)) {
            $cache->delete("directory:" . $number_alias . "@" . $user_context);
            $cache_cleared[] = "directory:" . $number_alias . "@" . $user_context;
        }
    }

    // Reload XML via event socket
    $reload_success = false;
    $reload_output = "";
    if (class_exists('event_socket')) {
        $esl = event_socket::create();
        if ($esl) {
            $reload_output = event_socket::api('reloadxml');
            $reload_success = true;
        }
    }

    if (!$reload_success) {
        $reload_output = shell_exec("/usr/bin/fs_cli -x 'reloadxml' 2>&1");
        $reload_success = ($reload_output !== null);
    }

    return array(
        "success" => true,
        "message" => "Extension status toggled successfully",
        "extension_uuid" => $extension_uuid,
        "extension" => $ext_number,
        "domain_name" => $domain_name,
        "previous_status" => $current_status,
        "new_status" => $new_status,
        "enabled" => $new_status,
        "cache_cleared" => $cache_cleared,
        "reloaded" => $reload_success
    );
}
