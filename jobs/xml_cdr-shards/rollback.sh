#!/bin/bash
#
# Back to the single CDR importer: removes the shard units and the main unit's
# shard drop-in, then starts xml_cdr.service on its own, taking every file again.
#
# The patched xml_cdr.php stays. Without shard= / shards= arguments it behaves
# exactly like the original. The pre-patch copy is in
# /var/backups/fusionpbx-patches/<timestamp>/xml_cdr.php if you want it back.
set -euo pipefail
[ "$(id -u)" = 0 ] || { echo "run as root"; exit 2; }

systemctl stop xml_cdr
systemctl stop 'xml_cdr-shard@*' 2>/dev/null || true
for w in /etc/systemd/system/xml_cdr.service.wants/xml_cdr-shard@*.service; do
    [ -e "$w" ] || continue
    u=${w##*/}
    systemctl disable "$u" >/dev/null 2>&1 || rm -f "$w"
done
rm -f /etc/systemd/system/xml_cdr-shard@.service \
      /etc/systemd/system/xml_cdr.service.d/20-shards.conf \
      /etc/default/xml_cdr-shards
systemctl daemon-reload
systemctl start xml_cdr
sleep 3

n=$( (pgrep -u www-data -f 'resources/service/xml_cdr\.php' || true) | wc -l)
echo "importers running: $n (expected 1)"
pgrep -a -u www-data -f 'resources/service/xml_cdr\.php' || true
[ "$n" -eq 1 ]
