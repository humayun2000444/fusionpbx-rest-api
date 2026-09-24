# Patches to FusionPBX core files

These are changes to files that ship with FusionPBX, not to this app. They live
here because a FusionPBX upgrade overwrites them **silently** — nothing warns
you, the symptom just comes back. After any upgrade, re-check each patch below.

Apply from the FusionPBX root (`/var/www/fusionpbx`):

    patch -p1 --dry-run < patches/<name>.patch   # check first
    patch -p1 < patches/<name>.patch

## xml_cdr-service-readdir.patch

`app/xml_cdr/resources/service/xml_cdr.php`, applied to **BTCL** 2026-09-24.
Backup on the box: `xml_cdr.php.bak-20260924-144811`. Restart after applying:
`systemctl restart xml_cdr`.

The service loop collected its next batch with

    array_slice(glob($xml_cdr_dir . '/*.cdr.xml'), 0, 100)

`glob()` builds and sorts the whole directory before the slice discards all but
100, so the cost of fetching a fixed 100 files grew with the spool. Measured on
the live box with 580,000 files queued:

    glob    100 files in 2.496s
    readdir 100 files in 0.007s

The replacement stops at 100 matches, so a pass costs the same whether the spool
holds a hundred files or a million. Safe because every file the loop touches is
unlinked on success or moved under `failed/` on error, so nothing is read twice;
losing `glob()`'s alphabetical order costs nothing, since the names are uuids
and that ordering was arbitrary.

Effect: the spool had been growing ~82,000/hour and began draining ~8,200 per
5 minutes.

**Second change in the same patch: skip CDRs already in the database.**

Added 2026-09-24 after a customer (stax) reported extensions 103, 106 and 109
showing far fewer calls than they had actually made. They were right, and the
cause was not the backlog.

**Two importers were running against the same spool.** `xml_cdr.service` (the
daemon) and a cron entry in www-data's crontab firing every minute:

    * * * * * /usr/bin/flock -n /tmp/xml_cdr_import.lock sh -c 'cd /var/www/fusionpbx && php app/xml_cdr/xml_cdr_import.php 5000'

The `flock` only stopped two *cron* runs overlapping; it knew nothing about the
daemon. Both picked up the same files. One inserted the row, the other raised
23505 on `v_xml_cdr_pkey`, which **aborts the transaction** — so every statement
after it failed with 25P02 `current transaction is aborted`, `save()` returned
falsy for the *whole batch*, and every file in it was moved to `failed/sql`
**even though its row had already committed**. 84,742 files piled up that way in
one day, and replaying them just raised fresh duplicates that took down more
batches.

The cron entry is now commented out — **that crontab is `chattr +i +a`**, so
editing it needs `chattr -i -a` first, then `chattr +i +a` after; without that,
`crontab -u` fails with `rename: Operation not permitted` and a plain `cp`
silently does nothing. Run ONE importer.

The patch adds a belt-and-braces guard: one indexed lookup per batch against the
primary key, and any file whose CDR is already recorded is unlinked instead of
being allowed to reach the transaction. Measured after both changes: new
failures went from ~440 per 90 seconds to **0**.

## replay-failed-cdr.sh

`jobs/replay-failed-cdr.sh`, installed at `/usr/local/sbin/`. Moves files out of
`failed/sql` back into the spool in throttled batches, backing off if the spool
climbs past 250k. Only safe **with** the dedupe patch above — without it,
replaying already-imported files is what feeds the cascade.

Note this was not the whole story — see
`migrations/20260924-cdr-admin-query-performance.sql`. The importer was also
being starved of disk I/O by un-indexed CDR page queries seq scanning 11 GB.
