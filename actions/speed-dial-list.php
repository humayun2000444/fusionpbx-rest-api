<?php

$required_params = array();

function do_action($body) {
    global $domain_uuid;

    $db_domain_uuid = isset($body->domainUuid) ? $body->domainUuid : (isset($body->domain_uuid) ? $body->domain_uuid : $domain_uuid);
    $extension_uuid = isset($body->extensionUuid) ? $body->extensionUuid : (isset($body->extension_uuid) ? $body->extension_uuid : null);
    $type_filter = isset($body->speedDialType) ? $body->speedDialType : null;

    $database = new database;

    $sql = "SELECT sd.*, d.domain_name,
                   e.extension as ext_number, e.description as ext_description
            FROM v_speed_dials sd
            LEFT JOIN v_domains d ON sd.domain_uuid = d.domain_uuid
            LEFT JOIN v_extensions e ON sd.extension_uuid = e.extension_uuid
            WHERE sd.domain_uuid = :domain";
    $params = array("domain" => $db_domain_uuid);

    if ($type_filter) {
        $sql .= " AND sd.speed_dial_type = :type";
        $params["type"] = $type_filter;
    }
    if ($extension_uuid) {
        // Get both personal (for this ext) and domain-wide
        $sql .= " AND (sd.extension_uuid = :ext OR sd.extension_uuid IS NULL)";
        $params["ext"] = $extension_uuid;
    }

    $sql .= " ORDER BY sd.speed_dial_type ASC, sd.speed_dial_code ASC";

    $speed_dials = $database->select($sql, $params, "all");

    return array(
        "success" => true,
        "total" => is_array($speed_dials) ? count($speed_dials) : 0,
        "speedDials" => is_array($speed_dials) ? $speed_dials : array()
    );
}
