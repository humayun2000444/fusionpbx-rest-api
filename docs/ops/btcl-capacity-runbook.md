# BTCL PBX capacity runbook

Getting `114.130.145.82` from "57 domains saturate it" to a node whose ceiling is
known, so the node count for 1000 PBXs can be calculated instead of guessed.

Every step is independent and reversible. Do them in order — each one makes the
next safer — and re-run **Step 0** between steps so you can attribute the change.

**Step 4 changed on 2026-09-30:** moving PostgreSQL to an sbc1 LXC was declined, so
Step 4 is now the memory problem that move was going to solve, and the database
exposure it would have closed is its own Step 5.

Nothing here has been applied. Measurements are from 2026-09-30 ~19:00 (+06).

---

## Baseline as measured

```
hardware        8 cores, 12 GB RAM, Ubuntu 24.04
scale           57 domains, 217 extensions, 59 gateways
load            31.30 / 24.96 / 20.01      r=59 runnable
cpu             us 83%  sy 15%  id 1%
interrupts      191,194/s     context switches 117,814/s
memory          289 MB free, 1.8 GB in swap, swappiness=60
nic             enp1s0 virtio_net, Combined queues 1 (max 1), RPS disabled
freeswitch      142% CPU, 3.7 GB RSS, 286 sessions/sec (peak 558), no transcoding
postgres        16.15, 22.7% CPU, 166 GB database, 273 transactions/sec
cdr spool       476,155 files in /var/log/freeswitch/xml_cdr, importer pinned ~25% of a core
call volume     961,240 attempts today, 99.9% from one tenant, 0.2% answered since 14:00
```

The load is signalling, not media: live channels were 74 PCMU→PCMU and 7 PCMA→PCMA,
so there is no transcoding to tune away.

---

## Step 0 — measure (run before and after every step)

```bash
ssh btcl '
date "+%F %T"
uptime
vmstat 1 3 | tail -1
free -m | head -2
fs_cli -x "status" | grep -E "session|idle"
ls -1 /var/log/freeswitch/xml_cdr | wc -l
'
```

Record: load, `r` (runnable), `in`/`cs`, free MB, swap used, sessions/sec, spool count.

Post-dial delay is the number the customer actually feels. `docs/ops/` queries aside,
this one answers "did it get better" for any tenant:

```sql
select to_char(to_timestamp(start_epoch) at time zone 'Asia/Dhaka','HH24') as hr,
       count(*) as calls, round(avg(pdd_ms)) as avg_pdd_ms,
       count(*) filter (where pdd_ms > 5000) as pdd_over_5s,
       count(*) filter (where pdd_ms = 0 or pdd_ms is null) as no_progress
from v_xml_cdr
where domain_uuid = (select domain_uuid from v_domains where domain_name = :'d')
  and start_epoch >= extract(epoch from (now() at time zone 'Asia/Dhaka')::date at time zone 'Asia/Dhaka')
group by 1 order by 1;
```

Reference (pbx-stax-349, 2026-09-30): avg PDD 2.3–3.4 s, max 15.5 s, 5–17 calls/hour
with no call progress at all. That is the "silent Calling then Temporarily Unavailable"
complaint, in numbers.

---

## Step 1 — spread packet receive across cores (RPS)

**Why.** `enp1s0` has one receive queue, so all 191k interrupts/s are processed by a
single core's softirq. More cores cannot help until that is spread.

**Correction to note:** real multiqueue is *not* available from inside the guest —
`ethtool -l` reports `Combined: 1` as the pre-set **maximum**, which means the
hypervisor only handed the VM one queue. `ethtool -L` will fail. Raising it needs a
change on the KVM host (`<driver name='vhost' queues='8'/>`) **and a VM restart**, so
schedule that separately. RPS is the software equivalent, applies live, no reboot.

```bash
# core 0 keeps the IRQ, cores 1-7 process the packets
echo fe    > /sys/class/net/enp1s0/queues/rx-0/rps_cpus
echo 32768 > /sys/class/net/enp1s0/queues/rx-0/rps_flow_cnt
sysctl -w net.core.rps_sock_flow_entries=32768
sysctl -w net.core.netdev_max_backlog=16384
```

**Verify** — `sy` and softirq time should fall and spread:

```bash
cat /sys/class/net/enp1s0/queues/rx-0/rps_cpus     # expect: fe
mpstat -P ALL 1 3 | awk '/soft|Average/'           # %soft should appear on several cores
vmstat 1 3 | tail -1                               # expect r and cs to drop
```

**Persist** once verified:

```bash
cat >/etc/sysctl.d/99-pbx-net.conf <<'EOF'
net.core.rps_sock_flow_entries = 32768
net.core.netdev_max_backlog = 16384
EOF
cat >/etc/systemd/system/rps-enp1s0.service <<'EOF'
[Unit]
Description=RPS for enp1s0
After=network-online.target
[Service]
Type=oneshot
RemainAfterExit=yes
ExecStart=/bin/sh -c 'echo fe > /sys/class/net/enp1s0/queues/rx-0/rps_cpus; echo 32768 > /sys/class/net/enp1s0/queues/rx-0/rps_flow_cnt'
[Install]
WantedBy=multi-user.target
EOF
systemctl daemon-reload && systemctl enable --now rps-enp1s0
```

**Rollback:** `echo 00 > /sys/class/net/enp1s0/queues/rx-0/rps_cpus` (and disable the unit).
RPS is a scheduling hint; turning it off is immediate and safe.

---

## Step 2 — stop paging the SIP stack

**Why.** 1.8 GB is swapped out with 289 MB free. FreeSWITCH is 3.7 GB RSS. Paging a
realtime process directly lengthens call setup, and is the leading explanation for the
15-second silent calls.

```bash
sysctl -w vm.swappiness=10
sysctl -w vm.vfs_cache_pressure=50
echo -e 'vm.swappiness = 10\nvm.vfs_cache_pressure = 50' >/etc/sysctl.d/99-pbx-vm.conf
```

> **Do not run `swapoff -a` on this box.** It forces all 1.8 GB back into RAM, and there
> are ~300 MB free. It will OOM, and the OOM killer's most attractive target is the
> 3.7 GB FreeSWITCH process. It stays unsafe until Step 4 creates real headroom.

**Verify:** swap-out should stop growing. `si`/`so` in `vmstat 1 5` should read 0.

This lowers the *rate* of new paging; it does not reclaim the 1.8 GB already out, and
nothing here will, because the process that would have freed it is no longer being
moved. Step 4 is what actually fixes the shortage.

**Rollback:** `sysctl -w vm.swappiness=60`, delete the file.

---

## Step 3 — shrink the database

**Why.** 166 GB, and nearly all of it is CDR, growing ~6.6 GB/day. With the database
staying on this box (Step 4), every gigabyte of it competes with FreeSWITCH for the same
RAM and the same disk. This was originally about shortening a migration; now it is one of
the few ways to give memory back without buying any.

Existing tooling on the box: `/usr/local/sbin/trim-cdr-side-tables.sh`, with indexes
already in place from migration `20260928-dashboard-and-trim-indexes.sql`.

```bash
# what retention is actually set, and what each table costs
psql -c "select pg_size_pretty(pg_total_relation_size('v_xml_cdr')) as cdr,
                pg_size_pretty(pg_total_relation_size('v_xml_cdr_json')) as json,
                pg_size_pretty(pg_total_relation_size('v_xml_cdr_flow')) as flow;"
```

Run the trim in **bounded batches under `flock`** — an unbounded `while :` loop with no
lock is what previously left four overlapping runs hammering the disk for days.

**Verify:** row counts and `pg_database_size` fall; Step 0's numbers do not get worse
while it runs. Stop it if `r` or PDD climb — it is not urgent enough to hurt calls.

**Rollback:** none — deleted CDR rows are gone. Confirm the retention figure with the
business **before** running, and make sure the export pipeline has delivered anything a
customer is owed (`exported_at`).

---

## Step 4 — memory headroom, with PostgreSQL staying put

**Decision (user, 2026-09-30): the Postgres-to-sbc1-LXC move is skipped.** Recorded here
with its reasoning so it is not re-proposed blind, and so what it was solving does not
get lost with it.

*Why it was proposed:* Postgres is 22.7% CPU, ~46 processes on a run queue of 59, and the
largest claim on 12 GB of RAM. Moving it was the most direct way to end the swapping.
*Why it was declined:* it puts the database and the SBC in one failure domain, and sbc1
is reached over the same saturated `enp1s0` that Step 1 addresses.

**What that leaves unsolved.** The box has ~300 MB free with 1.8 GB already swapped, and
FreeSWITCH alone is 3.7 GB RSS. Paging a realtime SIP process is the leading explanation
for the 15-second silent calls. Step 2 stops it getting worse; it cannot give the memory
back. Without the move, there are three levers, and only the first is decisive.

### 4a. Add RAM to the VM — the actual fix

12 GB is not enough for FreeSWITCH plus PostgreSQL plus a 166 GB database plus nginx and
php-fpm. 24 GB would give the page cache room to do its job and take the swap pressure
off; 32 GB leaves headroom for growth. Hypervisor change, needs a VM restart unless
memory hotplug is already configured.

**Verify:** `free -m` shows real free memory; `vmstat` `si`/`so` stay at 0 under load.
Only once there is genuine headroom does `swapoff -a && swapon -a` become safe — and that
is the step that finally clears the 1.8 GB.

### 4b. Right-size PostgreSQL's connection headroom

```bash
psql -c "show max_connections;"   # currently 500, with 45 actually active
```

pgbouncer already pools in front on `127.0.0.1:6432`, so 500 backends is headroom that
cannot be used and each one reserves memory. Dropping it to ~150 is safe with pgbouncer
in place and returns memory for nothing. `shared_buffers` (1 GB) and `work_mem` (4 MB)
are already conservative — leave them alone.

**Verify:** the portal and FreeSWITCH keep working under load; `pg_stat_activity` count
stays well under the new limit. **Rollback:** raise it back and restart Postgres.

### 4c. Step 3 matters more now

With the database staying on the PBX, trimming CDR is no longer just about shrinking a
migration — it directly reduces Postgres's working set and its IO on the same spindles
FreeSWITCH is using. Do not skip it.

---

## Step 5 — close the PostgreSQL exposure

This was originally folded into the move. It stands on its own now, and it does not
depend on anything else in this runbook.

```bash
psql -c "show listen_addresses;"      # currently: *
ss -lntp | grep 5432                  # currently: 0.0.0.0:5432 and [::]:5432
```

The database is listening on every interface, on a box with a public IP. FusionPBX and
pgbouncer both reach it over loopback, so nothing local needs the public binding.

```bash
# /etc/postgresql/16/main/postgresql.conf
listen_addresses = 'localhost'
```

**Verify:** `ss -lntp | grep 5432` shows only `127.0.0.1`; the portal still loads and
`fs_cli -x "status"` still reports healthy. **Rollback:** restore the previous value and
restart. If anything genuinely needs remote access, bind that interface specifically and
firewall the port to known sources rather than reverting to `*`.

---

## Step 6 — the CDR write path (decide, do not pre-commit)

**Correction:** `mod_odbc_cdr` is **not available** on this box. `/usr/lib/freeswitch/mod/`
has only `mod_cdr_csv`, `mod_cdr_sqlite` and `mod_xml_cdr`, and `apt-cache policy
freeswitch-mod-odbc-cdr` returns nothing. Switching to it would mean building it for
1.10.12 — not a config change, and not something to do during an incident.

What is true today: 286 CDR files/sec into a 476k-entry directory, with a single
importer daemon (`app/xml_cdr/resources/service/xml_cdr.php`) at ~25% of a core that
cannot keep up. `log-b-leg` is already `false`, which is the one easy halving and it is
already done.

The honest sequencing: **this is mostly a volume problem, not a config problem.** One
tenant is 99.9% of attempts. Once the SBC enforces per-customer CPS, the CDR rate falls
in proportion. Re-measure the spool after that before spending effort here.

If it is still behind afterwards, in order of preference:

1. Re-measure with Steps 1–4 applied — the importer may simply have been starved of CPU
   and IO by everything else on the box.
2. Raise importer batch size. Note the trap: `xml_cdr_import.php` defaults to **one file
   per run** and BTCL's cron passes no argument.
3. Only then consider more importers — and **not** without the dedupe guard. Two
   importers on one spool is what previously dumped whole batches into `failed/`: a
   duplicate key aborts the transaction and every later statement in it fails. The guard
   lives in `jobs/cdr-pipeline-guard.sh`.

**Rollback:** none needed — no change is being prescribed here yet.

---

## Step 7 — find the real ceiling, then size for 1000

With Steps 1–4 in place, measure what one node actually sustains rather than estimating:

1. Pick a quiet window. Record Step 0.
2. Let the offered rate rise (or generate it) and watch for the knee — where PDD starts
   climbing and `r` exceeds core count.
3. Record calls/sec **and** domains at that knee.

`sessions/sec` alone is not the ceiling. The ceiling is the rate at which **PDD stays
under ~1 s** and no calls show `pdd_ms = 0`. A box can post a high sessions/sec while
every one of those calls is failing — which is exactly what today's 286/sec is.

Node count for 1000 PBXs = 1000 ÷ (domains per node at the knee), plus headroom for the
fact that tenant behaviour is not uniform — one innoversal-shaped tenant consumed a
whole node's worth of capacity on its own.

---

## What is deliberately not in here

- **Per-domain call limits in the dialplan.** Decided against: per-customer CPS is being
  enforced at the SBC instead. Worth remembering that the FusionPBX `limit` app has
  previously matched nothing and routed every external inbound call to `limit_exceeded`
  for months, silently. If it is ever revisited, it goes in per-domain and guarded.
- **Throttling or blocking innoversal-345.** They are a customer; the platform is being
  sized for the load rather than shedding it.
- **Moving PostgreSQL to sbc1.** Declined — see Step 4 for the reasoning on both sides,
  and for what has to happen instead now that it is off the table.
