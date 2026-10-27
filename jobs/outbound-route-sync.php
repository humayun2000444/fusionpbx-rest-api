<?php
/**
 * Bring every domain's outbound routes to the standard set that TelcoREST's
 * partner provisioning installs (RTC-Manager PartnerService, OutboundRouteSpec;
 * the BTCL template), without changing how each domain sends digits.
 *
 *   php outbound-route-sync.php plan
 *   php outbound-route-sync.php apply <plan-id>
 *   php outbound-route-sync.php rollback <backup-dir>
 *
 * Run as root: apply writes /var/backups and runs each change as www-data.
 *
 * Per enabled domain that has an enabled 11-digit route (its gateway and digit
 * form are what everything else copies):
 *
 *   tighten  ^\+?(\d{11})$, ^(\d{11})$, ^(01[3-9]\d{8})$  -> ^(0\d{10})$
 *            ^\+?(\d{13})$, ^(\d{13})$                     -> ^(880\d{10})$
 *            The captured digits are the same, so the bridge is left alone.
 *   add      international (50), outbound-emergency (91), outbound-shortcode-16
 *            (92), outbound-shortcode-14 (93), outbound-landline-02 (94),
 *            outbound-landline-8802 (96), whichever the domain lacks, on the
 *            gateway of its 11-digit route. 02 copies that route's form (88$1
 *            or $1). 8802 only where the domain already has a 13-digit route:
 *            some gateways cannot take 880 numbers at all (2026-09-06).
 *   disable  catch-all routes ^(\d{N})$ / ^\+?(\d{N})$ for N = 3,4,5,9,10,12,14.
 *            They used to carry short codes, emergency numbers and landlines,
 *            so they go only after the standard routes exist. On CCL they had
 *            carried 45 calls in 30 days, none answered (2026-10-27).
 *
 * Everything goes through the installed rest_api actions -- outbound-route-
 * create / -update / -toggle -- one process each, exactly as TelcoREST calls
 * them, so a synced domain is indistinguishable from a freshly provisioned one
 * (companion call_direction-outbound, toll_allow_refresh, XML rebuilt from the
 * detail rows). Disabled routes stay in place; nothing is deleted.
 *
 * apply recomputes the plan and refuses unless it still matches <plan-id>, so
 * what runs is exactly what was reviewed. It stops at the first failure.
 * Backup and journal go to /var/backups/outbound-route-sync-<stamp>/, which is
 * what rollback takes.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$fusionpbx_root = getenv('FUSIONPBX_ROOT') ?: dirname(__DIR__, 3);
chdir($fusionpbx_root);
set_include_path($fusionpbx_root);
require_once $fusionpbx_root . '/resources/require.php';

const ORS_APP_UUID = '8c914ec3-9fc0-8ab5-4cda-6c9288bdc9a3';
const ORS_MOBILE = '^(0\d{10})$';
const ORS_D13 = '^(880\d{10})$';

// One child process per action: every action defines do_action().
if (($argv[1] ?? '') === '__action') {
    $action = basename($argv[2] ?? '');
    $body = json_decode(stream_get_contents(STDIN));
    include dirname(__DIR__) . '/actions/' . $action . '.php';
    echo json_encode(do_action($body));
    exit(0);
}

// Same names, orders, patterns and dialling rules as RTC-Manager PartnerService.
function ors_specs() {
    return array(
        'international' => array('name' => 'international', 'order' => 50, 'pattern' => '^(00|\+)(\d+)$', 'toll' => 'international', 'callee' => '$1$2', 'suffix' => '$1$2', 'call_direction' => true, 'description' => 'International outbound route (00 or +), passed through to the gateway unchanged'),
        'emergency' => array('name' => 'outbound-emergency', 'order' => 91, 'pattern' => '^(999|333|109|106|1090|1098|10921|10655)$', 'toll' => '', 'callee' => '$1', 'suffix' => '$1', 'call_direction' => false, 'description' => 'Emergency and helpline short codes'),
        '16' => array('name' => 'outbound-shortcode-16', 'order' => 92, 'pattern' => '^(16\d{3})$', 'toll' => '', 'callee' => '$1', 'suffix' => '$1', 'call_direction' => false, 'description' => '5 digit short codes starting with 16'),
        '14' => array('name' => 'outbound-shortcode-14', 'order' => 93, 'pattern' => '^(14\d{3})$', 'toll' => '', 'callee' => '$1', 'suffix' => '$1', 'call_direction' => false, 'description' => '5 digit short codes starting with 14'),
        '02' => array('name' => 'outbound-landline-02', 'order' => 94, 'pattern' => '^(02\d{8})$', 'toll' => '', 'callee' => '$1', 'suffix' => null, 'call_direction' => false, 'description' => 'Dhaka landline 02 numbers (10 digits)'),
        '8802' => array('name' => 'outbound-landline-8802', 'order' => 96, 'pattern' => '^(8802\d{8})$', 'toll' => '', 'callee' => '$1', 'suffix' => '$1', 'call_direction' => false, 'description' => 'Dhaka landline in 8802 form (12 digits)'),
    );
}

function ors_routes($database) {
    $sql = "select p.dialplan_uuid, p.domain_uuid, p.dialplan_name, p.dialplan_order, p.dialplan_enabled, ";
    $sql .= "(select s.dialplan_detail_data from v_dialplan_details s where s.dialplan_uuid = p.dialplan_uuid and s.dialplan_detail_tag = 'condition' and s.dialplan_detail_type = 'destination_number' limit 1) as pattern, ";
    $sql .= "(select s.dialplan_detail_data from v_dialplan_details s where s.dialplan_uuid = p.dialplan_uuid and s.dialplan_detail_tag = 'action' and s.dialplan_detail_type = 'bridge' limit 1) as bridge ";
    $sql .= "from v_dialplans p where p.domain_uuid is not null and p.dialplan_name not like 'call_direction-outbound%' and p.dialplan_name <> 'toll_allow_refresh' ";
    $sql .= "and (p.app_uuid = :app_uuid or p.dialplan_xml like '%sofia/gateway/%') order by p.dialplan_order, p.dialplan_name";
    return $database->select($sql, array('app_uuid' => ORS_APP_UUID), 'all') ?: array();
}

// Returns array(ops, report lines). Pure function of the database.
function ors_plan($database) {
    $mobile = array('^\+?(\d{11})$', '^(\d{11})$', '^(01[3-9]\d{8})$', ORS_MOBILE);
    $d13 = array('^\+?(\d{13})$', '^(\d{13})$', ORS_D13);
    $intl = array('^(00|\+)(\d+)$', '^(00\d+|\+\d+)$');
    $specs = ors_specs();
    $domains = $database->select("select domain_uuid, domain_name, domain_enabled from v_domains order by domain_name", null, 'all') ?: array();
    $gateways = array();
    foreach ($database->select("select gateway_uuid, gateway from v_gateways", null, 'all') ?: array() as $g) {
        $gateways[$g['gateway_uuid']] = $g['gateway'];
    }
    $all = ors_routes($database);
    $ops = array();
    $report = array();
    foreach ($domains as $domain) {
        $name = $domain['domain_name'];
        if (!filter_var($domain['domain_enabled'], FILTER_VALIDATE_BOOLEAN)) {
            $report[] = "=== $name: skipped, domain disabled";
            continue;
        }
        $live = array();
        foreach ($all as $r) {
            if ($r['domain_uuid'] !== $domain['domain_uuid'] || !filter_var($r['dialplan_enabled'], FILTER_VALIDATE_BOOLEAN)) {
                continue;
            }
            if (!preg_match('#^sofia/gateway/([0-9a-f-]{36})/(.*)$#', (string)$r['bridge'], $m)) {
                continue;
            }
            $r['gateway_uuid'] = $m[1];
            $r['suffix'] = $m[2];
            $live[] = $r;
        }
        $primary = null;
        foreach ($live as $r) {
            if (in_array($r['pattern'], $mobile, true)) { $primary = $r; break; }
        }
        if ($primary === null) {
            $report[] = "=== $name: skipped, no enabled 11-digit route to copy a gateway and digit form from (" . count($live) . " other live routes)";
            continue;
        }
        $lines = array();
        $patterns = array_column($live, 'pattern');
        foreach ($live as $r) {
            $new = null;
            if (in_array($r['pattern'], $mobile, true) && $r['pattern'] !== ORS_MOBILE) { $new = ORS_MOBILE; }
            if (in_array($r['pattern'], $d13, true) && $r['pattern'] !== ORS_D13) { $new = ORS_D13; }
            if ($new !== null) {
                $ops[] = array('op' => 'update', 'domain' => $name, 'dialplan_uuid' => $r['dialplan_uuid'], 'route' => $r['dialplan_name'], 'old' => $r['pattern'], 'new' => $new);
                $lines[] = sprintf("  tighten  %-36s %-18s -> %s (still sends %s)", $r['dialplan_name'], $r['pattern'], $new, $r['suffix']);
            }
        }
        $has13 = (bool)array_intersect($patterns, $d13);
        $have_intl = (bool)array_intersect($patterns, $intl);
        foreach ($specs as $key => $spec) {
            if (($key === 'international' && $have_intl) || ($key !== 'international' && in_array($spec['pattern'], $patterns, true))) {
                continue;
            }
            if ($key === '8802' && !$has13) {
                $lines[] = "  skip     outbound-landline-8802: no 13-digit route here, so no evidence the gateway takes 880 numbers";
                continue;
            }
            if ($spec['suffix'] === null) {
                $spec['suffix'] = (strpos($primary['suffix'], '88') === 0 ? '88' : '') . '$1';
            }
            $ops[] = array('op' => 'create', 'domain' => $name, 'domain_uuid' => $domain['domain_uuid'], 'gateway_uuid' => $primary['gateway_uuid']) + $spec;
            $lines[] = sprintf("  add      %-36s %-18s order %d, sends %s%s", $spec['name'], $spec['pattern'], $spec['order'], $spec['suffix'], $spec['toll'] ? ', needs toll_allow ' . $spec['toll'] : '');
        }
        foreach ($live as $r) {
            if (preg_match('#^\^(\\\\\+\?)?\(\\\\d\{(3|4|5|9|10|12|14)\}\)\$$#', (string)$r['pattern'])) {
                $ops[] = array('op' => 'disable', 'domain' => $name, 'dialplan_uuid' => $r['dialplan_uuid'], 'route' => $r['dialplan_name'], 'old' => $r['pattern']);
                $lines[] = sprintf("  disable  %-36s %s", $r['dialplan_name'], $r['pattern']);
            }
        }
        $report[] = "=== $name: gateway " . ($gateways[$primary['gateway_uuid']] ?? '?') . ", 11-digit numbers sent as " . $primary['suffix'] . ($lines ? '' : ' -- already standard');
        $report = array_merge($report, $lines);
    }
    // creates first: the catch-alls being disabled carried what they replace
    usort($ops, function ($a, $b) {
        $rank = array('create' => 0, 'update' => 1, 'disable' => 2);
        return $rank[$a['op']] - $rank[$b['op']];
    });
    return array($ops, $report);
}

function ors_plan_id($ops) {
    return substr(sha1(json_encode($ops)), 0, 12);
}

function ors_run_action($action, $body) {
    $cmd = 'runuser -u www-data -- php ' . escapeshellarg(__FILE__) . ' __action ' . escapeshellarg($action);
    $proc = proc_open($cmd, array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
    fwrite($pipes[0], json_encode($body));
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    proc_close($proc);
    $json = json_decode(substr($out, (int)strpos($out, '{')), true);
    return array($json, trim($out . "\n" . $err));
}

function ors_snapshot($database, $uuids) {
    $rows = array();
    foreach ($uuids as $uuid) {
        $rows[$uuid] = array(
            'dialplan' => $database->select("select * from v_dialplans where dialplan_uuid = :u", array('u' => $uuid), 'row'),
            'details' => $database->select("select * from v_dialplan_details where dialplan_uuid = :u", array('u' => $uuid), 'all'),
        );
    }
    return $rows;
}

function ors_flush($database, $contexts) {
    $cache = new cache;
    foreach (array_unique($contexts) as $context) {
        $cache->delete("dialplan:" . $context);
    }
    event_socket::api('reloadxml');
}

function ors_apply($database, $plan_id) {
    list($ops, ) = ors_plan($database);
    if (ors_plan_id($ops) !== $plan_id) {
        fwrite(STDERR, "ABORT: the plan is now " . ors_plan_id($ops) . ", not $plan_id. Run plan again and review it.\n");
        exit(1);
    }
    if (!$ops) {
        echo "nothing to do\n";
        return;
    }
    $dir = '/var/backups/outbound-route-sync-' . date('Ymd-His');
    mkdir($dir, 0700, true);
    file_put_contents("$dir/plan.json", json_encode($ops, JSON_PRETTY_PRINT));
    $touched = array_values(array_unique(array_column(array_filter($ops, function ($o) { return $o['op'] !== 'create'; }), 'dialplan_uuid')));
    file_put_contents("$dir/before.json", json_encode(ors_snapshot($database, $touched), JSON_PRETTY_PRINT));
    $refresh_before = array_column($database->select("select dialplan_uuid from v_dialplans where dialplan_name = 'toll_allow_refresh'", null, 'all') ?: array(), 'dialplan_uuid');
    $journal = array('created' => array(), 'updated' => array(), 'disabled' => array(), 'toll_allow_refresh_before' => $refresh_before);
    echo "backup: $dir\n";

    foreach ($ops as $i => $op) {
        $n = $i + 1;
        if ($op['op'] === 'create') {
            list($res, $raw) = ors_run_action('outbound-route-create', array(
                'domain_uuid' => $op['domain_uuid'], 'gateway_uuid' => $op['gateway_uuid'], 'dialplan_name' => $op['name'],
                'destination_pattern' => $op['pattern'], 'context' => $op['domain'], 'dialplan_order' => $op['order'],
                'dialplan_enabled' => 'true', 'dialplan_continue' => 'false', 'dialplan_description' => $op['description'],
                'callee_id_number' => $op['callee'], 'bridge_suffix' => $op['suffix'], 'toll_allow_type' => $op['toll'],
                'skip_call_direction' => !$op['call_direction'],
            ));
            $uuid = $res['dialplan_uuid'] ?? null;
            $ok = !empty($uuid) && empty($res['error']);
            if ($ok) {
                $journal['created'][] = $uuid;
                if (!empty($res['call_direction_dialplan_uuid'])) { $journal['created'][] = $res['call_direction_dialplan_uuid']; }
            }
            $label = "add {$op['name']}";
        } elseif ($op['op'] === 'update') {
            list($res, $raw) = ors_run_action('outbound-route-update', array('dialplan_uuid' => $op['dialplan_uuid'], 'destination_pattern' => $op['new']));
            $ok = is_array($res) && empty($res['error']) && ($res['success'] ?? true) !== false;
            if ($ok) { $journal['updated'][] = $op['dialplan_uuid']; }
            $label = "tighten {$op['route']} -> {$op['new']}";
        } else {
            list($res, $raw) = ors_run_action('outbound-route-toggle', array('dialplanUuid' => $op['dialplan_uuid']));
            $state = $database->select("select dialplan_enabled from v_dialplans where dialplan_uuid = :u", array('u' => $op['dialplan_uuid']), 'column');
            $ok = !filter_var($state, FILTER_VALIDATE_BOOLEAN);
            if ($ok) { $journal['disabled'][] = $op['dialplan_uuid']; }
            $label = "disable {$op['route']}";
        }
        file_put_contents("$dir/journal.json", json_encode($journal, JSON_PRETTY_PRINT));
        echo sprintf("%3d/%d %-14s %-40s %s\n", $n, count($ops), $op['domain'], $label, $ok ? 'ok' : 'FAILED');
        if (!$ok) {
            fwrite(STDERR, "STOPPED at step $n. Action output:\n" . substr($raw, 0, 2000) . "\nUndo with: php " . __FILE__ . " rollback $dir\n");
            ors_record_refresh($database, $journal, $dir);
            exit(1);
        }
    }
    ors_record_refresh($database, $journal, $dir);

    list($left, ) = ors_plan($database);
    echo "done. remaining plan after apply: " . count($left) . " operation(s)\n";
    echo "undo with: php " . __FILE__ . " rollback $dir\n";
}

// outbound-route-create adds toll_allow_refresh to a domain that lacks it and
// does not return its uuid; find the new ones so rollback can take them back out.
function ors_record_refresh($database, &$journal, $dir) {
    $now = array_column($database->select("select dialplan_uuid from v_dialplans where dialplan_name = 'toll_allow_refresh'", null, 'all') ?: array(), 'dialplan_uuid');
    $journal['created'] = array_values(array_unique(array_merge($journal['created'], array_diff($now, $journal['toll_allow_refresh_before']))));
    file_put_contents("$dir/journal.json", json_encode($journal, JSON_PRETTY_PRINT));
}

function ors_rollback($database, $dir) {
    $journal = json_decode((string)@file_get_contents("$dir/journal.json"), true);
    $before = json_decode((string)@file_get_contents("$dir/before.json"), true);
    if (!is_array($journal) || !is_array($before)) {
        fwrite(STDERR, "ABORT: no journal.json/before.json in $dir\n");
        exit(1);
    }
    $contexts = array();
    foreach ($journal['created'] as $uuid) {
        $ctx = $database->select("select dialplan_context from v_dialplans where dialplan_uuid = :u", array('u' => $uuid), 'column');
        if ($ctx) { $contexts[] = $ctx; }
        $database->execute("delete from v_dialplan_details where dialplan_uuid = :u", array('u' => $uuid));
        $database->execute("delete from v_dialplans where dialplan_uuid = :u", array('u' => $uuid));
    }
    foreach (array_unique(array_merge($journal['updated'], $journal['disabled'])) as $uuid) {
        $old = $before[$uuid] ?? null;
        if (empty($old['dialplan'])) { continue; }
        $contexts[] = $old['dialplan']['dialplan_context'];
        $database->execute("update v_dialplans set dialplan_enabled = :e, dialplan_xml = :x, update_date = now() where dialplan_uuid = :u",
            array('e' => $old['dialplan']['dialplan_enabled'], 'x' => $old['dialplan']['dialplan_xml'], 'u' => $uuid));
        foreach ($old['details'] as $detail) {
            $database->execute("update v_dialplan_details set dialplan_detail_data = :d where dialplan_detail_uuid = :u",
                array('d' => $detail['dialplan_detail_data'], 'u' => $detail['dialplan_detail_uuid']));
        }
    }
    ors_flush($database, $contexts);
    echo "rolled back: " . count($journal['created']) . " created rows removed, "
        . count(array_unique(array_merge($journal['updated'], $journal['disabled']))) . " routes restored\n";
}

$database = new database;
switch ($argv[1] ?? '') {
    case 'plan':
        list($ops, $report) = ors_plan($database);
        echo implode("\n", $report) . "\n";
        $count = array_count_values(array_column($ops, 'op'));
        echo "\n" . count($ops) . " operations (" . json_encode($count) . "), plan id " . ors_plan_id($ops) . "\n";
        break;
    case 'apply':
        ors_apply($database, $argv[2] ?? '');
        break;
    case 'rollback':
        ors_rollback($database, rtrim($argv[2] ?? '', '/'));
        break;
    default:
        fwrite(STDERR, "usage: php outbound-route-sync.php plan | apply <plan-id> | rollback <backup-dir>\n");
        exit(2);
}
