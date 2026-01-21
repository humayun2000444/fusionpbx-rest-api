<?php
$required_params = array("dialplan_uuid");

function do_action($body) {
    // Get the dialplan
    $sql = "SELECT d.dialplan_uuid, d.domain_uuid, d.dialplan_name, d.dialplan_number,
            d.dialplan_context, d.dialplan_continue, d.dialplan_order, d.dialplan_enabled,
            d.dialplan_description, d.dialplan_xml, d.insert_date, d.update_date
            FROM v_dialplans d
            WHERE d.dialplan_uuid = :dialplan_uuid";

    $parameters = array("dialplan_uuid" => $body->dialplan_uuid);
    $database = new database;
    $route = $database->select($sql, $parameters, "row");

    if(!$route) {
        return array("error" => "Outbound route not found");
    }

    // Get all dialplan details
    $detail_sql = "SELECT dialplan_detail_uuid, dialplan_detail_tag, dialplan_detail_type,
                   dialplan_detail_data, dialplan_detail_break, dialplan_detail_inline,
                   dialplan_detail_group, dialplan_detail_order, dialplan_detail_enabled
                   FROM v_dialplan_details
                   WHERE dialplan_uuid = :dialplan_uuid
                   ORDER BY dialplan_detail_group, dialplan_detail_order";

    $database = new database;
    $details = $database->select($detail_sql, $parameters, "all");
    $route["details"] = $details ? $details : array();

    // Extract gateway info from bridge action
    foreach($details as $detail) {
        if($detail["dialplan_detail_tag"] == "action" && $detail["dialplan_detail_type"] == "bridge") {
            $route["bridge_data"] = $detail["dialplan_detail_data"];

            // Extract gateway UUID
            if(preg_match('/sofia\/gateway\/([a-f0-9\-]+)\//i', $detail["dialplan_detail_data"], $matches)) {
                $route["gateway_uuid"] = $matches[1];

                // Get gateway name
                $gw_sql = "SELECT gateway FROM v_gateways WHERE gateway_uuid = :gateway_uuid";
                $gw_params = array("gateway_uuid" => $matches[1]);
                $database = new database;
                $gateway = $database->select($gw_sql, $gw_params, "row");
                $route["gateway_name"] = $gateway ? $gateway["gateway"] : null;
            }
            break;
        }
    }

    // Extract destination pattern
    foreach($details as $detail) {
        if($detail["dialplan_detail_tag"] == "condition" && $detail["dialplan_detail_type"] == "destination_number") {
            $route["destination_pattern"] = $detail["dialplan_detail_data"];
            break;
        }
    }

    return $route;
}
