<?php
/**
 * Nightly rollup of CDR retention, per domain.
 *
 *   php cdr-export-status.php [--dry-run]
 *
 * Mirrors recording-export-status.php so customers get one consistent story:
 * call records and recordings are both "kept for at least N days", both warn
 * before removal, and neither deletes anything the customer never collected.
 *
 * Two numbers on purpose:
 *
 *   RETENTION_DAYS  what the purge actually deletes against
 *   DISPLAY_DAYS    what customers are told
 *
 * We keep LONGER than we promise. A customer told 90 days who finds records
 * gone on day 91 has a real complaint; one told 90 who finds them still there
 * on day 94 does not. This refuses to run if that ever inverts.
 */

require_once dirname(__DIR__, 3) . '/resources/require.php';

$retention_days = 95;
$display_days   = 90;
if ($retention_days < $display_days) {
    fwrite(STDERR, "REFUSING: retention_days ($retention_days) is below display_days ($display_days). "
        . "That deletes customer data sooner than they were told.\n");
    exit(1);
}

$dry_run = in_array('--dry-run', $argv);
$database = new database;

// Month-end purge, like recordings: the real age at deletion is 95-125 days,
// so "at least 90" is always true.
$purge_date = date('Y-m-01', strtotime('first day of next month'));
$cutoff     = date('Y-m-d', strtotime($purge_date . " -{$retention_days} days"));

printf("purge_date=%s keep=%dd tell-customers=%dd cutoff=%s\n",
    $purge_date, $retention_days, $display_days, $cutoff);

$domains = $database->select("SELECT domain_uuid, domain_name FROM v_domains ORDER BY domain_name", array(), 'all');
if (empty($domains)) { fwrite(STDERR, "no domains\n"); exit(1); }

$written = 0;
foreach ($domains as $d) {
    // One pass per domain. total / at-risk / pending in a single scan rather
    // than three, because v_xml_cdr is 14M rows and this runs for every domain.
    $row = $database->select(
        "SELECT COUNT(*) AS total_count,
                COUNT(*) FILTER (WHERE start_stamp < :cutoff) AS at_risk_count,
                MIN(start_stamp) FILTER (WHERE start_stamp < :cutoff)::date AS at_risk_oldest,
                COUNT(*) FILTER (WHERE start_stamp < :cutoff AND exported_at IS NULL) AS pending_count,
                MIN(start_stamp) FILTER (WHERE start_stamp < :cutoff AND exported_at IS NULL)::date AS pending_oldest
           FROM v_xml_cdr WHERE domain_uuid = :d",
        array('d' => $d['domain_uuid'], 'cutoff' => $cutoff), 'row');

    $total   = (int) ($row['total_count'] ?? 0);
    $at_risk = (int) ($row['at_risk_count'] ?? 0);
    $pending = (int) ($row['pending_count'] ?? 0);

    printf("  %-42s total=%-9s at_risk=%-8s pending=%s\n",
        $d['domain_name'], number_format($total), number_format($at_risk), number_format($pending));

    if ($dry_run) { continue; }

    $database->execute(
        "INSERT INTO v_cdr_export_status
            (domain_uuid, computed_at, retention_days, display_days, purge_date, cutoff_date,
             at_risk_count, at_risk_oldest, pending_count, pending_oldest, total_count)
         VALUES (:d, NOW(), :rd, :dd, :p, :c, :ar, :aro, :pc, :po, :tc)
         ON CONFLICT (domain_uuid) DO UPDATE SET
            computed_at=NOW(), retention_days=EXCLUDED.retention_days,
            display_days=EXCLUDED.display_days, purge_date=EXCLUDED.purge_date,
            cutoff_date=EXCLUDED.cutoff_date, at_risk_count=EXCLUDED.at_risk_count,
            at_risk_oldest=EXCLUDED.at_risk_oldest, pending_count=EXCLUDED.pending_count,
            pending_oldest=EXCLUDED.pending_oldest, total_count=EXCLUDED.total_count",
        array('d'=>$d['domain_uuid'], 'rd'=>$retention_days, 'dd'=>$display_days,
              'p'=>$purge_date, 'c'=>$cutoff, 'ar'=>$at_risk,
              'aro'=>$row['at_risk_oldest'] ?: null, 'pc'=>$pending,
              'po'=>$row['pending_oldest'] ?: null, 'tc'=>$total));
    $written++;
}
printf("%s %d domain(s)\n", $dry_run ? "[dry-run] would write" : "wrote", $written);
