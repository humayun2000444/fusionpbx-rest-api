<?php

$required_params = array("callBlockUuid");

function do_action($body) {
    global $domain_uuid;

    $db_domain_uuid = isset($body->domain_uuid) ? $body->domain_uuid :
                     (isset($body->domainUuid) ? $body->domainUuid : $domain_uuid);

    $call_block_uuid = isset($body->callBlockUuid) ? $body->callBlockUuid :
                      (isset($body->call_block_uuid) ? $body->call_block_uuid : null);

    // Support bulk delete with array of UUIDs
    $call_block_uuids = isset($body->callBlockUuids) ? $body->callBlockUuids :
                       (isset($body->call_block_uuids) ? $body->call_block_uuids : null);

    if (empty($call_block_uuid) && empty($call_block_uuids)) {
        return array(
            "success" => false,
            "error" => "callBlockUuid or callBlockUuids is required"
        );
    }

    $database = new database;

    // Handle single delete
    if (!empty($call_block_uuid) && empty($call_block_uuids)) {
        $call_block_uuids = array($call_block_uuid);
    }

    // Ensure array
    if (!is_array($call_block_uuids)) {
        $call_block_uuids = array($call_block_uuids);
    }

    $deleted_count = 0;
    $errors = array();

    foreach ($call_block_uuids as $uuid) {
        // Verify call block exists and belongs to domain
        $check_sql = "SELECT call_block_uuid, call_block_number FROM v_call_block
                      WHERE call_block_uuid = :call_block_uuid AND domain_uuid = :domain_uuid";
        $check_result = $database->select($check_sql, array(
            "call_block_uuid" => $uuid,
            "domain_uuid" => $db_domain_uuid
        ), "row");

        if (empty($check_result)) {
            $errors[] = "Call block {$uuid} not found or access denied";
            continue;
        }

        // Delete
        $sql = "DELETE FROM v_call_block
                WHERE call_block_uuid = :call_block_uuid AND domain_uuid = :domain_uuid";

        try {
            $database->execute($sql, array(
                "call_block_uuid" => $uuid,
                "domain_uuid" => $db_domain_uuid
            ));
            $deleted_count++;
        } catch (Exception $e) {
            $errors[] = "Failed to delete {$uuid}: " . $e->getMessage();
        }
    }

    if ($deleted_count === 0) {
        return array(
            "success" => false,
            "error" => "No call blocks were deleted",
            "errors" => $errors
        );
    }

    $result = array(
        "success" => true,
        "message" => "Deleted {$deleted_count} call block(s)",
        "deletedCount" => $deleted_count
    );

    if (!empty($errors)) {
        $result["warnings"] = $errors;
    }

    return $result;
}
