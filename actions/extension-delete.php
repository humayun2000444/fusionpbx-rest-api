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

    // Get extension details before deletion
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

    $ext_number = $extension['extension'];
    $number_alias = $extension['number_alias'];
    $user_context = $extension['user_context'];
    $domain_name = $extension['domain_name'];
    $ext_domain_uuid = $extension['domain_uuid'];

    if (empty($user_context)) {
        $user_context = $domain_name;
    }

    // Delete voicemail if exists
    $sql = "DELETE FROM v_voicemails WHERE voicemail_uuid IN
            (SELECT voicemail_uuid FROM v_voicemails WHERE voicemail_id = :extension AND domain_uuid = :domain_uuid)";
    try {
        $database->execute($sql, array(
            "extension" => $ext_number,
            "domain_uuid" => $ext_domain_uuid
        ));
    } catch (Exception $e) {
        // Ignore voicemail deletion errors
    }

    // Delete extension
    $sql = "DELETE FROM v_extensions WHERE extension_uuid = :extension_uuid";
    try {
        $database->execute($sql, array("extension_uuid" => $extension_uuid));
    } catch (Exception $e) {
        return array(
            "success" => false,
            "error" => "Failed to delete extension: " . $e->getMessage()
        );
    }

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
        "message" => "Extension deleted successfully",
        "extension_uuid" => $extension_uuid,
        "extension" => $ext_number,
        "domain_name" => $domain_name,
        "cache_cleared" => $cache_cleared,
        "reloaded" => $reload_success
    );
}
