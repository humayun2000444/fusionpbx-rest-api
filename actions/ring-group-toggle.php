<?php

$required_params = array("ring_group_uuid");

function do_action($body) {
    global $domain_uuid;

    $ring_group_uuid = $body->ring_group_uuid;

    // Get current ring group details
    $sql = "SELECT rg.*, d.domain_name FROM v_ring_groups rg
            JOIN v_domains d ON rg.domain_uuid = d.domain_uuid
            WHERE rg.ring_group_uuid = :ring_group_uuid";
    $parameters = array("ring_group_uuid" => $ring_group_uuid);

    $database = new database;
    $rg = $database->select($sql, $parameters, "row");

    if (!$rg) {
        return array("error" => "Ring group not found");
    }

    $rg_domain_uuid = $rg["domain_uuid"];
    $domain_name = $rg["domain_name"];
    $dialplan_uuid = $rg["dialplan_uuid"];
    $ring_group_name = $rg["ring_group_name"];
    $ring_group_context = $rg["ring_group_context"];
    $current_enabled = $rg["ring_group_enabled"];

    // Toggle the enabled state
    $new_enabled = ($current_enabled === "true") ? "false" : "true";

    // Update ring group enabled state using direct SQL
    $sql = "UPDATE v_ring_groups SET ring_group_enabled = :ring_group_enabled, update_date = NOW() WHERE ring_group_uuid = :ring_group_uuid";
    $parameters = array(
        "ring_group_uuid" => $ring_group_uuid,
        "ring_group_enabled" => $new_enabled
    );
    $database = new database;
    $database->execute($sql, $parameters);

    // Update dialplan enabled state using direct SQL
    if (!empty($dialplan_uuid)) {
        $sql = "UPDATE v_dialplans SET dialplan_enabled = :dialplan_enabled, update_date = NOW() WHERE dialplan_uuid = :dialplan_uuid";
        $parameters = array(
            "dialplan_uuid" => $dialplan_uuid,
            "dialplan_enabled" => $new_enabled
        );
        $database = new database;
        $database->execute($sql, $parameters);
    }

    // Clear the dialplan cache
    if (class_exists('cache')) {
        $cache = new cache;
        $cache->delete("dialplan:" . $domain_name);
        if (!empty($ring_group_context) && $ring_group_context !== $domain_name) {
            $cache->delete("dialplan:" . $ring_group_context);
        }
    }

    return array(
        "success" => true,
        "message" => "Ring group " . ($new_enabled === "true" ? "enabled" : "disabled") . " successfully",
        "ringGroupUuid" => $ring_group_uuid,
        "ringGroupName" => $ring_group_name,
        "ringGroupEnabled" => $new_enabled === "true"
    );
}
