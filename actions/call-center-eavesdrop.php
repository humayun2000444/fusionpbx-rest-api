<?php

$required_params = array("uuid", "listenExtension");

function do_action($body) {
    global $domain_uuid;

    // Get parameters (support both camelCase and snake_case)
    $call_uuid = isset($body->uuid) ? $body->uuid : (isset($body->call_uuid) ? $body->call_uuid : null);
    $listen_extension = isset($body->listenExtension) ? $body->listenExtension : (isset($body->listen_extension) ? $body->listen_extension : null);

    if (!$call_uuid) {
        return array("error" => "Call UUID is required");
    }

    if (!$listen_extension) {
        return array("error" => "Listen extension is required");
    }

    $database = new database;

    // Get domain name
    $sql = "SELECT domain_name FROM v_domains WHERE domain_uuid = :domain_uuid";
    $domain = $database->select($sql, array("domain_uuid" => $domain_uuid), "row");

    if (!$domain) {
        return array("error" => "Domain not found");
    }

    $domain_name = $domain['domain_name'];

    // Create event socket connection
    $esl = event_socket::create();

    if (!$esl) {
        return array("error" => "Event socket connection failed");
    }

    // Verify the call exists
    $cmd = "uuid_exists $call_uuid";
    $exists = trim(event_socket::api($cmd));

    if ($exists !== 'true') {
        return array("error" => "Call not found or already ended");
    }

    // Originate a call to the listening extension and bridge to eavesdrop
    // The eavesdrop application allows listening to an active call
    $originate_cmd = "originate {origination_caller_id_name='Eavesdrop',origination_caller_id_number='*"}user/$listen_extension@$domain_name &eavesdrop($call_uuid)";

    $result = event_socket::api($originate_cmd);

    if (strpos($result, '-ERR') !== false) {
        return array(
            "error" => "Failed to initiate eavesdrop",
            "details" => trim($result)
        );
    }

    return array(
        "success" => true,
        "message" => "Eavesdrop initiated. Answer your phone to listen.",
        "callUuid" => $call_uuid,
        "listenExtension" => $listen_extension,
        "eslResult" => trim($result)
    );
}
