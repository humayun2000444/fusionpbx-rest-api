<?php

$required_params = array();

function do_action($body) {
    global $domain_uuid;

    $db_domain_uuid = isset($body->domain_uuid) ? $body->domain_uuid :
                     (isset($body->domainUuid) ? $body->domainUuid : $domain_uuid);

    $database = new database;

    // Get all extensions with their toll_allow (call permissions)
    $sql = "SELECT
                extension_uuid,
                domain_uuid,
                extension,
                effective_caller_id_name,
                toll_allow,
                enabled,
                description
            FROM v_extensions
            WHERE domain_uuid = :domain_uuid
            ORDER BY extension ASC";

    $parameters = array("domain_uuid" => $db_domain_uuid);
    $extensions = $database->select($sql, $parameters, "all");

    // Parse toll_allow into structured permissions
    $result = array();
    if (!empty($extensions)) {
        foreach ($extensions as $ext) {
            $tollAllow = $ext['toll_allow'] ?? '';
            $permissions = array_map('trim', explode(',', $tollAllow));

            $result[] = array(
                'extension_uuid' => $ext['extension_uuid'],
                'domain_uuid' => $ext['domain_uuid'],
                'extension' => $ext['extension'],
                'name' => $ext['effective_caller_id_name'],
                'description' => $ext['description'],
                'enabled' => $ext['enabled'],
                'toll_allow' => $tollAllow,
                'permissions' => array(
                    'local' => in_array('local', $permissions),
                    'domestic' => in_array('domestic', $permissions) || in_array('nationwide', $permissions),
                    'international' => in_array('international', $permissions),
                    'emergency' => in_array('emergency', $permissions),
                )
            );
        }
    }

    return array(
        "success" => true,
        "total" => count($result),
        "extensions" => $result
    );
}
