<?php

$required_params = array();

function do_action($body) {
    global $domain_uuid;

    // Get domain_uuid - use provided or global
    $conf_domain_uuid = isset($body->domain_uuid) ? $body->domain_uuid : $domain_uuid;

    if (empty($conf_domain_uuid)) {
        return array("error" => "Domain UUID is required");
    }

    // Get conferences for the domain
    $sql = "SELECT
                conference_uuid,
                dialplan_uuid,
                conference_name,
                conference_extension,
                conference_pin_number,
                conference_profile,
                conference_flags,
                conference_email_address,
                conference_account_code,
                conference_order,
                conference_description,
                conference_enabled
            FROM v_conferences
            WHERE domain_uuid = :domain_uuid
            ORDER BY conference_name ASC";

    $parameters = array("domain_uuid" => $conf_domain_uuid);

    $database = new database;
    $conferences = $database->select($sql, $parameters, "all");

    if (!$conferences) {
        $conferences = array();
    }

    // Format the response
    $result = array();
    foreach ($conferences as $conf) {
        $result[] = array(
            "conferenceUuid" => $conf["conference_uuid"],
            "dialplanUuid" => $conf["dialplan_uuid"],
            "conferenceName" => $conf["conference_name"],
            "conferenceExtension" => $conf["conference_extension"],
            "conferencePinNumber" => $conf["conference_pin_number"],
            "conferenceProfile" => $conf["conference_profile"],
            "conferenceFlags" => $conf["conference_flags"],
            "conferenceEmailAddress" => $conf["conference_email_address"],
            "conferenceAccountCode" => $conf["conference_account_code"],
            "conferenceOrder" => (int)$conf["conference_order"],
            "conferenceDescription" => $conf["conference_description"],
            "conferenceEnabled" => $conf["conference_enabled"] === "true"
        );
    }

    return array(
        "success" => true,
        "data" => $result,
        "count" => count($result)
    );
}
