#!/bin/bash
#
# Give the CDR spool a fresh, compact directory.  See README.md in this folder.
#
# ext4 never shrinks a directory. After the 2026-10-22 backlog (1.2 million
# files) /var/log/freeswitch/xml_cdr stayed 140 MB on disk while it held a few
# thousand files, and every sweep of it read all 140 MB: 0.06 s of kernel time,
# 0.4 s on the loaded box. The only way to get the space back is a new directory.
#
# Without losing a CDR:
#   1. pause the guard and stop every importer (FreeSWITCH keeps writing CDRs)
#   2. swap a new empty directory into place in one atomic step
#      (renameat2 RENAME_EXCHANGE), so every CDR FreeSWITCH writes lands in
#      either the old or the new directory, never in a missing one
#   3. move the waiting files and failed/ across (same filesystem, so renames)
#   4. start the importers and release the guard
# The importers are stopped for about 10-30 seconds; CDRs just wait in the spool.
#
#   compact-spool-dir.sh            only if the directory is over 8 MB
#   compact-spool-dir.sh --force    always
#   compact-spool-dir.sh --dry-run  say what it would do
set -euo pipefail

D=${XML_CDR_DIR:-/var/log/freeswitch/xml_cdr}
PAUSE=${PAUSE_FILE:-/run/cdr-pipeline-guard.pause}
MIN_BYTES=$((8 * 1024 * 1024))
ENVF=/etc/default/xml_cdr-shards

force=0; dry=0
for a in "$@"; do
    case "$a" in
        --force) force=1 ;;
        --dry-run) dry=1 ;;
        *) echo "usage: $0 [--force] [--dry-run]"; exit 2 ;;
    esac
done

[ "$(id -u)" = 0 ] || { echo "run as root"; exit 2; }
# CCL imports from cron; this script only knows how to stop the daemon.
systemctl cat xml_cdr >/dev/null 2>&1 || { echo "no xml_cdr.service on this box -- only for daemon boxes"; exit 2; }
[ -d "$D" ] && [ ! -L "$D" ] || { echo "$D is not a directory"; exit 2; }
mountpoint -q "$D" && { echo "$D is a mount point -- cannot swap it"; exit 2; }

SHARDS=1
[ -r "$ENVF" ] && SHARDS=$(sed -n 's/^XML_CDR_SHARDS=\([0-9][0-9]*\).*/\1/p' "$ENVF" | tail -1)
[ -n "$SHARDS" ] || SHARDS=1

count_files() { find "$1" -maxdepth 1 -type f -name '*.cdr.xml' -printf . 2>/dev/null | wc -c; }
importers() { pgrep -u www-data -f 'resources/service/xml_cdr\.php' 2>/dev/null || true; }

size=$(stat -c %s "$D")
echo "spool $D: $((size / 1024)) KB on disk, $(count_files "$D") CDR files waiting, $SHARDS importer(s)"
if [ "$size" -lt "$MIN_BYTES" ] && [ "$force" = 0 ]; then
    echo "under $((MIN_BYTES / 1048576)) MB -- nothing to do (--force to do it anyway)"
    exit 0
fi

ts=$(date +%Y%m%d-%H%M%S)
NEW=$(dirname "$D")/.$(basename "$D").new-$ts
OLD=$D.old-$ts
if [ "$dry" = 1 ]; then
    echo "would: pause the guard, stop xml_cdr and its shards, create $NEW,"
    echo "       swap it with $D, move everything from the old directory (now $OLD) across,"
    echo "       remove $OLD, start xml_cdr"
    exit 0
fi

# From here on the importers are stopped; whatever happens, start them again.
stopped=0
finish() {
    rc=$?
    if [ "$stopped" = 1 ]; then
        systemctl start xml_cdr || echo "!! FAILED to start xml_cdr -- start it by hand"
    fi
    rm -f "$PAUSE"
    exit $rc
}
trap finish EXIT
trap 'exit 130' INT TERM HUP

# 1. pause the guard, let a guard run that started before the pause finish (it
# could start an importer behind our back), then stop every importer
echo "compact-spool-dir.sh pid $$ since $(date -Is)" > "$PAUSE"
for _ in $(seq 1 60); do
    pgrep -f 'cdr-pipeline-guard\.sh' >/dev/null 2>&1 || break
    sleep 1
done
stopped=1
systemctl stop xml_cdr
systemctl stop 'xml_cdr-shard@*' 2>/dev/null || true
for _ in $(seq 1 30); do
    [ -z "$(importers)" ] && break
    sleep 1
done
[ -z "$(importers)" ] || { echo "importers still running after 30 s: $(importers | tr '\n' ' ') -- aborting, nothing swapped"; exit 1; }
echo "importers stopped"

# 2. a new, empty directory with the same owner and mode, swapped in atomically
mkdir "$NEW"
chown --reference="$D" "$NEW"
chmod --reference="$D" "$NEW"
if ! python3 -I - "$NEW" "$D" <<'PY'
import ctypes, os, sys
libc = ctypes.CDLL(None, use_errno=True)
AT_FDCWD, RENAME_EXCHANGE = -100, 2
if libc.renameat2(AT_FDCWD, os.fsencode(sys.argv[1]), AT_FDCWD, os.fsencode(sys.argv[2]), RENAME_EXCHANGE) != 0:
    sys.exit("renameat2: " + os.strerror(ctypes.get_errno()))
PY
then
    rmdir "$NEW" 2>/dev/null || true
    echo "swap failed -- nothing changed"
    exit 1
fi
mv -T "$NEW" "$OLD"
echo "swapped: $D is new, the old directory is $OLD"

# 3. move everything across. A CDR FreeSWITCH was writing at the moment of the
# swap finishes in the old directory, so wait a moment, then sweep twice.
sleep 2
for pass in 1 2; do
    # -n: never overwrite; anything that clashes stays behind and is counted below
    find "$OLD" -mindepth 1 -maxdepth 1 -print0 | xargs -0 -r mv -n -t "$D" || true
    if [ "$pass" = 1 ]; then sleep 2; fi
done
left=$(find "$OLD" -mindepth 1 -maxdepth 1 | wc -l)
if [ "$left" -eq 0 ]; then
    rmdir "$OLD"
    echo "moved everything across; removed the old directory"
else
    echo "!! $left entr(y/ies) still in $OLD (a name clash?) -- left in place, check by hand"
fi

# 4. start the importers again
systemctl start xml_cdr
stopped=0
sleep 5
n=$(importers | wc -l)
echo "spool $D: $(( $(stat -c %s "$D") / 1024 )) KB on disk, $(count_files "$D") CDR files waiting"
if [ "$n" -eq "$SHARDS" ]; then
    echo "OK: $n importer(s) running"
else
    echo "CHECK: $n importer(s) running, expected $SHARDS -- see systemctl status xml_cdr 'xml_cdr-shard@*'"
    exit 1
fi
