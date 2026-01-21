<?php
$required_params = array();

function do_action($body) {
    global $domain_uuid;

    // Get domain_uuid - use provided or global
    $filter_domain_uuid = isset($body->domain_uuid) ? $body->domain_uuid : null;

    // Build query
    $sql = "SELECT d.destination_uuid, d.domain_uuid, d.dialplan_uuid, d.destination_type,
            d.destination_number, d.destination_context, d.destination_caller_id_name,
            d.destination_caller_id_number, d.destination_app, d.destination_data,
            d.destination_enabled, d.destination_description, d.destination_record,
            d.destination_accountcode, d.destination_actions,
            d.insert_date, d.update_date,
            dom.domain_name
            FROM v_destinations d
            LEFT JOIN v_domains dom ON d.domain_uuid = dom.domain_uuid
            WHERE 1=1";

    $parameters = array();

    if ($filter_domain_uuid) {
        $sql .= " AND d.domain_uuid = :domain_uuid";
        $parameters["domain_uuid"] = $filter_domain_uuid;
    }

    // Filter by destination type if provided
    if (isset($body->destination_type) && $body->destination_type) {
        $sql .= " AND d.destination_type = :destination_type";
        $parameters["destination_type"] = $body->destination_type;
    }

    // Filter by enabled status if provided
    if (isset($body->destination_enabled)) {
        $sql .= " AND d.destination_enabled = :destination_enabled";
        $parameters["destination_enabled"] = $body->destination_enabled;
    }

    $sql .= " ORDER BY d.destination_number";

    $database = new database;
    $destinations = $database->select($sql, $parameters, "all");

    if (!$destinations) {
        return array();
    }

    // Parse JSON actions for each destination
    foreach ($destinations as &$dest) {
        if (isset($dest['destination_actions']) && $dest['destination_actions']) {
            $dest['destination_actions'] = json_decode($dest['destination_actions'], true);
        }
    }

    return $destinations;
}
