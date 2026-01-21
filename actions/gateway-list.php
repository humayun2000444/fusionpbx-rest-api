<?php
$required_params = array();

function do_action($body) {
    $sql = "SELECT gateway_uuid, domain_uuid, gateway, username, proxy, register_proxy,
            outbound_proxy, expire_seconds, register, register_transport, retry_seconds,
            from_user, from_domain, caller_id_in_from,
            ping, channels, context, profile, hostname, enabled, description,
            insert_date, update_date
            FROM v_gateways";

    $parameters = array();

    if(isset($body->domain_uuid) && !empty($body->domain_uuid)) {
        $sql .= " WHERE domain_uuid = :domain_uuid";
        $parameters["domain_uuid"] = $body->domain_uuid;
    }

    $sql .= " ORDER BY gateway ASC";

    $database = new database;
    $result = $database->select($sql, $parameters, "all");

    return $result ? $result : array();
}
