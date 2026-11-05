<?php

require_once dirname(__FILE__) . '/branch-scope.php';

$required_params = array("branchMemberUuid");

function do_action($body) {
    $caller = branch_caller_domain($body);

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

    // Scoped by the member's group, not by the member: removing someone else from
    // a group the caller is in is a legitimate act, removing anyone from a group
    // it has nothing to do with is not.
    if (!branch_domain_is_related($database, $member['branch_group_uuid'], $caller)) {
        return branch_not_related_error();
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
