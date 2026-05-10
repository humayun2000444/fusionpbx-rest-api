<?php

$required_params = array("dialplanUuid");

function do_action($body) {
    global $domain_uuid;

    $dialplan_uuid = isset($body->dialplanUuid) ? $body->dialplanUuid : $body->dialplan_uuid;

    $database = new database;

    // Get the time condition dialplan
    $sql = "SELECT
                d.dialplan_uuid,
                d.domain_uuid,
                d.dialplan_name,
                d.dialplan_number,
                d.dialplan_context,
                d.dialplan_enabled,
                d.dialplan_description,
                d.dialplan_continue,
                d.dialplan_order,
                d.insert_date,
                d.update_date,
                dom.domain_name
            FROM v_dialplans d
            LEFT JOIN v_domains dom ON d.domain_uuid = dom.domain_uuid
            WHERE d.dialplan_uuid = :dialplan_uuid
            AND d.app_uuid = '4b821450-926b-175a-af93-a03c441818b1'";

    $tc = $database->select($sql, array("dialplan_uuid" => $dialplan_uuid), "row");

    if (!$tc) {
        return array("error" => "Time condition not found");
    }

    // Get all dialplan details
    $sql_details = "SELECT
                        dialplan_detail_uuid,
                        dialplan_detail_tag,
                        dialplan_detail_type,
                        dialplan_detail_data,
                        dialplan_detail_group,
                        dialplan_detail_order
                    FROM v_dialplan_details
                    WHERE dialplan_uuid = :dialplan_uuid
                    ORDER BY dialplan_detail_group, dialplan_detail_order";

    $details = $database->select($sql_details, array("dialplan_uuid" => $dialplan_uuid), "all");
    $tc['details'] = $details ?: array();

    // Parse into structured condition groups
    $groups = array();
    foreach (($details ?: array()) as $d) {
        $g = $d['dialplan_detail_group'];
        if (!isset($groups[$g])) $groups[$g] = array("group" => $g, "destinationNumber" => "", "timeConditions" => array(), "actions" => array());

        if ($d['dialplan_detail_tag'] === 'condition') {
            if ($d['dialplan_detail_type'] === 'destination_number') {
                $groups[$g]['destinationNumber'] = $d['dialplan_detail_data'];
            } else {
                $groups[$g]['timeConditions'][] = array(
                    "uuid" => $d['dialplan_detail_uuid'],
                    "type" => $d['dialplan_detail_type'],
                    "value" => $d['dialplan_detail_data']
                );
            }
        }
        if (in_array($d['dialplan_detail_tag'], array('action', 'anti-action'))) {
            $groups[$g]['actions'][] = array(
                "uuid" => $d['dialplan_detail_uuid'],
                "tag" => $d['dialplan_detail_tag'],
                "type" => $d['dialplan_detail_type'],
                "data" => $d['dialplan_detail_data']
            );
        }
    }
    $tc['conditionGroups'] = array_values($groups);

    return array(
        "success" => true,
        "timeCondition" => $tc
    );
}
