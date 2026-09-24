-- BTCL, 2026-09-24. Applied to BTCL. Worth applying to CCL too -- it is not
-- slow today, but the point is to be told before it is.
--
-- WHY THIS EXISTS. The CDR page timed out because v_xml_cdr had no index on
-- domain_name. FusionPBX ships that table with only a primary key; every index
-- on it was added by us, reactively, and each was modelled on FusionPBX's own
-- access pattern (domain_uuid, what the PHP GUI filters on). TelcoREST filters
-- on domain_name instead. Same meaning, different column, no index, nobody
-- diffed the running queries against the indexes.
--
-- It stayed invisible for months because nothing was watching:
--   pg_stat_statements was ALREADY installed (1.10) and never read
--   log_min_duration_statement was -1, so no slow query was ever logged
--   auto_explain was not loaded, so no plan was ever logged
-- The data existed the whole time. There was no consumer.
--
-- All of the below is reload-only. No restart. pg_stat_statements was already
-- in shared_preload_libraries, which is the part that WOULD have needed one.

-- Log any statement over 10s, and its plan.
ALTER SYSTEM SET log_min_duration_statement = '10s';
ALTER SYSTEM SET session_preload_libraries = 'auto_explain';
-- (reload once here so the library is loadable before setting its GUCs)
SELECT pg_reload_conf();

ALTER SYSTEM SET auto_explain.log_min_duration = '10s';
ALTER SYSTEM SET auto_explain.log_nested_statements = on;
-- log_analyze instruments every plan node and costs real time on a live box.
-- The plan alone is enough to see "Seq Scan on v_xml_cdr".
ALTER SYSTEM SET auto_explain.log_analyze = off;
SELECT pg_reload_conf();

-- domain_name is severely skewed: pbx-innoversal-345 is ~15% of a 17M row
-- table (2,517,552 CDRs in 24h) while every other tenant is in double digits.
-- The default 100 bucket histogram cannot represent that.
--
-- Honest note: this did NOT change the plan for the busy tenant, which still
-- prefers v_xml_cdr_start_idx. It is kept because the skew is real and the
-- planner is better off knowing about it. Revert with SET STATISTICS -1.
ALTER TABLE v_xml_cdr ALTER COLUMN domain_name SET STATISTICS 1000;
ANALYZE v_xml_cdr (domain_name, start_stamp);

-- Rollback, all of it:
--   ALTER SYSTEM RESET log_min_duration_statement;
--   ALTER SYSTEM RESET session_preload_libraries;
--   ALTER SYSTEM RESET auto_explain.log_min_duration;
--   ALTER SYSTEM RESET auto_explain.log_nested_statements;
--   ALTER SYSTEM RESET auto_explain.log_analyze;
--   ALTER TABLE v_xml_cdr ALTER COLUMN domain_name SET STATISTICS -1;
--   SELECT pg_reload_conf();
--
-- The consumer for all this is jobs/db-health-report.sh, installed at
-- /usr/local/sbin/db-health-report.sh and run Mondays 06:00 by
-- /etc/cron.d/db-health-report. Its first run on BTCL reported
-- v_xml_cdr_json at 7,574 seq scans over 106 GB -- roughly 787 TB read --
-- which is the next thing to look at.
