<?php
$required_params = array();

function do_action($body) {
    global $domain_uuid;

    // Get domain_uuid - use provided or global
    $ivr_domain_uuid = isset($body->domain_uuid) ? $body->domain_uuid : $domain_uuid;

    if (empty($ivr_domain_uuid)) {
        return array("error" => "Domain UUID is required");
    }

    // Build query
    $sql = "SELECT
                m.ivr_menu_uuid,
                m.ivr_menu_name,
                m.ivr_menu_extension,
                m.ivr_menu_greet_long,
                m.ivr_menu_greet_short,
                m.ivr_menu_timeout,
                m.ivr_menu_exit_app,
                m.ivr_menu_exit_data,
                m.ivr_menu_direct_dial,
                m.ivr_menu_ringback,
                m.ivr_menu_cid_prefix,
                m.ivr_menu_language,
                m.ivr_menu_description,
                m.ivr_menu_enabled,
                m.domain_uuid,
                d.domain_name
            FROM v_ivr_menus m
            LEFT JOIN v_domains d ON m.domain_uuid = d.domain_uuid
            WHERE m.domain_uuid = :domain_uuid";

    $parameters = array("domain_uuid" => $ivr_domain_uuid);

    // Optional search filter
    if (isset($body->search) && !empty($body->search)) {
        $search = '%' . $body->search . '%';
        $sql .= " AND (m.ivr_menu_name ILIKE :search
                  OR m.ivr_menu_extension ILIKE :search
                  OR m.ivr_menu_description ILIKE :search)";
        $parameters["search"] = $search;
    }

    // Optional enabled filter
    if (isset($body->enabled)) {
        $sql .= " AND m.ivr_menu_enabled = :enabled";
        $parameters["enabled"] = $body->enabled;
    }

    $sql .= " ORDER BY m.ivr_menu_name ASC";

    // Optional pagination
    if (isset($body->limit) && is_numeric($body->limit)) {
        $sql .= " LIMIT :limit";
        $parameters["limit"] = intval($body->limit);

        if (isset($body->offset) && is_numeric($body->offset)) {
            $sql .= " OFFSET :offset";
            $parameters["offset"] = intval($body->offset);
        }
    }

    $database = new database;
    $ivr_menus = $database->select($sql, $parameters, "all");

    if (!$ivr_menus) {
        $ivr_menus = array();
    }

    // Format response and get options for each menu
    $result = array();
    foreach ($ivr_menus as $menu) {
        // Get options for this menu
        $options_sql = "SELECT
                            ivr_menu_option_uuid,
                            ivr_menu_option_digits,
                            ivr_menu_option_action,
                            ivr_menu_option_param,
                            ivr_menu_option_order,
                            ivr_menu_option_description,
                            ivr_menu_option_enabled
                        FROM v_ivr_menu_options
                        WHERE ivr_menu_uuid = :ivr_menu_uuid
                        ORDER BY ivr_menu_option_order ASC, ivr_menu_option_digits ASC";
        $options_params = array("ivr_menu_uuid" => $menu['ivr_menu_uuid']);
        $options = $database->select($options_sql, $options_params, "all");

        $formatted_options = array();
        if ($options) {
            foreach ($options as $opt) {
                $formatted_options[] = array(
                    "option_uuid" => $opt['ivr_menu_option_uuid'],
                    "digits" => $opt['ivr_menu_option_digits'],
                    "action" => $opt['ivr_menu_option_action'],
                    "param" => $opt['ivr_menu_option_param'],
                    "order" => intval($opt['ivr_menu_option_order']),
                    "description" => $opt['ivr_menu_option_description'],
                    "enabled" => $opt['ivr_menu_option_enabled']
                );
            }
        }

        $result[] = array(
            "ivr_menu_uuid" => $menu['ivr_menu_uuid'],
            "name" => $menu['ivr_menu_name'],
            "extension" => $menu['ivr_menu_extension'],
            "greet_long" => $menu['ivr_menu_greet_long'],
            "greet_short" => $menu['ivr_menu_greet_short'],
            "timeout" => intval($menu['ivr_menu_timeout']),
            "exit_app" => $menu['ivr_menu_exit_app'],
            "exit_data" => $menu['ivr_menu_exit_data'],
            "direct_dial" => $menu['ivr_menu_direct_dial'],
            "ringback" => $menu['ivr_menu_ringback'],
            "caller_id_prefix" => $menu['ivr_menu_cid_prefix'],
            "language" => $menu['ivr_menu_language'],
            "description" => $menu['ivr_menu_description'],
            "enabled" => $menu['ivr_menu_enabled'],
            "domain_uuid" => $menu['domain_uuid'],
            "domain_name" => $menu['domain_name'],
            "options" => $formatted_options
        );
    }

    return array(
        "count" => count($result),
        "ivr_menus" => $result
    );
}
