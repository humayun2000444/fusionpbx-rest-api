<?php

$required_params = array("branchGroupUuid");

function do_action($body) {
    global $domain_uuid;

    $group_uuid = isset($body->branchGroupUuid) ? $body->branchGroupUuid :
                  (isset($body->branch_group_uuid) ? $body->branch_group_uuid : null);

    if (empty($group_uuid)) {
        return array("success" => false, "error" => "branchGroupUuid is required");
    }

    $database = new database;

    // Check exists
    $existing = $database->select(
        "SELECT branch_group_uuid, branch_group_name FROM v_branch_groups WHERE branch_group_uuid = :uuid",
        array("uuid" => $group_uuid), "row"
    );
    if (empty($existing)) {
        return array("success" => false, "error" => "Branch group not found");
    }

    // Get all member domains to clean up dialplans
    $members = $database->select(
        "SELECT bm.domain_uuid, d.domain_name FROM v_branch_members bm
         JOIN v_domains d ON d.domain_uuid = bm.domain_uuid
         WHERE bm.branch_group_uuid = :uuid",
        array("uuid" => $group_uuid), "all"
    );

    // Delete inter-branch dialplans from all member domains
    if (is_array($members)) {
        foreach ($members as $member) {
            $database->execute(
                "DELETE FROM v_dialplan_details WHERE dialplan_uuid IN
                    (SELECT dialplan_uuid FROM v_dialplans
                     WHERE domain_uuid = :domain_uuid AND app_uuid = :app_uuid)",
                array("domain_uuid" => $member['domain_uuid'], "app_uuid" => branch_app_uuid())
            );
            $database->execute(
                "DELETE FROM v_dialplans WHERE domain_uuid = :domain_uuid AND app_uuid = :app_uuid",
                array("domain_uuid" => $member['domain_uuid'], "app_uuid" => branch_app_uuid())
            );
            // Clear dialplan cache
            $cache_file = '/var/cache/fusionpbx/dialplan.' . $member['domain_name'];
            if (file_exists($cache_file)) {
                @unlink($cache_file);
            }
        }
    }

    // Delete group (CASCADE will delete members)
    try {
        $database->execute(
            "DELETE FROM v_branch_groups WHERE branch_group_uuid = :uuid",
            array("uuid" => $group_uuid)
        );
    } catch (Exception $e) {
        return array("success" => false, "error" => "Failed to delete: " . $e->getMessage());
    }

    return array(
        "success" => true,
        "message" => "Branch group '" . $existing['branch_group_name'] . "' deleted successfully"
    );
}

function branch_app_uuid() {
    return 'b0555ec4-e7a4-4000-b0a4-000000000001';
}
