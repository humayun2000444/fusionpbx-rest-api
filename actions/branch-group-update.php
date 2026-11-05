<?php

require_once dirname(__FILE__) . '/branch-scope.php';

$required_params = array("branchGroupUuid");

function do_action($body) {
    $caller = branch_caller_domain($body);

    $group_uuid = isset($body->branchGroupUuid) ? $body->branchGroupUuid :
                  (isset($body->branch_group_uuid) ? $body->branch_group_uuid : null);

    if (empty($group_uuid)) {
        return array("success" => false, "error" => "branchGroupUuid is required");
    }

    $database = new database;

    // Must be a group the caller is related to. This also covers the group not
    // existing, and answers the same way, so the reply reveals no uuids.
    if (!branch_domain_is_related($database, $group_uuid, $caller)) {
        return branch_not_related_error();
    }

    // Build dynamic update
    $updates = array();
    $params = array("uuid" => $group_uuid, "update_user" => $caller);

    $field_map = array(
        "branchGroupName" => "branch_group_name",
        "branch_group_name" => "branch_group_name",
        "branchGroupDescription" => "branch_group_description",
        "branch_group_description" => "branch_group_description",
        "branchGroupEnabled" => "branch_group_enabled",
        "branch_group_enabled" => "branch_group_enabled",
    );

    foreach ($field_map as $input_key => $db_col) {
        if (isset($body->$input_key)) {
            $val = $body->$input_key;
            if ($db_col === 'branch_group_enabled') {
                $val = ($val === true || $val === 'true' || $val === '1') ? 'true' : 'false';
            }
            $updates[$db_col] = $val;
        }
    }

    if (empty($updates)) {
        return array("success" => false, "error" => "No fields to update");
    }

    $set_clauses = array();
    foreach ($updates as $col => $val) {
        $param_key = "p_" . $col;
        $set_clauses[] = "$col = :$param_key";
        $params[$param_key] = $val;
    }
    $set_clauses[] = "update_date = NOW()";
    $set_clauses[] = "update_user = :update_user";

    $sql = "UPDATE v_branch_groups SET " . implode(", ", $set_clauses) . " WHERE branch_group_uuid = :uuid";

    try {
        $database->execute($sql, $params);
    } catch (Exception $e) {
        return array("success" => false, "error" => "Failed to update: " . $e->getMessage());
    }

    return array("success" => true, "message" => "Branch group updated successfully");
}
