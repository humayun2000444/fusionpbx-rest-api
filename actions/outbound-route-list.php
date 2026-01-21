<?php
$required_params = array();

function do_action($body) {
    // Outbound routes app_uuid
    $outbound_app_uuid = '8c914ec3-9fc0-8ab5-4cda-6c9288bdc9a3';

    $sql = "SELECT d.dialplan_uuid, d.domain_uuid, d.dialplan_name, d.dialplan_number,
            d.dialplan_context, d.dialplan_order, d.dialplan_enabled, d.dialplan_description,
            d.insert_date, d.update_date
            FROM v_dialplans d
            WHERE d.app_uuid = :app_uuid";

    $parameters = array();
    $parameters["app_uuid"] = $outbound_app_uuid;

    // Filter by domain_uuid if provided
    if(isset($body->domain_uuid) && !empty($body->domain_uuid)) {
        $sql .= " AND d.domain_uuid = :domain_uuid";
        $parameters["domain_uuid"] = $body->domain_uuid;
    }

    // Filter by context if provided
    if(isset($body->context) && !empty($body->context)) {
        $sql .= " AND d.dialplan_context = :context";
        $parameters["context"] = $body->context;
    }

    $sql .= " ORDER BY d.dialplan_order, d.dialplan_name";

    $database = new database;
    $routes = $database->select($sql, $parameters, "all");

    if(empty($routes)) {
        return array();
    }

    // Get gateway info for each route
    foreach($routes as &$route) {
        // Get the bridge action to find the gateway
        $detail_sql = "SELECT dialplan_detail_data FROM v_dialplan_details
                       WHERE dialplan_uuid = :dialplan_uuid
                       AND dialplan_detail_tag = 'action'
                       AND dialplan_detail_type = 'bridge'
                       ORDER BY dialplan_detail_order LIMIT 1";
        $detail_params = array("dialplan_uuid" => $route["dialplan_uuid"]);
        $database = new database;
        $bridge = $database->select($detail_sql, $detail_params, "row");

        if($bridge && !empty($bridge["dialplan_detail_data"])) {
            // Extract gateway UUID from bridge data like "sofia/gateway/UUID/$1"
            if(preg_match('/sofia\/gateway\/([a-f0-9\-]+)\//i', $bridge["dialplan_detail_data"], $matches)) {
                $route["gateway_uuid"] = $matches[1];

                // Get gateway name
                $gw_sql = "SELECT gateway FROM v_gateways WHERE gateway_uuid = :gateway_uuid";
                $gw_params = array("gateway_uuid" => $matches[1]);
                $database = new database;
                $gateway = $database->select($gw_sql, $gw_params, "row");
                $route["gateway_name"] = $gateway ? $gateway["gateway"] : null;
            }
            $route["bridge_data"] = $bridge["dialplan_detail_data"];
        }

        // Get the destination pattern
        $pattern_sql = "SELECT dialplan_detail_data FROM v_dialplan_details
                        WHERE dialplan_uuid = :dialplan_uuid
                        AND dialplan_detail_tag = 'condition'
                        AND dialplan_detail_type = 'destination_number'
                        ORDER BY dialplan_detail_order LIMIT 1";
        $database = new database;
        $pattern = $database->select($pattern_sql, $detail_params, "row");

        if($pattern) {
            $route["destination_pattern"] = $pattern["dialplan_detail_data"];
        }
    }

    return $routes;
}
