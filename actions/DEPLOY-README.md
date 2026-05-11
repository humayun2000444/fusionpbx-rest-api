# FusionPBX REST API - Deployment Guide

## Quick Deploy to New Server

### 1. Copy PHP action files
```bash
sshpass -p 'PASSWORD' scp -P 22 -o StrictHostKeyChecking=no \
  /path/to/php-actions/*.php \
  user@SERVER:/tmp/

sshpass -p 'PASSWORD' ssh -P 22 user@SERVER \
  "sudo cp /tmp/*.php /var/www/fusionpbx/app/rest_api/actions/ && \
   sudo chown www-data:www-data /var/www/fusionpbx/app/rest_api/actions/*.php"
```

### 2. Copy Lua scripts (for Boss-Secretary busy check)
```bash
sshpass -p 'PASSWORD' scp -P 22 -o StrictHostKeyChecking=no \
  /path/to/php-actions/boss-secretary-busy-check.lua \
  user@SERVER:/tmp/

sshpass -p 'PASSWORD' ssh -P 22 user@SERVER \
  "sudo cp /tmp/boss-secretary-busy-check.lua /usr/share/freeswitch/scripts/"
```

### 3. Run database migrations
```bash
sshpass -p 'PASSWORD' ssh -P 22 user@SERVER \
  "sudo -u postgres psql -d fusionpbx < /tmp/SETUP-MIGRATIONS.sql"
```

### 4. Clear FusionPBX dialplan cache (after boss-secretary setup)
```bash
sudo rm -f /var/cache/fusionpbx/dialplan.*
sudo fs_cli -x 'reloadxml'
```

## Features Included

### Time Conditions
- `time-condition-create.php` - Create with minute-of-day/wday conditions
- `time-condition-list.php` - List by domain
- `time-condition-details.php` - Get details with condition groups
- `time-condition-update.php` - Update conditions + rebuild dialplan
- `time-condition-delete.php` - Delete + reload XML
- `time-condition-toggle.php` - Enable/disable

### Boss-Secretary
- `boss-secretary-create.php` - Create pair + generate dialplan XML
- `boss-secretary-list.php` - List pairs by domain
- `boss-secretary-details.php` - Get pair details
- `boss-secretary-update.php` - Update + regenerate dialplan
- `boss-secretary-delete.php` - Delete pair + remove dialplan
- `boss-secretary-mode.php` - Switch mode (filter_all/vip_only/off)
- `boss-secretary-dialplan.php` - Shared dialplan XML generator
- `boss-secretary-busy-check.lua` - Lua busy detection (goes to /usr/share/freeswitch/scripts/)

**Important:** Boss-Secretary requires `dialplan_xml` column in `v_dialplans` (FusionPBX Lua handler reads this).
After creating/updating a pair, clear the dialplan cache: `rm /var/cache/fusionpbx/dialplan.{domain}`

### Speed Dial
- `speed-dial-create.php` - Create speed dial (domain-wide or personal)
- `speed-dial-list.php` - List speed dials by domain
- `speed-dial-update.php` - Update speed dial
- `speed-dial-delete.php` - Delete speed dial
- `speed-dial-dialplan.php` - Auto-generate dialplan for *XX pattern
- `speed-dial-lookup.lua` - Lua lookup script (goes to /usr/share/freeswitch/scripts/)

**Features:** Domain-wide (shared) and personal (per-extension) speed dials.
Personal overrides domain. Secretary-to-boss speed dial bypasses boss-secretary filter.
Dialplan auto-generated at order 65 (before built-in *0 speed dial at 70).

### Predictive Dialer
- `call-broadcast-dialer.php` - Predictive pacing daemon
- `call-broadcast-report.php` - Campaign report API
- `call-broadcast-clone.php` - Clone campaign (settings + phone numbers, no leads)
- `call-broadcast-pause.php` - Pause/resume broadcast

### Call Center
- `call-center-queue-*.php` - Queue CRUD
- `call-center-agent-*.php` - Agent CRUD + status
- `call-center-tier-*.php` - Queue-agent assignments
- `call-center-eavesdrop.php` - Listen/whisper/barge

## File List (all PHP actions)
```
boss-secretary-create.php
boss-secretary-delete.php
boss-secretary-details.php
boss-secretary-dialplan.php
boss-secretary-list.php
boss-secretary-mode.php
boss-secretary-update.php
boss-secretary-busy-check.lua
call-broadcast-clone.php
call-broadcast-create.php
call-broadcast-delete.php
call-broadcast-details.php
call-broadcast-dialer.php
call-broadcast-lead-status.php
call-broadcast-list.php
call-broadcast-migrate-predictive.php
call-broadcast-pause.php
call-broadcast-report.php
call-broadcast-retry.php
call-broadcast-scheduler.php
call-broadcast-start.php
call-broadcast-stop.php
call-broadcast-update.php
call-broadcast-upload-leads.php
call-center-agent-create.php
call-center-agent-delete.php
call-center-agent-details.php
call-center-agent-list.php
call-center-agent-set-state.php
call-center-agent-set-status.php
call-center-agent-status.php
call-center-agent-update.php
call-center-eavesdrop.php
call-center-queue-create.php
call-center-queue-delete.php
call-center-queue-details.php
call-center-queue-list.php
call-center-queue-live.php
call-center-queue-status.php
call-center-queue-update.php
call-center-tier-add.php
call-center-tier-list.php
call-center-tier-remove.php
time-condition-create.php
time-condition-delete.php
time-condition-details.php
time-condition-list.php
time-condition-toggle.php
time-condition-update.php
speed-dial-create.php
speed-dial-delete.php
speed-dial-dialplan.php
speed-dial-list.php
speed-dial-lookup.lua
speed-dial-update.php
SETUP-MIGRATIONS.sql
DEPLOY-README.md
```
