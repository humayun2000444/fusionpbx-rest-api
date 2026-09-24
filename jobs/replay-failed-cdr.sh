#!/bin/bash
# Replay CDR files that failed to insert back into the import spool.
#
# 2026-09-24: 83,395 files sat in failed/sql, 79,708 of them from the last 24h.
# They land there when xml_cdr's database->save() returns falsy -- which during
# today's load spike it did in bulk. Postgres logged no error, so these were
# invisible: the calls happened, the CDRs existed, and the reports simply did
# not show them.
#
# Verified replayable: a 200 file test imported cleanly with zero bounce-back.
#
# Batched deliberately. Dumping 83k files into a spool the importer is already
# draining would just re-create the backlog we spent the afternoon clearing.
set -u
D=/var/log/freeswitch/xml_cdr
F=$D/failed/sql
BATCH=${BATCH:-5000}
PAUSE=${PAUSE:-45}

total=$(ls -U "$F" 2>/dev/null | wc -l)
echo "$(date -Is) start: $total files in failed/sql"

while :; do
    mapfile -t files < <(ls -U "$F" 2>/dev/null | head -"$BATCH")
    [ "${#files[@]}" -eq 0 ] && break

    for f in "${files[@]}"; do
        mv -n "$F/$f" "$D/$f" 2>/dev/null
    done

    spool=$(ls -U "$D" 2>/dev/null | wc -l)
    left=$(ls -U "$F" 2>/dev/null | wc -l)
    echo "$(date -Is) moved ${#files[@]} | failed/sql left: $left | spool: $spool"

    # If the spool is running away the importer is not keeping up; back off
    # rather than rebuild the backlog.
    while [ "$(ls -U "$D" 2>/dev/null | wc -l)" -gt 250000 ]; do
        echo "$(date -Is) spool over 250k, waiting for it to drain"
        sleep 120
    done

    sleep "$PAUSE"
done

echo "$(date -Is) done: $(ls -U "$F" 2>/dev/null | wc -l) left in failed/sql"
