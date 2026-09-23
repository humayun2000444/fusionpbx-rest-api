#!/bin/bash
#
# Trim the two internal CDR side tables. NOTHING customer-facing.
#
#   v_xml_cdr_json  raw switch payload, shown only in the FusionPBX admin
#                   drill-down. Writes are now off (cdr/format=none); this
#                   clears what was already stored.
#   v_xml_cdr_flow  per-call app trace, written unconditionally by the importer
#                   with no setting to disable it, so trimming is the only lever.
#
# v_xml_cdr -- the CDR customers actually see -- is NEVER touched here.
#
# Why not FusionPBX's own maintenance, which implements the same DELETE:
# it applies every retention setting at once, including
# call_recordings/filesystem_retention_days=90, whose deletion does NOT honour
# exported_at. Enabling it to trim two internal tables would let it delete
# recordings no customer has collected.
#
#   ./trim-cdr-side-tables.sh            dry run -- counts only
#   ./trim-cdr-side-tables.sh --apply    delete
set -u
APPLY=0; [[ "${1:-}" == "--apply" ]] && APPLY=1
PSQL="su postgres -c"
q() { $PSQL "psql -d fusionpbx -At -c \"$1\"" 2>/dev/null; }

setting() { # subcategory default
  local v; v=$(q "SELECT default_setting_value FROM v_default_settings
                   WHERE default_setting_category='cdr' AND default_setting_subcategory='$1'
                     AND default_setting_enabled=true LIMIT 1;")
  [[ "$v" =~ ^[0-9]+$ ]] && echo "$v" || echo "$2"
}
JSON_DAYS=$(setting json_database_retention_days 30)
FLOW_DAYS=$(setting flow_database_retention_days 30)
BATCH=${BATCH:-20000}

[[ $APPLY -eq 0 ]] && echo "=== DRY RUN — nothing will be deleted (pass --apply) ==="
echo "$(date -Is) json keep ${JSON_DAYS}d, flow keep ${FLOW_DAYS}d, batch ${BATCH}"

trim() { # table days
  local t=$1 d=$2
  local n; n=$(q "SELECT COUNT(*) FROM $t WHERE insert_date < NOW() - INTERVAL '$d days';")
  echo "  $t: $n rows older than ${d}d"
  [[ $APPLY -eq 1 ]] || return 0
  [[ "$n" -gt 0 ]] || return 0
  local done=0
  while :; do
    # Batched by ctid so each statement is short. A long DELETE on a 100 GB
    # table holds locks and bloats WAL; this box also runs live calls.
    local got
    got=$(q "WITH doomed AS (
               SELECT ctid FROM $t
                WHERE insert_date < NOW() - INTERVAL '$d days'
                LIMIT $BATCH)
             DELETE FROM $t t USING doomed WHERE t.ctid = doomed.ctid
             RETURNING 1;" | wc -l)
    [[ "$got" -eq 0 ]] && break
    done=$((done+got))
    echo "    deleted $done/$n"
    sleep 2   # let the box breathe; CDR import and live calls share this CPU
  done
  echo "  $t: removed $done rows"
}

trim v_xml_cdr_json "$JSON_DAYS"
trim v_xml_cdr_flow "$FLOW_DAYS"

if [[ $APPLY -eq 1 ]]; then
  # Marks space reusable so the tables stop growing. NOT vacuum full: that
  # takes an exclusive lock on a 100 GB table, which is an outage.
  echo "$(date -Is) vacuum (non-blocking)"
  $PSQL "psql -d fusionpbx -c 'VACUUM (ANALYZE) v_xml_cdr_json;'" >/dev/null 2>&1
  $PSQL "psql -d fusionpbx -c 'VACUUM (ANALYZE) v_xml_cdr_flow;'" >/dev/null 2>&1
fi
echo "$(date -Is) done"
