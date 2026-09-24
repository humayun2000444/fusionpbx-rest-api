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

Note this was not the whole story — see
`migrations/20260924-cdr-admin-query-performance.sql`. The importer was also
being starved of disk I/O by un-indexed CDR page queries seq scanning 11 GB.
