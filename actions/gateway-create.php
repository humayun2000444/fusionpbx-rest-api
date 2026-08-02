<?php
$required_params = array("gateway", "proxy");

/**
 * Resolve the domain a gateway belongs to, the way the GUI does.
 *
 * app/gateways/gateway_edit.php forces $_SESSION['domain_uuid'] for anyone
 * without the 'gateway_domain' permission, and only a user who has it may pick
 * a specific domain or "Global" (domain_uuid NULL). REST has no session, so the
 * old `isset($body->domain_uuid) ? ... : $domain_uuid` fallback quietly resolved
 * to NULL whenever the caller omitted the field - producing a global gateway
 * that no tenant can see (list-by-domain filters on domain_uuid) and that the
 * caller believed it had created inside its own domain.
 *
 * Now: a supplied domain_uuid must be a real domain, and a missing one is an
 * error unless the caller explicitly opts into a global gateway with
 * "global": true - the API equivalent of choosing "Global" in the GUI.
 *
 * Returns array("error" => ...) on failure, or array("domain_uuid" => uuid|null).
 */
if (!function_exists('gateway_resolve_domain_uuid')) {
function gateway_resolve_domain_uuid($body, $session_domain_uuid) {
    $database = new database;

    $requested = isset($body->domain_uuid) ? trim((string) $body->domain_uuid) : '';
    if ($requested === '' && !empty($session_domain_uuid)) {
        $requested = trim((string) $session_domain_uuid);
    }

    if ($requested !== '') {
        if (!preg_match('/^[0-9a-fA-F-]{36}$/', $requested)) {
            return array("error" => "invalid domain_uuid");
        }
        $exists = $database->select(
            "SELECT domain_uuid FROM v_domains WHERE domain_uuid = :domain_uuid",
            array("domain_uuid" => $requested), 'column');
        if (!$exists) {
            return array("error" => "domain_uuid does not match any domain");
        }
        return array("domain_uuid" => $requested);
    }

    // No domain anywhere: only proceed if the caller deliberately asked for a
    // global gateway, otherwise fail loudly instead of orphaning the record.
    $global = isset($body->global) ? $body->global : null;
    $wants_global = ($global === true || $global === 1
        || (is_string($global) && in_array(strtolower($global), array('true','1','yes'), true)));
    if ($wants_global) {
        return array("domain_uuid" => null);
    }

    return array("error" => "domain_uuid is required (or pass global=true to create a gateway not bound to a domain)");
}
}

function do_action($body) {
    global $domain_uuid;

    $resolved = gateway_resolve_domain_uuid($body, $domain_uuid);
    if (isset($resolved["error"])) {
        return array("error" => $resolved["error"], "code" => 400);
    }
    $gateway_domain_uuid = $resolved["domain_uuid"];

    // Check if gateway name already exists
    $sql = "SELECT gateway_uuid FROM v_gateways WHERE gateway = :gateway";
    $parameters["gateway"] = $body->gateway;
    $database = new database;
    $existing = $database->select($sql, $parameters, "row");

    if($existing) {
        return array("error" => "Gateway with this name already exists");
    }
    unset($parameters);

    // Generate UUID
    $gateway_uuid = uuid();

    // Build insert query
    $sql = "INSERT INTO v_gateways (gateway_uuid, domain_uuid, gateway, username, password,
            distinct_to, auth_username, realm, from_user, from_domain, proxy, register_proxy,
            outbound_proxy, expire_seconds, register, register_transport, contact_params,
            retry_seconds, extension, ping, ping_min, ping_max, contact_in_ping,
            caller_id_in_from, supress_cng, sip_cid_type, codec_prefs, channels,
            extension_in_contact, context, profile, hostname, enabled, description, insert_date)
            VALUES (:gateway_uuid, :domain_uuid, :gateway, :username, :password,
            :distinct_to, :auth_username, :realm, :from_user, :from_domain, :proxy, :register_proxy,
            :outbound_proxy, :expire_seconds, :register, :register_transport, :contact_params,
            :retry_seconds, :extension, :ping, :ping_min, :ping_max, :contact_in_ping,
            :caller_id_in_from, :supress_cng, :sip_cid_type, :codec_prefs, :channels,
            :extension_in_contact, :context, :profile, :hostname, :enabled, :description, NOW())";

    $parameters["gateway_uuid"] = $gateway_uuid;
    $parameters["domain_uuid"] = $gateway_domain_uuid;
    $parameters["gateway"] = $body->gateway;
    $parameters["username"] = isset($body->username) ? $body->username : null;
    $parameters["password"] = isset($body->password) ? $body->password : null;
    $parameters["distinct_to"] = isset($body->distinct_to) ? $body->distinct_to : null;
    $parameters["auth_username"] = isset($body->auth_username) ? $body->auth_username : null;
    $parameters["realm"] = isset($body->realm) ? $body->realm : null;
    $parameters["from_user"] = isset($body->from_user) ? $body->from_user : null;
    $parameters["from_domain"] = isset($body->from_domain) ? $body->from_domain : null;
    $parameters["proxy"] = $body->proxy;
    $parameters["register_proxy"] = isset($body->register_proxy) ? $body->register_proxy : null;
    $parameters["outbound_proxy"] = isset($body->outbound_proxy) ? $body->outbound_proxy : null;
    $parameters["expire_seconds"] = isset($body->expire_seconds) ? $body->expire_seconds : 800;
    $parameters["register"] = isset($body->register) ? $body->register : "false";
    $parameters["register_transport"] = isset($body->register_transport) ? $body->register_transport : "udp";
    $parameters["contact_params"] = isset($body->contact_params) ? $body->contact_params : null;
    $parameters["retry_seconds"] = isset($body->retry_seconds) ? $body->retry_seconds : 30;
    $parameters["extension"] = isset($body->extension) ? $body->extension : null;
    $parameters["ping"] = isset($body->ping) ? $body->ping : null;
    $parameters["ping_min"] = isset($body->ping_min) ? $body->ping_min : null;
    $parameters["ping_max"] = isset($body->ping_max) ? $body->ping_max : null;
    $parameters["contact_in_ping"] = isset($body->contact_in_ping) ? $body->contact_in_ping : null;
    $parameters["caller_id_in_from"] = isset($body->caller_id_in_from) ? $body->caller_id_in_from : "true";
    $parameters["supress_cng"] = isset($body->supress_cng) ? $body->supress_cng : null;
    $parameters["sip_cid_type"] = isset($body->sip_cid_type) ? $body->sip_cid_type : null;
    $parameters["codec_prefs"] = isset($body->codec_prefs) ? $body->codec_prefs : null;
    $parameters["channels"] = isset($body->channels) ? $body->channels : 0;
    $parameters["extension_in_contact"] = isset($body->extension_in_contact) ? $body->extension_in_contact : null;
    $parameters["context"] = isset($body->context) ? $body->context : "public";
    $parameters["profile"] = isset($body->profile) ? $body->profile : "external";
    $parameters["hostname"] = isset($body->hostname) ? $body->hostname : null;
    $parameters["enabled"] = isset($body->enabled) ? $body->enabled : "true";
    $parameters["description"] = isset($body->description) ? $body->description : null;

    $database = new database;
    $database->execute($sql, $parameters);

    // Get the profile for reload
    $profile = isset($body->profile) ? $body->profile : "external";

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

    unset($parameters);

    // Return created gateway
    $sql = "SELECT gateway_uuid, domain_uuid, gateway, username, proxy, register_proxy,
            outbound_proxy, expire_seconds, register, register_transport, retry_seconds,
            from_user, from_domain, caller_id_in_from,
            channels, context, profile, hostname, enabled, description, insert_date
            FROM v_gateways WHERE gateway_uuid = :gateway_uuid";
    $parameters["gateway_uuid"] = $gateway_uuid;
    $database = new database;
    $result = $database->select($sql, $parameters, "row");
    $result["reloaded"] = $reload_success;
    $result["reload_output"] = trim($reload_output);
    return $result;
}
