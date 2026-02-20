<?php

$required_params = array("extension");

function do_action($body) {
    global $domain_uuid, $domain_name;

    // Use domain_uuid from request if provided, otherwise use global
    $db_domain_uuid = isset($body->domain_uuid) ? $body->domain_uuid :
                     (isset($body->domainUuid) ? $body->domainUuid : $domain_uuid);

    // Get extension number
    $extension = isset($body->extension) ? $body->extension : null;

    if (empty($extension)) {
        return array(
            "success" => false,
            "error" => "Extension number is required"
        );
    }

    // Sanitize extension (same as FusionPBX)
    $extension = str_replace(' ', '-', $extension);

    if (empty($extension)) {
        return array(
            "success" => false,
            "error" => "Invalid extension number"
        );
    }

    $database = new database;

    // Get domain name
    $sql = "SELECT domain_name FROM v_domains WHERE domain_uuid = :domain_uuid";
    $domain_result = $database->select($sql, array("domain_uuid" => $db_domain_uuid), "row");
    $db_domain_name = $domain_result ? $domain_result['domain_name'] : 'default';

    // Check if extension already exists
    $sql = "SELECT extension_uuid FROM v_extensions WHERE extension = :extension AND domain_uuid = :domain_uuid";
    $existing = $database->select($sql, array(
        "extension" => $extension,
        "domain_uuid" => $db_domain_uuid
    ), "row");

    if (!empty($existing)) {
        return array(
            "success" => false,
            "error" => "Extension $extension already exists in this domain"
        );
    }

    // Get optional parameters with defaults (matching FusionPBX defaults)
    // Use FusionPBX's built-in generate_password function or our fallback
    $password = isset($body->password) ? $body->password : generate_extension_password(12);
    $number_alias = isset($body->numberAlias) ? $body->numberAlias :
                   (isset($body->number_alias) ? $body->number_alias : null);
    $accountcode = isset($body->accountcode) ? $body->accountcode : $db_domain_name;
    $effective_caller_id_name = isset($body->effectiveCallerIdName) ? $body->effectiveCallerIdName :
                               (isset($body->effective_caller_id_name) ? $body->effective_caller_id_name : $extension);
    $effective_caller_id_number = isset($body->effectiveCallerIdNumber) ? $body->effectiveCallerIdNumber :
                                 (isset($body->effective_caller_id_number) ? $body->effective_caller_id_number : $extension);
    $outbound_caller_id_name = isset($body->outboundCallerIdName) ? $body->outboundCallerIdName :
                              (isset($body->outbound_caller_id_name) ? $body->outbound_caller_id_name : null);
    $outbound_caller_id_number = isset($body->outboundCallerIdNumber) ? $body->outboundCallerIdNumber :
                                (isset($body->outbound_caller_id_number) ? $body->outbound_caller_id_number : null);
    $emergency_caller_id_name = isset($body->emergencyCallerIdName) ? $body->emergencyCallerIdName :
                               (isset($body->emergency_caller_id_name) ? $body->emergency_caller_id_name : null);
    $emergency_caller_id_number = isset($body->emergencyCallerIdNumber) ? $body->emergencyCallerIdNumber :
                                 (isset($body->emergency_caller_id_number) ? $body->emergency_caller_id_number : null);
    $directory_first_name = isset($body->directoryFirstName) ? $body->directoryFirstName :
                           (isset($body->directory_first_name) ? $body->directory_first_name : null);
    $directory_last_name = isset($body->directoryLastName) ? $body->directoryLastName :
                          (isset($body->directory_last_name) ? $body->directory_last_name : null);
    $directory_visible = isset($body->directoryVisible) ? $body->directoryVisible :
                        (isset($body->directory_visible) ? $body->directory_visible : 'true');
    $directory_exten_visible = isset($body->directoryExtenVisible) ? $body->directoryExtenVisible :
                              (isset($body->directory_exten_visible) ? $body->directory_exten_visible : 'true');
    $max_registrations = isset($body->maxRegistrations) ? $body->maxRegistrations :
                        (isset($body->max_registrations) ? $body->max_registrations : null);
    $limit_max = isset($body->limitMax) ? $body->limitMax :
                (isset($body->limit_max) ? $body->limit_max : '5');
    $limit_destination = isset($body->limitDestination) ? $body->limitDestination :
                        (isset($body->limit_destination) ? $body->limit_destination : 'error/user_busy');
    $user_context = isset($body->userContext) ? $body->userContext :
                   (isset($body->user_context) ? $body->user_context : $db_domain_name);
    $toll_allow = isset($body->tollAllow) ? $body->tollAllow :
                 (isset($body->toll_allow) ? $body->toll_allow : null);
    $call_timeout = isset($body->callTimeout) ? $body->callTimeout :
                   (isset($body->call_timeout) ? $body->call_timeout : '30');
    $call_group = isset($body->callGroup) ? $body->callGroup :
                 (isset($body->call_group) ? $body->call_group : null);
    $call_screen_enabled = isset($body->callScreenEnabled) ? $body->callScreenEnabled :
                          (isset($body->call_screen_enabled) ? $body->call_screen_enabled : 'false');
    $user_record = isset($body->userRecord) ? $body->userRecord :
                  (isset($body->user_record) ? $body->user_record : null);
    $hold_music = isset($body->holdMusic) ? $body->holdMusic :
                 (isset($body->hold_music) ? $body->hold_music : null);
    $auth_acl = isset($body->authAcl) ? $body->authAcl :
               (isset($body->auth_acl) ? $body->auth_acl : null);
    $cidr = isset($body->cidr) ? $body->cidr : null;
    $sip_force_contact = isset($body->sipForceContact) ? $body->sipForceContact :
                        (isset($body->sip_force_contact) ? $body->sip_force_contact : null);
    $sip_force_expires = isset($body->sipForceExpires) ? $body->sipForceExpires :
                        (isset($body->sip_force_expires) ? $body->sip_force_expires : null);
    $mwi_account = isset($body->mwiAccount) ? $body->mwiAccount :
                  (isset($body->mwi_account) ? $body->mwi_account : null);
    $sip_bypass_media = isset($body->sipBypassMedia) ? $body->sipBypassMedia :
                       (isset($body->sip_bypass_media) ? $body->sip_bypass_media : null);
    $absolute_codec_string = isset($body->absoluteCodecString) ? $body->absoluteCodecString :
                            (isset($body->absolute_codec_string) ? $body->absolute_codec_string : null);
    $force_ping = isset($body->forcePing) ? $body->forcePing :
                 (isset($body->force_ping) ? $body->force_ping : null);
    $dial_string = isset($body->dialString) ? $body->dialString :
                  (isset($body->dial_string) ? $body->dial_string : null);
    $enabled = isset($body->enabled) ? $body->enabled : 'true';
    $description = isset($body->description) ? $body->description : null;

    // Voicemail settings
    $voicemail_enabled = isset($body->voicemailEnabled) ? $body->voicemailEnabled :
                        (isset($body->voicemail_enabled) ? $body->voicemail_enabled : 'true');
    $voicemail_password = isset($body->voicemailPassword) ? $body->voicemailPassword :
                         (isset($body->voicemail_password) ? $body->voicemail_password : $password);
    $voicemail_mail_to = isset($body->voicemailMailTo) ? $body->voicemailMailTo :
                        (isset($body->voicemail_mail_to) ? $body->voicemail_mail_to : null);
    $voicemail_file = isset($body->voicemailFile) ? $body->voicemailFile :
                     (isset($body->voicemail_file) ? $body->voicemail_file : 'attach');
    $voicemail_local_after_email = isset($body->voicemailLocalAfterEmail) ? $body->voicemailLocalAfterEmail :
                                  (isset($body->voicemail_local_after_email) ? $body->voicemail_local_after_email : 'true');

    // User assignment
    $user_uuid = isset($body->userUuid) ? $body->userUuid :
                (isset($body->user_uuid) ? $body->user_uuid : null);

    // Generate UUIDs
    $extension_uuid = uuid();
    $voicemail_uuid = uuid();

    // Change toll allow delimiter (same as FusionPBX)
    if (!empty($toll_allow)) {
        $toll_allow = str_replace(',', ':', $toll_allow);
    }

    // Build the array for database save (exactly like FusionPBX extension_edit.php)
    $array = array();

    // Extension record
    $array["extensions"][0]["domain_uuid"] = $db_domain_uuid;
    $array["extensions"][0]["extension_uuid"] = $extension_uuid;
    $array["extensions"][0]["extension"] = $extension;
    if (!empty($number_alias)) {
        $array["extensions"][0]["number_alias"] = $number_alias;
    }
    $array["extensions"][0]["password"] = $password;
    $array["extensions"][0]["accountcode"] = $accountcode;
    $array["extensions"][0]["effective_caller_id_name"] = $effective_caller_id_name;
    $array["extensions"][0]["effective_caller_id_number"] = $effective_caller_id_number;
    if (!empty($outbound_caller_id_name)) {
        $array["extensions"][0]["outbound_caller_id_name"] = $outbound_caller_id_name;
    }
    if (!empty($outbound_caller_id_number)) {
        $array["extensions"][0]["outbound_caller_id_number"] = $outbound_caller_id_number;
    }
    if (!empty($emergency_caller_id_name)) {
        $array["extensions"][0]["emergency_caller_id_name"] = $emergency_caller_id_name;
    }
    if (!empty($emergency_caller_id_number)) {
        $array["extensions"][0]["emergency_caller_id_number"] = $emergency_caller_id_number;
    }
    if (!empty($directory_first_name)) {
        $array["extensions"][0]["directory_first_name"] = $directory_first_name;
    }
    if (!empty($directory_last_name)) {
        $array["extensions"][0]["directory_last_name"] = $directory_last_name;
    }
    $array["extensions"][0]["directory_visible"] = $directory_visible;
    $array["extensions"][0]["directory_exten_visible"] = $directory_exten_visible;
    if (!empty($max_registrations)) {
        $array["extensions"][0]["max_registrations"] = $max_registrations;
    }
    $array["extensions"][0]["limit_max"] = $limit_max;
    $array["extensions"][0]["limit_destination"] = $limit_destination;
    $array["extensions"][0]["user_context"] = $user_context;
    if (!empty($toll_allow)) {
        $array["extensions"][0]["toll_allow"] = $toll_allow;
    }
    $array["extensions"][0]["call_timeout"] = $call_timeout;
    if (!empty($call_group)) {
        $array["extensions"][0]["call_group"] = $call_group;
    }
    $array["extensions"][0]["call_screen_enabled"] = $call_screen_enabled;
    if (!empty($user_record)) {
        $array["extensions"][0]["user_record"] = $user_record;
    }
    if (!empty($hold_music)) {
        $array["extensions"][0]["hold_music"] = $hold_music;
    }
    if (!empty($auth_acl)) {
        $array["extensions"][0]["auth_acl"] = $auth_acl;
    }
    if (!empty($cidr)) {
        $array["extensions"][0]["cidr"] = $cidr;
    }
    if (!empty($sip_force_contact)) {
        $array["extensions"][0]["sip_force_contact"] = $sip_force_contact;
    }
    if (!empty($sip_force_expires)) {
        $array["extensions"][0]["sip_force_expires"] = $sip_force_expires;
    }
    if (!empty($mwi_account)) {
        $array["extensions"][0]["mwi_account"] = $mwi_account;
    }
    if (!empty($sip_bypass_media)) {
        $array["extensions"][0]["sip_bypass_media"] = $sip_bypass_media;
    }
    if (!empty($absolute_codec_string)) {
        $array["extensions"][0]["absolute_codec_string"] = $absolute_codec_string;
    }
    if (!empty($force_ping)) {
        $array["extensions"][0]["force_ping"] = $force_ping;
    }
    if (!empty($dial_string)) {
        $array["extensions"][0]["dial_string"] = $dial_string;
    }
    $array["extensions"][0]["enabled"] = $enabled;
    if (!empty($description)) {
        $array["extensions"][0]["description"] = $description;
    }

    // Extension user assignment (same as FusionPBX)
    if (!empty($user_uuid) && is_uuid_ext($user_uuid)) {
        $array["extension_users"][0]["extension_user_uuid"] = uuid();
        $array["extension_users"][0]["domain_uuid"] = $db_domain_uuid;
        $array["extension_users"][0]["user_uuid"] = $user_uuid;
        $array["extension_users"][0]["extension_uuid"] = $extension_uuid;
    }

    // Voicemail record (same as FusionPBX)
    if ($voicemail_enabled === 'true' || $voicemail_enabled === true) {
        $voicemail_id = !empty($number_alias) ? $number_alias : $extension;

        $array["voicemails"][0]["domain_uuid"] = $db_domain_uuid;
        $array["voicemails"][0]["voicemail_uuid"] = $voicemail_uuid;
        $array["voicemails"][0]["voicemail_id"] = $voicemail_id;
        $array["voicemails"][0]["voicemail_password"] = $voicemail_password;
        if (!empty($voicemail_mail_to)) {
            $array["voicemails"][0]["voicemail_mail_to"] = $voicemail_mail_to;
        }
        $array["voicemails"][0]["voicemail_file"] = $voicemail_file;
        $array["voicemails"][0]["voicemail_local_after_email"] = $voicemail_local_after_email;
        $array["voicemails"][0]["voicemail_enabled"] = 'true';
        if (!empty($description)) {
            $array["voicemails"][0]["voicemail_description"] = $description;
        }
    }

    // Build dynamic SQL with only non-null values
    $columns = array(
        "extension_uuid", "domain_uuid", "extension", "password", "accountcode",
        "effective_caller_id_name", "effective_caller_id_number",
        "directory_visible", "directory_exten_visible",
        "limit_max", "limit_destination", "user_context", "call_timeout",
        "call_screen_enabled", "enabled", "insert_date"
    );

    $values = array(
        ":extension_uuid", ":domain_uuid", ":extension", ":password", ":accountcode",
        ":effective_caller_id_name", ":effective_caller_id_number",
        ":directory_visible", ":directory_exten_visible",
        ":limit_max", ":limit_destination", ":user_context", ":call_timeout",
        ":call_screen_enabled", ":enabled", "NOW()"
    );

    $params = array(
        "extension_uuid" => $extension_uuid,
        "domain_uuid" => $db_domain_uuid,
        "extension" => $extension,
        "password" => $password,
        "accountcode" => $accountcode,
        "effective_caller_id_name" => $effective_caller_id_name,
        "effective_caller_id_number" => $effective_caller_id_number,
        "directory_visible" => $directory_visible,
        "directory_exten_visible" => $directory_exten_visible,
        "limit_max" => $limit_max,
        "limit_destination" => $limit_destination,
        "user_context" => $user_context,
        "call_timeout" => $call_timeout,
        "call_screen_enabled" => $call_screen_enabled,
        "enabled" => $enabled
    );

    // Add optional fields only if they have values
    $optional_fields = array(
        "number_alias" => $number_alias,
        "outbound_caller_id_name" => $outbound_caller_id_name,
        "outbound_caller_id_number" => $outbound_caller_id_number,
        "emergency_caller_id_name" => $emergency_caller_id_name,
        "emergency_caller_id_number" => $emergency_caller_id_number,
        "directory_first_name" => $directory_first_name,
        "directory_last_name" => $directory_last_name,
        "max_registrations" => $max_registrations,
        "toll_allow" => $toll_allow,
        "call_group" => $call_group,
        "user_record" => $user_record,
        "hold_music" => $hold_music,
        "auth_acl" => $auth_acl,
        "cidr" => $cidr,
        "sip_force_contact" => $sip_force_contact,
        "sip_force_expires" => $sip_force_expires,
        "mwi_account" => $mwi_account,
        "sip_bypass_media" => $sip_bypass_media,
        "absolute_codec_string" => $absolute_codec_string,
        "force_ping" => $force_ping,
        "dial_string" => $dial_string,
        "description" => $description
    );

    foreach ($optional_fields as $field => $value) {
        if (!empty($value)) {
            $columns[] = $field;
            $values[] = ":" . $field;
            $params[$field] = $value;
        }
    }

    $sql = "INSERT INTO v_extensions (" . implode(", ", $columns) . ") VALUES (" . implode(", ", $values) . ")";

    try {
        $database->execute($sql, $params);
    } catch (Exception $e) {
        return array(
            "success" => false,
            "error" => "Failed to create extension: " . $e->getMessage()
        );
    }

    // Verify the extension was actually created
    $verify_sql = "SELECT extension_uuid FROM v_extensions WHERE extension_uuid = :extension_uuid";
    $verify_result = $database->select($verify_sql, array("extension_uuid" => $extension_uuid), "row");
    if (empty($verify_result)) {
        return array(
            "success" => false,
            "error" => "Extension creation failed - database insert did not succeed"
        );
    }

    // Insert voicemail record if enabled
    if ($voicemail_enabled === 'true' || $voicemail_enabled === true) {
        $voicemail_id = !empty($number_alias) ? $number_alias : $extension;

        // Build dynamic voicemail SQL with only non-null values
        $vm_columns = array("voicemail_uuid", "domain_uuid", "voicemail_id", "voicemail_password",
                           "voicemail_file", "voicemail_local_after_email", "voicemail_enabled", "insert_date");
        $vm_values = array(":voicemail_uuid", ":domain_uuid", ":voicemail_id", ":voicemail_password",
                          ":voicemail_file", ":voicemail_local_after_email", "'true'", "NOW()");

        $params_vm = array(
            "voicemail_uuid" => $voicemail_uuid,
            "domain_uuid" => $db_domain_uuid,
            "voicemail_id" => $voicemail_id,
            "voicemail_password" => $voicemail_password,
            "voicemail_file" => $voicemail_file,
            "voicemail_local_after_email" => $voicemail_local_after_email
        );

        // Add optional voicemail fields
        if (!empty($voicemail_mail_to)) {
            $vm_columns[] = "voicemail_mail_to";
            $vm_values[] = ":voicemail_mail_to";
            $params_vm["voicemail_mail_to"] = $voicemail_mail_to;
        }
        if (!empty($description)) {
            $vm_columns[] = "voicemail_description";
            $vm_values[] = ":voicemail_description";
            $params_vm["voicemail_description"] = $description;
        }

        $sql_vm = "INSERT INTO v_voicemails (" . implode(", ", $vm_columns) . ") VALUES (" . implode(", ", $vm_values) . ")";
        $database->execute($sql_vm, $params_vm);
    }

    // Insert extension_user mapping if user_uuid provided
    if (!empty($user_uuid) && is_uuid_ext($user_uuid)) {
        $sql_user = "INSERT INTO v_extension_users (
                        extension_user_uuid, domain_uuid, extension_uuid, user_uuid, insert_date
                    ) VALUES (
                        :extension_user_uuid, :domain_uuid, :extension_uuid, :user_uuid, NOW()
                    )";

        $params_user = array(
            "extension_user_uuid" => uuid(),
            "domain_uuid" => $db_domain_uuid,
            "extension_uuid" => $extension_uuid,
            "user_uuid" => $user_uuid
        );

        $database->execute($sql_user, $params_user);
    }

    // Clear the cache (same as FusionPBX)
    $cache = new cache;
    $cache->delete("directory:".$extension."@".$db_domain_name);
    if (!empty($number_alias)) {
        $cache->delete("directory:".$number_alias."@".$db_domain_name);
    }

    // Write the XML for the extension (using FusionPBX's method)
    // Set up session variables needed for XML generation
    $_SESSION['domain_uuid'] = $db_domain_uuid;
    $_SESSION['domain_name'] = $db_domain_name;
    $_SESSION['domains'][$db_domain_uuid]['domain_name'] = $db_domain_name;

    // Get switch extensions directory from settings
    $sql = "SELECT default_setting_value FROM v_default_settings
            WHERE default_setting_category = 'switch'
            AND default_setting_subcategory = 'extensions'
            AND default_setting_name = 'dir'
            AND default_setting_enabled = 'true'";
    $extensions_dir = $database->select($sql, null, 'column');

    if (!empty($extensions_dir)) {
        $_SESSION['switch']['extensions']['dir'] = $extensions_dir;
    }

    // Use FusionPBX's extension class to generate XML
    $extension_class_path = '/var/www/fusionpbx/app/extensions/resources/classes/extension.php';
    if (file_exists($extension_class_path)) {
        require_once $extension_class_path;
        $ext = new extension;
        $ext->domain_uuid = $db_domain_uuid;
        $ext->extension_uuid = $extension_uuid;
        $ext->xml();
    }

    // Send Event-Socket command to reload XML (same as FusionPBX)
    $fp = @fsockopen('127.0.0.1', 8021, $errno, $errstr, 5);
    if ($fp) {
        $response = fgets($fp, 1024);
        fputs($fp, "auth ClueCon\n\n");
        usleep(100000);
        $response = '';
        while (!feof($fp)) {
            $response .= fgets($fp, 1024);
            if (strpos($response, "+OK") !== false || strpos($response, "-ERR") !== false) {
                break;
            }
        }

        // Reload XML
        fputs($fp, "api reloadxml\n\n");
        usleep(100000);
        $response = '';
        while (!feof($fp)) {
            $response .= fgets($fp, 1024);
            if (strpos($response, "+OK") !== false || strpos($response, "-ERR") !== false) {
                break;
            }
        }

        // Also clear FreeSWITCH cache for the extension
        fputs($fp, "api sofia profile internal flush_inbound_reg ".$extension."@".$db_domain_name."\n\n");
        usleep(50000);

        fclose($fp);
    }

    return array(
        "success" => true,
        "message" => "Extension created successfully",
        "extension_uuid" => $extension_uuid,
        "extension" => $extension,
        "password" => $password,
        "voicemail_uuid" => ($voicemail_enabled === 'true' || $voicemail_enabled === true) ? $voicemail_uuid : null,
        "voicemail_password" => ($voicemail_enabled === 'true' || $voicemail_enabled === true) ? $voicemail_password : null,
        "domain_uuid" => $db_domain_uuid,
        "domain_name" => $db_domain_name
    );
}

/**
 * Generate a random password for extension
 */
function generate_extension_password($length = 12) {
    // Use our own implementation to avoid FusionPBX function issues
    $chars = 'abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $chars_len = strlen($chars) - 1;
    $password = '';
    for ($i = 0; $i < $length; $i++) {
        $password .= $chars[random_int(0, $chars_len)];
    }
    return $password;
}

/**
 * Check if string is valid UUID (uses FusionPBX's is_uuid if available)
 */
function is_uuid_ext($uuid) {
    if (function_exists('is_uuid')) {
        return is_uuid($uuid);
    }
    if (empty($uuid)) return false;
    return preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/i', $uuid);
}
