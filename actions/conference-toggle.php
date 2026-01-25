<?php

$required_params = array("conference_uuid");

function do_action($body) {
    global $domain_uuid;

    $conference_uuid = $body->conference_uuid;

    // Get current conference details
    $sql = "SELECT c.*, d.domain_name FROM v_conferences c
            JOIN v_domains d ON c.domain_uuid = d.domain_uuid
            WHERE c.conference_uuid = :conference_uuid";
    $parameters = array("conference_uuid" => $conference_uuid);

    $database = new database;
    $conf = $database->select($sql, $parameters, "row");

    if (!$conf) {
        return array("error" => "Conference not found");
    }

    $conf_domain_uuid = $conf["domain_uuid"];
    $domain_name = $conf["domain_name"];
    $dialplan_uuid = $conf["dialplan_uuid"];
    $conference_name = $conf["conference_name"];
    $current_enabled = $conf["conference_enabled"];

    // Toggle the enabled state
    $new_enabled = ($current_enabled === "true") ? "false" : "true";

    // Update conference enabled state using direct SQL
    $sql = "UPDATE v_conferences SET conference_enabled = :conference_enabled, update_date = NOW() WHERE conference_uuid = :conference_uuid";
    $parameters = array(
        "conference_uuid" => $conference_uuid,
        "conference_enabled" => $new_enabled
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
    }

    return array(
        "success" => true,
        "message" => "Conference " . ($new_enabled === "true" ? "enabled" : "disabled") . " successfully",
        "conferenceUuid" => $conference_uuid,
        "conferenceName" => $conference_name,
        "conferenceEnabled" => $new_enabled === "true"
    );
}
