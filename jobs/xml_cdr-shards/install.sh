#!/bin/bash
#
# Run the CDR import as N parallel importers.  See README.md in this folder.
#
#   install.sh 4    xml_cdr.service = shard 0, xml_cdr-shard@1..3 take the rest
#   install.sh 1    back to one importer (rollback.sh also removes the units)
#
# Safe to run again, for example to change N. It always stops every importer
# before starting the new set, so an old importer that takes every file never
# runs next to the shards.
set -euo pipefail

N=${1:-4}
HERE=$(cd "$(dirname "$0")" && pwd)
FPBX=/var/www/fusionpbx
SVC=app/xml_cdr/resources/service/xml_cdr.php
PATCH=$HERE/../../patches/xml_cdr-service-shard.patch
ENVF=/etc/default/xml_cdr-shards

[[ $N =~ ^[0-9]+$ ]] && [ "$N" -ge 1 ] && [ "$N" -le 16 ] || { echo "usage: $0 N   (N = 1..16)"; exit 2; }
[ "$(id -u)" = 0 ] || { echo "run as root"; exit 2; }
# CCL imports from cron and has no xml_cdr.service: a daemon there would race the cron importer.
systemctl cat xml_cdr >/dev/null 2>&1 || { echo "no xml_cdr.service on this box -- sharding is only for daemon boxes"; exit 2; }

ts=$(date +%Y%m%d-%H%M%S)
B=/var/backups/fusionpbx-patches/$ts
mkdir -p "$B" && chmod 700 "$B"

# 1. the importer must understand shard= / shards=
if grep -q 'another shard owns this file' "$FPBX/$SVC"; then
    echo "importer is already shard-aware"
else
    cp -a "$FPBX/$SVC" "$B/"
    (cd "$FPBX" && patch -p1 --dry-run -s < "$PATCH" && patch -p1 -s --no-backup-if-mismatch < "$PATCH")
    chown --reference="$B/xml_cdr.php" "$FPBX/$SVC"
    chmod --reference="$B/xml_cdr.php" "$FPBX/$SVC"
    php -l "$FPBX/$SVC" >/dev/null
    echo "patched $SVC (original kept in $B)"
fi

# 2. units and the shard count
[ -f "$ENVF" ] && cp -a "$ENVF" "$B/"
install -m 644 "$HERE/xml_cdr-shard@.service" /etc/systemd/system/xml_cdr-shard@.service
install -d -m 755 /etc/systemd/system/xml_cdr.service.d
install -m 644 "$HERE/20-shards.conf" /etc/systemd/system/xml_cdr.service.d/20-shards.conf
printf '%s\n' "# number of CDR importers -- change it with $HERE/install.sh N, not by hand" \
              "XML_CDR_SHARDS=$N" > "$ENVF"
systemctl daemon-reload

# 3. stop every importer first (PartOf= takes the shards down with the main one)
systemctl stop xml_cdr
systemctl stop 'xml_cdr-shard@*' 2>/dev/null || true

# 4. enable exactly shards 1..N-1; enabling only links them to xml_cdr.service
for w in /etc/systemd/system/xml_cdr.service.wants/xml_cdr-shard@*.service; do
    [ -e "$w" ] || continue
    i=${w##*@}; i=${i%.service}
    if ! [[ $i =~ ^[0-9]+$ ]] || [ "$i" -lt 1 ] || [ "$i" -ge "$N" ]; then
        systemctl disable "xml_cdr-shard@$i" >/dev/null 2>&1 || true
    fi
done
for i in $(seq 1 $((N - 1))); do
    systemctl enable "xml_cdr-shard@$i" >/dev/null 2>&1
done

# 5. start them together
systemctl start xml_cdr
sleep 5

# 6. verify: exactly N importers, shard 0..N-1, all with shards=N
echo "running importers:"
ok=1; count=0; seen=" "
for pid in $(pgrep -u www-data -f 'resources/service/xml_cdr\.php' || true); do
    args=$(tr '\0' ' ' < "/proc/$pid/cmdline" 2>/dev/null | sed 's/.*xml_cdr\.php *//')
    echo "  pid $pid  ${args:-<no arguments>}"
    count=$((count + 1))
    s=$(grep -o 'shards=[0-9]*' <<< "$args" | head -1 | cut -d= -f2 || true)
    k=$(grep -o '^shard=[0-9]*' <<< "$args" | cut -d= -f2 || true)
    [ "${s:-1}" = "$N" ] || ok=0
    seen="$seen${k:-0} "
done
[ "$count" -eq "$N" ] || ok=0
for i in $(seq 0 $((N - 1))); do
    [[ $seen == *" $i "* ]] || ok=0
done
if [ "$ok" = 1 ]; then
    echo "OK: $N importer(s) running"
else
    echo "CHECK FAILED: expected $N importers (shard 0..$((N - 1)), shards=$N), found $count -- see above."
    echo "Roll back with: $HERE/rollback.sh"
    exit 1
fi
