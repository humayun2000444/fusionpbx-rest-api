<?php

$required_params = array("branchGroupName");

function do_action($body) {
    global $domain_uuid;

    $group_name = isset($body->branchGroupName) ? $body->branchGroupName :
                  (isset($body->branch_group_name) ? $body->branch_group_name : null);
    $group_description = isset($body->branchGroupDescription) ? $body->branchGroupDescription :
                         (isset($body->branch_group_description) ? $body->branch_group_description : '');
    $group_enabled = isset($body->branchGroupEnabled) ? $body->branchGroupEnabled : 'true';

    if (empty($group_name)) {
        return array("success" => false, "error" => "branchGroupName is required");
    }

    // Normalize enabled
    if ($group_enabled === true || $group_enabled === 'true' || $group_enabled === '1') {
        $group_enabled = 'true';
    } else {
        $group_enabled = 'false';
    }

    $database = new database;
    $branch_group_uuid = uuid();

    $sql = "INSERT INTO v_branch_groups (branch_group_uuid, branch_group_name, branch_group_description,
                branch_group_enabled, insert_date, insert_user)
            VALUES (:branch_group_uuid, :branch_group_name, :branch_group_description,
                :branch_group_enabled, NOW(), :insert_user)";

    $params = array(
        "branch_group_uuid" => $branch_group_uuid,
        "branch_group_name" => $group_name,
        "branch_group_description" => $group_description,
        "branch_group_enabled" => $group_enabled,
        "insert_user" => $domain_uuid
    );

    try {
        $database->execute($sql, $params);
    } catch (Exception $e) {
        return array("success" => false, "error" => "Failed to create branch group: " . $e->getMessage());
    }

    return array(
        "success" => true,
        "message" => "Branch group created successfully",
        "branchGroupUuid" => $branch_group_uuid,
        "branchGroupName" => $group_name
    );
}
