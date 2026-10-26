<?php

$required_params = array("extension_uuid");

function do_action($body) {
    global $domain_uuid;

    $extension_uuid = $body->extension_uuid;

    // Get current extension details
    $sql = "SELECT extension_uuid, extension, number_alias, domain_uuid
            FROM v_extensions
            WHERE extension_uuid = :extension_uuid";
    $parameters = array("extension_uuid" => $extension_uuid);

    $database = new database;
    $ext = $database->select($sql, $parameters, "row");

    if (!$ext) {
        return array("error" => "Extension not found");
    }

    $ext_domain_uuid = $ext["domain_uuid"];
    $extension = $ext["extension"];
    $number_alias = $ext["number_alias"];

    // Get domain name for cache clearing
    $sql = "SELECT domain_name FROM v_domains WHERE domain_uuid = :domain_uuid";
    $parameters = array("domain_uuid" => $ext_domain_uuid);
    $domain = $database->select($sql, $parameters, "row");
    $domain_name = $domain ? $domain["domain_name"] : "";

    // Build update array
    $array['extensions'][0]['domain_uuid'] = $ext_domain_uuid;
    $array['extensions'][0]['extension_uuid'] = $extension_uuid;

    // Do Not Disturb
    if (isset($body->do_not_disturb)) {
        $array['extensions'][0]['do_not_disturb'] = ($body->do_not_disturb === true || $body->do_not_disturb === "true") ? "true" : "false";
    }

    // Forward All / Busy / No Answer / User Not Registered. A forward is only
    // enabled with a destination. Destinations are cleaned the way the GUI's
    // call_forward_edit.php cleans them: digits and * only.
    foreach (array('forward_all', 'forward_busy', 'forward_no_answer', 'forward_user_not_registered') as $forward) {
        $enabled_key = $forward . '_enabled';
        $destination_key = $forward . '_destination';
        $destination = isset($body->$destination_key) ? preg_replace('#[^\*0-9]#', '', (string)$body->$destination_key) : "";

        if (isset($body->$enabled_key)) {
            $enabled = ($body->$enabled_key === true || $body->$enabled_key === "true");
            if ($enabled && $destination !== "") {
                $array['extensions'][0][$enabled_key] = "true";
                $array['extensions'][0][$destination_key] = $destination;
            } else {
                $array['extensions'][0][$enabled_key] = "false";
            }
        } elseif (isset($body->$destination_key)) {
            $array['extensions'][0][$destination_key] = $destination;
        }
    }

    // save() empties the array it is given; keep what was asked for to check it landed.
    $requested = $array['extensions'][0];

    // save() gates on permission_exists('extension_edit'). There is no logged-in
    // user over REST, so it refused every update with "Forbidden, does not have
    // 'extension_edit'" and this action still reported success. The GUI's
    // call_forward_edit.php grants the same temporary permission around its save.
    $permission = permissions::new();
    $permission->add('extension_edit', 'temp');

    // Execute update using FusionPBX ORM
    $database = new database;
    $database->app_name = 'call_forward';
    $database->app_uuid = '19806921-e8ed-dcff-b325-dd3e5da4959d';
    $database->save($array);
    $save_message = $database->message;
    unset($array);

    $permission->delete('extension_edit', 'temp');

    // Clear the cache
    if (class_exists('cache') && !empty($domain_name)) {
        $cache = new cache;
        $cache->delete("directory:" . $extension . "@" . $domain_name);
        if (!empty($number_alias)) {
            $cache->delete("directory:" . $number_alias . "@" . $domain_name);
        }
    }

    // Get updated extension data
    $sql = "SELECT
                extension_uuid,
                extension,
                do_not_disturb,
                forward_all_enabled,
                forward_all_destination,
                forward_busy_enabled,
                forward_busy_destination,
                forward_no_answer_enabled,
                forward_no_answer_destination,
                forward_user_not_registered_enabled,
                forward_user_not_registered_destination
            FROM v_extensions
            WHERE extension_uuid = :extension_uuid";
    $parameters = array("extension_uuid" => $extension_uuid);
    $database = new database;
    $updated = $database->select($sql, $parameters, "row");

    // Confirm the values landed, so a refused save cannot be reported as a success.
    // save() stores an empty string as NULL, hence the string comparison.
    foreach ($requested as $field => $value) {
        if (array_key_exists($field, $updated) && (string)$updated[$field] !== (string)$value) {
            $reason = isset($save_message['message']) ? $save_message['message'] : 'unknown database error';
            error_log("CALL_FORWARD_UPDATE_ERROR: " . $extension_uuid . ": " . $reason);
            return array("error" => "Call forward settings were not saved: " . $reason);
        }
    }

    return array(
        "success" => true,
        "message" => "Call forward settings updated successfully",
        "extensionUuid" => $updated["extension_uuid"],
        "extension" => $updated["extension"],
        "doNotDisturb" => $updated["do_not_disturb"] === "true",
        "forwardAllEnabled" => $updated["forward_all_enabled"] === "true",
        "forwardAllDestination" => $updated["forward_all_destination"],
        "forwardBusyEnabled" => $updated["forward_busy_enabled"] === "true",
        "forwardBusyDestination" => $updated["forward_busy_destination"],
        "forwardNoAnswerEnabled" => $updated["forward_no_answer_enabled"] === "true",
        "forwardNoAnswerDestination" => $updated["forward_no_answer_destination"],
        "forwardUserNotRegisteredEnabled" => $updated["forward_user_not_registered_enabled"] === "true",
        "forwardUserNotRegisteredDestination" => $updated["forward_user_not_registered_destination"]
    );
}
