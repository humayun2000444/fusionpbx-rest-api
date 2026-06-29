<?php

$required_params = array("branchMemberUuid");

function do_action($body) {
    global $domain_uuid;

    $member_uuid = isset($body->branchMemberUuid) ? $body->branchMemberUuid :
                   (isset($body->branch_member_uuid) ? $body->branch_member_uuid : null);

    if (empty($member_uuid)) {
        return array("success" => false, "error" => "branchMemberUuid is required");
    }

    $database = new database;

    // Get member info before deleting
    $member = $database->select(
        "SELECT bm.*, d.domain_name FROM v_branch_members bm
         JOIN v_domains d ON d.domain_uuid = bm.domain_uuid
         WHERE bm.branch_member_uuid = :uuid",
        array("uuid" => $member_uuid), "row"
    );
    if (empty($member)) {
        return array("success" => false, "error" => "Branch member not found");
    }

    try {
        $database->execute(
            "DELETE FROM v_branch_members WHERE branch_member_uuid = :uuid",
            array("uuid" => $member_uuid)
        );
    } catch (Exception $e) {
        return array("success" => false, "error" => "Failed to remove member: " . $e->getMessage());
    }

    return array(
        "success" => true,
        "message" => "Branch member '" . $member['branch_label'] . "' removed successfully",
        "domainName" => $member['domain_name'],
        "branchGroupUuid" => $member['branch_group_uuid']
    );
}
