<?php

$required_params = array("ring_group_destination_uuid");

function do_action($body) {
    global $domain_uuid;

    $ring_group_destination_uuid = $body->ring_group_destination_uuid;

    // Get destination details to find ring group
    $sql = "SELECT d.*, rg.ring_group_name, rg.ring_group_context, dom.domain_name
            FROM v_ring_group_destinations d
            JOIN v_ring_groups rg ON d.ring_group_uuid = rg.ring_group_uuid
            JOIN v_domains dom ON d.domain_uuid = dom.domain_uuid
            WHERE d.ring_group_destination_uuid = :dest_uuid";
    $parameters = array("dest_uuid" => $ring_group_destination_uuid);

    $database = new database;
    $dest = $database->select($sql, $parameters, "row");

    if (!$dest) {
        return array("error" => "Destination not found");
    }

    $domain_name = $dest["domain_name"];
    $ring_group_context = $dest["ring_group_context"];
    $destination_number = $dest["destination_number"];

    // Delete destination using direct SQL
    $sql = "DELETE FROM v_ring_group_destinations WHERE ring_group_destination_uuid = :dest_uuid";
    $parameters = array("dest_uuid" => $ring_group_destination_uuid);
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
        "message" => "Destination deleted successfully",
        "ringGroupDestinationUuid" => $ring_group_destination_uuid,
        "destinationNumber" => $destination_number
    );
}
