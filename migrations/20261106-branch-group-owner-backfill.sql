-- Branch groups: give the pre-existing rows an owner, so they stay visible.
--
-- 2026-11-06. Branch config was visible to every domain on the box:
-- branch-group-list had no WHERE on domain at all, and the writes identified
-- their target by uuid alone. A group is now the business of the domains
-- related to it only -- its members, plus the domain that created it, which
-- v_branch_groups records in insert_user.
--
-- Two facts about the rows that already exist:
--
--   1. insert_user is NULL on all of them. Every branch handler read the
--      caller from `global $domain_uuid`, which rest.php never assigns, so
--      every create and every member-add stamped a null.
--
--   2. Most groups hold exactly one member, and it is the domain that created
--      the group rather than the domain the admin typed. branch-member-add
--      read the member from the body's domainUuid -- the field TelcoREST
--      rewrites to the CALLER's own domain -- so "add b" added the caller, and
--      the next attempt was refused as a duplicate.
--
-- A group with at least one member is reachable by that member under the new
-- rule and needs nothing. A group with NO members and a null insert_user is an
-- orphan: nobody can see it, so nobody can add to it or delete it. This file
-- finds those and hands them an owner. Nothing here runs automatically -- the
-- owner cannot be derived, only chosen.
--
-- Run the three steps in order, on the FusionPBX database.


-- ─── 1. Does insert_user tolerate a domain uuid? ─────────────────────────────
-- Read this BEFORE deploying the PHP. insert_user is meant to hold a v_users
-- uuid; the branch handlers now write a DOMAIN uuid into it, which is what they
-- always intended but never actually did, since the value was null. If the
-- column carries a foreign key to v_users, every branch group create will fail
-- on it and the column needs widening (or a real domain_uuid column added)
-- before any of this is deployed. No output from this query = no constraint =
-- nothing to do.

SELECT conname, pg_get_constraintdef(oid) AS definition
FROM pg_constraint
WHERE conrelid = 'v_branch_groups'::regclass
  AND contype = 'f'
  AND pg_get_constraintdef(oid) LIKE '%insert_user%';


-- ─── 2. Which groups would become invisible? ─────────────────────────────────
-- Each row here is a group no domain can reach once the new rule is live.

SELECT bg.branch_group_uuid,
       bg.branch_group_name,
       bg.branch_group_description,
       bg.insert_date
FROM v_branch_groups bg
LEFT JOIN v_branch_members bm ON bm.branch_group_uuid = bg.branch_group_uuid
WHERE bm.branch_member_uuid IS NULL
  AND bg.insert_user IS NULL
ORDER BY bg.insert_date;


-- ─── 3. Give each one an owner, or drop it ───────────────────────────────────
-- Per group from step 2, decide which domain owns it. The group name usually
-- says; v_domains gives the uuid:
--
--     SELECT domain_uuid, domain_name FROM v_domains ORDER BY domain_name;
--
-- Then, one statement per group -- deliberately not a bulk UPDATE, because
-- there is no column to join on and a wrong guess hands one tenant's group to
-- another:

-- UPDATE v_branch_groups
--    SET insert_user = '<owning domain_uuid>'
--  WHERE branch_group_uuid = '<branch_group_uuid from step 2>';

-- A group that is simply leftover test data is better deleted. Members cascade,
-- and a memberless group has generated no dialplans, so there is nothing else
-- to clean up:

-- DELETE FROM v_branch_groups WHERE branch_group_uuid = '<branch_group_uuid>';


-- ─── Afterwards ──────────────────────────────────────────────────────────────
-- Step 2 should return nothing. Groups whose single member is the creator (fact
-- 2 above) are visible but wrong: the domains the admin meant to add are not in
-- them. Those are fixed from the portal once the new PHP is live -- remove the
-- stray member, add the real ones by name -- not from here.
