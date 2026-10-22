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
#   2b. sharded import (jobs/xml_cdr-shards): a shard down -> start it; importers
#       disagreeing on the shard count -> restart them all together
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

# Number of daemon importers (jobs/xml_cdr-shards). 1 = the stock single daemon.
SHARDS=1
if [ -r /etc/default/xml_cdr-shards ]; then
    SHARDS=$(sed -n 's/^XML_CDR_SHARDS=\([0-9][0-9]*\).*/\1/p' /etc/default/xml_cdr-shards | tail -1)
fi
[ -n "$SHARDS" ] || SHARDS=1

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

# --- 0. which importer is this box supposed to run? --------------------------
# BTCL runs the daemon (xml_cdr.service). CCL has no unit file at all and runs
# xml_cdr_import.php from cron. Getting this backwards is dangerous: on a
# cron-only box, "start the daemon" would create the very daemon-plus-cron race
# this script exists to prevent. So detect rather than assume.
if systemctl cat "$SERVICE" >/dev/null 2>&1; then
    MODE=daemon
else
    MODE=cron
fi
[ -n "${IMPORTER_MODE:-}" ] && MODE="$IMPORTER_MODE"

daemon_up=$(systemctl is-active "$SERVICE" 2>/dev/null || echo inactive)

# Only the PHP process itself counts as an importer.
#
# pgrep -f 'xml_cdr_import\.php' matches the whole chain a single cron tick
# produces -- cron's "sh -c", the flock wrapper, and php -- because the script
# path appears in all three command lines. It also matches any shell that merely
# mentions the name, this script's own callers included. Counting those made one
# legitimate invocation look like three concurrent importers, and the cron-mode
# branch below then "kept the oldest" (the sh wrapper) and killed the real php.
# It did that every five minutes on CCL until 2026-09-26.
#
# So: filter to processes whose argv[0] is the interpreter.
importers_now() {
    local pid cmd first
    for pid in $(pgrep -f 'xml_cdr_import\.php' 2>/dev/null); do
        [ -r "/proc/$pid/cmdline" ] || continue
        cmd=$(tr '\0' ' ' < "/proc/$pid/cmdline" 2>/dev/null)
        case "$cmd" in
            *xml_cdr_import.php*) : ;;
            *) continue ;;
        esac
        first=${cmd%% *}
        case "${first##*/}" in
            php|php[0-9]*) echo "$pid" ;;
        esac
    done
}
mapfile -t cron_importers < <(importers_now)
killed=0

# --- 1. exactly one importer -------------------------------------------------
if [ "$MODE" = daemon ]; then
    # The daemon is the keeper; any xml_cdr_import.php is the 2026-09-24 bug.
    if [ "$daemon_up" = "active" ] && [ "${#cron_importers[@]}" -gt 0 ]; then
        alert dual-importer \
            "xml_cdr_import.php (pids: ${cron_importers[*]}) running while $SERVICE is active. \
This is what corrupted CDR reporting on 2026-09-24: both importers take the same files, \
the loser raises a duplicate key, the transaction aborts and the whole batch is discarded \
into failed/sql. Killing it. Check for a re-added cron entry -- www-data's crontab is chattr +i +a."
        for p in "${cron_importers[@]}"; do kill -9 "$p" 2>/dev/null; killed=$((killed+1)); done
        # Same discipline as importers_now(): match the flock binary itself, not
        # any process whose command line happens to mention the lock path -- a
        # grep or an editor would qualify under a bare pkill -f.
        for _p in $(pgrep -f 'xml_cdr_import\.lock' 2>/dev/null); do
            [ -r "/proc/$_p/cmdline" ] || continue
            _c=$(tr '\0' ' ' < "/proc/$_p/cmdline" 2>/dev/null)
            case "${_c%% *}" in
                */flock|flock) kill -9 "$_p" 2>/dev/null ;;
            esac
        done
        log "killed $killed stray importer process(es)"
    fi

    # --- 2. the daemon must be running ---------------------------------------
    if [ "$daemon_up" != "active" ]; then
        alert daemon-down "$SERVICE is $daemon_up -- starting it. Nothing imports CDRs while it is down."
        systemctl start "$SERVICE" 2>/dev/null
    fi

    # --- 2b. sharded import ----------------------------------------------------
    # With XML_CDR_SHARDS=N the daemon runs as N processes: xml_cdr.service is
    # shard 0 and xml_cdr-shard@1..N-1 take the rest. Each owns the files that
    # hash to its slot, so they never meet -- but only while every running
    # importer uses the same N. One started without shards= takes EVERY file,
    # and next to running shards that is the 2026-09-24 race again. A shard that
    # is down leaves 1 in N of all CDRs unimported, silently.
    if [ "$SHARDS" -gt 1 ] && ! grep -q 'another shard owns this file' "$SVC_FILE" 2>/dev/null; then
        # Fails safe -- the stock importer shares one pid file, so only one of
        # the N can run -- but the import is back to a single process.
        alert shard-patch-missing \
            "XML_CDR_SHARDS=$SHARDS but $SVC_FILE is not shard-aware (a FusionPBX upgrade probably \
reverted it). Only one importer can run. Re-apply rest_api patches/xml_cdr-service-*.patch and \
re-run jobs/xml_cdr-shards/install.sh $SHARDS."
    fi
    if [ "$daemon_up" = "active" ]; then
        mismatch=""
        for _pid in $(pgrep -f 'resources/service/xml_cdr\.php' 2>/dev/null); do
            [ -r "/proc/$_pid/cmdline" ] || continue
            _c=$(tr '\0' ' ' < "/proc/$_pid/cmdline" 2>/dev/null)
            _f=${_c%% *}
            case "${_f##*/}" in php|php[0-9]*) ;; *) continue ;; esac
            _s=$(grep -o 'shards=[0-9]*' <<< "$_c" | head -1 | cut -d= -f2)
            [ "${_s:-1}" = "$SHARDS" ] || mismatch="$mismatch $_pid(shards=${_s:-1})"
        done
        if [ -n "$mismatch" ]; then
            alert shard-mismatch \
                "CDR importers running with a shard count other than XML_CDR_SHARDS=$SHARDS:$mismatch. \
Importers that disagree on the slot map take the same files (the 2026-09-24 race). \
Stopping all of them and starting $SERVICE and its shards together."
            systemctl stop "$SERVICE" 2>/dev/null
            systemctl stop 'xml_cdr-shard@*' 2>/dev/null
            systemctl start "$SERVICE" 2>/dev/null
            log "restarted $SERVICE and its shards (shard-mismatch)"
        elif [ "$SHARDS" -gt 1 ]; then
            for i in $(seq 1 $((SHARDS - 1))); do
                st=$(systemctl is-active "xml_cdr-shard@$i" 2>/dev/null || true)
                if [ "$st" != "active" ]; then
                    alert "shard-$i-down" "xml_cdr-shard@$i is ${st:-unknown} -- starting it. \
1 in $SHARDS CDR files is not imported while it is down."
                    systemctl start "xml_cdr-shard@$i" 2>/dev/null
                fi
            done
        fi
    fi
else
    # Cron-only box. Never start a daemon here -- that would CREATE the race.
    if [ "$daemon_up" = "active" ]; then
        alert daemon-on-cron-box \
            "$SERVICE is active on a cron-driven box. That is two importers on one spool, which is \
exactly the 2026-09-24 failure. NOT stopping it automatically -- decide which one this box should \
run, then disable the other."
    fi
    # More than one concurrent cron importer is the same race. Keep the oldest.
    if [ "${#cron_importers[@]}" -gt 1 ]; then
        keep=$(ps -o pid= --sort=start_time -p "${cron_importers[*]}" 2>/dev/null | head -1 | tr -d ' ')
        alert concurrent-cron-importers \
            "${#cron_importers[@]} copies of xml_cdr_import.php running at once (pids: ${cron_importers[*]}). \
They share one spool with no lock, so they take the same files and raise duplicate keys, which abort \
the transaction and discard whole batches. Keeping $keep, killing the rest. Fix the crontab: one \
entry, wrapped in flock."
        for p in "${cron_importers[@]}"; do
            [ "$p" = "$keep" ] && continue
            kill -9 "$p" 2>/dev/null; killed=$((killed+1))
        done
        log "killed $killed duplicate cron importer(s), kept $keep"
    fi
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

# --- 5. liveness: an "active" daemon that is importing nothing ----------------
# systemd only knows whether the process still exists. On 2026-10-01 it existed
# and slept for 10 hours inside an unbounded database-reconnect loop while the
# spool grew to 324,000 files - and every check above reported healthy the whole
# time ("daemon=active importers=0"), with spool-depth alerting into the void.
# The CDR table ended up 617 minutes behind before a human noticed.
#
# The signal that catches it is CPU time: an importer with files in front of it
# always burns some. Zero ticks across a whole interval, with work waiting, means
# asleep rather than slow.
#
# Every importer is checked when the import is sharded: one asleep shard leaves
# 1 in N CDRs behind while the others look busy. (An importer whose slice is
# empty still polls the spool every 100 ms, so it never shows zero ticks.)
CPU_STATE=/var/run/cdr-pipeline-guard.cpu
if [ "$MODE" = daemon ] && [ "$daemon_up" = active ] && [ "$spool" -gt 0 ]; then
    units="$SERVICE"
    if [ "$SHARDS" -gt 1 ]; then
        for i in $(seq 1 $((SHARDS - 1))); do units="$units xml_cdr-shard@$i"; done
    fi
    for unit in $units; do
        if [ "$unit" = "$SERVICE" ]; then
            cpu_state=$CPU_STATE; key=daemon-wedged
        else
            cpu_state="$CPU_STATE.${unit#xml_cdr-shard@}"; key="shard-${unit#xml_cdr-shard@}-wedged"
        fi
        svc_pid=$(systemctl show -p MainPID --value "$unit" 2>/dev/null)
        [ -n "$svc_pid" ] && [ "$svc_pid" != 0 ] && [ -r "/proc/$svc_pid/stat" ] || continue
        # fields 14 and 15 of /proc/<pid>/stat are utime and stime, in clock ticks
        ticks=$(awk '{print $14+$15}' "/proc/$svc_pid/stat" 2>/dev/null)
        prev_pid=""; prev_ticks=""
        if [ -r "$cpu_state" ]; then read -r prev_pid prev_ticks < "$cpu_state" || true; fi
        echo "$svc_pid $ticks" > "$cpu_state"

        if [ -n "$prev_ticks" ] && [ "$prev_pid" = "$svc_pid" ] && [ "$prev_ticks" = "$ticks" ]; then
            alert "$key" \
                "$unit (pid $svc_pid) has used NO cpu since the last check while $spool \
files wait. It is asleep, not slow -- restarting it. If this keeps happening, the bounded \
reconnect patch is missing: see rest_api patches/xml_cdr-service-db-reconnect.patch."
            if systemctl restart "$unit"; then
                log "restarted $unit (wedged: 0 cpu with $spool files queued)"
                rm -f "$cpu_state"
            else
                log "FAILED to restart $unit"
            fi
        fi
    done
fi

# --- 6. buckets that need a human --------------------------------------------
for b in xml size; do
    c=$(ls -U "$D/failed/$b" 2>/dev/null | wc -l)
    [ "$c" -gt 0 ] && alert "failed-$b" "$c file(s) in failed/$b -- these do not replay on their own."
done

log "ok mode=$MODE spool=$spool failed_sql=$failed_sql daemon=$daemon_up shards=$SHARDS importers=${#cron_importers[@]} killed=$killed"
