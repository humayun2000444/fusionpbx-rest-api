<?php
/**
 * IVR Create - Creates IVR menu matching FusionPBX GUI behavior
 * Generates dialplan_xml and clears cache so changes take effect immediately
 */
$required_params = array("name", "extension", "greet_long");

function do_action($body) {
    global $domain_uuid;

    // Get domain_uuid - use provided or global
    $ivr_domain_uuid = isset($body->domain_uuid) ? $body->domain_uuid : $domain_uuid;

    if (empty($ivr_domain_uuid)) {
        return array("error" => "Domain UUID is required");
    }

    $database = new database;

    // Get domain name for context
    $sql = "SELECT domain_name FROM v_domains WHERE domain_uuid = :domain_uuid";
    $domain = $database->select($sql, array("domain_uuid" => $ivr_domain_uuid), "row");
    if (!$domain) {
        return array("error" => "Domain not found");
    }
    $domain_name = $domain['domain_name'];

    // Check if extension already exists
    $sql = "SELECT ivr_menu_uuid FROM v_ivr_menus
            WHERE ivr_menu_extension = :extension AND domain_uuid = :domain_uuid";
    $parameters = array(
        "extension" => $body->extension,
        "domain_uuid" => $ivr_domain_uuid
    );
    $existing = $database->select($sql, $parameters, "row");

    if ($existing) {
        return array("error" => "IVR menu with extension " . $body->extension . " already exists");
    }

    // Generate UUIDs
    $ivr_menu_uuid = uuid();
    $dialplan_uuid = uuid();

    // Parse language components (format: en/us/callie)
    $ivr_menu_language = isset($body->language) ? $body->language : 'en';
    $ivr_menu_dialect = 'us';
    $ivr_menu_voice = 'callie';
    if (isset($body->language) && strpos($body->language, '/') !== false) {
        $language_array = explode("/", $body->language);
        $ivr_menu_language = $language_array[0] ?? 'en';
        $ivr_menu_dialect = $language_array[1] ?? 'us';
        $ivr_menu_voice = $language_array[2] ?? 'callie';
    }

    // Get IVR settings
    $ivr_menu_name = $body->name;
    $ivr_menu_extension = $body->extension;
    $ivr_menu_greet_long = $body->greet_long;
    $ivr_menu_greet_short = isset($body->greet_short) ? $body->greet_short : '';
    $ivr_menu_invalid_sound = isset($body->invalid_sound) ? $body->invalid_sound : '';
    $ivr_menu_exit_sound = isset($body->exit_sound) ? $body->exit_sound : '';
    $ivr_menu_timeout = isset($body->timeout) ? intval($body->timeout) : 3000;
    $ivr_menu_exit_app = isset($body->exit_app) ? $body->exit_app : 'hangup';
    $ivr_menu_exit_data = isset($body->exit_data) ? $body->exit_data : '';
    $ivr_menu_direct_dial = isset($body->direct_dial) ? $body->direct_dial : 'false';
    $ivr_menu_ringback = isset($body->ringback) ? $body->ringback : '';
    $ivr_menu_cid_prefix = isset($body->cid_prefix) ? $body->cid_prefix : '';
    $ivr_menu_context = $domain_name;
    $ivr_menu_enabled = isset($body->enabled) ? $body->enabled : 'true';
    $ivr_menu_description = isset($body->description) ? $body->description : '';
    $ivr_menu_confirm_attempts = isset($body->confirm_attempts) ? intval($body->confirm_attempts) : 3;
    $ivr_menu_inter_digit_timeout = isset($body->inter_digit_timeout) ? intval($body->inter_digit_timeout) : 2000;
    $ivr_menu_max_failures = isset($body->max_failures) ? intval($body->max_failures) : 3;
    $ivr_menu_max_timeouts = isset($body->max_timeouts) ? intval($body->max_timeouts) : 3;
    $ivr_menu_digit_len = isset($body->digit_len) ? intval($body->digit_len) : 5;

    // Get FusionPBX settings for IVR
    $ivr_answer_setting = get_ivr_setting($database, 'answer', 'false');
    $ivr_answer = ($ivr_answer_setting === 'true' || $ivr_answer_setting === true);
    $default_ringback = get_ivr_setting($database, 'default_ringback', 'local_stream://default');

    // Use default ringback if not specified
    if (empty($ivr_menu_ringback) && !empty($default_ringback)) {
        $ivr_menu_ringback = $default_ringback;
    }

    // Build the dialplan XML exactly like FusionPBX GUI does
    $dialplan_xml = "<extension name=\"" . xml_safe($ivr_menu_name) . "\" continue=\"false\" uuid=\"" . xml_safe($dialplan_uuid) . "\">\n";
    $dialplan_xml .= "	<condition field=\"destination_number\" expression=\"^" . xml_safe($ivr_menu_extension) . "\$\">\n";
    $dialplan_xml .= "		<action application=\"ring_ready\" data=\"\"/>\n";

    // Add answer action if enabled in settings (like FusionPBX GUI)
    if ($ivr_answer) {
        $dialplan_xml .= "		<action application=\"answer\" data=\"\"/>\n";
    }

    $dialplan_xml .= "		<action application=\"sleep\" data=\"1000\"/>\n";
    $dialplan_xml .= "		<action application=\"set\" data=\"hangup_after_bridge=true\"/>\n";

    if (!empty($ivr_menu_ringback)) {
        $dialplan_xml .= "		<action application=\"set\" data=\"ringback=" . $ivr_menu_ringback . "\"/>\n";
    }

    if (!empty($ivr_menu_language)) {
        $dialplan_xml .= "		<action application=\"set\" data=\"sound_prefix=\$\${sounds_dir}/" . xml_safe($ivr_menu_language) . "/" . xml_safe($ivr_menu_dialect) . "/" . xml_safe($ivr_menu_voice) . "\" inline=\"true\"/>\n";
        $dialplan_xml .= "		<action application=\"set\" data=\"default_language=" . xml_safe($ivr_menu_language) . "\" inline=\"true\"/>\n";
        $dialplan_xml .= "		<action application=\"set\" data=\"default_dialect=" . xml_safe($ivr_menu_dialect) . "\" inline=\"true\"/>\n";
        $dialplan_xml .= "		<action application=\"set\" data=\"default_voice=" . xml_safe($ivr_menu_voice) . "\" inline=\"true\"/>\n";
    }

    if (!empty($ivr_menu_ringback)) {
        $dialplan_xml .= "		<action application=\"set\" data=\"transfer_ringback=" . $ivr_menu_ringback . "\"/>\n";
    }

    $dialplan_xml .= "		<action application=\"set\" data=\"ivr_menu_uuid=" . xml_safe($ivr_menu_uuid) . "\"/>\n";

    if (!empty($ivr_menu_cid_prefix)) {
        $dialplan_xml .= "		<action application=\"set\" data=\"caller_id_name=" . xml_safe($ivr_menu_cid_prefix) . "#\${caller_id_name}\"/>\n";
        $dialplan_xml .= "		<action application=\"set\" data=\"effective_caller_id_name=\${caller_id_name}\"/>\n";
    }

    $dialplan_xml .= "		<action application=\"ivr\" data=\"" . xml_safe($ivr_menu_uuid) . "\"/>\n";

    if (!empty($ivr_menu_exit_app)) {
        $dialplan_xml .= "		<action application=\"" . xml_safe($ivr_menu_exit_app) . "\" data=\"" . xml_safe($ivr_menu_exit_data) . "\"/>\n";
    }

    $dialplan_xml .= "	</condition>\n";
    $dialplan_xml .= "</extension>\n";

    // Insert IVR menu with all fields
    $sql = "INSERT INTO v_ivr_menus (
                ivr_menu_uuid,
                domain_uuid,
                dialplan_uuid,
                ivr_menu_name,
                ivr_menu_extension,
                ivr_menu_greet_long,
                ivr_menu_greet_short,
                ivr_menu_invalid_sound,
                ivr_menu_exit_sound,
                ivr_menu_confirm_attempts,
                ivr_menu_timeout,
                ivr_menu_exit_app,
                ivr_menu_exit_data,
                ivr_menu_inter_digit_timeout,
                ivr_menu_max_failures,
                ivr_menu_max_timeouts,
                ivr_menu_digit_len,
                ivr_menu_direct_dial,
                ivr_menu_ringback,
                ivr_menu_cid_prefix,
                ivr_menu_context,
                ivr_menu_language,
                ivr_menu_dialect,
                ivr_menu_voice,
                ivr_menu_description,
                ivr_menu_enabled
            ) VALUES (
                :ivr_menu_uuid,
                :domain_uuid,
                :dialplan_uuid,
                :ivr_menu_name,
                :ivr_menu_extension,
                :ivr_menu_greet_long,
                :ivr_menu_greet_short,
                :ivr_menu_invalid_sound,
                :ivr_menu_exit_sound,
                :ivr_menu_confirm_attempts,
                :ivr_menu_timeout,
                :ivr_menu_exit_app,
                :ivr_menu_exit_data,
                :ivr_menu_inter_digit_timeout,
                :ivr_menu_max_failures,
                :ivr_menu_max_timeouts,
                :ivr_menu_digit_len,
                :ivr_menu_direct_dial,
                :ivr_menu_ringback,
                :ivr_menu_cid_prefix,
                :ivr_menu_context,
                :ivr_menu_language,
                :ivr_menu_dialect,
                :ivr_menu_voice,
                :ivr_menu_description,
                :ivr_menu_enabled
            )";

    $parameters = array(
        "ivr_menu_uuid" => $ivr_menu_uuid,
        "domain_uuid" => $ivr_domain_uuid,
        "dialplan_uuid" => $dialplan_uuid,
        "ivr_menu_name" => $ivr_menu_name,
        "ivr_menu_extension" => $ivr_menu_extension,
        "ivr_menu_greet_long" => $ivr_menu_greet_long,
        "ivr_menu_greet_short" => $ivr_menu_greet_short,
        "ivr_menu_invalid_sound" => $ivr_menu_invalid_sound,
        "ivr_menu_exit_sound" => $ivr_menu_exit_sound,
        "ivr_menu_confirm_attempts" => $ivr_menu_confirm_attempts,
        "ivr_menu_timeout" => $ivr_menu_timeout,
        "ivr_menu_exit_app" => $ivr_menu_exit_app,
        "ivr_menu_exit_data" => $ivr_menu_exit_data,
        "ivr_menu_inter_digit_timeout" => $ivr_menu_inter_digit_timeout,
        "ivr_menu_max_failures" => $ivr_menu_max_failures,
        "ivr_menu_max_timeouts" => $ivr_menu_max_timeouts,
        "ivr_menu_digit_len" => $ivr_menu_digit_len,
        "ivr_menu_direct_dial" => $ivr_menu_direct_dial,
        "ivr_menu_ringback" => $ivr_menu_ringback,
        "ivr_menu_cid_prefix" => $ivr_menu_cid_prefix,
        "ivr_menu_context" => $ivr_menu_context,
        "ivr_menu_language" => $ivr_menu_language,
        "ivr_menu_dialect" => $ivr_menu_dialect,
        "ivr_menu_voice" => $ivr_menu_voice,
        "ivr_menu_description" => $ivr_menu_description,
        "ivr_menu_enabled" => $ivr_menu_enabled
    );

    $database->execute($sql, $parameters);

    // Insert dialplan with XML (like FusionPBX GUI)
    $sql = "INSERT INTO v_dialplans (
                dialplan_uuid,
                domain_uuid,
                app_uuid,
                dialplan_name,
                dialplan_number,
                dialplan_context,
                dialplan_continue,
                dialplan_xml,
                dialplan_order,
                dialplan_enabled,
                dialplan_description
            ) VALUES (
                :dialplan_uuid,
                :domain_uuid,
                :app_uuid,
                :dialplan_name,
                :dialplan_number,
                :dialplan_context,
                'false',
                :dialplan_xml,
                :dialplan_order,
                :dialplan_enabled,
                :dialplan_description
            )";

    $parameters = array(
        "dialplan_uuid" => $dialplan_uuid,
        "domain_uuid" => $ivr_domain_uuid,
        "app_uuid" => 'a5788e9b-58bc-bd1b-df59-fff5d51253ab',
        "dialplan_name" => $ivr_menu_name,
        "dialplan_number" => $ivr_menu_extension,
        "dialplan_context" => $ivr_menu_context,
        "dialplan_xml" => $dialplan_xml,
        "dialplan_order" => 101,
        "dialplan_enabled" => $ivr_menu_enabled,
        "dialplan_description" => $ivr_menu_description
    );

    $database->execute($sql, $parameters);

    // Insert options if provided
    $options_created = 0;
    if (isset($body->options) && is_array($body->options)) {
        foreach ($body->options as $index => $option) {
            $opt = (object) $option;
            if (isset($opt->digits)) {
                $option_uuid = uuid();

                // Handle option action and param EXACTLY like FusionPBX GUI
                $raw_param = isset($opt->param) ? $opt->param : '';

                // If param is just a number (extension), build full transfer command
                if (is_numeric($raw_param)) {
                    $ivr_menu_option_action = 'menu-exec-app';
                    $ivr_menu_option_param = 'transfer ' . $raw_param . ' XML ' . $ivr_menu_context;
                } else {
                    // Parse the combined action:param format like FusionPBX does
                    // Input format: "menu-exec-app:transfer 123 XML context" or "menu-top" etc.
                    $options_array = explode(":", $raw_param);
                    $ivr_menu_option_action = array_shift($options_array);
                    $ivr_menu_option_param = join(':', $options_array);

                    // If action was passed separately, use it
                    if (isset($opt->action) && !empty($opt->action)) {
                        // Check if action contains colon (e.g., "menu-exec-app:transfer")
                        if (strpos($opt->action, ':') !== false) {
                            $action_parts = explode(':', $opt->action, 2);
                            $ivr_menu_option_action = $action_parts[0];
                            // Prepend the second part to param if it's an application name
                            if (!empty($action_parts[1]) && !empty($ivr_menu_option_param)) {
                                $ivr_menu_option_param = $action_parts[1] . ' ' . $ivr_menu_option_param;
                            } elseif (!empty($action_parts[1])) {
                                $ivr_menu_option_param = $action_parts[1];
                            }
                        } else {
                            $ivr_menu_option_action = $opt->action;
                        }
                    }
                }

                $opt_sql = "INSERT INTO v_ivr_menu_options (
                                ivr_menu_option_uuid,
                                ivr_menu_uuid,
                                domain_uuid,
                                ivr_menu_option_digits,
                                ivr_menu_option_action,
                                ivr_menu_option_param,
                                ivr_menu_option_order,
                                ivr_menu_option_description,
                                ivr_menu_option_enabled
                            ) VALUES (
                                :option_uuid,
                                :ivr_menu_uuid,
                                :domain_uuid,
                                :digits,
                                :action,
                                :param,
                                :order_num,
                                :description,
                                :enabled
                            )";

                $opt_params = array(
                    "option_uuid" => $option_uuid,
                    "ivr_menu_uuid" => $ivr_menu_uuid,
                    "domain_uuid" => $ivr_domain_uuid,
                    "digits" => $opt->digits,
                    "action" => $ivr_menu_option_action,
                    "param" => $ivr_menu_option_param,
                    "order_num" => isset($opt->order) ? intval($opt->order) : $index,
                    "description" => isset($opt->description) ? $opt->description : '',
                    "enabled" => isset($opt->enabled) ? $opt->enabled : 'true'
                );

                $database->execute($opt_sql, $opt_params);
                $options_created++;
            }
        }
    }

    // Clear the cache - CRITICAL for changes to take effect immediately
    clear_ivr_cache($ivr_menu_uuid, $ivr_menu_context);

    return array(
        "success" => true,
        "message" => "IVR menu created successfully",
        "ivr_menu_uuid" => $ivr_menu_uuid,
        "dialplan_uuid" => $dialplan_uuid,
        "name" => $ivr_menu_name,
        "extension" => $ivr_menu_extension,
        "options_created" => $options_created,
        "cache_cleared" => true
    );
}

/**
 * Clear IVR and dialplan cache so changes take effect immediately
 */
function clear_ivr_cache($ivr_menu_uuid, $context) {
    // Use FusionPBX cache class
    if (class_exists('cache')) {
        $cache = new cache;
        $cache->delete("dialplan:" . $context);
        $cache->delete("configuration:ivr.conf:" . $ivr_menu_uuid);
    }

    // Also try direct memcache delete via event socket
    if (class_exists('event_socket')) {
        $esl = event_socket::create();
        if ($esl) {
            // Clear dialplan cache
            $command = "memcache delete dialplan." . str_replace(":", ".", $context);
            event_socket::api($command);

            // Clear IVR config cache
            $command = "memcache delete configuration.ivr.conf." . $ivr_menu_uuid;
            event_socket::api($command);

            // Reload XML
            event_socket::api("reloadxml");
        }
    }
}

/**
 * XML-safe string escaping
 */
function xml_safe($string) {
    return htmlspecialchars($string, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

/**
 * Get IVR menu setting from FusionPBX default_settings
 */
function get_ivr_setting($database, $subcategory, $default = null) {
    $sql = "SELECT default_setting_value FROM v_default_settings
            WHERE default_setting_category = 'ivr_menu'
            AND default_setting_subcategory = :subcategory
            AND default_setting_enabled = 'true'";
    $parameters = array("subcategory" => $subcategory);
    $result = $database->select($sql, $parameters, "column");
    return $result !== null ? $result : $default;
}
