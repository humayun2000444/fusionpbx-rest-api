#!/usr/bin/env bash
# Retention purge for call recordings.
#
#   ./purge-retention.sh                 # DRY RUN (default) -- prints, deletes nothing
#   ./purge-retention.sh --apply         # actually delete
#   ./purge-retention.sh --apply --force # delete even data the customer never downloaded
#
# DEFAULTS ARE DELIBERATELY TIMID. This script deletes customer data that a
# customer has told you is "very valuable". It refuses by default to remove
# anything they have not collected, and it never runs destructively unless asked.
#
# ── Policy ───────────────────────────────────────────────────────────────────
#  archive  (/var/lib/freeswitch/recordings)  keep >= ARCHIVE_DAYS (95)
#  pickup   (/srv/sftp/*/recordings)          keep    PICKUP_DAYS  (30)
#
# The pickup tree is a collection window, not a second archive.
#
# "at least 90 days": a month-end run means the effective age at deletion is
# 95-125 days. Customers are told 90 (display_days in recording-export-status.php)
# while this deletes at 95, so we always keep longer than we promise and can
# never be
# accused of deleting early.
#
# ⚠ HARDLINKS: staged files share inodes with the archive. Disk is freed only
# when BOTH links go. Pruning one tree alone deletes the customer's access and
# frees nothing. This script prunes both -- do not replace it with a bare find.
set -euo pipefail

ARCHIVE_ROOT=/var/lib/freeswitch/recordings
SFTP_ROOT=/srv/sftp
ARCHIVE_DAYS=95
PICKUP_DAYS=30
DISK_PRESSURE_PCT=90          # above this, exported data may be purged early
CONF=/etc/fusionpbx/config.conf

APPLY=0; FORCE=0
for a in "$@"; do
    [[ "$a" == "--apply" ]] && APPLY=1
    [[ "$a" == "--force" ]] && FORCE=1
done
[[ $APPLY -eq 0 ]] && echo "=== DRY RUN — nothing will be deleted (pass --apply) ==="

PGUSER=$(grep -E '^database\.0\.username'     "$CONF" | sed 's/.*= *//')
PGDB=$(grep -E '^database\.0\.name'           "$CONF" | sed 's/.*= *//')
export PGPASSWORD
q(){ psql -qAt -h 127.0.0.1 -U "$PGUSER" -d "$PGDB" -c "$1"; }

CUTOFF=$(date -u -d "-${ARCHIVE_DAYS} days" +%F)
PICKUP_CUTOFF=$(date -u -d "-${PICKUP_DAYS} days" +%F)
USE_PCT=$(df --output=pcent / | tail -1 | tr -cd '0-9')

echo "archive cutoff : $CUTOFF   (delete recordings older than this)"
echo "pickup  cutoff : $PICKUP_CUTOFF"
echo "disk usage     : ${USE_PCT}%  (pressure threshold ${DISK_PRESSURE_PCT}%)"
echo

# ── Safety gate: has everything old actually been collected? ─────────────────
pending=$(q "
    SELECT COUNT(*) FROM v_xml_cdr
    WHERE record_name IS NOT NULL AND record_name <> ''
      AND start_stamp < DATE '$CUTOFF'
      AND exported_at IS NULL" || echo 0)

echo "recordings older than cutoff that were NEVER downloaded: $pending"

if [[ "${pending:-0}" -gt 0 && $FORCE -eq 0 ]]; then
    if [[ "$USE_PCT" -lt "$DISK_PRESSURE_PCT" ]]; then
        echo
        echo "REFUSING to purge: $pending recordings past the cutoff were never collected,"
        echo "and the disk is not under pressure (${USE_PCT}% < ${DISK_PRESSURE_PCT}%)."
        echo "Chase the customer, or re-run with --force once you have accepted the loss."
        echo
        q "SELECT d.domain_name, COUNT(*) AS never_downloaded
           FROM v_xml_cdr c JOIN v_domains d USING(domain_uuid)
           WHERE c.record_name IS NOT NULL AND c.record_name <> ''
             AND c.start_stamp < DATE '$CUTOFF' AND c.exported_at IS NULL
           GROUP BY 1 ORDER BY 2 DESC" || true
        exit 2
    fi
    echo "Disk at ${USE_PCT}% -- over threshold. Proceeding, EXPORTED data first."
fi

# ── 1. pickup tree ───────────────────────────────────────────────────────────
echo "--- pickup tree (older than $PICKUP_CUTOFF)"
while IFS= read -r dir; do
    [[ -z "$dir" ]] && continue
    d=$(echo "$dir" | grep -oE '[0-9]{4}/[0-9]{2}/[0-9]{2}$' | tr '/' '-') || continue
    [[ -z "$d" ]] && continue
    if [[ "$d" < "$PICKUP_CUTOFF" ]]; then
        if [[ $APPLY -eq 1 ]]; then rm -rf -- "$dir"; echo "removed $dir"
        else echo "would remove $dir"; fi
    fi
done < <(find "$SFTP_ROOT" -mindepth 4 -maxdepth 4 -type d -path '*/recordings/*' 2>/dev/null | sort)

# ── 2. archive tree ──────────────────────────────────────────────────────────
#
# UPLOADED AUDIO LIVES IN THIS SAME TREE AND MUST NEVER BE DELETED.
#
# /var/lib/freeswitch/recordings holds two unrelated things: call recordings,
# which age out, and files a customer uploaded -- IVR greetings, hold music,
# survey prompts -- which are configuration and must outlive any retention
# window. Both are .mp3 in the same per-domain directory, so age alone cannot
# tell them apart.
#
# Checked 2026-09-23: of the 13 files this block selected, 11 were registered
# uploads -- Welcome.mp3, Despacito.mp3, the ElevenLabs greetings, the CSAT
# prompt -- across five domains. Deleting them would have silently broken
# those IVRs, and nothing would have said why.
#
# v_recordings is the register of uploads. Anything listed there is skipped,
# whatever its age. If that list cannot be read we delete NOTHING from this
# tree, because the alternative is destroying configuration we cannot identify.
KEEP_LIST=$(mktemp)
if su postgres -c "psql -d fusionpbx -At -F'/' -c \"
      SELECT d.domain_name, r.recording_filename
        FROM v_recordings r JOIN v_domains d USING (domain_uuid)
       WHERE r.recording_filename IS NOT NULL AND r.recording_filename <> '';\"" \
      > "$KEEP_LIST" 2>/dev/null && [[ -s "$KEEP_LIST" ]]; then
    echo "--- archive tree (older than $CUTOFF), protecting $(wc -l < "$KEEP_LIST") uploaded file(s)"
else
    echo "--- archive tree: CANNOT read v_recordings — skipping this tree entirely."
    echo "    Refusing to delete audio when uploads cannot be identified."
    rm -f "$KEEP_LIST"
    KEEP_LIST=""
fi

count=0; bytes=0; kept=0
if [[ -n "$KEEP_LIST" ]]; then
while IFS= read -r f; do
    [[ -z "$f" ]] && continue
    rel="${f#$ARCHIVE_ROOT/}"
    # Match on domain/filename, and on the bare filename too: the same upload
    # is copied into several domains under one name.
    if grep -qxF "$rel" "$KEEP_LIST" 2>/dev/null \
       || grep -qF "/$(basename "$f")" "$KEEP_LIST" 2>/dev/null; then
        kept=$((kept+1)); continue
    fi
    sz=$(stat -c %s "$f" 2>/dev/null || echo 0)
    if [[ $APPLY -eq 1 ]]; then rm -f -- "$f"; fi
    count=$((count+1)); bytes=$((bytes+sz))
done < <(find "$ARCHIVE_ROOT" -type f -name '*.mp3' ! -newermt "$CUTOFF" 2>/dev/null)
echo "    protected $kept uploaded file(s) from deletion"
rm -f "$KEEP_LIST"
fi

human=$(numfmt --to=iec --suffix=B "$bytes" 2>/dev/null || echo "${bytes}B")
if [[ $APPLY -eq 1 ]]; then echo "deleted $count files, $human"
else echo "would delete $count files, $human"; fi

# Prune the empty YYYY/Mon/DD skeletons left behind.
[[ $APPLY -eq 1 ]] && find "$ARCHIVE_ROOT" -mindepth 2 -type d -empty -delete 2>/dev/null || true

echo
echo "disk after: $(df -h / | tail -1 | awk '{print $3" used, "$4" free ("$5")"}')"
