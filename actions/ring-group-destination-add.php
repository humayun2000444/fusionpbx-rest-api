<?php

$required_params = array("ring_group_uuid", "destination_number");

function do_action($body) {
    global $domain_uuid;

    $ring_group_uuid = $body->ring_group_uuid;

    // Get ring group details
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
    $ring_group_context = $rg["ring_group_context"];

    // Get destination data
    $destination_number = $body->destination_number;
    $destination_delay = isset($body->destination_delay) ? (int)$body->destination_delay : 0;
    $destination_timeout = isset($body->destination_timeout) ? (int)$body->destination_timeout : 30;
    $destination_enabled = isset($body->destination_enabled) ? ($body->destination_enabled ? "true" : "false") : "true";
    $destination_prompt = null;
    if (isset($body->destination_prompt) && $body->destination_prompt !== '' && $body->destination_prompt !== null) {
        $destination_prompt = (int)$body->destination_prompt;
    }
    $destination_description = isset($body->destination_description) && $body->destination_description !== '' ? $body->destination_description : null;

    // Generate UUID
    $ring_group_destination_uuid = uuid();

    // Insert destination using direct SQL
    $sql = "INSERT INTO v_ring_group_destinations (
            ring_group_destination_uuid, domain_uuid, ring_group_uuid,
            destination_number, destination_delay, destination_timeout,
            destination_enabled, destination_prompt, destination_description, insert_date
        ) VALUES (
            :dest_uuid, :domain_uuid, :ring_group_uuid,
            :destination_number, :destination_delay, :destination_timeout,
            :destination_enabled, :destination_prompt, :destination_description, NOW()
        )";

    $parameters = array();
    $parameters["dest_uuid"] = $ring_group_destination_uuid;
    $parameters["domain_uuid"] = $rg_domain_uuid;
    $parameters["ring_group_uuid"] = $ring_group_uuid;
    $parameters["destination_number"] = $destination_number;
    $parameters["destination_delay"] = $destination_delay;
    $parameters["destination_timeout"] = $destination_timeout;
    $parameters["destination_enabled"] = $destination_enabled;
    $parameters["destination_prompt"] = $destination_prompt;
    $parameters["destination_description"] = $destination_description;

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
        "message" => "Destination added successfully",
        "ringGroupDestinationUuid" => $ring_group_destination_uuid,
        "ringGroupUuid" => $ring_group_uuid,
        "destinationNumber" => $destination_number
    );
}
