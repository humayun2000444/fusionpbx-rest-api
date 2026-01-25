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

    // Delete conference users using direct SQL
    $sql = "DELETE FROM v_conference_users WHERE conference_uuid = :conference_uuid";
    $parameters = array("conference_uuid" => $conference_uuid);
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

    // Delete conference using direct SQL
    $sql = "DELETE FROM v_conferences WHERE conference_uuid = :conference_uuid";
    $parameters = array("conference_uuid" => $conference_uuid);
    $database = new database;
    $database->execute($sql, $parameters);

    // Clear the dialplan cache
    if (class_exists('cache')) {
        $cache = new cache;
        $cache->delete("dialplan:" . $domain_name);
    }

    return array(
        "success" => true,
        "message" => "Conference deleted successfully",
        "conferenceUuid" => $conference_uuid,
        "conferenceName" => $conference_name
    );
}
