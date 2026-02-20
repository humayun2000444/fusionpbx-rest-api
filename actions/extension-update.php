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

    // Get existing extension
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

    // Build update query dynamically
    $updates = array();
    $parameters = array("extension_uuid" => $extension_uuid);

    // Map of API field names to database column names
    $field_mappings = array(
        // camelCase -> snake_case database columns
        "extension" => "extension",
        "password" => "password",
        "accountcode" => "accountcode",
        "effectiveCallerIdName" => "effective_caller_id_name",
        "effective_caller_id_name" => "effective_caller_id_name",
        "effectiveCallerIdNumber" => "effective_caller_id_number",
        "effective_caller_id_number" => "effective_caller_id_number",
        "outboundCallerIdName" => "outbound_caller_id_name",
        "outbound_caller_id_name" => "outbound_caller_id_name",
        "outboundCallerIdNumber" => "outbound_caller_id_number",
        "outbound_caller_id_number" => "outbound_caller_id_number",
        "emergencyCallerIdName" => "emergency_caller_id_name",
        "emergency_caller_id_name" => "emergency_caller_id_name",
        "emergencyCallerIdNumber" => "emergency_caller_id_number",
        "emergency_caller_id_number" => "emergency_caller_id_number",
        "directoryFirstName" => "directory_first_name",
        "directory_first_name" => "directory_first_name",
        "directoryLastName" => "directory_last_name",
        "directory_last_name" => "directory_last_name",
        "directoryVisible" => "directory_visible",
        "directory_visible" => "directory_visible",
        "directoryExtenVisible" => "directory_exten_visible",
        "directory_exten_visible" => "directory_exten_visible",
        "maxRegistrations" => "max_registrations",
        "max_registrations" => "max_registrations",
        "limitMax" => "limit_max",
        "limit_max" => "limit_max",
        "limitDestination" => "limit_destination",
        "limit_destination" => "limit_destination",
        "missedCallApp" => "missed_call_app",
        "missed_call_app" => "missed_call_app",
        "missedCallData" => "missed_call_data",
        "missed_call_data" => "missed_call_data",
        "tollAllow" => "toll_allow",
        "toll_allow" => "toll_allow",
        "callTimeout" => "call_timeout",
        "call_timeout" => "call_timeout",
        "callGroup" => "call_group",
        "call_group" => "call_group",
        "callScreenEnabled" => "call_screen_enabled",
        "call_screen_enabled" => "call_screen_enabled",
        "userRecord" => "user_record",
        "user_record" => "user_record",
        "holdMusic" => "hold_music",
        "hold_music" => "hold_music",
        "userContext" => "user_context",
        "user_context" => "user_context",
        "authAcl" => "auth_acl",
        "auth_acl" => "auth_acl",
        "cidr" => "cidr",
        "sipForceContact" => "sip_force_contact",
        "sip_force_contact" => "sip_force_contact",
        "sipForceExpires" => "sip_force_expires",
        "sip_force_expires" => "sip_force_expires",
        "mwiAccount" => "mwi_account",
        "mwi_account" => "mwi_account",
        "sipBypassMedia" => "sip_bypass_media",
        "sip_bypass_media" => "sip_bypass_media",
        "absoluteCodecString" => "absolute_codec_string",
        "absolute_codec_string" => "absolute_codec_string",
        "forcePing" => "force_ping",
        "force_ping" => "force_ping",
        "dialString" => "dial_string",
        "dial_string" => "dial_string",
        "enabled" => "enabled",
        "description" => "description",
        "doNotDisturb" => "do_not_disturb",
        "do_not_disturb" => "do_not_disturb",
        "forwardAllEnabled" => "forward_all_enabled",
        "forward_all_enabled" => "forward_all_enabled",
        "forwardAllDestination" => "forward_all_destination",
        "forward_all_destination" => "forward_all_destination",
        "forwardBusyEnabled" => "forward_busy_enabled",
        "forward_busy_enabled" => "forward_busy_enabled",
        "forwardBusyDestination" => "forward_busy_destination",
        "forward_busy_destination" => "forward_busy_destination",
        "forwardNoAnswerEnabled" => "forward_no_answer_enabled",
        "forward_no_answer_enabled" => "forward_no_answer_enabled",
        "forwardNoAnswerDestination" => "forward_no_answer_destination",
        "forward_no_answer_destination" => "forward_no_answer_destination",
        "forwardUserNotRegisteredEnabled" => "forward_user_not_registered_enabled",
        "forward_user_not_registered_enabled" => "forward_user_not_registered_enabled",
        "forwardUserNotRegisteredDestination" => "forward_user_not_registered_destination",
        "forward_user_not_registered_destination" => "forward_user_not_registered_destination",
        "followMeEnabled" => "follow_me_enabled",
        "follow_me_enabled" => "follow_me_enabled",
        "followMeUuid" => "follow_me_uuid",
        "follow_me_uuid" => "follow_me_uuid"
    );

    // Process each field in the request
    foreach ($body as $key => $value) {
        if ($key === 'extensionUuid' || $key === 'extension_uuid') continue;
        if ($key === 'domainUuid' || $key === 'domain_uuid') continue;

        $db_column = isset($field_mappings[$key]) ? $field_mappings[$key] : null;
        if ($db_column && $value !== null) {
            $param_name = str_replace('.', '_', $db_column);
            $updates[] = $db_column . " = :" . $param_name;
            $parameters[$param_name] = $value;
        }
    }

    if (count($updates) == 0) {
        return array(
            "success" => false,
            "error" => "No fields to update"
        );
    }

    // Add update_date
    $updates[] = "update_date = NOW()";

    // Execute update
    $sql = "UPDATE v_extensions SET " . implode(", ", $updates) . " WHERE extension_uuid = :extension_uuid";
    try {
        $database->execute($sql, $parameters);
    } catch (Exception $e) {
        return array(
            "success" => false,
            "error" => "Failed to update extension: " . $e->getMessage()
        );
    }

    // Get user_context for cache clearing
    $user_context = $extension['user_context'];
    if (empty($user_context)) {
        $user_context = $extension['domain_name'];
    }

    // Get extension number (use new value if provided)
    $ext_number = isset($body->extension) ? $body->extension : $extension['extension'];
    $old_ext_number = $extension['extension'];
    $number_alias = $extension['number_alias'];

    // Clear directory cache (like FusionPBX does)
    $cache_cleared = array();
    if (class_exists('cache')) {
        $cache = new cache;

        // Clear old extension cache
        $cache->delete("directory:" . $old_ext_number . "@" . $user_context);
        $cache_cleared[] = "directory:" . $old_ext_number . "@" . $user_context;

        // Clear new extension cache if changed
        if ($ext_number != $old_ext_number) {
            $cache->delete("directory:" . $ext_number . "@" . $user_context);
            $cache_cleared[] = "directory:" . $ext_number . "@" . $user_context;
        }

        // Clear number alias cache
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

    // Get updated extension
    $sql = "SELECT e.*, d.domain_name FROM v_extensions e
            LEFT JOIN v_domains d ON e.domain_uuid = d.domain_uuid
            WHERE e.extension_uuid = :extension_uuid";
    $updated = $database->select($sql, array("extension_uuid" => $extension_uuid), "row");

    return array(
        "success" => true,
        "message" => "Extension updated successfully",
        "extension_uuid" => $extension_uuid,
        "extension" => $updated['extension'],
        "domain_name" => $updated['domain_name'],
        "outbound_caller_id_name" => $updated['outbound_caller_id_name'],
        "outbound_caller_id_number" => $updated['outbound_caller_id_number'],
        "effective_caller_id_name" => $updated['effective_caller_id_name'],
        "effective_caller_id_number" => $updated['effective_caller_id_number'],
        "enabled" => $updated['enabled'],
        "cache_cleared" => $cache_cleared,
        "reloaded" => $reload_success
    );
}
