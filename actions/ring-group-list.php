<?php

$required_params = array();

function do_action($body) {
    global $domain_uuid;

    // Get domain_uuid - use provided or global
    $rg_domain_uuid = isset($body->domain_uuid) ? $body->domain_uuid : $domain_uuid;

    // Build the SQL query
    $sql = "SELECT rg.*, d.domain_name FROM v_ring_groups rg
            JOIN v_domains d ON rg.domain_uuid = d.domain_uuid
            WHERE 1=1 ";

    $parameters = array();

    // Filter by domain if provided
    if (!empty($rg_domain_uuid)) {
        $sql .= "AND rg.domain_uuid = :domain_uuid ";
        $parameters["domain_uuid"] = $rg_domain_uuid;
    }

    // Filter by enabled status if provided
    if (isset($body->ring_group_enabled)) {
        $enabled = ($body->ring_group_enabled === true || $body->ring_group_enabled === "true") ? "true" : "false";
        $sql .= "AND rg.ring_group_enabled = :enabled ";
        $parameters["enabled"] = $enabled;
    }

    // Search by name or extension if provided
    if (!empty($body->search)) {
        $sql .= "AND (LOWER(rg.ring_group_name) LIKE :search OR rg.ring_group_extension LIKE :search) ";
        $parameters["search"] = "%" . strtolower($body->search) . "%";
    }

    $sql .= "ORDER BY rg.ring_group_name ASC";

    $database = new database;
    $ring_groups = $database->select($sql, $parameters, "all");

    if (!$ring_groups) {
        $ring_groups = array();
    }

    // Get destinations for each ring group
    $result = array();
    foreach ($ring_groups as $rg) {
        $dest_sql = "SELECT * FROM v_ring_group_destinations
                     WHERE ring_group_uuid = :ring_group_uuid
                     ORDER BY destination_delay, destination_number ASC";
        $dest_params = array("ring_group_uuid" => $rg["ring_group_uuid"]);
        $database = new database;
        $destinations = $database->select($dest_sql, $dest_params, "all");

        $result[] = array(
            "ringGroupUuid" => $rg["ring_group_uuid"],
            "domainUuid" => $rg["domain_uuid"],
            "domainName" => $rg["domain_name"],
            "dialplanUuid" => $rg["dialplan_uuid"],
            "ringGroupName" => $rg["ring_group_name"],
            "ringGroupExtension" => $rg["ring_group_extension"],
            "ringGroupGreeting" => $rg["ring_group_greeting"],
            "ringGroupContext" => $rg["ring_group_context"],
            "ringGroupStrategy" => $rg["ring_group_strategy"],
            "ringGroupCallTimeout" => $rg["ring_group_call_timeout"],
            "ringGroupForwardDestination" => $rg["ring_group_forward_destination"],
            "ringGroupForwardEnabled" => $rg["ring_group_forward_enabled"] === "true",
            "ringGroupCallerIdName" => $rg["ring_group_caller_id_name"],
            "ringGroupCallerIdNumber" => $rg["ring_group_caller_id_number"],
            "ringGroupCidNamePrefix" => $rg["ring_group_cid_name_prefix"],
            "ringGroupCidNumberPrefix" => $rg["ring_group_cid_number_prefix"],
            "ringGroupTimeoutApp" => $rg["ring_group_timeout_app"],
            "ringGroupTimeoutData" => $rg["ring_group_timeout_data"],
            "ringGroupDistinctiveRing" => $rg["ring_group_distinctive_ring"],
            "ringGroupRingback" => $rg["ring_group_ringback"],
            "ringGroupCallScreenEnabled" => $rg["ring_group_call_screen_enabled"] === "true",
            "ringGroupCallForwardEnabled" => $rg["ring_group_call_forward_enabled"] === "true",
            "ringGroupFollowMeEnabled" => $rg["ring_group_follow_me_enabled"] === "true",
            "ringGroupMissedCallApp" => $rg["ring_group_missed_call_app"],
            "ringGroupMissedCallData" => $rg["ring_group_missed_call_data"],
            "ringGroupEnabled" => $rg["ring_group_enabled"] === "true",
            "ringGroupDescription" => $rg["ring_group_description"],
            "ringGroupForwardTollAllow" => $rg["ring_group_forward_toll_allow"],
            "destinations" => $destinations ? array_map(function($d) {
                return array(
                    "ringGroupDestinationUuid" => $d["ring_group_destination_uuid"],
                    "destinationNumber" => $d["destination_number"],
                    "destinationDelay" => $d["destination_delay"],
                    "destinationTimeout" => $d["destination_timeout"],
                    "destinationEnabled" => $d["destination_enabled"],
                    "destinationPrompt" => $d["destination_prompt"],
                    "destinationDescription" => $d["destination_description"]
                );
            }, $destinations) : array()
        );
    }

    return array(
        "success" => true,
        "ringGroups" => $result,
        "count" => count($result)
    );
}
