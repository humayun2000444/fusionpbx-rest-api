#!/bin/bash
# Video call CDRs - install, switch Janus on, roll back.
#
#   sudo ./deploy.sh install              receiver + sweep timer. Janus untouched,
#                                         no call is affected. Safe any time.
#   sudo ./deploy.sh test                 logic tests, then a real insert into
#                                         v_xml_cdr that is rolled back.
#   sudo ./deploy.sh enable-janus --yes   turn on Janus event reporting and the
#                                         localhost-only admin API, RESTART JANUS.
#                                         Every WebRTC softphone drops and
#                                         re-registers (a few seconds); any call
#                                         through Janus at that moment is cut.
#                                         Do it in a quiet window.
#   sudo ./deploy.sh status
#   sudo ./deploy.sh rollback --yes       put the three Janus files back, restart
#                                         Janus, stop the receiver.
#
# Janus files changed by enable-janus (backed up first, see BACKUP_ROOT):
#   janus.jcfg                         events.broadcast = true
#                                      general.admin_secret = <new random secret>
#   janus.transport.http.jcfg          admin_http = true on 127.0.0.1:7088 only
#   janus.eventhandler.sampleevh.jcfg  enabled, events = plugins,handles,core,
#                                      backend = http://127.0.0.1:7099/janus-events
#
# The admin API was off and its secret was still the Janus sample default; it
# is switched on bound to 127.0.0.1 with a fresh secret, so nothing off the box
# can reach it.

set -euo pipefail

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
CONF=/etc/video-call-cdr.conf
JANUS_ETC=/opt/janus/etc/janus
BACKUP_ROOT=/var/backups/video-call-cdr
UNITS=(video-call-cdr.service video-call-cdr-sweep.service video-call-cdr-sweep.timer)
JANUS_FILES=(janus.jcfg janus.transport.http.jcfg janus.eventhandler.sampleevh.jcfg)

die() { echo "ERROR: $*" >&2; exit 1; }
need_yes() { [ "${1:-}" = "--yes" ] || die "this restarts Janus - re-run with --yes"; }
conf_get() { sed -n "s/^[[:space:]]*$1[[:space:]]*=[[:space:]]*//p" "$CONF" | tail -1; }
health() { curl -s --max-time 3 http://127.0.0.1:7099/health || echo "(receiver not answering)"; }
admin_ok() {
    curl -s --max-time 3 -X POST http://127.0.0.1:7088/admin \
        -d "{\"janus\":\"ping\",\"transaction\":\"deploy\",\"admin_secret\":\"$(conf_get admin_secret)\"}" | grep -q '"pong"'
}

[ "$(id -u)" = 0 ] || die "run as root"

case "${1:-}" in
install)
    command -v php >/dev/null || die "php not found"
    php -m | grep -qx pdo_pgsql || die "php pdo_pgsql missing"
    php -m | grep -qx curl || die "php curl missing"
    if [ ! -f "$CONF" ]; then
        secret=$(head -c 24 /dev/urandom | base64 | tr -dc 'A-Za-z0-9' | head -c 32)
        sed "s/^admin_secret = .*/admin_secret = $secret/" "$DIR/video-call-cdr.conf.example" > "$CONF"
        echo "wrote $CONF (new admin secret)"
    else
        echo "keeping existing $CONF"
    fi
    chown root:www-data "$CONF"; chmod 0640 "$CONF"
    for u in "${UNITS[@]}"; do
        sed "s|@DIR@|$DIR|g" "$DIR/systemd/$u" > "/etc/systemd/system/$u"
    done
    systemctl daemon-reload
    systemctl enable --now video-call-cdr.service video-call-cdr-sweep.timer
    sleep 1
    echo "receiver: $(health)"
    echo "Janus is not reporting yet - run './deploy.sh enable-janus --yes' in a quiet window."
    ;;

test)
    sudo -u www-data php "$DIR/tests/run-tests.php" --db
    ;;

enable-janus)
    need_yes "${2:-}"
    [ -f "$CONF" ] || die "run '$0 install' first"
    systemctl is-active -q video-call-cdr.service || die "receiver is not running"
    secret=$(conf_get admin_secret)
    [ -n "$secret" ] && [ "$secret" != CHANGE_ME ] || die "no admin_secret in $CONF"

    backup="$BACKUP_ROOT/janus-$(date +%Y%m%d-%H%M%S)"
    mkdir -p "$backup"
    for f in "${JANUS_FILES[@]}"; do cp -a "$JANUS_ETC/$f" "$backup/"; done
    echo "backed up to $backup"

    python3 "$DIR/janus_config.py" "$JANUS_ETC/janus.jcfg" \
        events broadcast true \
        general admin_secret "\"$secret\""
    python3 "$DIR/janus_config.py" "$JANUS_ETC/janus.transport.http.jcfg" \
        admin admin_http true \
        admin admin_port 7088 \
        admin admin_ip '"127.0.0.1"'
    python3 "$DIR/janus_config.py" "$JANUS_ETC/janus.eventhandler.sampleevh.jcfg" \
        general enabled true \
        general events '"plugins,handles,core"' \
        general grouping true \
        general json '"plaintext"' \
        general backend '"http://127.0.0.1:7099/janus-events"'

    echo "--- changes ---"
    for f in "${JANUS_FILES[@]}"; do diff -u "$backup/$f" "$JANUS_ETC/$f" | grep -E '^[+-][^+-]' | sed 's/admin_secret = .*/admin_secret = (hidden)/' || true; done

    echo "--- restarting Janus ($(ss -tn state established '( sport = :8188 )' | tail -n +2 | wc -l) WebSocket clients connected) ---"
    systemctl restart janus
    for i in $(seq 1 20); do admin_ok && break; sleep 1; done
    admin_ok || { echo "admin API not answering - rolling back"; "$0" rollback --yes; exit 1; }
    systemctl is-active -q janus || { echo "Janus not running - rolling back"; "$0" rollback --yes; exit 1; }
    ss -ltn | grep -q '127.0.0.1:7088 ' || echo "WARNING: admin API not on 127.0.0.1:7088 - check before leaving it"
    ss -ltn | grep -E ':7088 ' | grep -v '127.0.0.1' && echo "WARNING: admin API reachable beyond localhost"
    echo "Janus up, admin API answering on 127.0.0.1:7088"
    sleep 3
    echo "receiver: $(health)"
    echo "Now place a test video call, then: $0 status"
    ;;

status)
    echo "janus:     $(systemctl is-active janus)"
    echo "receiver:  $(systemctl is-active video-call-cdr.service)  $(health)"
    echo "sweep:     $(systemctl is-active video-call-cdr-sweep.timer)"
    echo "admin API: $(admin_ok && echo answering || echo 'not answering')"
    echo "--- receiver log (last 15) ---"
    journalctl -u video-call-cdr.service -u video-call-cdr-sweep.service -n 15 --no-pager -o cat || true
    ;;

rollback)
    need_yes "${2:-}"
    latest=$(ls -1d "$BACKUP_ROOT"/janus-* 2>/dev/null | tail -1)
    if [ -n "$latest" ]; then
        for f in "${JANUS_FILES[@]}"; do cp -a "$latest/$f" "$JANUS_ETC/$f"; done
        echo "restored Janus config from $latest"
        systemctl restart janus
        sleep 3
        echo "janus: $(systemctl is-active janus)"
    else
        echo "no Janus backup found - Janus was never changed"
    fi
    systemctl disable --now video-call-cdr.service video-call-cdr-sweep.timer 2>/dev/null || true
    echo "receiver stopped. CDRs already written are kept (last_app = 'videocall')."
    ;;

*)
    sed -n '2,30p' "$0" | sed 's/^# \{0,1\}//'
    exit 1
    ;;
esac
