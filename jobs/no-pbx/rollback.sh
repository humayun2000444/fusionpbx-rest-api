#!/bin/bash
#
# Take the no-pbx profile out again.
#
#   rollback.sh [--restart]
#
# Stops the no-pbx profile now (its calls drop, no other call does), removes the profile file and points
# lua.conf.xml back at FusionPBX's "app.lua xml_handler".
#
# mod_lua keeps calling no-pbx-xml-handler.lua until FreeSWITCH restarts, so the wrapper stays in place
# until then; with the profile file gone it only runs the FusionPBX handler. --restart restarts
# FreeSWITCH now (drops every call) and then deletes the wrapper.
set -euo pipefail
[ "$(id -u)" = 0 ] || { echo "run as root"; exit 2; }

LUA_CONF=/etc/freeswitch/autoload_configs/lua.conf.xml
SCRIPTS=/usr/share/freeswitch/scripts
STOCK='app.lua xml_handler'
OURS='no-pbx-xml-handler.lua'
restart=0
[ "${1:-}" = --restart ] && restart=1

sed -i "s#name=\"xml-handler-script\" value=\"$OURS\"#name=\"xml-handler-script\" value=\"$STOCK\"#" "$LUA_CONF"
grep -q "name=\"xml-handler-script\" value=\"$STOCK\"" "$LUA_CONF"
echo "lua.conf.xml: xml-handler-script is $STOCK again"

rm -rf /etc/freeswitch/no-pbx
if fs_cli -x 'sofia status' | grep -qE '^[[:space:]]*no-pbx[[:space:]]'; then
    fs_cli -x 'sofia profile no-pbx stop'
fi

if [ $restart = 1 ]; then
    systemctl restart freeswitch
    for i in $(seq 1 30); do fs_cli -x status >/dev/null 2>&1 && break; sleep 2; done
    rm -f "$SCRIPTS/$OURS"
    fs_cli -x 'sofia status'
else
    echo "Delete $SCRIPTS/$OURS after the next FreeSWITCH restart, not before."
fi
