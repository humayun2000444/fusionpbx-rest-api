<?php
/*
 * Smart IVR - List Queue
 * Returns list of calls in queue
 */

$required_params = array();

function do_action($body) {
    global $domain_uuid;

    // Get domain_uuid from request or use global
    $req_domain_uuid = isset($body->domain_uuid) ? $body->domain_uuid : $domain_uuid;
    $status = isset($body->status) ? $body->status : null;

    if (empty($req_domain_uuid)) {
        return array('error' => 'domain_uuid is required');
    }

    $database = new database;

    // Build where clause
    $where = "domain_uuid = :domain_uuid";
    $params = array(':domain_uuid' => $req_domain_uuid);

    if ($status) {
        $where .= " AND status = :status";
        $params[':status'] = $status;
    }

    // Get queue items
    $sql = "SELECT * FROM v_smart_ivr_queue
            WHERE $where
            ORDER BY scheduled_time ASC, insert_date ASC";
    $queue = $database->select($sql, $params, 'all');

    return array(
        'success' => true,
        'queue' => $queue ? $queue : array()
    );
}
