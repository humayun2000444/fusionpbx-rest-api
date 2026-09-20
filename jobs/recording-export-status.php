<?php
/**
 * Nightly per-domain retention rollup.
 *
 *   php /var/www/fusionpbx/app/rest_api/jobs/recording-export-status.php
 *
 * Writes v_recording_export_status so the portal can render the retention panel
 * from ONE row instead of counting 9.9M. Run it once a night, off-peak.
 *
 * SIZE IS AN ESTIMATE, on purpose. v_xml_cdr has no byte column, and stat()-ing
 * 1.85M files nightly is an I/O load this box cannot spare. Bytes are derived
 * from duration x RECORDING_BYTES_PER_SEC. The UI must say "about", and the
 * constant below should be corrected once from a real sample:
 *
 *   find /var/lib/freeswitch/recordings -name '*.mp3' | shuf -n 500 \
 *     | xargs stat -c %s | awk '{s+=$1} END {print s/NR}'
 *   ...divided by the mean duration of those calls.
 */
define('RECORDING_BYTES_PER_SEC', 4000);   // ~32 kbps mono mp3

require_once dirname(__DIR__, 3) . '/resources/require.php';

$database = new database;

// Purge runs on the 1st of each month (cron: `40 3 1 * *`).
//
// This MUST match when purge-retention.sh actually runs, because that script
// computes its own cutoff as "today - retention_days". If the UI promises a
// different date than the day the script runs, the cutoff it announces and the
// cutoff it applies drift by up to a month -- and you will have told customers
// the wrong deletion date in writing.
$purge_date = date('Y-m-01', strtotime('first day of next month'));
$retention_days = 120;

$domains = $database->select("SELECT domain_uuid, domain_name FROM v_domains", array(), 'all');
if (empty($domains)) { fwrite(STDERR, "no domains\n"); exit(1); }

$cutoff = date('Y-m-d', strtotime($purge_date . ' -' . $retention_days . ' days'));
printf("purge_date=%s retention=%dd cutoff=%s\n", $purge_date, $retention_days, $cutoff);

foreach ($domains as $d) {
    $p = array("domain_uuid" => $d['domain_uuid'], "cutoff" => $cutoff);

    $row = $database->select(
        "SELECT
            COUNT(*)                                             AS total_count,
            COALESCE(SUM(duration),0)                            AS total_secs,
            COUNT(*) FILTER (WHERE start_stamp < :cutoff::date)   AS at_risk_count,
            COALESCE(SUM(duration) FILTER (WHERE start_stamp < :cutoff::date),0) AS at_risk_secs,
            MIN(start_stamp) FILTER (WHERE start_stamp < :cutoff::date)::date    AS at_risk_oldest,
            COUNT(*) FILTER (WHERE start_stamp < :cutoff::date AND exported_at IS NULL) AS pending_count,
            COALESCE(SUM(duration) FILTER (WHERE start_stamp < :cutoff::date AND exported_at IS NULL),0) AS pending_secs,
            MIN(start_stamp) FILTER (WHERE start_stamp < :cutoff::date AND exported_at IS NULL)::date AS pending_oldest
         FROM v_xml_cdr
         WHERE domain_uuid = :domain_uuid
           AND record_name IS NOT NULL AND record_name <> ''",
        $p, 'row');

    $b = function ($secs) { return (int) round(((int) $secs) * RECORDING_BYTES_PER_SEC); };

    $database->execute(
        "INSERT INTO v_recording_export_status
            (domain_uuid, computed_at, retention_days, purge_date, cutoff_date,
             at_risk_count, at_risk_bytes, at_risk_oldest,
             pending_count, pending_bytes, pending_oldest,
             total_count, total_bytes)
         VALUES
            (:domain_uuid, NOW(), :retention_days, :purge_date::date, :cutoff::date,
             :arc, :arb, :aro::date, :pc, :pb, :po::date, :tc, :tb)
         ON CONFLICT (domain_uuid) DO UPDATE SET
            computed_at    = EXCLUDED.computed_at,
            retention_days = EXCLUDED.retention_days,
            purge_date     = EXCLUDED.purge_date,
            cutoff_date    = EXCLUDED.cutoff_date,
            at_risk_count  = EXCLUDED.at_risk_count,
            at_risk_bytes  = EXCLUDED.at_risk_bytes,
            at_risk_oldest = EXCLUDED.at_risk_oldest,
            pending_count  = EXCLUDED.pending_count,
            pending_bytes  = EXCLUDED.pending_bytes,
            pending_oldest = EXCLUDED.pending_oldest,
            total_count    = EXCLUDED.total_count,
            total_bytes    = EXCLUDED.total_bytes",
        array(
            "domain_uuid"    => $d['domain_uuid'],
            "retention_days" => $retention_days,
            "purge_date"     => $purge_date,
            "cutoff"         => $cutoff,
            "arc" => (int) $row['at_risk_count'], "arb" => $b($row['at_risk_secs']),
            "aro" => $row['at_risk_oldest'],
            "pc"  => (int) $row['pending_count'], "pb"  => $b($row['pending_secs']),
            "po"  => $row['pending_oldest'],
            "tc"  => (int) $row['total_count'],   "tb"  => $b($row['total_secs']),
        ));

    if ((int) $row['pending_count'] > 0) {
        printf("  %-45s pending=%-8d oldest=%s\n",
            $d['domain_name'], (int) $row['pending_count'], $row['pending_oldest']);
    }
}
echo "done\n";
