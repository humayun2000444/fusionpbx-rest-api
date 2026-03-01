<?php

$required_params = array("extensionUuid");

function do_action($body) {
    global $domain_uuid;

    $db_domain_uuid = isset($body->domain_uuid) ? $body->domain_uuid :
                     (isset($body->domainUuid) ? $body->domainUuid : $domain_uuid);

    $extension_uuid = isset($body->extensionUuid) ? $body->extensionUuid :
                     (isset($body->extension_uuid) ? $body->extension_uuid : null);

    if (empty($extension_uuid)) {
        return array(
            "success" => false,
            "error" => "extensionUuid is required"
        );
    }

    $database = new database;

    // Verify extension exists and belongs to domain
    $check_sql = "SELECT extension_uuid, toll_allow FROM v_extensions
                  WHERE extension_uuid = :extension_uuid AND domain_uuid = :domain_uuid";
    $check_result = $database->select($check_sql, array(
        "extension_uuid" => $extension_uuid,
        "domain_uuid" => $db_domain_uuid
    ), "row");

    if (empty($check_result)) {
        return array(
            "success" => false,
            "error" => "Extension not found or access denied"
        );
    }

    // Build toll_allow string from permissions
    $permissions = array();

    // Check for individual permission flags
    $local = isset($body->local) ? $body->local :
            (isset($body->permissions->local) ? $body->permissions->local : null);
    $domestic = isset($body->domestic) ? $body->domestic :
               (isset($body->permissions->domestic) ? $body->permissions->domestic :
               (isset($body->nationwide) ? $body->nationwide :
               (isset($body->permissions->nationwide) ? $body->permissions->nationwide : null)));
    $international = isset($body->international) ? $body->international :
                    (isset($body->permissions->international) ? $body->permissions->international : null);
    $emergency = isset($body->emergency) ? $body->emergency :
                (isset($body->permissions->emergency) ? $body->permissions->emergency : null);

    // If toll_allow is provided directly, use it
    if (isset($body->toll_allow) || isset($body->tollAllow)) {
        $toll_allow = isset($body->toll_allow) ? $body->toll_allow : $body->tollAllow;
    } else {
        // Build from individual permissions
        if ($local === true || $local === 'true' || $local === '1') {
            $permissions[] = 'local';
        }
        if ($domestic === true || $domestic === 'true' || $domestic === '1') {
            $permissions[] = 'domestic';
        }
        if ($international === true || $international === 'true' || $international === '1') {
            $permissions[] = 'international';
        }
        if ($emergency === true || $emergency === 'true' || $emergency === '1') {
            $permissions[] = 'emergency';
        }

        $toll_allow = implode(',', $permissions);
    }

    // Update extension
    $sql = "UPDATE v_extensions SET toll_allow = :toll_allow, update_date = NOW()
            WHERE extension_uuid = :extension_uuid AND domain_uuid = :domain_uuid";

    $parameters = array(
        "toll_allow" => $toll_allow,
        "extension_uuid" => $extension_uuid,
        "domain_uuid" => $db_domain_uuid
    );

    try {
        $database->execute($sql, $parameters);
    } catch (Exception $e) {
        return array(
            "success" => false,
            "error" => "Failed to update call permissions: " . $e->getMessage()
        );
    }

    // Parse the updated toll_allow for response
    $permissionsList = array_map('trim', explode(',', $toll_allow));

    return array(
        "success" => true,
        "message" => "Call permissions updated successfully",
        "extensionUuid" => $extension_uuid,
        "tollAllow" => $toll_allow,
        "permissions" => array(
            'local' => in_array('local', $permissionsList),
            'domestic' => in_array('domestic', $permissionsList),
            'international' => in_array('international', $permissionsList),
            'emergency' => in_array('emergency', $permissionsList),
        )
    );
}
