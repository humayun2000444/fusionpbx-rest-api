<?php

// Domains the caller may name as a branch member (for the member picker).
//
// This listed EVERY enabled domain on the box, with each one's extension count,
// to any caller. On CCL that is 15 tenants and, more to the point, their
// domain_uuids -- which is exactly what the branch handlers use to decide who a
// caller is. Handing those out undermines the scoping in lib/branch-scope.php.
//
// Scoped to what the caller can already see: its own domain, and the domains it
// shares a branch group with. Adding a domain that is in no group with you still
// works -- the portal's Add Member takes a domain NAME, typed by someone who
// knows it -- this only stops the list being used to enumerate the platform.

require_once dirname(__FILE__) . '/../lib/branch-scope.php';

$required_params = array();

function do_action($body) {
    $caller = branch_caller_domain($body);
    if (empty($caller)) {
        return array("success" => true, "total" => 0, "domains" => array());
    }

    $database = new database;

    // One value, three placeholders: PDO will not reliably take the same named
    // parameter more than once.
    $sql = "SELECT d.domain_uuid, d.domain_name, d.domain_description, d.domain_enabled
            FROM v_domains d
            WHERE d.domain_enabled = 'true'
              AND (
                d.domain_uuid = :caller_self
                OR d.domain_uuid IN (
                     SELECT peer.domain_uuid
                     FROM v_branch_members peer
                     WHERE peer.branch_group_uuid IN (
                         SELECT bg.branch_group_uuid FROM v_branch_groups bg
                          WHERE bg.insert_user = :caller_creator
                         UNION
                         SELECT mine.branch_group_uuid FROM v_branch_members mine
                          WHERE mine.domain_uuid = :caller_member
                     )
                   )
              )
            ORDER BY d.domain_name";

    $domains = $database->select($sql, array(
        "caller_self" => $caller,
        "caller_creator" => $caller,
        "caller_member" => $caller
    ), "all");

    if (!is_array($domains)) {
        $domains = array();
    }

    // For each domain, get extension count
    foreach ($domains as &$domain) {
        $ext_count = $database->select(
            "SELECT COUNT(*) as cnt FROM v_extensions WHERE domain_uuid = :domain_uuid AND enabled = 'true'",
            array("domain_uuid" => $domain['domain_uuid']), "row"
        );
        $domain['extension_count'] = $ext_count ? (int)$ext_count['cnt'] : 0;
    }

    return array(
        "success" => true,
        "total" => count($domains),
        "domains" => $domains
    );
}
