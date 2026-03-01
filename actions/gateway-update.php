<?php
$required_params = array("gateway_uuid");

function do_action($body) {
    // Check if gateway exists
    $sql = "SELECT gateway_uuid FROM v_gateways WHERE gateway_uuid = :gateway_uuid";
    $parameters["gateway_uuid"] = $body->gateway_uuid;
    $database = new database;
    $existing = $database->select($sql, $parameters, "row");

    if(!$existing) {
        return array("error" => "Gateway not found");
    }
    unset($parameters);

    // Build update query dynamically
    $updates = array();
    $parameters = array();
    $parameters["gateway_uuid"] = $body->gateway_uuid;

    if(isset($body->gateway)) {
        $updates[] = "gateway = :gateway";
        $parameters["gateway"] = $body->gateway;
    }
    if(isset($body->username)) {
        $updates[] = "username = :username";
        $parameters["username"] = $body->username;
    }
    if(isset($body->password)) {
        $updates[] = "password = :password";
        $parameters["password"] = $body->password;
    }
    if(isset($body->distinct_to)) {
        $updates[] = "distinct_to = :distinct_to";
        $parameters["distinct_to"] = $body->distinct_to;
    }
    if(isset($body->auth_username)) {
        $updates[] = "auth_username = :auth_username";
        $parameters["auth_username"] = $body->auth_username;
    }
    if(isset($body->realm)) {
        $updates[] = "realm = :realm";
        $parameters["realm"] = $body->realm;
    }
    if(isset($body->from_user)) {
        $updates[] = "from_user = :from_user";
        $parameters["from_user"] = $body->from_user;
    }
    if(isset($body->from_domain)) {
        $updates[] = "from_domain = :from_domain";
        $parameters["from_domain"] = $body->from_domain;
    }
    if(isset($body->proxy)) {
        $updates[] = "proxy = :proxy";
        $parameters["proxy"] = $body->proxy;
    }
    if(isset($body->register_proxy)) {
        $updates[] = "register_proxy = :register_proxy";
        $parameters["register_proxy"] = $body->register_proxy;
    }
    if(isset($body->outbound_proxy)) {
        $updates[] = "outbound_proxy = :outbound_proxy";
        $parameters["outbound_proxy"] = $body->outbound_proxy;
    }
    if(isset($body->expire_seconds)) {
        $updates[] = "expire_seconds = :expire_seconds";
        $parameters["expire_seconds"] = $body->expire_seconds;
    }
    if(isset($body->register)) {
        $updates[] = "register = :register";
        $parameters["register"] = $body->register;
    }
    if(isset($body->register_transport)) {
        $updates[] = "register_transport = :register_transport";
        $parameters["register_transport"] = $body->register_transport;
    }
    if(isset($body->contact_params)) {
        $updates[] = "contact_params = :contact_params";
        $parameters["contact_params"] = $body->contact_params;
    }
    if(isset($body->retry_seconds)) {
        $updates[] = "retry_seconds = :retry_seconds";
        $parameters["retry_seconds"] = $body->retry_seconds;
    }
    if(isset($body->extension)) {
        $updates[] = "extension = :extension";
        $parameters["extension"] = $body->extension;
    }
    if(isset($body->ping)) {
        $updates[] = "ping = :ping";
        $parameters["ping"] = $body->ping;
    }
    if(isset($body->channels)) {
        $updates[] = "channels = :channels";
        $parameters["channels"] = $body->channels;
    }
    if(isset($body->context)) {
        $updates[] = "context = :context";
        $parameters["context"] = $body->context;
    }
    if(isset($body->profile)) {
        $updates[] = "profile = :profile";
        $parameters["profile"] = $body->profile;
    }
    if(isset($body->hostname)) {
        $updates[] = "hostname = :hostname";
        $parameters["hostname"] = $body->hostname;
    }
    if(isset($body->enabled)) {
        $updates[] = "enabled = :enabled";
        $parameters["enabled"] = $body->enabled;
    }
    if(isset($body->description)) {
        $updates[] = "description = :description";
        $parameters["description"] = $body->description;
    }
    if(isset($body->caller_id_in_from)) {
        $updates[] = "caller_id_in_from = :caller_id_in_from";
        $parameters["caller_id_in_from"] = $body->caller_id_in_from;
    }
    if(isset($body->sip_cid_type)) {
        $updates[] = "sip_cid_type = :sip_cid_type";
        $parameters["sip_cid_type"] = $body->sip_cid_type;
    }
    if(isset($body->codec_prefs)) {
        $updates[] = "codec_prefs = :codec_prefs";
        $parameters["codec_prefs"] = $body->codec_prefs;
    }

    if(empty($updates)) {
        return array("error" => "No fields to update");
    }

    $updates[] = "update_date = NOW()";

    $sql = "UPDATE v_gateways SET " . implode(", ", $updates) . " WHERE gateway_uuid = :gateway_uuid";
    $database = new database;
    $database->execute($sql, $parameters);
    unset($parameters);

    // Get the profile for reload
    $sql = "SELECT profile FROM v_gateways WHERE gateway_uuid = :gateway_uuid";
    $parameters["gateway_uuid"] = $body->gateway_uuid;
    $database = new database;
    $gw = $database->select($sql, $parameters, "row");
    $profile = isset($gw["profile"]) ? $gw["profile"] : "external";
    unset($parameters);

    $reload_output = "";
    $reload_success = false;

    // Use event socket like FusionPBX does
    if (class_exists('event_socket')) {
        $esl = event_socket::create();
        if ($esl) {
            // Get hostname for cache key
            $hostname = trim(event_socket::api('switchname'));

            // Clear the cache (same as FusionPBX)
            if (class_exists('cache')) {
                $cache = new cache;
                $cache->delete("configuration:sofia.conf:" . $hostname);
            }

            // Rescan the profile
            $reload_output = event_socket::api('sofia profile ' . $profile . ' rescan');
            usleep(500000); // Wait 0.5 seconds

            // Reload XML as well
            event_socket::api('reloadxml');

            $reload_success = true;
        }
    }

    // Fallback to fs_cli if event socket failed
    if (!$reload_success) {
        $reload_output = shell_exec("/usr/bin/fs_cli -x 'sofia profile " . $profile . " rescan' 2>&1");
        usleep(500000);
        shell_exec("/usr/bin/fs_cli -x 'reloadxml' 2>&1");
        $reload_success = ($reload_output !== null && strpos($reload_output, 'err') === false);
    }

    // Return updated gateway
    $sql = "SELECT gateway_uuid, domain_uuid, gateway, username, proxy, register_proxy,
            outbound_proxy, expire_seconds, register, register_transport, retry_seconds,
            from_user, from_domain, caller_id_in_from,
            channels, context, profile, hostname, enabled, description, update_date
            FROM v_gateways WHERE gateway_uuid = :gateway_uuid";
    $parameters["gateway_uuid"] = $body->gateway_uuid;
    $database = new database;
    $result = $database->select($sql, $parameters, "row");
    $result["reloaded"] = $reload_success;
    $result["reload_output"] = trim($reload_output);
    return $result;
}
