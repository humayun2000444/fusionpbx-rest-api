<?php

require_once dirname(__FILE__) . '/branch-scope.php';

$required_params = array();

function do_action($body) {
    $caller = branch_caller_domain($body);
    if (empty($caller)) {
        // Nothing vouched for the caller, so there is no set of groups to show.
        // Fails closed: this used to list every group on the box.
        return array("success" => true, "total" => 0, "groups" => array());
    }

    $database = new database;

    // Only the groups this domain is related to: one it is a member of, or one it
    // created and has not added members to yet. Two placeholders for one value
    // because PDO will not reliably take the same named parameter twice.
    $sql = "SELECT bg.branch_group_uuid, bg.branch_group_name, bg.branch_group_description,
                   bg.branch_group_enabled, bg.insert_date,
                   COUNT(bm.branch_member_uuid) as member_count
            FROM v_branch_groups bg
            LEFT JOIN v_branch_members bm ON bm.branch_group_uuid = bg.branch_group_uuid
            WHERE bg.insert_user = :caller_creator
               OR EXISTS (SELECT 1 FROM v_branch_members mine
                          WHERE mine.branch_group_uuid = bg.branch_group_uuid
                            AND mine.domain_uuid = :caller_member)
            GROUP BY bg.branch_group_uuid, bg.branch_group_name, bg.branch_group_description,
                     bg.branch_group_enabled, bg.insert_date
            ORDER BY bg.branch_group_name";

    $groups = $database->select($sql, array(
        "caller_creator" => $caller,
        "caller_member" => $caller
    ), "all");

    if (!is_array($groups)) {
        $groups = array();
    }

    // For each group, get its members with domain info. Every member is returned,
    // not just the caller: knowing the other branches' prefixes is the point of
    // the feature, and they are only reachable through a group the caller is in.
    foreach ($groups as &$group) {
        $member_sql = "SELECT bm.branch_member_uuid, bm.domain_uuid, bm.branch_prefix,
                              bm.branch_label, bm.branch_member_enabled,
                              d.domain_name, d.domain_description
                       FROM v_branch_members bm
                       JOIN v_domains d ON d.domain_uuid = bm.domain_uuid
                       WHERE bm.branch_group_uuid = :branch_group_uuid
                       ORDER BY bm.branch_prefix";
        $members = $database->select($member_sql, array(
            "branch_group_uuid" => $group['branch_group_uuid']
        ), "all");
        $group['members'] = is_array($members) ? $members : array();
    }

    return array(
        "success" => true,
        "total" => count($groups),
        "groups" => $groups
    );
}
