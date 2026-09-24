-- BTCL, 2026-09-24. Applied to BTCL only. CCL does not need it: its v_xml_cdr
-- is 35,362 rows / 47 MB, so a seq scan there costs 4,511 and nothing is slow.
--
-- Two separate problems surfaced the same day. The portal's CDR page was timing
-- out at the client's 15s, and the CDR importer had fallen ~8x behind, leaving
-- 580,000 files in /var/log/freeswitch/xml_cdr. They shared a cause: the CDR
-- page was seq scanning 11 GB repeatedly and saturating disk I/O, which starved
-- the importer. pg_stat_database showed 6.57 billion blocks read, about 52 TB.

-- 1. Index for the TENANT CDR query, which is
--      select count(xml_cdr_uuid) from v_xml_cdr
--      where domain_name = $1 and start_stamp >= $2 and start_stamp <= $3
--    Every pre-existing index is on domain_uuid, so none of them applied and
--    this planned as a parallel seq scan. Equality then range, so that column
--    order. 168 MB on 17M rows.
--      before: Parallel Seq Scan, cost 1,296,302
--      after:  Index Scan,       cost     2,136
--    CONCURRENTLY because the importer inserts continuously; took 5 minutes.
--    Rollback: DROP INDEX CONCURRENTLY v_xml_cdr_domain_name_start_idx;
CREATE INDEX CONCURRENTLY IF NOT EXISTS v_xml_cdr_domain_name_start_idx
    ON public.v_xml_cdr USING btree (domain_name, start_stamp DESC);

-- 2. The ADMIN CDR query is a different shape and NO index can help it:
--      select count(vc1_0.xml_cdr_uuid) from v_xml_cdr vc1_0
--      left join v_extensions v1_0 on vc1_0.extension_uuid=v1_0.extension_uuid
--      where ($1 is null or vc1_0.caller_id_number=$2)
--        and ... and (true=$15 or vc1_0.start_stamp>=$16)
--    It carries no domain filter at all, so it counts all 17,014,332 rows, and
--    every predicate is Hibernate's "($n is null or col = $n+1)" catch-all.
--    Under a GENERIC plan the planner cannot know those parameters, so it can
--    fold nothing and seq scans. Given literal values it folds correctly and
--    uses the index -- verified: rewriting the date clause as "(false OR
--    start_stamp >= ...)" produced an identical Index Scan plan.
--
--    plan_cache_mode was 'auto', which switches to a generic plan after five
--    executions of a prepared statement -- exactly what a Hibernate connection
--    pool does. Forcing custom plans makes the planner re-plan with the real
--    parameters each time, so supplied filters become usable again:
--      unfiltered count : cost 1,299,261  (unchanged -- a 17M row count is a
--                                          17M row count, see the caveat below)
--      1 day of data    : cost   283,912  Index Scan on v_xml_cdr_start_idx
--
--    Re-planning costs well under a millisecond against queries that run for
--    seconds, so this is a clear win here. The role is shared with FusionPBX's
--    own PHP, which is unaffected in practice: its connections are short lived
--    and rarely reach the five executions that would trigger a generic plan.
--
--    Takes effect as pooled connections recycle -- pgbouncer here is
--    pool_mode=transaction, server_lifetime=900, so within 15 minutes.
--    Rollback: ALTER ROLE fusionpbx RESET plan_cache_mode;
ALTER ROLE fusionpbx SET plan_cache_mode = 'force_custom_plan';

-- CAVEAT, still outstanding: none of this makes an UNFILTERED admin page load
-- fast. An exact count() over 17M rows is a full scan however it is planned.
-- The real fix belongs in TelcoREST: build the WHERE clause dynamically so null
-- filters are omitted rather than wrapped, default the admin view to a bounded
-- date range, and stop doing an exact count() for the pager -- keyset
-- pagination, or reltuples for an estimate.
