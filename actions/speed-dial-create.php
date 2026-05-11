<?php

$required_params = array("speedDialCode", "speedDialNumber");

require_once dirname(__FILE__) . '/speed-dial-dialplan.php';

function do_action($body) {
    global $domain_uuid;

    $db_domain_uuid = isset($body->domainUuid) ? $body->domainUuid : (isset($body->domain_uuid) ? $body->domain_uuid : $domain_uuid);

    $code = isset($body->speedDialCode) ? $body->speedDialCode : $body->speed_dial_code;
    $number = isset($body->speedDialNumber) ? $body->speedDialNumber : $body->speed_dial_number;
    $label = isset($body->speedDialLabel) ? $body->speedDialLabel : (isset($body->speed_dial_label) ? $body->speed_dial_label : '');
    $type = isset($body->speedDialType) ? $body->speedDialType : (isset($body->speed_dial_type) ? $body->speed_dial_type : 'domain');
    $extension_uuid = isset($body->extensionUuid) ? $body->extensionUuid : (isset($body->extension_uuid) ? $body->extension_uuid : null);
    if (empty($extension_uuid)) $extension_uuid = null;
    $enabled = isset($body->enabled) ? $body->enabled : 'true';

    // Validate code format: must start with * and be 2-3 digits
    if (!preg_match('/^\*\d{1,3}$/', $code)) {
        return array("error" => "Speed dial code must be * followed by 1-3 digits (e.g., *01, *12)");
    }

    // Personal speed dial requires extension_uuid
    if ($type === 'personal' && empty($extension_uuid)) {
        return array("error" => "extensionUuid is required for personal speed dials");
    }

    $database = new database;

    // Check duplicate
    $sql_check = "SELECT speed_dial_uuid FROM v_speed_dials
                  WHERE domain_uuid = :domain AND speed_dial_code = :code";
    $params_check = array("domain" => $db_domain_uuid, "code" => $code);
    if ($type === 'personal' && !empty($extension_uuid)) {
        $sql_check .= " AND extension_uuid = :ext";
        $params_check["ext"] = $extension_uuid;
    } else {
        $sql_check .= " AND extension_uuid IS NULL";
    }
    $existing = $database->select($sql_check, $params_check, "row");
    if ($existing) {
        return array("error" => "Speed dial code $code already exists");
    }

    $sd_uuid = uuid();
    $sql = "INSERT INTO v_speed_dials (
        speed_dial_uuid, domain_uuid, extension_uuid, speed_dial_code,
        speed_dial_number, speed_dial_label, speed_dial_type, enabled, insert_date
    ) VALUES (
        :uuid, :domain, :ext, :code, :number, :label, :type, :enabled, NOW()
    )";
    $result = $database->execute($sql, array(
        "uuid" => $sd_uuid, "domain" => $db_domain_uuid,
        "ext" => $extension_uuid, "code" => $code,
        "number" => $number, "label" => $label,
        "type" => $type, "enabled" => $enabled
    ));
    if ($result === false) return array("error" => "Failed to create speed dial");

    // Ensure speed dial dialplan exists for this domain
    $domain_result = $database->select("SELECT domain_name FROM v_domains WHERE domain_uuid = :uuid",
        array("uuid" => $db_domain_uuid), "row");
    if ($domain_result) {
        generate_speed_dial_dialplan($database, $db_domain_uuid, $domain_result['domain_name']);
        require_once "resources/switch.php";
        $esl = event_socket::create();
        if ($esl) event_socket::api("reloadxml");
    }

    return array(
        "success" => true,
        "speedDialUuid" => $sd_uuid,
        "speedDialCode" => $code,
        "speedDialNumber" => $number,
        "message" => "Speed dial $code created"
    );
}
