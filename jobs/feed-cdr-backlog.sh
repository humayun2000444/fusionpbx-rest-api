#!/bin/bash
#
# Feed held CDR files back into the live spool, but only when the importer is
# actually keeping up.
#
# Why files are held at all: xml_cdr_import.php walks the spool with readdir
# from the start on every run, and a single-threaded PHP importer manages about
# 2,000 files/min. One tenant's dialler generates ~5,700/min (roughly three CDR
# legs per call), so during a campaign the spool grows no matter how large the
# batch. Parking the old files keeps the live directory small enough that
# CURRENT calls are recorded within seconds instead of hours behind.
#
# This returns them gradually. It refuses to feed while the live spool is still
# large, so it can never make the lag worse -- the whole point is that recent
# calls stay current.
set -u

LIVE=/var/log/freeswitch/xml_cdr
HELD=/var/log/freeswitch/xml_cdr_backlog
MAX_LIVE=${MAX_LIVE:-20000}     # don't feed if the live spool is bigger than this
CHUNK=${CHUNK:-20000}           # how many to return per run

[ -d "$HELD" ] || { echo "$(date -Is) nothing held"; exit 0; }

held=$(ls -U "$HELD" 2>/dev/null | wc -l)
[ "$held" -gt 0 ] || { echo "$(date -Is) backlog empty; removing holding dir"; rmdir "$HELD" 2>/dev/null; exit 0; }

live=$(ls -U "$LIVE" 2>/dev/null | wc -l)
if [ "$live" -gt "$MAX_LIVE" ]; then
    echo "$(date -Is) live=$live > $MAX_LIVE, held=$held — not feeding, importer is still behind"
    exit 0
fi

# -n so a name that somehow exists in both is left alone rather than clobbered.
ls -U "$HELD" 2>/dev/null | head -n "$CHUNK" \
  | xargs -r -n 500 -I{} echo "$HELD/{}" \
  | xargs -r -n 500 mv -n -t "$LIVE" 2>/dev/null

now_live=$(ls -U "$LIVE" 2>/dev/null | wc -l)
now_held=$(ls -U "$HELD" 2>/dev/null | wc -l)
echo "$(date -Is) fed $((now_live-live)) files; live=$now_live held=$now_held"
[ "$now_held" -eq 0 ] && rmdir "$HELD" 2>/dev/null && echo "$(date -Is) backlog cleared"
exit 0
