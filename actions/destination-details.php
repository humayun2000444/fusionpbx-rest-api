<?php
$required_params = array("destination_uuid");

function do_action($body) {
    // Get destination details
    $sql = "SELECT d.destination_uuid, d.domain_uuid, d.dialplan_uuid, d.fax_uuid,
            d.user_uuid, d.group_uuid, d.destination_type, d.destination_number,
            d.destination_trunk_prefix, d.destination_area_code, d.destination_prefix,
            d.destination_condition_field, d.destination_number_regex,
            d.destination_caller_id_name, d.destination_caller_id_number,
            d.destination_cid_name_prefix, d.destination_context, d.destination_record,
            d.destination_hold_music, d.destination_distinctive_ring, d.destination_ringback,
            d.destination_accountcode, d.destination_type_voice, d.destination_type_fax,
            d.destination_type_emergency, d.destination_type_text,
            d.destination_conditions, d.destination_actions,
            d.destination_app, d.destination_data,
            d.destination_alternate_app, d.destination_alternate_data,
            d.destination_order, d.destination_enabled, d.destination_description,
            d.destination_email, d.insert_date, d.update_date,
            dom.domain_name
            FROM v_destinations d
            LEFT JOIN v_domains dom ON d.domain_uuid = dom.domain_uuid
            WHERE d.destination_uuid = :destination_uuid";

    $parameters = array("destination_uuid" => $body->destination_uuid);
    $database = new database;
    $destination = $database->select($sql, $parameters, "row");

    if (!$destination) {
        return array("error" => "Destination not found");
    }

    // Parse JSON fields
    if (isset($destination['destination_actions']) && $destination['destination_actions']) {
        $destination['destination_actions'] = json_decode($destination['destination_actions'], true);
    }
    if (isset($destination['destination_conditions']) && $destination['destination_conditions']) {
        $destination['destination_conditions'] = json_decode($destination['destination_conditions'], true);
    }

    // Get dialplan details if dialplan exists
    if ($destination['dialplan_uuid']) {
        $sql = "SELECT dialplan_detail_uuid, dialplan_detail_tag, dialplan_detail_type,
                dialplan_detail_data, dialplan_detail_inline, dialplan_detail_group,
                dialplan_detail_order, dialplan_detail_enabled
                FROM v_dialplan_details
                WHERE dialplan_uuid = :dialplan_uuid
                ORDER BY dialplan_detail_group, dialplan_detail_order";
        $parameters = array("dialplan_uuid" => $destination['dialplan_uuid']);
        $details = $database->select($sql, $parameters, "all");
        $destination['dialplan_details'] = $details ? $details : array();

        // Get dialplan XML
        $sql = "SELECT dialplan_xml FROM v_dialplans WHERE dialplan_uuid = :dialplan_uuid";
        $parameters = array("dialplan_uuid" => $destination['dialplan_uuid']);
        $dialplan = $database->select($sql, $parameters, "row");
        $destination['dialplan_xml'] = $dialplan ? $dialplan['dialplan_xml'] : null;
    }

    return $destination;
}
