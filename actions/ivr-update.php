<?php

/**
 * Normalize an IVR option param to exactly what the FusionPBX GUI stores.
 *
 * The GUI (app/ivr_menus/ivr_menu_edit.php) always persists a transfer as
 * "transfer <destination> XML <context>". A bare "transfer 100" carries no
 * dialplan or context, so FreeSWITCH cannot resolve it and the IVR option
 * silently fails -- which is why an option set from the GUI worked while the
 * same option set over REST did not.
 *
 * Accepts every shape a client may send and returns the GUI-equivalent:
 *   "100"                      -> "transfer 100 XML <context>"
 *   "transfer 100"             -> "transfer 100 XML <context>"
 *   "transfer 100 XML"         -> "transfer 100 XML <context>"
 *   "transfer 100 XML ctx"     -> unchanged (already complete)
 * Non-transfer params (hangup, playback, lua, set, sleep, answer, ...) are
 * returned untouched.
 */
if (!function_exists('ivr_normalize_option_param')) {
function ivr_normalize_option_param($param, $context) {
    $param = trim((string) $param);
    if ($param === '' || $context === '') {
        return $param;
    }

    // A bare destination ("100") is shorthand for a transfer.
    if (is_numeric($param)) {
        return 'transfer ' . $param . ' XML ' . $context;
    }

    // Only transfers take the dialplan/context suffix.
    if (stripos($param, 'transfer ') !== 0) {
        return $param;
    }

    $rest = trim(substr($param, strlen('transfer ')));
    if ($rest === '') {
        return $param;
    }
    $parts = preg_split('/\s+/', $rest);

    // "transfer <dest>" -> add the dialplan + context the GUI would add.
    if (count($parts) === 1) {
        return 'transfer ' . $parts[0] . ' XML ' . $context;
    }
    // "transfer <dest> XML" -> context was dropped; restore it.
    if (count($parts) === 2 && strcasecmp($parts[1], 'XML') === 0) {
        return 'transfer ' . $parts[0] . ' XML ' . $context;
    }

    // Already complete (e.g. "transfer 100 XML ctx").
    return $param;
}
}
/**
 * IVR Update - Updates IVR menu matching FusionPBX GUI behavior
 * Regenerates dialplan_xml and clears cache so changes take effect immediately
 */
$required_params = array("ivr_menu_uuid");

function do_action($body) {
    $database = new database;

    // Check if IVR menu exists and get current data
    $sql = "SELECT m.*, d.domain_name
            FROM v_ivr_menus m
            JOIN v_domains d ON m.domain_uuid = d.domain_uuid
            WHERE m.ivr_menu_uuid = :ivr_menu_uuid";
    $existing = $database->select($sql, array("ivr_menu_uuid" => $body->ivr_menu_uuid), "row");

    if (!$existing) {
        return array("error" => "IVR menu not found");
    }

    $ivr_menu_uuid = $body->ivr_menu_uuid;
    $domain_uuid = $existing['domain_uuid'];
    $domain_name = $existing['domain_name'];
    $dialplan_uuid = $existing['dialplan_uuid'];

    // Merge existing values with new values
    $ivr_menu_name = isset($body->name) ? $body->name : $existing['ivr_menu_name'];
    $ivr_menu_extension = isset($body->extension) ? $body->extension : $existing['ivr_menu_extension'];
    $ivr_menu_greet_long = isset($body->greet_long) ? $body->greet_long : $existing['ivr_menu_greet_long'];
    $ivr_menu_greet_short = isset($body->greet_short) ? $body->greet_short : $existing['ivr_menu_greet_short'];
    $ivr_menu_invalid_sound = isset($body->invalid_sound) ? $body->invalid_sound : $existing['ivr_menu_invalid_sound'];
    $ivr_menu_exit_sound = isset($body->exit_sound) ? $body->exit_sound : $existing['ivr_menu_exit_sound'];
    $ivr_menu_timeout = isset($body->timeout) ? intval($body->timeout) : intval($existing['ivr_menu_timeout']);
    $ivr_menu_exit_app = isset($body->exit_app) ? $body->exit_app : $existing['ivr_menu_exit_app'];
    $ivr_menu_exit_data = isset($body->exit_data) ? $body->exit_data : $existing['ivr_menu_exit_data'];
    $ivr_menu_direct_dial = isset($body->direct_dial) ? $body->direct_dial : $existing['ivr_menu_direct_dial'];
    $ivr_menu_ringback = isset($body->ringback) ? $body->ringback : $existing['ivr_menu_ringback'];
    $ivr_menu_ringback = ivr_resolve_ringback($database, $ivr_menu_ringback, $domain_uuid);
    $ivr_menu_cid_prefix = isset($body->cid_prefix) ? $body->cid_prefix : $existing['ivr_menu_cid_prefix'];
    $ivr_menu_context = $domain_name;
    $ivr_menu_enabled = isset($body->enabled) ? $body->enabled : $existing['ivr_menu_enabled'];
    $ivr_menu_description = isset($body->description) ? $body->description : $existing['ivr_menu_description'];
    $ivr_menu_confirm_attempts = isset($body->confirm_attempts) ? intval($body->confirm_attempts) : intval($existing['ivr_menu_confirm_attempts']);
    $ivr_menu_inter_digit_timeout = isset($body->inter_digit_timeout) ? intval($body->inter_digit_timeout) : intval($existing['ivr_menu_inter_digit_timeout']);
    $ivr_menu_max_failures = isset($body->max_failures) ? intval($body->max_failures) : intval($existing['ivr_menu_max_failures']);
    $ivr_menu_max_timeouts = isset($body->max_timeouts) ? intval($body->max_timeouts) : intval($existing['ivr_menu_max_timeouts']);
    $ivr_menu_digit_len = isset($body->digit_len) ? intval($body->digit_len) : intval($existing['ivr_menu_digit_len']);

    // Parse language components
    $ivr_menu_language = $existing['ivr_menu_language'];
    $ivr_menu_dialect = $existing['ivr_menu_dialect'];
    $ivr_menu_voice = $existing['ivr_menu_voice'];
    if (isset($body->language)) {
        if (strpos($body->language, '/') !== false) {
            $language_array = explode("/", $body->language);
            $ivr_menu_language = $language_array[0] ?? 'en';
            $ivr_menu_dialect = $language_array[1] ?? 'us';
            $ivr_menu_voice = $language_array[2] ?? 'callie';
        } else {
            $ivr_menu_language = $body->language;
        }
    }

    // Generate new dialplan UUID if it doesn't exist
    if (empty($dialplan_uuid)) {
        $dialplan_uuid = uuid();
    }

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

    // Update IVR menu
    $sql = "UPDATE v_ivr_menus SET
                dialplan_uuid = :dialplan_uuid,
                ivr_menu_name = :ivr_menu_name,
                ivr_menu_extension = :ivr_menu_extension,
                ivr_menu_greet_long = :ivr_menu_greet_long,
                ivr_menu_greet_short = :ivr_menu_greet_short,
                ivr_menu_invalid_sound = :ivr_menu_invalid_sound,
                ivr_menu_exit_sound = :ivr_menu_exit_sound,
                ivr_menu_confirm_attempts = :ivr_menu_confirm_attempts,
                ivr_menu_timeout = :ivr_menu_timeout,
                ivr_menu_exit_app = :ivr_menu_exit_app,
                ivr_menu_exit_data = :ivr_menu_exit_data,
                ivr_menu_inter_digit_timeout = :ivr_menu_inter_digit_timeout,
                ivr_menu_max_failures = :ivr_menu_max_failures,
                ivr_menu_max_timeouts = :ivr_menu_max_timeouts,
                ivr_menu_digit_len = :ivr_menu_digit_len,
                ivr_menu_direct_dial = :ivr_menu_direct_dial,
                ivr_menu_ringback = :ivr_menu_ringback,
                ivr_menu_cid_prefix = :ivr_menu_cid_prefix,
                ivr_menu_context = :ivr_menu_context,
                ivr_menu_language = :ivr_menu_language,
                ivr_menu_dialect = :ivr_menu_dialect,
                ivr_menu_voice = :ivr_menu_voice,
                ivr_menu_description = :ivr_menu_description,
                ivr_menu_enabled = :ivr_menu_enabled
            WHERE ivr_menu_uuid = :ivr_menu_uuid";

    $parameters = array(
        "ivr_menu_uuid" => $ivr_menu_uuid,
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

    // Check if dialplan exists
    $sql = "SELECT dialplan_uuid FROM v_dialplans WHERE dialplan_uuid = :dialplan_uuid";
    $dialplan_exists = $database->select($sql, array("dialplan_uuid" => $dialplan_uuid), "row");

    if ($dialplan_exists) {
        // Update existing dialplan with new XML
        $sql = "UPDATE v_dialplans SET
                    dialplan_name = :dialplan_name,
                    dialplan_number = :dialplan_number,
                    dialplan_context = :dialplan_context,
                    dialplan_xml = :dialplan_xml,
                    dialplan_enabled = :dialplan_enabled,
                    dialplan_description = :dialplan_description
                WHERE dialplan_uuid = :dialplan_uuid";

        $parameters = array(
            "dialplan_uuid" => $dialplan_uuid,
            "dialplan_name" => $ivr_menu_name,
            "dialplan_number" => $ivr_menu_extension,
            "dialplan_context" => $ivr_menu_context,
            "dialplan_xml" => $dialplan_xml,
            "dialplan_enabled" => $ivr_menu_enabled,
            "dialplan_description" => $ivr_menu_description
        );

        $database->execute($sql, $parameters);
    } else {
        // Insert new dialplan with XML
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
            "domain_uuid" => $domain_uuid,
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
    }

    // Update options if provided
    $options_updated = 0;
    if (isset($body->options) && is_array($body->options)) {
        // Delete existing options
        $sql = "DELETE FROM v_ivr_menu_options WHERE ivr_menu_uuid = :ivr_menu_uuid";
        $database->execute($sql, array("ivr_menu_uuid" => $ivr_menu_uuid));

        // Insert new options
        foreach ($body->options as $index => $option) {
            $opt = (object) $option;
            if (isset($opt->digits)) {
                $option_uuid = uuid();

                $raw_param = isset($opt->param) ? $opt->param : '';

                if (isset($opt->action) && !empty($opt->action)) {
                    $ivr_menu_option_action = $opt->action;
                    // Store exactly what the GUI would store (adds the
                    // "XML <context>" a client may have omitted).
                    $ivr_menu_option_param = ivr_normalize_option_param($raw_param, $ivr_menu_context);
                } elseif (is_numeric($raw_param)) {
                    $ivr_menu_option_action = 'menu-exec-app';
                    $ivr_menu_option_param = ivr_normalize_option_param($raw_param, $ivr_menu_context);
                } else {
                    // Parse combined action:param format (e.g., "menu-exec-app:transfer 1001 XML ctx")
                    $options_array = explode(":", $raw_param, 2);
                    $ivr_menu_option_action = $options_array[0];
                    $ivr_menu_option_param = ivr_normalize_option_param(
                        isset($options_array[1]) ? $options_array[1] : '', $ivr_menu_context);
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
                    "domain_uuid" => $domain_uuid,
                    "digits" => $opt->digits,
                    "action" => $ivr_menu_option_action,
                    "param" => $ivr_menu_option_param,
                    "order_num" => isset($opt->order) ? intval($opt->order) : $index,
                    "description" => isset($opt->description) ? $opt->description : '',
                    "enabled" => isset($opt->enabled) ? $opt->enabled : 'true'
                );

                $database->execute($opt_sql, $opt_params);
                $options_updated++;
            }
        }
    }

    // A "menu-sub" option only works if the target menu points back at this one
    // (see ivr_sync_submenu_parents) -- the GUI's Parent Menu field.
    $ivr_linked_submenus = array();
    if (isset($body->options) && is_array($body->options)) {
        $ivr_linked_submenus = ivr_sync_submenu_parents(
            $database, $ivr_menu_uuid, $ivr_domain_uuid, $body->options);
    }

    // Clear the cache - CRITICAL for changes to take effect immediately
    clear_ivr_cache($ivr_menu_uuid, $ivr_menu_context);
    foreach ($ivr_linked_submenus as $ivr_child_uuid) {
        clear_ivr_cache($ivr_child_uuid, $ivr_menu_context);
    }

    return array(
        "success" => true,
        "message" => "IVR menu updated successfully",
        "ivr_menu_uuid" => $ivr_menu_uuid,
        "dialplan_uuid" => $dialplan_uuid,
        "options_updated" => $options_updated,
        "cache_cleared" => true
    );
}

/**
 * Link the menus a "menu-sub" option points at to THIS menu as their parent.
 *
 * FusionPBX serves a nested IVR by walking ivr_menu_parent_uuid with a
 * recursive query (xml_handler .../configuration/ivr.conf.lua): only menus
 * whose parent chain reaches the one being dialled are emitted into the XML,
 * each named by its uuid. So a "menu-sub" option resolves ONLY if the TARGET
 * menu records this menu as its parent. The GUI has a "Parent Menu" field for
 * that; the REST path never wrote it, so a REST-built nested IVR failed at
 * call time with "Invalid Menu!" and the trailing hangup() in the IVR dialplan
 * dropped the call. Deriving the link from the options keeps the API
 * self-normalizing, so no client has to know about the parent field.
 *
 * Returns the child uuids that were linked (used to clear their cached XML).
 */
function ivr_sync_submenu_parents($database, $parent_uuid, $domain_uuid, $options) {
    $targets = array();
    foreach ((array) $options as $option) {
        $opt = (object) $option;
        $action = isset($opt->action) ? trim((string) $opt->action) : '';
        $param  = isset($opt->param)  ? trim((string) $opt->param)  : '';
        if ($action !== 'menu-sub') {
            continue;
        }
        // The param must be a menu uuid; anything else is not a sub-menu link.
        if (!preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', $param)) {
            continue;
        }
        if (strcasecmp($param, $parent_uuid) === 0) {
            continue;   // a menu cannot be its own parent
        }
        $targets[strtolower($param)] = true;
    }
    if (empty($targets)) {
        return array();
    }

    // Collect this menu's ancestors. Parenting an ancestor underneath us would
    // close a loop, and the recursive query above has no cycle guard.
    $ancestors = array();
    $walk = $parent_uuid;
    for ($i = 0; $i < 25 && !empty($walk); $i++) {
        $row = $database->select(
            "SELECT ivr_menu_parent_uuid FROM v_ivr_menus WHERE ivr_menu_uuid = :u",
            array("u" => $walk), "row");
        $walk = !empty($row['ivr_menu_parent_uuid']) ? $row['ivr_menu_parent_uuid'] : null;
        if (!empty($walk)) {
            $ancestors[strtolower($walk)] = true;
        }
    }

    $linked = array();
    foreach (array_keys($targets) as $child_uuid) {
        if (isset($ancestors[$child_uuid])) {
            continue;   // would create a cycle
        }
        $database->execute(
            "UPDATE v_ivr_menus SET ivr_menu_parent_uuid = :parent_uuid "
            ."WHERE ivr_menu_uuid = :child_uuid AND domain_uuid = :domain_uuid "
            ."AND ivr_menu_parent_uuid IS DISTINCT FROM :parent_uuid",
            array("parent_uuid" => $parent_uuid, "child_uuid" => $child_uuid,
                  "domain_uuid" => $domain_uuid));
        $linked[] = $child_uuid;
    }
    return $linked;
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

/**
 * Resolve a ringback value the portal can actually produce.
 *
 * The switch needs an absolute path, but a browser cannot build one: the
 * recordings directory is a server-side setting. The portal therefore sends
 * "recording:<uuid>", resolved here against THIS domain's recordings - the same
 * contract extension-update.php uses for hold music, and the same directory the
 * FusionPBX GUI reads, so both interfaces produce identical values.
 *
 * A bare filename is accepted too, since that is what the greeting fields store
 * and it is an easy thing to send by mistake.
 *
 * Anything else - local_stream://, ${us-ring}, an absolute path - is returned
 * untouched.
 */
function ivr_resolve_ringback($database, $value, $domain_uuid) {
    $value = is_string($value) ? trim($value) : '';
    if ($value === '') { return $value; }
    if (strpos($value, '://') !== false || strpos($value, '$') === 0) { return $value; }

    $filename = null;
    if (preg_match('/^recording:([0-9a-fA-F-]{36})$/', $value, $m)) {
        $rec = $database->select(
            "SELECT recording_filename FROM v_recordings "
            ."WHERE recording_uuid = :recording_uuid AND domain_uuid = :domain_uuid",
            array("recording_uuid" => $m[1], "domain_uuid" => $domain_uuid), "row"
        );
        if (empty($rec['recording_filename'])) { return ''; }
        $filename = $rec['recording_filename'];
    }
    elseif (strpos($value, '/') === false) {
        $filename = $value;
    }
    else {
        return $value;
    }

    $dir_row = $database->select(
        "SELECT default_setting_value FROM v_default_settings "
        ."WHERE default_setting_category = 'switch' "
        ."AND default_setting_subcategory = 'recordings' "
        ."AND default_setting_enabled = 'true' LIMIT 1",
        array(), "row"
    );
    $rec_dir = !empty($dir_row['default_setting_value'])
        ? rtrim($dir_row['default_setting_value'], '/')
        : '/var/lib/freeswitch/recordings';

    $dom = $database->select(
        "SELECT domain_name FROM v_domains WHERE domain_uuid = :domain_uuid",
        array("domain_uuid" => $domain_uuid), "row"
    );
    if (empty($dom['domain_name'])) { return ''; }

    return $rec_dir.'/'.$dom['domain_name'].'/'.basename($filename);
}
