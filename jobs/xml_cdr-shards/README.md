# Parallel CDR import (xml_cdr shards)

For **daemon boxes only** (BTCL). CCL imports from cron and has no
`xml_cdr.service`, so `install.sh` refuses to run there.

## Why

On 2026-10-21 and 2026-10-22, BTCL's CDR spool grew from zero at 09:00 to over a
million files by 21:00, and drained only overnight (empty again by ~02:30).
Customers' Extension Summary and call history showed 1-2 hours of an 8-hour day.
No data was lost; it simply had not been imported yet.

| | CDRs per hour |
|---|---|
| Arriving, 09:00-12:00 | 173,000 - 202,000 |
| Arriving, 12:00-21:00 | 110,000 - 147,000 |
| One importer, night (idle CPU) | ~210,000 |
| One importer, day (CPU ~91% busy, load 25-90 on 8 cores) | ~58,000 |

The single importer spends most of each file waiting on Postgres, and in the
daytime it gets a small share of a saturated CPU. N importers get N shares, so
throughput scales with N even under load. The total work is the same CDRs, done
during the day instead of overnight. That adds roughly half a core of daytime
demand on an 8-core box.

## How

- `patches/xml_cdr-service-shard.patch` teaches `xml_cdr.php` two arguments,
  `shard=K&shards=N`. An importer only takes spool files where
  `crc32(filename) % N == K`, so no file is ever seen by two importers. That
  matters because two importers on one spool discarded whole batches into
  `failed/sql` on 2026-09-24. With no arguments it is the original importer.
  Shard 0 keeps the original pid file.
- `xml_cdr.service` becomes shard 0 (drop-in `20-shards.conf`).
- `xml_cdr-shard@1..N-1` are the extra importers. They are `WantedBy=` and
  `PartOf=` the main unit, so start, stop and restart always move all N together.
- N lives in `/etc/default/xml_cdr-shards` (`XML_CDR_SHARDS=N`).
- `jobs/cdr-pipeline-guard.sh` knows about the shards:
  - It starts a shard that is down.
  - It restarts the whole set if any importer runs with a different N.
  - It checks every importer for the 0-CPU "wedged" state.

  **Deploy the updated guard with it**: the old guard only watches `xml_cdr.service`.

## Install / change N / roll back (as root, on the box)

    cd /var/www/fusionpbx/app/rest_api && git pull        # or however rest_api is deployed
    install -m 755 jobs/cdr-pipeline-guard.sh /usr/local/sbin/cdr-pipeline-guard.sh
    jobs/xml_cdr-shards/install.sh 4                     # prints "OK: 4 importer(s) running"

    jobs/xml_cdr-shards/install.sh 3                     # change N later
    jobs/xml_cdr-shards/rollback.sh                      # back to one importer

`install.sh` backs up `xml_cdr.php` to `/var/backups/fusionpbx-patches/<ts>/`,
applies the patch (it skips this if already applied), installs the units, stops
every importer, then starts the new set, and verifies there are exactly N with
shard 0..N-1. An import cut off by the restart is safe: the dedupe guard drops
a file whose CDR already committed.

## Watch it

    grep 'ok mode' /var/log/cdr-pipeline-guard.log | tail     # spool= should fall during the day too
    pgrep -a -u www-data -f 'resources/service/xml_cdr\.php'  # N processes
    systemctl status 'xml_cdr-shard@*'

If calls suffer at peak (one-way audio, jitter), lower N with `install.sh 2` or
roll back. The real fix for the CPU is the capacity work in
memory `btcl-capacity-ceiling` (Postgres off the PBX box).

After a FusionPBX upgrade, `xml_cdr.php` is overwritten. That fails safe: the
stock importer ignores `shard=` and uses one pid file, so only the first process
to start runs, and the other shards exit at once. You are back to one importer,
never two. The guard alerts `shard-patch-missing` until you re-apply the earlier
`xml_cdr-service-*` patches and re-run `install.sh N`.
