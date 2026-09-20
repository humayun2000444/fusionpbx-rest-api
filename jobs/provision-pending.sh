#!/usr/bin/env bash
# Create SFTP accounts requested from the portal.
#
#   provision-pending.sh [--apply]      (default: dry run)
#
# Runs as root from cron. recording-sftp-enable.php writes a request file per
# domain; this turns each into a real account via provision-tenant.sh. The web
# process deliberately cannot do this itself -- it runs as www-data, and a web
# process that can add Unix users and write /etc/ssh is a worse problem than the
# friction it saves.
set -euo pipefail

SPOOL=/var/spool/fusionpbx/sftp-provision
APPLY=0; [[ "${1:-}" == "--apply" ]] && APPLY=1
[[ $EUID -eq 0 ]] || { echo "must run as root" >&2; exit 1; }
[[ $APPLY -eq 0 ]] && echo "=== DRY RUN (pass --apply) ==="
[[ -d "$SPOOL" ]] || { echo "no requests"; exit 0; }

shopt -s nullglob
found=0
for f in "$SPOOL"/*; do
    domain=$(head -1 "$f" | tr -d '\r\n')
    [[ -n "$domain" ]] || { echo "empty request $(basename "$f") — removing"; [[ $APPLY -eq 1 ]] && rm -f "$f"; continue; }
    found=$((found+1))

    if [[ $APPLY -eq 0 ]]; then
        echo "would provision: $domain"
        continue
    fi

    echo "--- provisioning $domain"
    if SFTP_HOST="${SFTP_HOST:-}" /usr/local/sbin/provision-tenant.sh "$domain"; then
        rm -f "$f"
        # Stage what they already have, so the folder is not empty the first
        # time they connect. An empty folder reads as "it did not work".
        /usr/local/sbin/stage-recordings.sh >/dev/null 2>&1 || true

        # Tell the customer. Until this existed they were shown "usually ready
        # within the hour" and then nothing, so the only way to find out was to
        # keep reloading the page. Best-effort: the account already exists, so a
        # failure to announce it must not fail the provisioning -- hence || true.
        # SYSTEM_ACCESS_KEY unlocks the domain-to-partner lookup, which is
        # service-key gated because it answers with a partner's address.
        # Without it the job still sends, just to the fallback address.
        SYSTEM_ACCESS_KEY="${SYSTEM_ACCESS_KEY:-}" \
        php /var/www/fusionpbx/app/rest_api/jobs/recording-sftp-ready.php "$domain" \
            >> /var/log/sftp-ready.log 2>&1 || true

        echo "    done"
    else
        # Leave the request in place so the next run retries rather than
        # silently dropping a customer who asked and heard nothing.
        echo "    FAILED — request kept for retry" >&2
    fi
done
[[ $found -eq 0 ]] && echo "no requests"
exit 0
