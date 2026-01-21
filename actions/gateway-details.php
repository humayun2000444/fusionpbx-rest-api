<?php
$required_params = array("gateway_uuid");

function do_action($body) {
    $sql = "SELECT gateway_uuid, domain_uuid, gateway, username, password, distinct_to,
            auth_username, realm, from_user, from_domain, proxy, register_proxy,
            outbound_proxy, expire_seconds, register, register_transport, contact_params,
            retry_seconds, extension, ping, ping_min, ping_max, contact_in_ping,
            caller_id_in_from, supress_cng, sip_cid_type, codec_prefs, channels,
            extension_in_contact, context, profile, hostname, enabled, description,
            insert_date, update_date
            FROM v_gateways WHERE gateway_uuid = :gateway_uuid";

    $parameters["gateway_uuid"] = $body->gateway_uuid;

    $database = new database;
    $result = $database->select($sql, $parameters, "row");

    if(!$result) {
        return array("error" => "Gateway not found");
    }

    return $result;
}
