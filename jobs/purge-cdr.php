<?php
/**
 * Delete call records past their retention window.
 *
 *   php purge-cdr.php                 DRY RUN (default) -- counts only
 *   php purge-cdr.php --apply         delete
 *   php purge-cdr.php --apply --force delete even records never exported
 *
 * Refuses, without --force, to delete anything the customer has never taken a
 * copy of. v_xml_cdr.exported_at is set when a download actually delivers, so
 * "pending" means we warned them, they never collected, and deleting would
 * destroy the only copy. That is a support incident, not a cleanup.
 *
 * Deletes in batches with a pause between them. A single DELETE across 14M
 * rows holds locks and floods WAL on a box that is also carrying live calls.
 *
 * Only v_xml_cdr. The internal side tables have their own trimmer
 * (trim-cdr-side-tables.sh) with a different, shorter window.
 */

require_once dirname(__DIR__, 3) . '/resources/require.php';

$apply = in_array('--apply', $argv);
$force = in_array('--force', $argv);
$batch = 20000;

$database = new database;

$status = $database->select(
    "SELECT MIN(retention_days) AS rd, MIN(display_days) AS dd, MIN(cutoff_date) AS cutoff
       FROM v_cdr_export_status", array(), 'row');

if (empty($status['cutoff'])) {
    fwrite(STDERR, "No rollup yet. Run cdr-export-status.php first -- without it there is no\n"
                 . "cutoff, and guessing one would delete against a window nobody agreed.\n");
    exit(1);
}
$cutoff = $status['cutoff'];
$rd = (int) $status['rd'];
$dd = (int) $status['dd'];
if ($rd < $dd) {
    fwrite(STDERR, "REFUSING: retention_days ($rd) is below display_days ($dd).\n");
    exit(1);
}

if (!$apply) { echo "=== DRY RUN — nothing will be deleted (pass --apply) ===\n"; }
printf("cutoff %s   keep %dd   customers told %dd\n", $cutoff, $rd, $dd);

$counts = $database->select(
    "SELECT COUNT(*) FILTER (WHERE exported_at IS NOT NULL) AS exported,
            COUNT(*) FILTER (WHERE exported_at IS NULL)     AS pending
       FROM v_xml_cdr WHERE start_stamp < :c",
    array('c' => $cutoff), 'row');
$exported = (int) ($counts['exported'] ?? 0);
$pending  = (int) ($counts['pending'] ?? 0);

printf("older than cutoff: %s exported, %s never collected\n",
    number_format($exported), number_format($pending));

if ($pending > 0 && !$force) {
    printf("SKIPPING %s record(s) the customer never collected. They were warned; they did not\n"
         . "download. Deleting would destroy the only copy. Pass --force only if that is a\n"
         . "deliberate decision someone owns.\n", number_format($pending));
}

$target = $force ? ($exported + $pending) : $exported;
if ($target === 0) { echo "nothing to delete\n"; exit(0); }
if (!$apply) { printf("would delete %s record(s)\n", number_format($target)); exit(0); }

$where = $force ? "start_stamp < :c" : "start_stamp < :c AND exported_at IS NOT NULL";
$done = 0;
while (true) {
    $n = $database->execute(
        "WITH doomed AS (SELECT ctid FROM v_xml_cdr WHERE $where LIMIT $batch)
         DELETE FROM v_xml_cdr t USING doomed WHERE t.ctid = doomed.ctid",
        array('c' => $cutoff));
    $before = $done;
    $left = $database->select("SELECT COUNT(*) AS n FROM v_xml_cdr WHERE $where",
                              array('c' => $cutoff), 'row');
    $remaining = (int) ($left['n'] ?? 0);
    $done = $target - $remaining;
    if ($done <= $before) { break; }          // no progress -- stop rather than spin
    printf("  deleted %s / %s\n", number_format($done), number_format($target));
    if ($remaining === 0) { break; }
    sleep(2);                                  // share the box with live calls
}
printf("deleted %s record(s)\n", number_format($done));
