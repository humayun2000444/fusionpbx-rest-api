-- BTCL, 2026-09-28. Reported as "the FusionPBX dashboard is loading slow".
-- Two independent causes, one of them self-inflicted.

-- ===== 1. the dashboard's own query had no usable index =====
-- FusionPBX's missed-calls widget:
--   select ... from v_xml_cdr where domain_uuid = $2
--     and (direction='inbound' or direction='local') and status='missed'
--     and hangup_cause <> 'LOSE_RACE' and start_epoch > $n
--   order by start_epoch desc limit $3
--
-- It filters AND orders by start_epoch. Every index on the table was built on
-- start_stamp, so none applied: Parallel Seq Scan over 13 GB, cost 1,580,551,
-- 8,323 ms mean across 39 calls.
--   after: Index Scan, 29.9 ms measured. Built in 2m31s.
CREATE INDEX CONCURRENTLY IF NOT EXISTS v_xml_cdr_domain_epoch_idx
    ON public.v_xml_cdr USING btree (domain_uuid, start_epoch DESC);

-- ===== 2. the trim job was seq scanning 146 GB in a loop, for days =====
-- trim-cdr-side-tables.sh deletes by insert_date, and NEITHER side table had an
-- index on it. Each 20,000-row batch was a parallel seq scan:
--   v_xml_cdr_flow  40 GB, cost 2,786,728  -> 89,346 ms per batch
--   v_xml_cdr_json 106 GB                  ->  2,747 ms per batch
-- Together they accounted for 509,000 seconds of database time in
-- pg_stat_statements, more than every other query on the box combined.
--
-- The job's loop is "while :" and breaks only when a batch deletes 0 rows, so at
-- 89s per batch a run could not finish inside a day -- and the cron entry had no
-- lock, so each night's run started on top of the last. On 2026-09-28 there were
-- runs 4 days and 3 days old still going, 11 processes in total. The cron now
-- uses flock -n (see /etc/cron.d/cdr-trim), and these indexes make a batch cheap
-- enough that a run actually completes.
CREATE INDEX CONCURRENTLY IF NOT EXISTS v_xml_cdr_flow_insert_date_idx
    ON public.v_xml_cdr_flow USING btree (insert_date);
CREATE INDEX CONCURRENTLY IF NOT EXISTS v_xml_cdr_json_insert_date_idx
    ON public.v_xml_cdr_json USING btree (insert_date);

-- Rollback:
--   DROP INDEX CONCURRENTLY v_xml_cdr_domain_epoch_idx;
--   DROP INDEX CONCURRENTLY v_xml_cdr_flow_insert_date_idx;
--   DROP INDEX CONCURRENTLY v_xml_cdr_json_insert_date_idx;
