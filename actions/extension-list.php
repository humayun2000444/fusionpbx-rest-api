<?php
$required_params = array("domain_uuid");

function do_action($body) {
    $sql = "SELECT extension_uuid, extension, emergency_caller_id_number, description FROM v_extensions WHERE v_extensions.domain_uuid = :domain_uuid";
    $parameters['domain_uuid'] = $body->domain_uuid;
    // optional: one extension by number (a caller that needs its uuid should not have to list the whole domain)
    if (isset($body->extension) && $body->extension !== '') {
        $sql .= " AND v_extensions.extension = :extension";
        $parameters['extension'] = $body->extension;
    }
    $database = new database;
    return $database->select($sql, $parameters, 'all');
}
