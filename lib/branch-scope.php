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
// WHERE THE CALLER COMES FROM, AND HOW FAR TO TRUST IT
// ----------------------------------------------------
// The body's domainUuid. An earlier version of this comment called that
// trustworthy, on the grounds that TelcoGateway's TenantScopeFilters injects the
// caller's database-backed domain and TelcoREST's DomainScopeFilter rewrites the
// field to it. That is NOT true for these endpoints:
//
//   - All eight /FREESWITCHREST/api/v1/branch/* paths sit in TelcoGateway's
//     PublicEndpointRegistry and TelcoREST's DatabaseContextResolver
//     PUBLIC_ENDPOINTS, so they take no token. With no authenticated caller
//     there is no AuthUser to read and no X-Auth-Domain-Uuid is injected.
//     Verified 2026-11-06: both deployments answer branch-group-list over
//     HTTPS with no Authorization header at all.
//   - DomainScopeFilter only rewrites "when we actually know the caller's
//     domain", so with no injected header it rewrites nothing, whatever
//     tenant.scope.overwrite is set to. Setting overwrite for CCL changes
//     nothing while the paths remain public.
//
// So what this file enforces is scoping, not authorisation. It stops the portal
// showing one domain another's branch config, which is what it was written for,
// and it is only as strong as the secrecy of a domain_uuid against a caller who
// sets the field deliberately. Closing that needs the branch paths taken out of
// both public lists and given a policy in the gateway's AppConfig, after which
// the injected header arrives and this becomes a real boundary. 138
// /FREESWITCHREST paths share the same gap, writes among them, so it is a
// platform decision rather than a branch one.
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
