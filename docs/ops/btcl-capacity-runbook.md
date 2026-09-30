# BTCL PBX capacity runbook

Getting `114.130.145.82` from "57 domains saturate it" to a node whose ceiling is
known, so the node count for 1000 PBXs can be calculated instead of guessed.

Every step is independent and reversible. Do them in order — each one makes the
next safer — and re-run **Step 0** between steps so you can attribute the change.

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

> **Do not run `swapoff -a` on this box yet.** It forces all 1.8 GB back into RAM, and
> there are 289 MB free. It will OOM and the OOM killer's most attractive target is the
> 3.7 GB FreeSWITCH process. Only consider it *after* Step 4 has freed the memory.

**Verify:** swap-out should stop growing. `si`/`so` in `vmstat 1 5` should read 0.
Existing swap will not come back on its own — that is expected, and Step 4 fixes it.

**Rollback:** `sysctl -w vm.swappiness=60`, delete the file.

---

## Step 3 — shrink the database before moving it

**Why.** 166 GB, and nearly all of it is CDR. Trimming first turns a multi-hour
migration into a short one, and cuts what sbc1 has to hold. Growth is ~6.6 GB/day, so
this is worth doing regardless of the move.

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

## Step 4 — move PostgreSQL to an LXC on sbc1

**Why.** Postgres is 22.7% CPU, ~46 processes on a run queue of 59, and the single
biggest claim on 12 GB of RAM. Moving it is what actually ends the swapping.

**The catch, measured:** `ip route get 192.168.24.101` → `via 114.130.145.81 dev enp1s0`.
sbc1 is reached over the *same* NIC that Step 1 addresses. There is no separate storage
LAN. 273 tx/s is small against the SIP load so this should still net out positive — but
**do Step 1 first**. Latency today: 0.316 ms avg, max 1.95 ms, 0% loss over 20 packets.

**Confirm before starting** (no access from here — `telcobright`, `root` and `ubuntu`
were all refused on 192.168.24.101):

- cores and RAM free on sbc1 after the existing MySQL / TelcoREST containers
- disk ≥ trimmed DB size + 6.6 GB/day, on the LXC's storage pool
- a static 192.168.24.x for the container
- accepted: this puts the database and the SBC in one failure domain. If sbc1 dies you
  lose call routing *and* the database. If that is not acceptable, the LXC belongs on
  different hardware — the rest of this step is unchanged either way.

**Good news:** the primary is already replication-ready, so no restart is needed:

```
wal_level = replica      max_wal_senders = 10
hot_standby = on         max_replication_slots = 10
```

### 4a. On the primary (114.130.145.82)

```bash
sudo -u postgres psql -c "create role replicator with replication login password '<strong>';"
sudo -u postgres psql -c "select pg_create_physical_replication_slot('sbc1_pg');"
# allow only the new container
echo "host replication replicator 192.168.24.<LXC>/32 scram-sha-256" >> /etc/postgresql/16/main/pg_hba.conf
systemctl reload postgresql          # reload, not restart
```

### 4b. In the LXC (PostgreSQL 16 — must match 16.x)

```bash
systemctl stop postgresql
rm -rf /var/lib/postgresql/16/main/*
sudo -u postgres pg_basebackup \
  -h 114.130.145.82 -U replicator -D /var/lib/postgresql/16/main \
  -Fp -Xs -P -R -S sbc1_pg
systemctl start postgresql
```

`-R` writes `standby.signal` and `primary_conninfo` for you.

### 4c. Wait for catch-up — do not cut over before this is near zero

```bash
# on the primary
sudo -u postgres psql -c "select client_addr, state,
  pg_size_pretty(pg_wal_lsn_diff(pg_current_wal_lsn(), replay_lsn)) as lag
  from pg_stat_replication;"
```

### 4d. Cutover (seconds, in a quiet window)

```bash
systemctl stop freeswitch nginx php8.3-fpm     # stop writers on the PBX
# confirm lag is 0, then on the LXC:
sudo -u postgres pg_ctl promote -D /var/lib/postgresql/16/main
# on the PBX, repoint FusionPBX and pgbouncer:
sed -i 's/^database.0.host.*/database.0.host = 192.168.24.<LXC>/' /etc/fusionpbx/config.conf
#   also update pgbouncer's [databases] host, then:
systemctl restart pgbouncer && systemctl start php8.3-fpm nginx freeswitch
```

**Verify:**

```bash
fs_cli -x "status"                  # FreeSWITCH up, taking calls
psql -h 192.168.24.<LXC> -U fusionpbx -d fusionpbx -c "select count(*) from v_domains;"
free -m                             # free RAM should jump; swap stops growing
```
Then place a real test call and re-run Step 0.

**Rollback:** the old primary still holds the data. Point `config.conf` and pgbouncer
back at `127.0.0.1`, restart, and you are where you started — **as long as you do it
before writes accumulate on the new primary**. After that, rolling back means
replicating in the other direction. Decide the point of no return in advance.

**Afterwards:** `listen_addresses` is `*` and port 5432 is open to the internet. Once
the move is done, bind the new primary to the 192.168.24.x interface only and firewall
5432 to the PBX. Do not carry the exposure across.

---

## Step 5 — the CDR write path (decide, do not pre-commit)

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

## Step 6 — find the real ceiling, then size for 1000

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
