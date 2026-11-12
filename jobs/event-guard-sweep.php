<?php
/**
 * Clear the firewall rules that Event Guard already shows as unblocked.
 *
 *   php event-guard-sweep.php plan                 what it would clear
 *   php event-guard-sweep.php apply <plan-id>      do it; refuses if the plan has changed since
 *   php event-guard-sweep.php rollback <dir>       put the cleared rules back
 *
 * Run as root on the PBX node. The backup goes to /var/backups/event-guard-sweep-<time>/.
 *
 * Until 2026-11-12, actions/event-guard-log-unblock.php (the Unblock button in
 * SOFTSWITCH_DASHBOARD and btcl-hosted-pbx) marked log rows 'unblocked' before the
 * Event Guard service had removed the rule, so the service often found nothing to
 * do. Those addresses show "unblocked" in every UI while the firewall keeps
 * dropping them. BTCL had 191.
 *
 * Stuck means: an address on this host whose Event Guard rows are ALL 'unblocked'
 * and that still has a DROP rule in sip-auth-fail or sip-auth-ip. An address with
 * any row still 'blocked' or 'pending' is left alone: it was blocked again since,
 * or an unblock is already under way.
 *
 * It clears them the way the GUI does. For each stuck rule it sets the newest row
 * of that address and chain back to 'pending', sends one CUSTOM event_guard:unblock,
 * and lets the Event Guard service remove the rules and mark the rows 'unblocked'.
 * Then it reads the firewall again and reports every rule that is still there.
 *
 * A rule with no row for its address and chain is reported and left alone: the
 * service only removes what a row points at.
 *
 * FUSIONPBX_ROOT overrides the FusionPBX directory (for running a copy of this
 * file from outside app/rest_api/jobs).
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$fusionpbx_root = getenv('FUSIONPBX_ROOT') ?: dirname(__DIR__, 3);
chdir($fusionpbx_root);
set_include_path($fusionpbx_root);
if (empty($_SERVER['DOCUMENT_ROOT'])) {
    $_SERVER['DOCUMENT_ROOT'] = $fusionpbx_root;
}
require_once $fusionpbx_root . '/resources/require.php';
// event_guard_unblock_send_event()
require_once dirname(__DIR__) . '/actions/event-guard-log-unblock.php';

const EGS_CHAINS = array('sip-auth-fail', 'sip-auth-ip');

function egs_iptables() {
    foreach (array('/usr/sbin/iptables', '/sbin/iptables') as $path) {
        if (is_executable($path)) {
            return $path;
        }
    }
    fwrite(STDERR, "iptables not found\n");
    exit(1);
}

// The DROP rules in each chain: [chain][ip] => how many. A failed read stops the
// run; it must never be taken for "no rules".
function egs_rules() {
    $sets = array();
    foreach (EGS_CHAINS as $chain) {
        $out = array();
        $rc = 1;
        exec(escapeshellarg(egs_iptables()) . ' -w 5 -S ' . escapeshellarg($chain) . ' 2>&1', $out, $rc);
        if ($rc !== 0) {
            fwrite(STDERR, "iptables -S $chain failed (rc $rc): " . implode(' ', $out) . "\n");
            exit(1);
        }
        $sets[$chain] = array();
        foreach ($out as $line) {
            if (preg_match('#^-A ' . preg_quote($chain, '#') . ' -s ([0-9.]+)(?:/32)? -j DROP$#', trim($line), $m)) {
                $sets[$chain][$m[1]] = ($sets[$chain][$m[1]] ?? 0) + 1;
            }
        }
    }
    return $sets;
}

function egs_plan($database) {
    $rows = $database->select(
        "select event_guard_log_uuid, ip_address, filter, log_status, extension, user_agent, "
        . "cast(log_date as text) as log_date, cast(update_date as text) as update_date "
        . "from v_event_guard_logs where hostname = :h order by ip_address, update_date desc nulls last",
        array('h' => gethostname()), 'all') ?: array();
    $by_ip = array();
    foreach ($rows as $r) {
        $by_ip[(string) $r['ip_address']][] = $r;
    }
    $rules = egs_rules();

    $plan = array();
    $skipped = array();
    $orphans = array();
    foreach ($by_ip as $ip => $list) {
        $statuses = array_values(array_unique(array_map('strval', array_column($list, 'log_status'))));
        foreach (EGS_CHAINS as $chain) {
            if (empty($rules[$chain][$ip])) {
                continue;
            }
            if ($statuses !== array('unblocked')) {
                if (in_array('unblocked', $statuses, true)) {
                    $skipped[] = array('ip' => $ip, 'chain' => $chain, 'statuses' => implode(',', $statuses));
                }
                continue;
            }
            $row = null;
            foreach ($list as $r) { // newest first
                if ((string) $r['filter'] === $chain) {
                    $row = $r;
                    break;
                }
            }
            if ($row === null) {
                $orphans[] = array('ip' => $ip, 'chain' => $chain);
                continue;
            }
            $plan[] = array(
                'ip' => $ip,
                'chain' => $chain,
                'rules' => $rules[$chain][$ip],
                'uuid' => (string) $row['event_guard_log_uuid'],
                'extension' => (string) $row['extension'],
                'user_agent' => (string) $row['user_agent'],
                'log_date' => $row['log_date'],
                'update_date' => $row['update_date'],
            );
        }
    }
    $pending = (int) $database->select(
        "select count(*) from v_event_guard_logs where hostname = :h and log_status = 'pending'",
        array('h' => gethostname()), 'column');
    return array($plan, $skipped, $orphans, $pending);
}

function egs_plan_id($plan) {
    $key = array();
    foreach ($plan as $item) {
        $key[] = $item['ip'] . '|' . $item['chain'] . '|' . $item['uuid'];
    }
    sort($key);
    return substr(sha1(json_encode($key)), 0, 12);
}

function egs_print($plan, $skipped, $orphans, $pending) {
    $addresses = count(array_unique(array_column($plan, 'ip')));
    $rules = array_sum(array_column($plan, 'rules'));
    echo "host " . gethostname() . ": $addresses stuck address(es), " . count($plan) . " chain entr(ies), $rules rule(s) to clear\n";
    foreach ($plan as $p) {
        printf("  %-16s %-14s rules=%d  %s  %s  (marked unblocked %s)\n", $p['ip'], $p['chain'], $p['rules'],
            $p['extension'], substr($p['user_agent'], 0, 40), substr((string) $p['update_date'], 0, 16));
    }
    echo "left alone, blocked again since: " . count($skipped) . "\n";
    foreach ($skipped as $s) {
        echo "  {$s['ip']} {$s['chain']} (rows: {$s['statuses']})\n";
    }
    echo "left alone, rule with no row for its chain: " . count($orphans) . "\n";
    foreach ($orphans as $o) {
        echo "  {$o['ip']} {$o['chain']}\n";
    }
    echo "rows already pending (the service handles them too): $pending\n";
}

function egs_count_pending($database, $uuids) {
    $params = array();
    $names = array();
    foreach (array_values($uuids) as $i => $uuid) {
        $params['u' . $i] = $uuid;
        $names[] = ':u' . $i;
    }
    return (int) $database->select(
        "select count(*) from v_event_guard_logs where log_status = 'pending' and event_guard_log_uuid in (" . implode(', ', $names) . ")",
        $params, 'column');
}

function egs_apply($database, $plan_id) {
    list($plan, $skipped, $orphans, $pending) = egs_plan($database);
    if (egs_plan_id($plan) !== $plan_id) {
        fwrite(STDERR, "ABORT: the plan is now " . egs_plan_id($plan) . ", not $plan_id. Run plan again and review it.\n");
        exit(1);
    }
    if (!$plan) {
        echo "nothing to clear\n";
        return 0;
    }

    $dir = '/var/backups/event-guard-sweep-' . date('Ymd-His');
    if (!mkdir($dir, 0700, true)) {
        fwrite(STDERR, "cannot create $dir (run as root)\n");
        exit(1);
    }
    $out = array();
    $rc = 1;
    exec(escapeshellarg(dirname(egs_iptables()) . '/iptables-save') . ' 2>&1', $out, $rc);
    if ($rc !== 0) {
        fwrite(STDERR, "iptables-save failed (rc $rc); nothing changed\n");
        exit(1);
    }
    file_put_contents("$dir/iptables.before", implode("\n", $out) . "\n");
    file_put_contents("$dir/plan.json", json_encode($plan, JSON_PRETTY_PRINT));
    echo "backup: $dir\n";

    // 1. Hand the rows back to the service.
    $uuids = array_values(array_unique(array_column($plan, 'uuid')));
    foreach ($uuids as $uuid) {
        $database->execute(
            "update v_event_guard_logs set log_status = 'pending', update_date = now() "
            . "where event_guard_log_uuid = :u and log_status = 'unblocked'",
            array('u' => $uuid));
    }
    $queued = egs_count_pending($database, $uuids);
    echo "queued: $queued of " . count($uuids) . " row(s) set to pending\n";
    file_put_contents("$dir/journal.json", json_encode(array('queued' => $uuids), JSON_PRETTY_PRINT));

    // 2. One event: the service then works through every pending row of this host.
    if (!event_guard_unblock_send_event()) {
        fwrite(STDERR, "could not send event_guard:unblock. The rows stay pending until the next unblock request.\n"
            . "undo: php " . __FILE__ . " rollback $dir\n");
        exit(1);
    }

    // 3. Wait for it. It saves the whole batch at the end, so the count drops at once.
    $deadline = time() + 300;
    $left = count($uuids);
    $shown = -1;
    while ($left > 0 && time() < $deadline) {
        sleep(2);
        $left = egs_count_pending($database, $uuids);
        if ($left !== $shown) {
            echo "  Event Guard service: $left row(s) still pending\n";
            $shown = $left;
        }
    }

    // 4. Read the firewall again.
    $rules = egs_rules();
    $cleared = array();
    $remaining = array();
    foreach ($plan as $item) {
        if (empty($rules[$item['chain']][$item['ip']])) {
            $cleared[] = $item;
        }
        else {
            $remaining[] = $item;
        }
    }
    file_put_contents("$dir/journal.json", json_encode(array(
        'queued' => $uuids, 'cleared' => $cleared, 'remaining' => $remaining, 'rows_still_pending' => $left,
    ), JSON_PRETTY_PRINT));

    echo "cleared: " . count($cleared) . " of " . count($plan) . " chain entr(ies), "
        . count(array_unique(array_column($cleared, 'ip'))) . " address(es)\n";
    foreach ($remaining as $r) {
        echo "  STILL THERE: {$r['ip']} {$r['chain']}\n";
    }
    if ($left > 0) {
        echo "rows still pending after 300 s: $left (the service finishes them on its own)\n";
    }
    echo "undo: php " . __FILE__ . " rollback $dir\n";
    return $remaining ? 1 : 0;
}

function egs_rollback($database, $dir) {
    $plan = json_decode((string) @file_get_contents("$dir/plan.json"), true);
    $journal = json_decode((string) @file_get_contents("$dir/journal.json"), true);
    if (!is_array($plan)) {
        fwrite(STDERR, "no usable backup in $dir\n");
        exit(1);
    }
    // Without a finished journal, put back every planned rule that is gone now.
    $items = (is_array($journal) && isset($journal['cleared'])) ? $journal['cleared'] : $plan;
    $rules = egs_rules();
    $added = 0;
    foreach ($items as $it) {
        if (empty($rules[$it['chain']][$it['ip']])) {
            $out = array();
            $rc = 1;
            exec(escapeshellarg(egs_iptables()) . ' -w 5 -I ' . escapeshellarg($it['chain']) . ' -s ' . escapeshellarg($it['ip']) . ' -j DROP 2>&1', $out, $rc);
            if ($rc !== 0) {
                fwrite(STDERR, "could not re-add {$it['ip']} to {$it['chain']} (rc $rc): " . implode(' ', $out) . "\n");
                continue;
            }
            $rules[$it['chain']][$it['ip']] = 1;
            $added++;
        }
    }
    // The rows go back to how they were: 'unblocked', with their old dates.
    foreach ($plan as $it) {
        $sql = "update v_event_guard_logs set log_status = 'unblocked'";
        $params = array('u' => $it['uuid']);
        if (!empty($it['log_date'])) {
            $sql .= ", log_date = cast(:ld as timestamptz)";
            $params['ld'] = $it['log_date'];
        }
        if (!empty($it['update_date'])) {
            $sql .= ", update_date = cast(:ud as timestamptz)";
            $params['ud'] = $it['update_date'];
        }
        $database->execute($sql . " where event_guard_log_uuid = :u", $params);
    }
    echo "rolled back from $dir: $added rule(s) re-added, " . count($plan) . " row(s) restored\n";
    return 0;
}

if (!function_exists('posix_geteuid') || posix_geteuid() !== 0) {
    fwrite(STDERR, "run as root (it reads and changes the firewall)\n");
    exit(2);
}

$database = new database;
switch ($argv[1] ?? '') {
    case 'plan':
        list($plan, $skipped, $orphans, $pending) = egs_plan($database);
        egs_print($plan, $skipped, $orphans, $pending);
        echo "plan " . egs_plan_id($plan) . ". Apply with: php " . __FILE__ . " apply " . egs_plan_id($plan) . "\n";
        exit(0);
    case 'apply':
        if (empty($argv[2])) {
            fwrite(STDERR, "usage: apply <plan-id>\n");
            exit(2);
        }
        exit(egs_apply($database, $argv[2]));
    case 'rollback':
        if (empty($argv[2])) {
            fwrite(STDERR, "usage: rollback <backup-dir>\n");
            exit(2);
        }
        exit(egs_rollback($database, rtrim($argv[2], '/')));
    default:
        fwrite(STDERR, "usage: php event-guard-sweep.php plan | apply <plan-id> | rollback <backup-dir>\n");
        exit(2);
}
