<?php

// Tenant scoping for the branch feature
// Included by every branch-* action

// WHY THIS EXISTS
// ---------------
// A branch group spans several domains, so the branch actions are the only ones
// that legitimately read rows belonging to more than one tenant. They took that
// as licence to filter by nothing at all: branch-group-list returned every group
// on the box to whoever asked, and every write identified its target by uuid
// alone, so any domain could rename, delete or add members to another domain's
// group.
//
// A group is visible to, and writable by, the domains RELATED to it: its members,
// plus the domain that created it (a group with no members yet would otherwise
// vanish from its creator's list the moment it was made).
//
// WHERE THE CALLER COMES FROM
// ---------------------------
// The body's domainUuid, which is trustworthy by the time it arrives here even
// though the client sent it: TelcoGateway's TenantScopeFilters strips any
// client-supplied X-Auth-* header and injects the caller's database-backed
// domain, and TelcoREST's DomainScopeFilter (tenant.scope.overwrite=true)
// rewrites domainUuid in the body to that domain, injecting it when the body
// omitted it. So domainUuid means "the caller" on every branch call.
//
// NOT the `global $domain_uuid` that these handlers all declared: rest.php never
// assigns it, so it was null in all nine of them. That is also why insert_user is
// null on every existing row and creator-based visibility only works for groups
// made from here on.

/** The calling domain's uuid, or null when nothing vouched for the caller. */
function branch_caller_domain($body) {
    $uuid = isset($body->domainUuid) ? $body->domainUuid
            : (isset($body->domain_uuid) ? $body->domain_uuid : null);
    $uuid = is_string($uuid) ? trim($uuid) : null;
    return $uuid === '' ? null : $uuid;
}

/**
 * Whether $caller_domain_uuid is a member of the group, or created it.
 *
 * Fails closed: an unknown caller is related to nothing.
 */
function branch_domain_is_related($database, $group_uuid, $caller_domain_uuid) {
    if (empty($group_uuid) || empty($caller_domain_uuid)) {
        return false;
    }

    $member = $database->select(
        "SELECT branch_member_uuid FROM v_branch_members
         WHERE branch_group_uuid = :group_uuid AND domain_uuid = :domain_uuid",
        array("group_uuid" => $group_uuid, "domain_uuid" => $caller_domain_uuid), "row"
    );
    if (!empty($member)) {
        return true;
    }

    $creator = $database->select(
        "SELECT branch_group_uuid FROM v_branch_groups
         WHERE branch_group_uuid = :group_uuid AND insert_user = :domain_uuid",
        array("group_uuid" => $group_uuid, "domain_uuid" => $caller_domain_uuid), "row"
    );
    return !empty($creator);
}

/**
 * The standard refusal. Deliberately the same message for "no such group" and
 * "not yours", so the reply cannot be used to discover which group uuids exist.
 */
function branch_not_related_error() {
    return array("success" => false, "error" => "Branch group not found");
}
