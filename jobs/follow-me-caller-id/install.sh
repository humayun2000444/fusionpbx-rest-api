#!/bin/bash
#
# Install the follow-me caller-ID fix (index.lua in this folder).  See README.md.
#
#   install.sh            back up the live script, install ours
#   install.sh --check    show what it would do, change nothing
#   install.sh --force    also replace a version this script does not know
#                         (it is still backed up first)
#
# Takes effect on the next call: mod_lua reads the script from disk every time
# it runs, so there is nothing to reload and calls in progress are not touched.
set -euo pipefail

HERE=$(cd "$(dirname "$0")" && pwd)
NEW=$HERE/index.lua
S=${SCRIPTS_DIR:-/usr/share/freeswitch/scripts}
LIVE=$S/app/follow_me/index.lua

check=0; force=0
for a in "$@"; do
    case "$a" in
        --check) check=1 ;;
        --force) force=1 ;;
        *) echo "usage: $0 [--check] [--force]"; exit 2 ;;
    esac
done

[ "$(id -u)" = 0 ] || { echo "run as root"; exit 2; }
[ -f "$NEW" ] || { echo "missing $NEW"; exit 2; }
[ -f "$LIVE" ] || { echo "no follow-me script at $LIVE (set SCRIPTS_DIR?)"; exit 2; }

sum() { md5sum < "$1" | cut -d' ' -f1; }
known() {
    case "$1" in
        8f48898f75c77b41dbe6085741f67d5f) echo "stock FusionPBX (BTCL, CCL .105, testbed)" ;;
        b64a5f0e98acf212a7463bd58acba839) echo "CCL .109 with the 2026-04-21 caller_destination edit" ;;
        0f05d0bb165eedd444e46bb1f1b307f1) echo "older stock (CCL .109 index.lua.bak)" ;;
        *) return 1 ;;
    esac
}

new_sum=$(sum "$NEW"); live_sum=$(sum "$LIVE")
echo "host:   $(hostname)"
echo "live:   $LIVE  md5 $live_sum  ($(known "$live_sum" || echo UNKNOWN))"
echo "ours:   $NEW  md5 $new_sum"

if [ "$live_sum" = "$new_sum" ]; then
    echo "already installed -- nothing to do"
    exit 0
fi
if ! known "$live_sum" >/dev/null && [ "$force" = 0 ]; then
    echo
    echo "The live script is a version this installer does not know, so someone has"
    echo "changed it. Their change would be lost. Differences (live -> ours):"
    diff -u "$LIVE" "$NEW" | head -60 || true
    echo
    echo "Stopping. Look at the diff; rerun with --force to replace it anyway."
    exit 1
fi

if [ "$check" = 1 ]; then
    echo; echo "--check: would back up the live script and install ours. Differences:"
    diff -u "$LIVE" "$NEW" || true
    exit 0
fi

if command -v luac5.2 >/dev/null 2>&1; then
    luac5.2 -p "$NEW" && echo "syntax: ok (luac5.2)"
fi

ts=$(date +%Y%m%d-%H%M%S)
B=/var/backups/fusionpbx-patches/$ts-follow-me
mkdir -p "$B" && chmod 700 "$B"
cp -a "$LIVE" "$B/index.lua"
echo "$LIVE" > "$B/source-path"

# same owner and mode as the file it replaces; rename into place in one step
TMP=$(dirname "$LIVE")/.index.lua.new
install -o "$(stat -c %U "$LIVE")" -g "$(stat -c %G "$LIVE")" -m "$(stat -c %a "$LIVE")" "$NEW" "$TMP"
mv -f "$TMP" "$LIVE"

[ "$(sum "$LIVE")" = "$new_sum" ] || { echo "md5 mismatch after install -- restoring"; cp -a "$B/index.lua" "$LIVE"; exit 1; }
echo
echo "installed: $(ls -l "$LIVE" | awk '{print $1, $3":"$4}') md5 $new_sum"
echo "backup:    $B/index.lua"
echo "rollback:  sudo bash $HERE/rollback.sh $B"
