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
    $array['extensions'][0]['extension_uuid'] = $extension_uuid;

    // Do Not Disturb
    if (isset($body->do_not_disturb)) {
        $array['extensions'][0]['do_not_disturb'] = ($body->do_not_disturb === true || $body->do_not_disturb === "true") ? "true" : "false";
    }

    // Forward All
    if (isset($body->forward_all_enabled)) {
        $forward_all_enabled = ($body->forward_all_enabled === true || $body->forward_all_enabled === "true");
        $forward_all_destination = isset($body->forward_all_destination) ? $body->forward_all_destination : "";

        if ($forward_all_enabled && !empty($forward_all_destination)) {
            $array['extensions'][0]['forward_all_enabled'] = "true";
            $array['extensions'][0]['forward_all_destination'] = $forward_all_destination;
        } else {
            $array['extensions'][0]['forward_all_enabled'] = "false";
        }
    }
    if (isset($body->forward_all_destination) && !isset($body->forward_all_enabled)) {
        $array['extensions'][0]['forward_all_destination'] = $body->forward_all_destination;
    }

    // Forward Busy
    if (isset($body->forward_busy_enabled)) {
        $forward_busy_enabled = ($body->forward_busy_enabled === true || $body->forward_busy_enabled === "true");
        $forward_busy_destination = isset($body->forward_busy_destination) ? $body->forward_busy_destination : "";

        if ($forward_busy_enabled && !empty($forward_busy_destination)) {
            $array['extensions'][0]['forward_busy_enabled'] = "true";
            $array['extensions'][0]['forward_busy_destination'] = $forward_busy_destination;
        } else {
            $array['extensions'][0]['forward_busy_enabled'] = "false";
        }
    }
    if (isset($body->forward_busy_destination) && !isset($body->forward_busy_enabled)) {
        $array['extensions'][0]['forward_busy_destination'] = $body->forward_busy_destination;
    }

    // Forward No Answer
    if (isset($body->forward_no_answer_enabled)) {
        $forward_no_answer_enabled = ($body->forward_no_answer_enabled === true || $body->forward_no_answer_enabled === "true");
        $forward_no_answer_destination = isset($body->forward_no_answer_destination) ? $body->forward_no_answer_destination : "";

        if ($forward_no_answer_enabled && !empty($forward_no_answer_destination)) {
            $array['extensions'][0]['forward_no_answer_enabled'] = "true";
            $array['extensions'][0]['forward_no_answer_destination'] = $forward_no_answer_destination;
        } else {
            $array['extensions'][0]['forward_no_answer_enabled'] = "false";
        }
    }
    if (isset($body->forward_no_answer_destination) && !isset($body->forward_no_answer_enabled)) {
        $array['extensions'][0]['forward_no_answer_destination'] = $body->forward_no_answer_destination;
    }

    // Forward User Not Registered
    if (isset($body->forward_user_not_registered_enabled)) {
        $forward_user_not_registered_enabled = ($body->forward_user_not_registered_enabled === true || $body->forward_user_not_registered_enabled === "true");
        $forward_user_not_registered_destination = isset($body->forward_user_not_registered_destination) ? $body->forward_user_not_registered_destination : "";

        if ($forward_user_not_registered_enabled && !empty($forward_user_not_registered_destination)) {
            $array['extensions'][0]['forward_user_not_registered_enabled'] = "true";
            $array['extensions'][0]['forward_user_not_registered_destination'] = $forward_user_not_registered_destination;
        } else {
            $array['extensions'][0]['forward_user_not_registered_enabled'] = "false";
        }
    }
    if (isset($body->forward_user_not_registered_destination) && !isset($body->forward_user_not_registered_enabled)) {
        $array['extensions'][0]['forward_user_not_registered_destination'] = $body->forward_user_not_registered_destination;
    }

    // Execute update using FusionPBX ORM
    $database = new database;
    $database->app_name = 'calls';
    $database->app_uuid = '19806921-e8ed-dcff-b325-dd3e5da4959d';
    $database->save($array);
    unset($array);

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
