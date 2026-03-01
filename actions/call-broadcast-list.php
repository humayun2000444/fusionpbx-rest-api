<?php

$required_params = array();

function do_action($body) {
    global $domain_uuid;

    $db_domain_uuid = isset($body->domain_uuid) ? $body->domain_uuid : $domain_uuid;
    $limit = isset($body->limit) ? intval($body->limit) : 100;
    $offset = isset($body->offset) ? intval($body->offset) : 0;

    $database = new database;

    // Get broadcasts
    $sql = "SELECT
                call_broadcast_uuid,
                domain_uuid,
                broadcast_name,
                broadcast_description,
                broadcast_start_time,
                broadcast_timeout,
                broadcast_concurrent_limit,
                broadcast_caller_id_name,
                broadcast_caller_id_number,
                broadcast_destination_type,
                broadcast_destination_data,
                broadcast_avmd,
                broadcast_accountcode,
                insert_date,
                update_date,
                LENGTH(broadcast_phone_numbers) - LENGTH(REPLACE(broadcast_phone_numbers, E'\\n', '')) + 1 as phone_count
            FROM v_call_broadcasts
            WHERE domain_uuid = :domain_uuid
            ORDER BY broadcast_name ASC
            LIMIT :limit OFFSET :offset";

    $parameters = array(
        "domain_uuid" => $db_domain_uuid,
        "limit" => $limit,
        "offset" => $offset
    );

    $broadcasts = $database->select($sql, $parameters, "all");

    // Get total count
    $sql_count = "SELECT COUNT(*) as total FROM v_call_broadcasts WHERE domain_uuid = :domain_uuid";
    $count_result = $database->select($sql_count, array("domain_uuid" => $db_domain_uuid), "row");
    $total = $count_result ? intval($count_result['total']) : 0;

    // Fix phone count for empty numbers
    if (!empty($broadcasts)) {
        foreach ($broadcasts as &$broadcast) {
            if (empty($broadcast['phone_count']) || $broadcast['phone_count'] < 0) {
                $broadcast['phone_count'] = 0;
            }
        }
    }

    return array(
        "success" => true,
        "total" => $total,
        "limit" => $limit,
        "offset" => $offset,
        "broadcasts" => $broadcasts ?: array()
    );
}
