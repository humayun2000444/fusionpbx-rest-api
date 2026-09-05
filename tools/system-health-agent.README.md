# system-health-agent.php

Writes this PBX box's CPU / RAM / disk into the shared `system_health_snapshot`
MySQL table so the admin dashboard's **PBX Server** card shows the FusionPBX
machine. The TelcoREST watchdog only ever measures the host TelcoREST runs on
(the RTC/gateway node), so without this agent the card reported the wrong box.

No TelcoREST change is needed: `SystemHealthController` already exposes
`GET /admin/system-health/latest?node=<node>` and the table is node-keyed.

## Install (per PBX box)

    apt-get install -y php8.3-mysql          # FusionPBX ships pgsql only
    install -m 0755 -o root -g root tools/system-health-agent.php \
        /usr/local/bin/tb-system-health-agent.php
    mkdir -p /etc/telcobright
    # /etc/telcobright/system-health-agent.conf  (root:root, chmod 0600)
    #   db_host = "<mysql ip>"
    #   db_port = "3306"
    #   db_name = "telcobright"
    #   db_user = "tbuser"
    #   db_pass = "..."          # quote it: parse_ini_file chokes on a bare $
    #   node    = "ccl-pbx-100"  # must match systemHealth.pbxNode in the dashboard
    # /etc/cron.d/tb-system-health:
    #   */2 * * * * root /usr/bin/flock -n /tmp/tb-health.lock \
    #       /usr/bin/php /usr/local/bin/tb-system-health-agent.php >/dev/null 2>&1

Test with `php /usr/local/bin/tb-system-health-agent.php --verbose`.

## Node names in use

| Box | node |
|---|---|
| CCL FusionPBX 103.95.96.100 | `ccl-pbx-100` |
| BTCL FusionPBX 114.130.145.82 | `btcl-pbx-82` |

The MySQL user must be granted from the PBX box's own IP — BTCL initially
returned `Host '114.130.145.82' is not allowed to connect to this MySQL server`.

Credentials live only in the conf file, never in this script, so it is safe to
version.
