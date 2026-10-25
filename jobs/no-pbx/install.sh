#!/bin/bash
#
# Add the no-pbx SIP profile to the FreeSWITCH that FusionPBX runs: a listener of its own whose calls
# park at once, for RouteSphere to control over the event socket. See README.md.
#
#   install.sh <sip-ip> [sip-port] [--restart]       (sip-port defaults to 5070)
#
# mod_lua binds the xml handler script only when FreeSWITCH starts, so the profile goes live at the next
# FreeSWITCH restart, which drops every call on the box. --restart does that restart now and checks
# that no-pbx is running and every other profile came back. Without it nothing changes until you restart.
set -euo pipefail
[ "$(id -u)" = 0 ] || { echo "run as root"; exit 2; }

HERE=$(cd "$(dirname "$0")" && pwd)
CONF=/etc/freeswitch
LUA_CONF=$CONF/autoload_configs/lua.conf.xml
SCRIPTS=/usr/share/freeswitch/scripts
PROFILE_DIR=$CONF/no-pbx
STOCK='app.lua xml_handler'
OURS='no-pbx-xml-handler.lua'

ip= port=5070 restart=0
for a in "$@"; do
    case "$a" in
        --restart) restart=1 ;;
        *) if [ -z "$ip" ]; then ip=$a; else port=$a; fi ;;
    esac
done
[ -n "$ip" ] || { echo "usage: $0 <sip-ip> [sip-port] [--restart]"; exit 2; }
[[ $ip =~ ^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+$ ]] || { echo "sip-ip must be an IPv4 address"; exit 2; }
[[ $port =~ ^[0-9]+$ ]] || { echo "sip-port must be a number"; exit 2; }

[ -f "$SCRIPTS/app.lua" ] || { echo "$SCRIPTS/app.lua not found: this is not a FusionPBX box"; exit 1; }
if ! grep -qE "name=\"xml-handler-script\" value=\"($STOCK|$OURS)\"" "$LUA_CONF"; then
    echo "$LUA_CONF has an xml-handler-script this script does not know, refusing:"
    grep -n 'xml-handler-script' "$LUA_CONF"
    exit 1
fi
if ! ip -4 -o addr | grep -q "inet $ip/" && [ "$(sysctl -n net.ipv4.ip_nonlocal_bind)" != 1 ]; then
    echo "$ip is not an address of this host"; exit 1
fi
if ss -ltnupH "sport = :$port" | grep -v '"freeswitch"' | grep -q .; then
    echo "port $port is in use by something other than FreeSWITCH:"; ss -ltnupH "sport = :$port"; exit 1
fi

#the profile, checked as XML before it is put in place
mkdir -p "$PROFILE_DIR"
sed -e "s/{no_pbx_ip}/$ip/g" -e "s/{no_pbx_port}/$port/g" "$HERE/no-pbx-profile.xml" > "$PROFILE_DIR/no-pbx-profile.xml.new"
php -r 'exit(@simplexml_load_file($argv[1]) === false ? 1 : 0);' "$PROFILE_DIR/no-pbx-profile.xml.new" \
    || { rm -f "$PROFILE_DIR/no-pbx-profile.xml.new"; echo "the profile is not valid XML"; exit 1; }
mv "$PROFILE_DIR/no-pbx-profile.xml.new" "$PROFILE_DIR/no-pbx-profile.xml"
chown -R --reference="$CONF" "$PROFILE_DIR"
echo "profile: $PROFILE_DIR/no-pbx-profile.xml ($ip:$port)"

#the wrapper around the FusionPBX handler
cp "$HERE/no-pbx-xml-handler.lua" "$SCRIPTS/$OURS"
chown --reference="$SCRIPTS/app.lua" "$SCRIPTS/$OURS"
chmod --reference="$SCRIPTS/app.lua" "$SCRIPTS/$OURS"
echo "handler: $SCRIPTS/$OURS"

#point mod_lua at the wrapper (one line), backing up lua.conf.xml first
wrapper_was_live=0
grep -q "name=\"xml-handler-script\" value=\"$OURS\"" "$LUA_CONF" && wrapper_was_live=1
if grep -q "name=\"xml-handler-script\" value=\"$STOCK\"" "$LUA_CONF"; then
    backup=/var/backups/fusionpbx-patches/$(date +%Y%m%d-%H%M%S)
    mkdir -p "$backup"
    cp -p "$LUA_CONF" "$backup/"
    sed -i "s#name=\"xml-handler-script\" value=\"$STOCK\"#name=\"xml-handler-script\" value=\"$OURS\"#" "$LUA_CONF"
    echo "lua.conf.xml: xml-handler-script is now $OURS (backup in $backup)"
fi
grep -q "name=\"xml-handler-script\" value=\"$OURS\"" "$LUA_CONF"

profiles() { fs_cli -x 'sofia status' | awk '$2 == "profile" {print $1}' | sort; }
#the wrapper is already in mod_lua and no-pbx runs: only the profile changed, and restarting that one
#profile applies it (drops no-pbx calls only, no other call)
profile_only=0
[ $wrapper_was_live = 1 ] && profiles | grep -qx no-pbx && profile_only=1

if [ $restart = 0 ]; then
    echo
    if [ $profile_only = 1 ]; then
        echo "Profile updated, not live yet. Apply it with:  fs_cli -x 'sofia profile no-pbx restart'"
        echo "(drops no-pbx calls only), or run this again with --restart."
    else
        echo "Installed, not live. Restart FreeSWITCH off-hours, it drops every call:  systemctl restart freeswitch"
        echo "or run this again with --restart."
    fi
    exit 0
fi

before=$(profiles)
if [ $profile_only = 1 ]; then
    echo "restarting the no-pbx profile only"
    fs_cli -x 'sofia profile no-pbx restart'
    sleep 3
else
    echo "restarting FreeSWITCH (profiles now: $(echo $before))"
    systemctl restart freeswitch
fi
for i in $(seq 1 30); do
    fs_cli -x 'sofia status' 2>/dev/null | grep -qE '^[[:space:]]*no-pbx[[:space:]]' && break
    sleep 2
done
fs_cli -x 'sofia status'
fs_cli -x 'sofia status' | grep -qE "^[[:space:]]*no-pbx[[:space:]]+profile[[:space:]]+sip:mod_sofia@$ip:$port[[:space:]]+RUNNING" \
    || { echo "FAILED: no-pbx is not RUNNING on $ip:$port"; exit 1; }
missing=$(comm -23 <(echo "$before") <(profiles))
[ -z "$missing" ] || { echo "FAILED: these profiles did not come back: $missing"; exit 1; }
echo "OK: no-pbx is RUNNING on $ip:$port and every other profile is back"
