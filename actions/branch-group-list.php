<?php

$required_params = array();

function do_action($body) {
    global $domain_uuid;

    $database = new database;

    // List all branch groups with their members
    $sql = "SELECT bg.branch_group_uuid, bg.branch_group_name, bg.branch_group_description,
                   bg.branch_group_enabled, bg.insert_date,
                   COUNT(bm.branch_member_uuid) as member_count
            FROM v_branch_groups bg
            LEFT JOIN v_branch_members bm ON bm.branch_group_uuid = bg.branch_group_uuid
            GROUP BY bg.branch_group_uuid, bg.branch_group_name, bg.branch_group_description,
                     bg.branch_group_enabled, bg.insert_date
            ORDER BY bg.branch_group_name";

    $groups = $database->select($sql, null, "all");

    if (!is_array($groups)) {
        $groups = array();
    }

    // For each group, get its members with domain info
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
