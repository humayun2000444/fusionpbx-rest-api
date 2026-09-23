#!/bin/bash
set -u
R=/var/lib/freeswitch/recordings
TS=$(date +%Y%m%d-%H%M%S)
Q() { su postgres -c "psql -d fusionpbx -At -F'|' -c \"$1\"" 2>/dev/null; }

# Re-derive the list HERE rather than trusting one passed in: the only rows we
# delete are ones whose file is verifiably absent at this moment.
Q "SELECT r.recording_uuid||'|'||d.domain_name||'|'||r.recording_filename
     FROM v_recordings r JOIN v_domains d USING (domain_uuid)
    WHERE r.recording_filename IS NOT NULL AND r.recording_filename <> '';" > /tmp/all.txt

: > /tmp/dead.txt
while IFS='|' read -r uuid dom fn; do
  [ -z "${uuid:-}" ] && continue
  [ -f "$R/$dom/$fn" ] || echo "$uuid|$dom|$fn" >> /tmp/dead.txt
done < /tmp/all.txt

n=$(wc -l < /tmp/dead.txt)
echo "  rows whose file is missing: $n"
sed 's/^/    /' /tmp/dead.txt
[ "$n" -gt 0 ] || { echo "  nothing to do"; exit 0; }

# Full row backup before deleting anything, so this is reversible.
BK=/root/v_recordings-orphans-$TS.sql
echo "  backing up full rows -> $BK"
UUIDS=$(cut -d'|' -f1 /tmp/dead.txt | sed "s/^/'/; s/$/'/" | paste -sd, -)
su postgres -c "psql -d fusionpbx -c \"COPY (SELECT * FROM v_recordings WHERE recording_uuid IN ($UUIDS)) TO STDOUT\"" > "$BK" 2>/dev/null
echo "  backup lines: $(wc -l < "$BK")"

if [ "$(wc -l < "$BK")" -ne "$n" ]; then
  echo "  ABORT: backup has $(wc -l < "$BK") rows but $n were selected. Not deleting."
  exit 1
fi

del=$(Q "WITH d AS (DELETE FROM v_recordings WHERE recording_uuid IN ($UUIDS) RETURNING 1) SELECT COUNT(*) FROM d;")
echo "  deleted: $del row(s)"
echo "  remaining registered: $(Q "SELECT COUNT(*) FROM v_recordings;")"
rm -f /tmp/all.txt /tmp/dead.txt
