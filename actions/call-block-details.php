<?php

$required_params = array("callBlockUuid");

function do_action($body) {
    global $domain_uuid;

    $db_domain_uuid = isset($body->domain_uuid) ? $body->domain_uuid :
                     (isset($body->domainUuid) ? $body->domainUuid : $domain_uuid);

    $call_block_uuid = isset($body->callBlockUuid) ? $body->callBlockUuid :
                      (isset($body->call_block_uuid) ? $body->call_block_uuid : null);

    if (empty($call_block_uuid)) {
        return array(
            "success" => false,
            "error" => "callBlockUuid is required"
        );
    }

    $database = new database;

    // Get call block details with extension info
    $sql = "SELECT
                cb.call_block_uuid,
                cb.domain_uuid,
                cb.call_block_direction,
                cb.extension_uuid,
                cb.call_block_name,
                cb.call_block_country_code,
                cb.call_block_number,
                cb.call_block_count,
                cb.call_block_action,
                cb.call_block_app,
                cb.call_block_data,
                cb.date_added,
                cb.call_block_enabled,
                cb.call_block_description,
                cb.insert_date,
                cb.update_date,
                e.extension,
                e.effective_caller_id_name as extension_name
            FROM v_call_block cb
            LEFT JOIN v_extensions e ON cb.extension_uuid = e.extension_uuid
            WHERE cb.call_block_uuid = :call_block_uuid
            AND cb.domain_uuid = :domain_uuid";

    $parameters = array(
        "call_block_uuid" => $call_block_uuid,
        "domain_uuid" => $db_domain_uuid
    );

    $call_block = $database->select($sql, $parameters, "row");

    if (empty($call_block)) {
        return array(
            "success" => false,
            "error" => "Call block not found or access denied"
        );
    }

    return array(
        "success" => true,
        "call_block" => $call_block
    );
}
