#!/bin/bash
#
# Weekly Postgres health report.
#
# Written 2026-09-24, after the CDR page timed out for want of an index on
# domain_name. The galling part: pg_stat_statements had been installed the whole
# time and nothing ever read it, and log_min_duration_statement was -1, so no
# slow query had ever been logged. The data was there. Nobody looked.
#
# This looks. Three sections:
#   1. queries burning the most total time      -- volume problems
#   2. queries slowest per execution            -- missing index / bad plan
#   3. big tables taking sequential scans       -- the index that isn't there
#
# Read-only. Safe to run any time.
#
# Install:  */0 6 * * 1 root /usr/local/sbin/db-health-report.sh
set -u
PSQL="psql -d fusionpbx -X"
run() { sudo -u postgres $PSQL -c "$1" 2>/dev/null; }

echo "================ Postgres health, $(date -Is) ================"

echo
echo "--- 1. most total time (a small cost x a huge call count is still a problem) ---"
run "SELECT round(total_exec_time/1000)::text||'s' AS total,
            calls,
            round(mean_exec_time)::text||'ms' AS mean,
            left(regexp_replace(query,'[[:space:]]+',' ','g'),100) AS query
     FROM pg_stat_statements
     ORDER BY total_exec_time DESC LIMIT 10;"

echo
echo "--- 2. slowest per execution (>1s average: usually a missing index) ---"
run "SELECT round(mean_exec_time)::text||'ms' AS mean,
            calls,
            left(regexp_replace(query,'[[:space:]]+',' ','g'),100) AS query
     FROM pg_stat_statements
     WHERE mean_exec_time > 1000 AND calls > 5
     ORDER BY mean_exec_time DESC LIMIT 10;"

echo
echo "--- 3. large tables being sequentially scanned ---"
echo "    A big table with a high seq_scan count is the signature of the bug"
echo "    this report exists because of. Check what queries hit it."
run "SELECT relname,
            seq_scan,
            idx_scan,
            pg_size_pretty(pg_total_relation_size(relid)) AS size,
            CASE WHEN seq_scan > 0
                 THEN pg_size_pretty((pg_total_relation_size(relid) * seq_scan))
                 ELSE '-' END AS approx_bytes_scanned
     FROM pg_stat_user_tables
     WHERE pg_total_relation_size(relid) > 1073741824   -- over 1 GB
       AND seq_scan > 100
     ORDER BY seq_scan * pg_total_relation_size(relid) DESC LIMIT 10;"

echo
echo "--- 4. slow statements logged since yesterday (auto_explain plans) ---"
journalctl -u postgresql@16-main --since '25 hours ago' --no-pager 2>/dev/null \
  | grep -E 'duration:|Seq Scan' | tail -25 || echo "    (none)"

echo
echo "Reset the statement counters after acting on them:"
echo "  sudo -u postgres psql -d fusionpbx -c 'SELECT pg_stat_statements_reset();'"
