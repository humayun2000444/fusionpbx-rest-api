<?php

$required_params = array();

function do_action($body) {
    global $domain_uuid;

    $db_domain_uuid = isset($body->domain_uuid) ? $body->domain_uuid :
                     (isset($body->domainUuid) ? $body->domainUuid : $domain_uuid);
    $limit = isset($body->limit) ? intval($body->limit) : 100;
    $offset = isset($body->offset) ? intval($body->offset) : 0;

    // Optional filters
    $direction = isset($body->direction) ? $body->direction :
                (isset($body->call_block_direction) ? $body->call_block_direction : null);
    $enabled = isset($body->enabled) ? $body->enabled :
              (isset($body->call_block_enabled) ? $body->call_block_enabled : null);

    $database = new database;

    // Build WHERE clause
    $where_clauses = array("domain_uuid = :domain_uuid");
    $parameters = array("domain_uuid" => $db_domain_uuid);

    if (!empty($direction)) {
        $where_clauses[] = "call_block_direction = :direction";
        $parameters["direction"] = $direction;
    }

    if ($enabled !== null) {
        $where_clauses[] = "call_block_enabled = :enabled";
        $parameters["enabled"] = ($enabled === true || $enabled === 'true') ? 'true' : 'false';
    }

    $where_sql = implode(" AND ", $where_clauses);

    // Get call blocks
    $sql = "SELECT
                call_block_uuid,
                domain_uuid,
                call_block_direction,
                extension_uuid,
                call_block_name,
                call_block_country_code,
                call_block_number,
                call_block_count,
                call_block_action,
                call_block_app,
                call_block_data,
                date_added,
                call_block_enabled,
                call_block_description,
                insert_date,
                update_date
            FROM v_call_block
            WHERE {$where_sql}
            ORDER BY call_block_name ASC, call_block_number ASC
            LIMIT :limit OFFSET :offset";

    $parameters["limit"] = $limit;
    $parameters["offset"] = $offset;

    $call_blocks = $database->select($sql, $parameters, "all");

    // Get total count
    $sql_count = "SELECT COUNT(*) as total FROM v_call_block WHERE {$where_sql}";
    unset($parameters["limit"], $parameters["offset"]);
    $count_result = $database->select($sql_count, $parameters, "row");
    $total = $count_result ? intval($count_result['total']) : 0;

    return array(
        "success" => true,
        "total" => $total,
        "limit" => $limit,
        "offset" => $offset,
        "call_blocks" => $call_blocks ?: array()
    );
}
