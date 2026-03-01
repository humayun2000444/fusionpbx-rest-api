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

    // Delete ring group users using direct SQL
    $sql = "DELETE FROM v_ring_group_users WHERE ring_group_uuid = :ring_group_uuid";
    $parameters = array("ring_group_uuid" => $ring_group_uuid);
    $database = new database;
    $database->execute($sql, $parameters);

    // Delete ring group destinations using direct SQL
    $sql = "DELETE FROM v_ring_group_destinations WHERE ring_group_uuid = :ring_group_uuid";
    $parameters = array("ring_group_uuid" => $ring_group_uuid);
    $database = new database;
    $database->execute($sql, $parameters);

    // Delete dialplan details using direct SQL
    if (!empty($dialplan_uuid)) {
        $sql = "DELETE FROM v_dialplan_details WHERE dialplan_uuid = :dialplan_uuid";
        $parameters = array("dialplan_uuid" => $dialplan_uuid);
        $database = new database;
        $database->execute($sql, $parameters);

        // Delete dialplan using direct SQL
        $sql = "DELETE FROM v_dialplans WHERE dialplan_uuid = :dialplan_uuid";
        $parameters = array("dialplan_uuid" => $dialplan_uuid);
        $database = new database;
        $database->execute($sql, $parameters);
    }

    // Delete ring group using direct SQL
    $sql = "DELETE FROM v_ring_groups WHERE ring_group_uuid = :ring_group_uuid";
    $parameters = array("ring_group_uuid" => $ring_group_uuid);
    $database = new database;
    $database->execute($sql, $parameters);

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
        "message" => "Ring group deleted successfully",
        "ringGroupUuid" => $ring_group_uuid,
        "ringGroupName" => $ring_group_name
    );
}
