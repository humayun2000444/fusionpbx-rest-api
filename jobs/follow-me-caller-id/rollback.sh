#!/bin/bash
#
# Put back the follow-me script that install.sh replaced.
#
#   rollback.sh /var/backups/fusionpbx-patches/<timestamp>-follow-me
#   rollback.sh            uses the newest such backup
set -euo pipefail
[ "$(id -u)" = 0 ] || { echo "run as root"; exit 2; }

B=${1:-$(ls -d /var/backups/fusionpbx-patches/*-follow-me 2>/dev/null | sort | tail -1)}
[ -n "$B" ] && [ -f "$B/index.lua" ] && [ -f "$B/source-path" ] || { echo "no backup found (${B:-none})"; exit 2; }
LIVE=$(cat "$B/source-path")
[ -f "$LIVE" ] || { echo "$LIVE is missing"; exit 2; }

TMP=$(dirname "$LIVE")/.index.lua.old
cp -a "$B/index.lua" "$TMP"
mv -f "$TMP" "$LIVE"
echo "restored $LIVE from $B (md5 $(md5sum < "$LIVE" | cut -d' ' -f1))"
