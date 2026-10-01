# Why CDRs stopped every morning on BTCL

**Cause:** unattended-upgrades + needrestart. Whenever a morning security update
touched a shared library (openssl on 2026-10-01, libevent on 09-30, curl/libexpat
on 09-26), needrestart restarted every dependent service at once - including
`freeswitch`, `postgresql@16-main`, `pgbouncer` and `xml_cdr`. The importer
started before Postgres was accepting connections and got stuck in an unbounded
reconnect loop: process alive, `active (running)`, 0 CPU, no socket. 2026-10-01:
asleep 06:34 -> 19:43, 430k files queued, CDR table 13 h behind. FreeSWITCH was
restarted at the same moment, dropping live calls.

**Fixes (all in `deploy/system/` and `patches/`):**

| file | goes to | effect |
|---|---|---|
| `needrestart-50-pbx.conf` | `/etc/needrestart/conf.d/50-pbx.conf` | freeswitch / postgres / pgbouncer never auto-restarted |
| `xml_cdr-10-db-ordering.conf` | `/etc/systemd/system/xml_cdr.service.d/` | importer starts after the DB; `RestartSec=10` |
| `apt-daily-upgrade-10-quiet-hour.conf` | `/etc/systemd/system/apt-daily-upgrade.timer.d/` | upgrades at 03:30, not ~06:00-07:00 |
| `patches/xml_cdr-service-db-reconnect.patch` | FusionPBX core | importer exits after 10 failed connects so systemd restarts it |
| `jobs/cdr-pipeline-guard.sh` | `/usr/local/sbin/` | restarts an importer that is "active" but using no CPU |

After any of the protected services gets a library update, restart it in a
maintenance window: `needrestart -b` shows which ones are pending.
