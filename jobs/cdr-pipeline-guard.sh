#!/bin/bash
#
# CDR pipeline guard. Runs every 5 minutes.
#
# Written 2026-09-24, the day a customer told US that their call reports were
# short. Two importers were running against one spool; a duplicate key aborted
# the transaction and every file in the batch was discarded into failed/sql,
# including records that had already committed. It ran for a full day. Postgres
# logs nothing for a duplicate (it is returned to the client and swallowed), and
# nothing watched the spool, the failure directories, or how many importers were
# alive. The only alarm in the whole system was a customer counting their calls.
#
# This is that alarm. It fixes what is safe to fix and shouts about the rest.
#
#   1. a second importer            -> KILL IT (this is the bug; never allow two)
#   2. daemon not running           -> start it
#   3. failed/sql piling up         -> replay, but only if the dedupe guard exists
#   4. spool backing up             -> warn (import is slower than arrival)
#   5. failed/xml, failed/size      -> warn, needs a human
#
# Alerts go to syslog (journald) and ALERT_LOG. State is kept so a continuing
# problem does not re-alert every five minutes.

set -u

D=/var/log/freeswitch/xml_cdr
SERVICE=xml_cdr
SVC_FILE=/var/www/fusionpbx/app/xml_cdr/resources/service/xml_cdr.php
ALERT_LOG=/var/log/cdr-pipeline-guard.log
STATE=/var/run/cdr-pipeline-guard.state

SPOOL_WARN=${SPOOL_WARN:-50000}
FAILED_REPLAY=${FAILED_REPLAY:-500}
REPLAY_MAX=${REPLAY_MAX:-20000}

now() { date -Is; }
log()   { echo "$(now) $*" >> "$ALERT_LOG"; }
alert() {
    local key="$1"; shift
    log "ALERT [$key] $*"
    logger -t cdr-pipeline-guard -p daemon.err "[$key] $*"
    # only notify once per hour for the same condition
    local last
    last=$(grep -E "^$key " "$STATE" 2>/dev/null | awk '{print $2}')
    local nowsec; nowsec=$(date +%s)
    if [ -z "$last" ] || [ $((nowsec - last)) -gt 3600 ]; then
        grep -vE "^$key " "$STATE" 2>/dev/null > "$STATE.tmp" || true
        echo "$key $nowsec" >> "$STATE.tmp"
        mv "$STATE.tmp" "$STATE"
        return 0   # caller may escalate (mail/webhook) on a fresh alert
    fi
    return 1
}

touch "$STATE" 2>/dev/null || true

# --- 1. exactly one importer -------------------------------------------------
# The daemon is the one we keep. xml_cdr_import.php is the cron-style importer;
# if it is alive at the same time as the daemon, that is the 2026-09-24 bug
# recurring -- someone re-added the cron entry, or ran it by hand.
daemon_up=$(systemctl is-active "$SERVICE" 2>/dev/null)
mapfile -t cron_importers < <(pgrep -f 'xml_cdr_import\.php' 2>/dev/null)

if [ "$daemon_up" = "active" ] && [ "${#cron_importers[@]}" -gt 0 ]; then
    alert dual-importer \
        "xml_cdr_import.php (pids: ${cron_importers[*]}) running while $SERVICE is active. \
This is what corrupted CDR reporting on 2026-09-24: both importers take the same files, \
the loser raises a duplicate key, the transaction aborts and the whole batch is discarded \
into failed/sql. Killing it. Check for a re-added cron entry -- www-data's crontab is chattr +i +a."
    for p in "${cron_importers[@]}"; do kill -9 "$p" 2>/dev/null; done
    # kill the flock/sh wrappers too, or cron just respawns into the same lock
    pkill -9 -f 'flock -n /tmp/xml_cdr_import.lock' 2>/dev/null
    log "killed ${#cron_importers[@]} stray importer process(es)"
fi

# --- 2. the daemon must be running -------------------------------------------
if [ "$daemon_up" != "active" ]; then
    alert daemon-down "$SERVICE is $daemon_up -- starting it. Nothing imports CDRs while it is down."
    systemctl start "$SERVICE" 2>/dev/null
fi

# --- 3. failed/sql ------------------------------------------------------------
failed_sql=$(ls -U "$D/failed/sql" 2>/dev/null | wc -l)
if [ "$failed_sql" -ge "$FAILED_REPLAY" ]; then
    # Replaying is ONLY safe with the dedupe guard in the service. Without it,
    # every replayed file that is already in the database raises a fresh
    # duplicate, aborts another transaction, and discards another batch -- the
    # replay becomes the thing causing the damage. Verified the hard way.
    if grep -q 'already in the database' "$SVC_FILE" 2>/dev/null; then
        alert failed-sql "$failed_sql CDR files in failed/sql -- replaying (dedupe guard present)."
        n=0
        while [ "$n" -lt "$REPLAY_MAX" ]; do
            mapfile -t batch < <(ls -U "$D/failed/sql" 2>/dev/null | head -1000)
            [ "${#batch[@]}" -eq 0 ] && break
            for f in "${batch[@]}"; do mv -n "$D/failed/sql/$f" "$D/$f" 2>/dev/null; done
            n=$((n + ${#batch[@]}))
            # do not rebuild the backlog we are trying to drain
            [ "$(ls -U "$D" 2>/dev/null | wc -l)" -gt "$SPOOL_WARN" ] && break
            sleep 5
        done
        log "replayed $n file(s) from failed/sql"
    else
        alert failed-sql-noguard \
            "$failed_sql files in failed/sql and the dedupe guard is MISSING from $SVC_FILE \
(a FusionPBX upgrade probably reverted it -- see rest_api patches/). NOT replaying: without the \
guard, replaying is what spreads the damage. Reapply patches/xml_cdr-service-readdir.patch."
    fi
fi

# --- 4. spool depth -----------------------------------------------------------
spool=$(ls -U "$D" 2>/dev/null | wc -l)
if [ "$spool" -gt "$SPOOL_WARN" ]; then
    alert spool-depth \
        "$spool CDR files queued (threshold $SPOOL_WARN). Imports are slower than arrivals; \
reports will be incomplete until it drains. Check load, and that only one importer is running."
fi

# --- 5. buckets that need a human --------------------------------------------
for b in xml size; do
    c=$(ls -U "$D/failed/$b" 2>/dev/null | wc -l)
    [ "$c" -gt 0 ] && alert "failed-$b" "$c file(s) in failed/$b -- these do not replay on their own."
done

log "ok spool=$spool failed_sql=$failed_sql daemon=$daemon_up importers_killed=${#cron_importers[@]}"
