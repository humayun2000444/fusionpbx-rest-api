<?php

$required_params = array();

function do_action($body) {
    global $domain_uuid;

    $db_domain_uuid = isset($body->domainUuid) ? $body->domainUuid : (isset($body->domain_uuid) ? $body->domain_uuid : $domain_uuid);

    $database = new database;

    // Get all time condition dialplans for this domain
    $sql = "SELECT
                d.dialplan_uuid,
                d.domain_uuid,
                d.dialplan_name,
                d.dialplan_number,
                d.dialplan_context,
                d.dialplan_enabled,
                d.dialplan_description,
                d.insert_date,
                d.update_date,
                dom.domain_name
            FROM v_dialplans d
            LEFT JOIN v_domains dom ON d.domain_uuid = dom.domain_uuid
            WHERE d.domain_uuid = :domain_uuid
            AND d.app_uuid = '4b821450-926b-175a-af93-a03c441818b1'
            ORDER BY d.dialplan_name ASC";

    $time_conditions = $database->select($sql, array("domain_uuid" => $db_domain_uuid), "all");

    if (!$time_conditions) {
        $time_conditions = array();
    }

    // For each time condition, get a summary of its conditions
    foreach ($time_conditions as &$tc) {
        $sql_details = "SELECT
                            dialplan_detail_tag,
                            dialplan_detail_type,
                            dialplan_detail_data,
                            dialplan_detail_group,
                            dialplan_detail_order
                        FROM v_dialplan_details
                        WHERE dialplan_uuid = :dialplan_uuid
                        ORDER BY dialplan_detail_group, dialplan_detail_order";
        $details = $database->select($sql_details, array("dialplan_uuid" => $tc['dialplan_uuid']), "all");
        $tc['details'] = $details ?: array();

        // Parse conditions into a readable summary
        $tc['conditionSummary'] = parse_condition_summary($details ?: array());
    }

    return array(
        "success" => true,
        "total" => count($time_conditions),
        "timeConditions" => $time_conditions
    );
}

function parse_condition_summary($details) {
    $groups = array();
    foreach ($details as $d) {
        $g = $d['dialplan_detail_group'];
        if (!isset($groups[$g])) $groups[$g] = array();
        $groups[$g][] = $d;
    }

    $summary = array();
    foreach ($groups as $group_id => $items) {
        $entry = array("group" => $group_id, "conditions" => array(), "actions" => array());
        foreach ($items as $item) {
            if ($item['dialplan_detail_tag'] === 'condition' && $item['dialplan_detail_type'] !== 'destination_number') {
                $entry['conditions'][] = array(
                    "type" => $item['dialplan_detail_type'],
                    "value" => $item['dialplan_detail_data']
                );
            }
            if (in_array($item['dialplan_detail_tag'], array('action', 'anti-action'))) {
                $entry['actions'][] = array(
                    "type" => $item['dialplan_detail_type'],
                    "data" => $item['dialplan_detail_data'],
                    "isAntiAction" => $item['dialplan_detail_tag'] === 'anti-action'
                );
            }
        }
        if (!empty($entry['conditions']) || !empty($entry['actions'])) {
            $summary[] = $entry;
        }
    }
    return $summary;
}
