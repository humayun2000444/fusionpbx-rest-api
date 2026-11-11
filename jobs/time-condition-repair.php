<?php
/**
 * Bring time conditions to the shape the FusionPBX GUI writes, so FreeSWITCH
 * actually evaluates them.
 *
 *   php time-condition-repair.php plan [--xml]       what would change (--xml: the XML it would serve)
 *   php time-condition-repair.php apply <plan-id>    do it; refuses if the plan has changed since
 *   php time-condition-repair.php rollback <dir>     put every repaired time condition back
 *
 * Run as root: the backup goes to /var/backups/time-condition-repair-<time>/.
 *
 * Time conditions saved through the REST API before 2026-11-11 were broken
 * three ways. The actions are fixed now; this repairs what they already wrote.
 *
 *  1. No dialplan_xml. FreeSWITCH is served that column verbatim, so the time
 *     condition did not exist for it, and calls to its number found nothing.
 *  2. The default / after-hours route was an anti-action in its own group. That
 *     group's only condition is the extension's own destination_number, which is
 *     always true, so the anti-action could never run. The GUI writes the
 *     "Alternate Destination" as group 999 with a plain action.
 *  3. Time rows (wday, minute-of-day, ...) had no break. The GUI writes every one
 *     with break=never (app/time_conditions/time_condition_edit.php). Without it,
 *     FreeSWITCH's default break=on-false ends the whole extension at the first
 *     time check that fails, so later groups and the default route never run.
 *
 * Per time condition it changes only what is needed:
 *  - time rows without a break get break=never;
 *  - a default group of exactly {destination_number condition + anti-action(s)}
 *    moves to group 999, with its anti-actions turned into actions;
 *  - dialplan_xml is regenerated from the rows by FusionPBX's own dialplan class
 *    (time_condition_rebuild_xml, the call the actions make), and the domain's
 *    dialplan cache is cleared.
 * Time conditions that are already right are not touched.
 *
 * Skipped and reported, never changed:
 *  - a shape it does not recognise;
 *  - a time condition whose actions transfer to its own number, because repairing
 *    that would make a transfer loop live.
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
// time_condition_rebuild_xml() finds the dialplan class through DOCUMENT_ROOT,
// which the web server sets and the CLI does not.
if (empty($_SERVER['DOCUMENT_ROOT'])) {
    $_SERVER['DOCUMENT_ROOT'] = $fusionpbx_root;
}
require_once $fusionpbx_root . '/resources/require.php';
require_once dirname(__DIR__) . '/actions/time-condition-helper.php';

const TCR_APP_UUID = '4b821450-926b-175a-af93-a03c441818b1';

function tcr_time_types() {
    return array('year', 'mon', 'mday', 'wday', 'yday', 'week', 'mweek', 'hour', 'minute', 'minute-of-day', 'time-of-day', 'date-time');
}

function tcr_time_conditions($database) {
    $sql = "select p.*, d.domain_name from v_dialplans p left join v_domains d on d.domain_uuid = p.domain_uuid "
         . "where p.app_uuid = :app order by d.domain_name, p.dialplan_name";
    return $database->select($sql, array('app' => TCR_APP_UUID), 'all') ?: array();
}

function tcr_details($database, $uuid) {
    $rows = $database->select("select * from v_dialplan_details where dialplan_uuid = :u", array('u' => $uuid), 'all') ?: array();
    $rank = array('condition' => 0, 'regex' => 1, 'action' => 2, 'anti-action' => 3);
    usort($rows, function ($a, $b) use ($rank) {
        $g = intval($a['dialplan_detail_group']) - intval($b['dialplan_detail_group']);
        if ($g !== 0) { return $g; }
        $t = ($rank[$a['dialplan_detail_tag']] ?? 9) - ($rank[$b['dialplan_detail_tag']] ?? 9);
        if ($t !== 0) { return $t; }
        return intval($a['dialplan_detail_order']) - intval($b['dialplan_detail_order']);
    });
    return $rows;
}

/** array('ops' => array|null, 'skip' => string|null): ops null and skip null means already right. */
function tcr_analyse($tc, $details) {
    $number = trim((string) $tc['dialplan_number']);
    if ($number === '') {
        return array('ops' => null, 'skip' => 'no extension number');
    }
    if (empty($details)) {
        return array('ops' => null, 'skip' => 'no detail rows');
    }
    foreach ($details as $d) {
        if (in_array($d['dialplan_detail_tag'], array('action', 'anti-action'), true)
            && $d['dialplan_detail_type'] === 'transfer'
            && preg_match('/^' . preg_quote($number, '/') . '(\s|$)/', trim((string) $d['dialplan_detail_data']))) {
            return array('ops' => null, 'skip' => "transfers to its own number $number - repairing it would make a loop live");
        }
    }

    $groups = array();
    foreach ($details as $d) {
        $groups[(string) intval($d['dialplan_detail_group'])][] = $d;
    }
    $ops = array('break' => array(), 'default_group' => null, 'default_actions' => array());
    // PHP turns the numeric string keys into ints ('505' -> 505), and a strict
    // comparison against a string then silently never matches. Work in strings.
    foreach ($groups as $gk => $rows) {
        $g = (string) $gk;
        $dest = $anti = $act = $time = $other = array();
        foreach ($rows as $r) {
            if ($r['dialplan_detail_tag'] === 'condition' && $r['dialplan_detail_type'] === 'destination_number') { $dest[] = $r; }
            elseif ($r['dialplan_detail_tag'] === 'condition' && in_array($r['dialplan_detail_type'], tcr_time_types(), true)) { $time[] = $r; }
            elseif ($r['dialplan_detail_tag'] === 'action') { $act[] = $r; }
            elseif ($r['dialplan_detail_tag'] === 'anti-action') { $anti[] = $r; }
            else { $other[] = $r['dialplan_detail_tag'] . ':' . $r['dialplan_detail_type']; }
        }
        if (count($dest) !== 1 || $dest[0]['dialplan_detail_data'] !== '^' . $number . '$') {
            return array('ops' => null, 'skip' => "group $g does not start with one destination_number ^$number\$ condition");
        }
        if ($other) {
            return array('ops' => null, 'skip' => "group $g has rows this job does not know: " . implode(', ', $other));
        }
        if ($anti) {
            if ($act || $time) {
                return array('ops' => null, 'skip' => "group $g mixes anti-actions with actions or time rows");
            }
            if ($ops['default_group'] !== null) {
                return array('ops' => null, 'skip' => 'more than one anti-action group');
            }
            if ($g !== '999' && isset($groups['999'])) {
                return array('ops' => null, 'skip' => 'has an anti-action group and a group 999');
            }
            $ops['default_group'] = $g;
            foreach ($anti as $r) { $ops['default_actions'][] = trim($r['dialplan_detail_type'] . ' ' . $r['dialplan_detail_data']); }
        }
        foreach ($time as $r) {
            if (trim((string) $r['dialplan_detail_break']) === '') { $ops['break'][] = $r['dialplan_detail_uuid']; }
        }
    }
    $xml_missing = trim((string) $tc['dialplan_xml']) === '';
    if (!$ops['break'] && $ops['default_group'] === null && !$xml_missing) {
        return array('ops' => null, 'skip' => null);
    }
    $ops['xml_missing'] = $xml_missing;
    return array('ops' => $ops, 'skip' => null);
}

/** The rows as they will be after the repair, for the XML preview. */
function tcr_repaired_rows($tc, $details, $ops) {
    $rows = array();
    foreach ($details as $d) {
        if (in_array($d['dialplan_detail_uuid'], $ops['break'], true)) { $d['dialplan_detail_break'] = 'never'; }
        if ($ops['default_group'] !== null && (string) intval($d['dialplan_detail_group']) === (string) $ops['default_group']) {
            $d['dialplan_detail_group'] = 999;
            if ($d['dialplan_detail_tag'] === 'anti-action') { $d['dialplan_detail_tag'] = 'action'; }
        }
        foreach (array('domain_uuid', 'dialplan_uuid', 'app_uuid', 'dialplan_context', 'dialplan_name', 'dialplan_number',
                       'dialplan_continue', 'dialplan_order', 'dialplan_enabled', 'dialplan_description') as $k) {
            $d[$k] = $tc[$k];
        }
        $rows[] = $d;
    }
    $rank = array('condition' => 0, 'regex' => 1, 'action' => 2, 'anti-action' => 3);
    usort($rows, function ($a, $b) use ($rank) {
        $g = intval($a['dialplan_detail_group']) - intval($b['dialplan_detail_group']);
        if ($g !== 0) { return $g; }
        $t = ($rank[$a['dialplan_detail_tag']] ?? 9) - ($rank[$b['dialplan_detail_tag']] ?? 9);
        if ($t !== 0) { return $t; }
        return intval($a['dialplan_detail_order']) - intval($b['dialplan_detail_order']);
    });
    return $rows;
}

function tcr_preview_xml($tc, $rows) {
    $class = $_SERVER['DOCUMENT_ROOT'] . '/app/dialplans/resources/classes/dialplan.php';
    if (!class_exists('dialplan')) { require_once $class; }
    $d = new dialplan;
    $d->source = 'details';
    $d->destination = 'array';
    $d->uuid = $tc['dialplan_uuid'];
    $d->dialplan_details = $rows;
    $out = $d->xml();
    return is_array($out) ? ($out[$tc['dialplan_uuid']] ?? '') : '';
}

function tcr_plan($database) {
    $plan = array(); $report = array(); $fine = 0;
    foreach (tcr_time_conditions($database) as $tc) {
        $details = tcr_details($database, $tc['dialplan_uuid']);
        $a = tcr_analyse($tc, $details);
        $label = ($tc['domain_name'] ?? '?') . ' / ' . $tc['dialplan_name'] . ' (' . $tc['dialplan_number'] . ')';
        if ($a['skip'] !== null) {
            $report[] = "  SKIP   $label: " . $a['skip'];
            continue;
        }
        if ($a['ops'] === null) { $fine++; continue; }
        $o = $a['ops'];
        $what = array();
        if ($o['xml_missing']) { $what[] = 'XML missing'; }
        if ($o['break']) { $what[] = 'break=never on ' . count($o['break']) . ' time row(s)'; }
        if ($o['default_group'] !== null) { $what[] = 'default route group ' . $o['default_group'] . ' -> 999 as action (' . implode('; ', $o['default_actions']) . ')'; }
        $report[] = "  REPAIR $label: " . implode(', ', $what);
        $plan[] = array('uuid' => $tc['dialplan_uuid'], 'label' => $label, 'domain' => $tc['domain_name'],
                        'break' => $o['break'], 'default_group' => $o['default_group'],
                        'tc' => $tc, 'details' => $details, 'ops' => $o);
    }
    return array($plan, $report, $fine);
}

function tcr_plan_id($plan) {
    $key = array();
    foreach ($plan as $p) { $key[] = array($p['uuid'], $p['break'], $p['default_group']); }
    return substr(sha1(json_encode($key)), 0, 12);
}

function tcr_flush($database, $contexts) {
    if (!class_exists('cache')) { return; }
    $cache = new cache;
    foreach (array_unique($contexts) as $c) { $cache->delete('dialplan:' . $c); }
}

function tcr_apply($database, $plan_id) {
    list($plan, $report, ) = tcr_plan($database);
    if (tcr_plan_id($plan) !== $plan_id) {
        fwrite(STDERR, "ABORT: the plan is now " . tcr_plan_id($plan) . ", not $plan_id. Run plan again and review it.\n");
        exit(1);
    }
    if (!$plan) { echo "nothing to do\n"; return 0; }

    $dir = '/var/backups/time-condition-repair-' . date('Ymd-His');
    if (!mkdir($dir, 0700, true)) { fwrite(STDERR, "cannot create $dir (run as root)\n"); exit(1); }
    $before = array();
    foreach ($plan as $p) {
        $before[$p['uuid']] = array(
            'dialplan' => $database->select("select * from v_dialplans where dialplan_uuid = :u", array('u' => $p['uuid']), 'row'),
            'details' => $database->select("select * from v_dialplan_details where dialplan_uuid = :u", array('u' => $p['uuid']), 'all') ?: array(),
        );
    }
    file_put_contents("$dir/before.json", json_encode($before, JSON_PRETTY_PRINT));
    file_put_contents("$dir/plan.txt", implode("\n", $report) . "\n");
    $journal = array('repaired' => array());
    echo "backup: $dir\n";

    $bad = 0;
    foreach ($plan as $p) {
        $u = $p['uuid'];
        if ($p['break']) {
            $params = array('u' => $u); $in = array();
            foreach (array_values($p['break']) as $i => $d) { $params["b$i"] = $d; $in[] = ":b$i"; }
            $database->execute("update v_dialplan_details set dialplan_detail_break = 'never' where dialplan_uuid = :u and dialplan_detail_uuid in (" . implode(', ', $in) . ")", $params);
        }
        if ($p['default_group'] !== null) {
            $database->execute("update v_dialplan_details set dialplan_detail_tag = 'action' where dialplan_uuid = :u and dialplan_detail_group = :g and dialplan_detail_tag = 'anti-action'",
                array('u' => $u, 'g' => $p['default_group']));
            $database->execute("update v_dialplan_details set dialplan_detail_group = 999 where dialplan_uuid = :u and dialplan_detail_group = :g",
                array('u' => $u, 'g' => $p['default_group']));
        }
        $journal['repaired'][] = $u;
        file_put_contents("$dir/journal.json", json_encode($journal, JSON_PRETTY_PRINT));

        $result = time_condition_rebuild_xml($u, $p['domain']);

        // check what landed rather than trusting the calls above
        $now = tcr_details($database, $u);
        $xml = (string) $database->select("select dialplan_xml from v_dialplans where dialplan_uuid = :u", array('u' => $u), 'column');
        $problems = array();
        foreach ($now as $r) {
            if ($r['dialplan_detail_tag'] === 'anti-action') { $problems[] = 'an anti-action is left'; break; }
        }
        foreach ($now as $r) {
            if ($r['dialplan_detail_tag'] === 'condition' && in_array($r['dialplan_detail_type'], tcr_time_types(), true)
                && trim((string) $r['dialplan_detail_break']) !== 'never') { $problems[] = 'a time row has no break=never'; break; }
        }
        if (trim($xml) === '') { $problems[] = 'dialplan_xml is empty'; }
        elseif (strpos($xml, '<anti-action') !== false) { $problems[] = 'the XML still has an anti-action'; }
        if ($problems) { $bad++; }
        echo sprintf("  %-7s %s  [%s]\n", $problems ? 'FAILED' : 'ok', $p['label'], $problems ? implode('; ', $problems) : $result);
    }
    tcr_flush($database, array_column($plan, 'domain'));
    echo "repaired " . (count($plan) - $bad) . " of " . count($plan) . ($bad ? " - see FAILED above" : "") . "\n";
    echo "undo: php " . __FILE__ . " rollback $dir\n";
    return $bad ? 1 : 0;
}

function tcr_sql_value($v) {
    if (is_bool($v)) { return $v ? 'true' : 'false'; }
    return $v;
}

function tcr_rollback($database, $dir) {
    $before = json_decode((string) @file_get_contents("$dir/before.json"), true);
    $journal = json_decode((string) @file_get_contents("$dir/journal.json"), true);
    if (!is_array($before) || !is_array($journal)) { fwrite(STDERR, "no usable backup in $dir\n"); exit(1); }
    $contexts = array();
    foreach ($journal['repaired'] as $u) {
        if (!isset($before[$u])) { continue; }
        $snap = $before[$u];
        $database->execute("delete from v_dialplan_details where dialplan_uuid = :u", array('u' => $u));
        foreach ($snap['details'] as $row) {
            $cols = array_keys($row);
            $params = array();
            foreach ($row as $k => $v) { $params[$k] = tcr_sql_value($v); }
            $database->execute("insert into v_dialplan_details (" . implode(', ', $cols) . ") values (:" . implode(', :', $cols) . ")", $params);
        }
        $database->execute("update v_dialplans set dialplan_xml = :x, update_date = :d where dialplan_uuid = :u",
            array('x' => $snap['dialplan']['dialplan_xml'], 'd' => $snap['dialplan']['update_date'], 'u' => $u));
        $contexts[] = $snap['dialplan']['dialplan_context'];
        echo "  restored " . $snap['dialplan']['dialplan_context'] . " / " . $snap['dialplan']['dialplan_name'] . "\n";
    }
    tcr_flush($database, $contexts);
    echo "rolled back " . count($contexts) . " time condition(s) from $dir\n";
}

$database = new database;
switch ($argv[1] ?? '') {
    case 'plan':
        list($plan, $report, $fine) = tcr_plan($database);
        echo implode("\n", $report) . ($report ? "\n" : '');
        if (in_array('--xml', $argv, true)) {
            foreach ($plan as $p) {
                echo "\n--- XML after repair: {$p['label']}\n" . tcr_preview_xml($p['tc'], tcr_repaired_rows($p['tc'], $p['details'], $p['ops'])) . "\n";
            }
        }
        echo "already right: $fine\n";
        echo "plan " . tcr_plan_id($plan) . ": " . count($plan) . " to repair. Apply with: php " . __FILE__ . " apply " . tcr_plan_id($plan) . "\n";
        break;
    case 'apply':
        if (empty($argv[2])) { fwrite(STDERR, "usage: apply <plan-id>\n"); exit(2); }
        exit(tcr_apply($database, $argv[2]));
    case 'rollback':
        if (empty($argv[2])) { fwrite(STDERR, "usage: rollback <backup-dir>\n"); exit(2); }
        tcr_rollback($database, rtrim($argv[2], '/'));
        break;
    default:
        fwrite(STDERR, "usage: php time-condition-repair.php plan [--xml] | apply <plan-id> | rollback <backup-dir>\n");
        exit(2);
}
