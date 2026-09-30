<?php

/**
 * outbound-ringback.php
 *
 * Make the caller hear ringing while the carrier takes its time.
 *
 * WHY THIS EXISTS
 * ---------------
 * On BTCL every tenant that dials a mobile number waits 2-3 seconds between the
 * INVITE going out and the carrier returning 180/183 - measured 2026-09-30 as a
 * median of 2,320 ms for pbx-stax-349, 2,620 for hcc_samsung, 2,370 for kobul,
 * 3,020 for classic, and up to 23 seconds on a single call. It is the same on
 * every gateway, so it is the shared path out (SBC 192.168.24.101 -> carrier),
 * not this PBX, and it does not move with platform load.
 *
 * That delay has to be fixed upstream. What this fixes is the part that is ours:
 * no outbound route on these domains sets a ringback, so for those 2-3 seconds
 * FreeSWITCH plays the caller nothing at all. Dead air. Customers report it as
 * "stuck on Calling, no ringing, completely silent" and assume the phone system
 * is broken.
 *
 * With ringback + instant_ringback the caller hears ringing from the moment they
 * dial. Real early media from the carrier - "the number is switched off" and so
 * on - still takes over the moment it arrives, so nothing is hidden. The call
 * does not connect any faster. It just stops sounding broken while it tries.
 *
 * WHAT IT TOUCHES
 * ---------------
 * Only dialplans that bridge to a gateway (data like 'sofia/gateway/%'), and only
 * by adding two rows immediately before that bridge. No existing row is modified.
 * It is idempotent - a route that already sets a ringback is skipped - and
 * --revert removes exactly what it added.
 *
 * It also regenerates dialplan_xml. That is not optional: FreeSWITCH is served
 * v_dialplans.dialplan_xml verbatim and never reads v_dialplan_details, so detail
 * rows alone would show up correctly in both UIs and change nothing about the call.
 *
 * USAGE
 *   php tools/outbound-ringback.php --domain=pbx-stax-349.alaapcloud.gov.bd --dry-run
 *   php tools/outbound-ringback.php --domain=pbx-stax-349.alaapcloud.gov.bd
 *   php tools/outbound-ringback.php --domain=pbx-stax-349.alaapcloud.gov.bd --revert
 *   php tools/outbound-ringback.php --domain=all --dry-run
 *
 *   --tone=<value>   ringback to play. Default ${us-ring}, which resolves on these
 *                    boxes to %(2000,4000,440,480) - 2s on, 4s off, 440+480 Hz.
 *                    For a Bangladesh cadence instead:
 *                      --tone='tone_stream://%(1000,4000,400)'
 *
 * Do one domain first, place a real test call, and confirm before doing the rest.
 */

$opts = getopt('', array('domain:', 'tone::', 'dry-run', 'revert', 'help'));
if (isset($opts['help']) || empty($opts['domain'])) {
    fwrite(STDERR, "usage: php outbound-ringback.php --domain=<name|all> [--tone=...] [--dry-run] [--revert]\n");
    exit(1);
}

$domain_arg = $opts['domain'];
$tone       = isset($opts['tone']) && $opts['tone'] !== '' ? $opts['tone'] : '${us-ring}';
$dry_run    = array_key_exists('dry-run', $opts);
$revert     = array_key_exists('revert', $opts);

$doc_root = !empty($_SERVER['DOCUMENT_ROOT']) ? $_SERVER['DOCUMENT_ROOT'] : '/var/www/fusionpbx';
require_once $doc_root . '/resources/require.php';

$database = new database;

// Which domains
$params = array();
$sql = "SELECT domain_uuid, domain_name FROM v_domains ";
if ($domain_arg !== 'all') {
    $sql .= "WHERE domain_name = :domain_name ";
    $params['domain_name'] = $domain_arg;
}
$sql .= "ORDER BY domain_name";
$domains = $database->select($sql, $params ?: null, 'all');
if (empty($domains)) {
    fwrite(STDERR, "no such domain: $domain_arg\n");
    exit(1);
}

printf("%s ringback on outbound routes%s\n", $revert ? 'REMOVING' : 'ADDING', $dry_run ? '  [DRY RUN - nothing written]' : '');
printf("tone: %s\n\n", $tone);

$touched = array();
$changed = 0;
$skipped = 0;

foreach ($domains as $dom) {
    // Outbound routes = the dialplans that bridge to a gateway.
    $routes = $database->select(
        "SELECT DISTINCT p.dialplan_uuid, p.dialplan_name
         FROM v_dialplans p
         JOIN v_dialplan_details d ON d.dialplan_uuid = p.dialplan_uuid
         WHERE p.domain_uuid = :domain_uuid
           AND d.dialplan_detail_type = 'bridge'
           AND d.dialplan_detail_data LIKE 'sofia/gateway/%'
         ORDER BY p.dialplan_name",
        array('domain_uuid' => $dom['domain_uuid']), 'all');

    if (empty($routes)) { continue; }

    foreach ($routes as $route) {
        $uuid = $route['dialplan_uuid'];

        if ($revert) {
            $rows = $database->select(
                "SELECT dialplan_detail_uuid, dialplan_detail_data FROM v_dialplan_details
                 WHERE dialplan_uuid = :u AND dialplan_detail_type = 'set'
                   AND (dialplan_detail_data LIKE 'ringback=%' OR dialplan_detail_data = 'instant_ringback=true')",
                array('u' => $uuid), 'all');
            if (empty($rows)) { $skipped++; continue; }
            printf("  %-24s %-22s remove %d row(s)\n", $dom['domain_name'], $route['dialplan_name'], count($rows));
            if (!$dry_run) {
                foreach ($rows as $r) {
                    $database->execute("DELETE FROM v_dialplan_details WHERE dialplan_detail_uuid = :u",
                        array('u' => $r['dialplan_detail_uuid']));
                }
            }
            $touched[$uuid] = $dom['domain_name'];
            $changed++;
            continue;
        }

        // Idempotent: a route that already has a ringback is left alone.
        $existing = $database->select(
            "SELECT 1 FROM v_dialplan_details
             WHERE dialplan_uuid = :u AND dialplan_detail_data LIKE 'ringback=%' LIMIT 1",
            array('u' => $uuid), 'row');
        if (!empty($existing)) {
            printf("  %-24s %-22s already set, skipped\n", $dom['domain_name'], $route['dialplan_name']);
            $skipped++;
            continue;
        }

        // Insert immediately before the bridge, in the bridge's own group.
        $bridge = $database->select(
            "SELECT dialplan_detail_group, dialplan_detail_order FROM v_dialplan_details
             WHERE dialplan_uuid = :u AND dialplan_detail_type = 'bridge'
               AND dialplan_detail_data LIKE 'sofia/gateway/%'
             ORDER BY dialplan_detail_group, dialplan_detail_order LIMIT 1",
            array('u' => $uuid), 'row');
        if (empty($bridge)) { $skipped++; continue; }

        $grp = $bridge['dialplan_detail_group'];
        // Fractional orders sort between the last action and the bridge whatever
        // the spacing is, so no existing row has to be renumbered.
        $o1 = $bridge['dialplan_detail_order'] - 0.2;
        $o2 = $bridge['dialplan_detail_order'] - 0.1;

        printf("  %-24s %-22s insert at group %s order %s / %s (bridge is %s)\n",
            $dom['domain_name'], $route['dialplan_name'], $grp, $o1, $o2, $bridge['dialplan_detail_order']);

        if (!$dry_run) {
            foreach (array(array($o1, 'ringback=' . $tone), array($o2, 'instant_ringback=true')) as $row) {
                $database->execute(
                    "INSERT INTO v_dialplan_details (
                        dialplan_detail_uuid, domain_uuid, dialplan_uuid, dialplan_detail_tag,
                        dialplan_detail_type, dialplan_detail_data, dialplan_detail_group,
                        dialplan_detail_order, dialplan_detail_enabled
                     ) VALUES (
                        :detail_uuid, :domain_uuid, :dialplan_uuid, 'action',
                        'set', :data, :grp, :ord, 'true'
                     )",
                    array(
                        'detail_uuid'   => uuid(),
                        'domain_uuid'   => $dom['domain_uuid'],
                        'dialplan_uuid' => $uuid,
                        'data'          => $row[1],
                        'grp'           => $grp,
                        'ord'           => $row[0],
                    ));
            }
        }
        $touched[$uuid] = $dom['domain_name'];
        $changed++;
    }
}

printf("\n%d route(s) %s, %d skipped\n", $changed, $revert ? 'reverted' : 'changed', $skipped);

if ($dry_run || empty($touched)) {
    if ($dry_run) { echo "dry run - no XML regenerated, no reload\n"; }
    exit(0);
}

// Regenerate the XML FreeSWITCH is actually served. Detail rows on their own
// would display correctly in both UIs and change nothing about the call.
require_once $doc_root . '/app/dialplans/resources/classes/dialplan.php';
foreach ($touched as $uuid => $domain_name) {
    $d = new dialplan;
    $d->source = 'details';
    $d->destination = 'database';
    $d->uuid = $uuid;
    $d->xml();
}
$domain_names = array_unique(array_values($touched));
if (class_exists('cache')) {
    $cache = new cache;
    foreach ($domain_names as $dn) { $cache->delete('dialplan:' . $dn); }
}
printf("regenerated dialplan_xml for %d route(s), cleared cache for %d domain(s)\n",
    count($touched), count($domain_names));

require_once $doc_root . '/resources/switch.php';
if (event_socket::create()) {
    event_socket::api('reloadxml');
    echo "reloadxml sent\n";
} else {
    echo "WARNING: event socket unavailable - run 'fs_cli -x reloadxml' by hand\n";
}

echo "\nVerify: place a test call and confirm ringing starts immediately. Then:\n";
echo "  select dialplan_detail_data from v_dialplan_details where dialplan_detail_data like 'ringback=%';\n";
