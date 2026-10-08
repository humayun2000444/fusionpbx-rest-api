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

## xml_cdr-service-db-reconnect.patch

`app/xml_cdr/resources/service/xml_cdr.php`. **Not yet applied anywhere.**

The service guarded its batch with an unbounded reconnect:

    while (!$database->is_connected()) {
        $database->connect();
        sleep(3);
    }

A supervised process that never exits cannot be restarted by its supervisor. On
2026-10-01 the BTCL service sat `active (running)` for 10 hours having used
26.7s of CPU, holding no TCP socket and no Postgres backend, while the spool
grew to 324,941 files. The CDR table was **617 minutes** behind. `Restart=always`
is set on the unit and never fired, because the process was still there, asleep.
Postgres and pgbouncer were healthy throughout - a test connect returned
instantly - so whatever blip broke it had long passed and the loop simply could
not climb out.

The patch bounds it at 10 attempts (~30s), then `exit(1)` and lets systemd
restart the unit. It also breaks out as soon as `connect()` succeeds rather than
always sleeping 3s first.

Apply and restart:

    patch -p1 --dry-run < patches/xml_cdr-service-db-reconnect.patch
    patch -p1 < patches/xml_cdr-service-db-reconnect.patch
    systemctl restart xml_cdr

Also worth adding to the unit, since `StartLimitIntervalSec=0` disables start
rate limiting: `RestartSec=10`, so a genuinely unreachable database produces a
restart every ~40s instead of a tight loop.

Verified by applying to a copy of the live file: hunk applies (fuzz 1), `php -l`
clean, resulting region reviewed.

## database-pgbouncer-emulate-prepares.patch

`resources/classes/database.php`, for **BTCL** (FusionPBX talks to Postgres through
pgbouncer on 127.0.0.1:6432, pool_mode = transaction, since 2026-09-20 11:03).

PDO creates a server-side prepared statement per query and drops it with
`DEALLOCATE pdo_stmt_000000NN`. pgbouncer renames prepared statements, so the
DEALLOCATE fails ("prepared statement ... does not exist", ~200,000 a day). Outside
a transaction that is noise; inside one it aborts the transaction. FusionPBX's
`delete()` runs BEGIN / DELETE / COMMIT, discards a statement in between, and its
`execute()` swallows the error - so the delete rolls back while the page and
`v_database_transactions` both say "OK". Found 2026-10-08: removing members from
ring group 6789 (pbx-manager) "saved" but the members stayed. Postgres log:

    DEALLOCATE pdo_stmt_00000002  -> ERROR does not exist
    delete from v_ring_group_destinations ...  -> current transaction is aborted

The patch sets `PDO::ATTR_EMULATE_PREPARES` on the pgsql connection: PDO builds
the statement client-side, so there is nothing for pgbouncer to rename and no
DEALLOCATE. Verified on BTCL with the same BEGIN/discard/DELETE sequence (rolled
back): without it the DELETE errors, with it the DELETE removes the row.

Apply on BTCL (back up first, then watch the Postgres log - the "does not exist"
errors should stop):

    cp -a resources/classes/database.php resources/classes/database.php.bak-$(date +%Y%m%d-%H%M%S)
    patch -p1 --dry-run < .../patches/database-pgbouncer-emulate-prepares.patch
    patch -p1 < .../patches/database-pgbouncer-emulate-prepares.patch
    systemctl reload php8.3-fpm
