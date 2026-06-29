<?php

$required_params = array("branchGroupUuid", "branchPrefix");

function do_action($body) {
    global $domain_uuid;

    $group_uuid = isset($body->branchGroupUuid) ? $body->branchGroupUuid :
                  (isset($body->branch_group_uuid) ? $body->branch_group_uuid : null);
    $member_domain_uuid = isset($body->domainUuid) ? $body->domainUuid :
                          (isset($body->domain_uuid) ? $body->domain_uuid : null);
    $member_domain_name = isset($body->domainName) ? $body->domainName :
                          (isset($body->domain_name) ? $body->domain_name : null);
    $prefix = isset($body->branchPrefix) ? $body->branchPrefix :
              (isset($body->branch_prefix) ? $body->branch_prefix : null);
    $label = isset($body->branchLabel) ? $body->branchLabel :
             (isset($body->branch_label) ? $body->branch_label : '');

    if (empty($group_uuid) || empty($prefix)) {
        return array("success" => false, "error" => "branchGroupUuid and branchPrefix are required");
    }

    if (empty($member_domain_uuid) && empty($member_domain_name)) {
        return array("success" => false, "error" => "Either domainUuid or domainName is required");
    }

    // Validate prefix: must be 1-4 digits
    if (!preg_match('/^\d{1,4}$/', $prefix)) {
        return array("success" => false, "error" => "branchPrefix must be 1-4 digits (e.g., 10, 20, 100)");
    }

    $database = new database;

    // Check group exists
    $group = $database->select(
        "SELECT branch_group_uuid, branch_group_name FROM v_branch_groups WHERE branch_group_uuid = :uuid",
        array("uuid" => $group_uuid), "row"
    );
    if (empty($group)) {
        return array("success" => false, "error" => "Branch group not found");
    }

    // Look up domain by name or UUID
    if (!empty($member_domain_name) && empty($member_domain_uuid)) {
        $domain = $database->select(
            "SELECT domain_uuid, domain_name FROM v_domains WHERE domain_name = :name",
            array("name" => $member_domain_name), "row"
        );
    } else {
        $domain = $database->select(
            "SELECT domain_uuid, domain_name FROM v_domains WHERE domain_uuid = :uuid",
            array("uuid" => $member_domain_uuid), "row"
        );
    }
    if (empty($domain)) {
        return array("success" => false, "error" => "Domain not found: " . ($member_domain_name ?: $member_domain_uuid));
    }
    $member_domain_uuid = $domain['domain_uuid'];

    // Check domain not already in this group
    $existing = $database->select(
        "SELECT branch_member_uuid FROM v_branch_members
         WHERE branch_group_uuid = :group_uuid AND domain_uuid = :domain_uuid",
        array("group_uuid" => $group_uuid, "domain_uuid" => $member_domain_uuid), "row"
    );
    if (!empty($existing)) {
        return array("success" => false, "error" => "This domain is already a member of this branch group");
    }

    // Check prefix not already used in this group
    $prefix_check = $database->select(
        "SELECT branch_member_uuid, branch_label FROM v_branch_members
         WHERE branch_group_uuid = :group_uuid AND branch_prefix = :prefix",
        array("group_uuid" => $group_uuid, "prefix" => $prefix), "row"
    );
    if (!empty($prefix_check)) {
        return array("success" => false, "error" => "Prefix '$prefix' is already used by another branch in this group");
    }

    // Use domain_name as label if not provided
    if (empty($label)) {
        $label = $domain['domain_name'];
    }

    $member_uuid = uuid();

    $sql = "INSERT INTO v_branch_members (branch_member_uuid, branch_group_uuid, domain_uuid,
                branch_prefix, branch_label, branch_member_enabled, insert_date, insert_user)
            VALUES (:member_uuid, :group_uuid, :domain_uuid,
                :prefix, :label, 'true', NOW(), :insert_user)";

    try {
        $database->execute($sql, array(
            "member_uuid" => $member_uuid,
            "group_uuid" => $group_uuid,
            "domain_uuid" => $member_domain_uuid,
            "prefix" => $prefix,
            "label" => $label,
            "insert_user" => $domain_uuid
        ));
    } catch (Exception $e) {
        return array("success" => false, "error" => "Failed to add member: " . $e->getMessage());
    }

    return array(
        "success" => true,
        "message" => "Branch member added successfully",
        "branchMemberUuid" => $member_uuid,
        "domainName" => $domain['domain_name'],
        "branchPrefix" => $prefix,
        "branchLabel" => $label
    );
}
