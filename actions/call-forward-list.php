<?php

$required_params = array();

function do_action($body) {
    global $domain_uuid;

    // Get domain_uuid - use provided or global
    $cf_domain_uuid = isset($body->domain_uuid) ? $body->domain_uuid : $domain_uuid;

    if (empty($cf_domain_uuid)) {
        return array("error" => "Domain UUID is required");
    }

    // Get extensions with call forward settings
    $sql = "SELECT
                extension_uuid,
                extension,
                number_alias,
                effective_caller_id_name,
                effective_caller_id_number,
                description,
                call_timeout,
                enabled,
                do_not_disturb,
                forward_all_enabled,
                forward_all_destination,
                forward_busy_enabled,
                forward_busy_destination,
                forward_no_answer_enabled,
                forward_no_answer_destination,
                forward_user_not_registered_enabled,
                forward_user_not_registered_destination,
                follow_me_enabled,
                follow_me_uuid
            FROM v_extensions
            WHERE domain_uuid = :domain_uuid
            AND enabled = 'true'
            ORDER BY extension ASC";

    $parameters = array("domain_uuid" => $cf_domain_uuid);

    $database = new database;
    $extensions = $database->select($sql, $parameters, "all");

    if (!$extensions) {
        $extensions = array();
    }

    // Format the response
    $result = array();
    foreach ($extensions as $ext) {
        $result[] = array(
            "extensionUuid" => $ext["extension_uuid"],
            "extension" => $ext["extension"],
            "numberAlias" => $ext["number_alias"],
            "effectiveCallerIdName" => $ext["effective_caller_id_name"],
            "effectiveCallerIdNumber" => $ext["effective_caller_id_number"],
            "description" => $ext["description"],
            "callTimeout" => (int)$ext["call_timeout"],
            "enabled" => $ext["enabled"] === "true",
            "doNotDisturb" => $ext["do_not_disturb"] === "true",
            "forwardAllEnabled" => $ext["forward_all_enabled"] === "true",
            "forwardAllDestination" => $ext["forward_all_destination"],
            "forwardBusyEnabled" => $ext["forward_busy_enabled"] === "true",
            "forwardBusyDestination" => $ext["forward_busy_destination"],
            "forwardNoAnswerEnabled" => $ext["forward_no_answer_enabled"] === "true",
            "forwardNoAnswerDestination" => $ext["forward_no_answer_destination"],
            "forwardUserNotRegisteredEnabled" => $ext["forward_user_not_registered_enabled"] === "true",
            "forwardUserNotRegisteredDestination" => $ext["forward_user_not_registered_destination"],
            "followMeEnabled" => $ext["follow_me_enabled"] === "true",
            "followMeUuid" => $ext["follow_me_uuid"]
        );
    }

    return array(
        "success" => true,
        "data" => $result,
        "count" => count($result)
    );
}
