<?php
/**
 * system-health-agent.php — PBX-side system health collector.
 *
 * The TelcoREST watchdog only ever measures the box TelcoREST itself runs on
 * (the RTC/gateway node), so the admin dashboard's System Status card never
 * showed the FusionPBX machine. This agent writes a snapshot for THIS box into
 * the same shared `system_health_snapshot` table under its own node name, so
 * the existing /admin/system-health/latest?node=<name> API serves it with no
 * TelcoREST change at all.
 *
 * Usage: php system-health-agent.php [--once] [--verbose]
 * Config: /etc/telcobright/system-health-agent.conf  (ini; keeps creds out of
 * this file so it can live in git).
 */

$CONF = '/etc/telcobright/system-health-agent.conf';
$verbose = in_array('--verbose', $argv, true);

function fail($msg) { fwrite(STDERR, "[health-agent] $msg\n"); exit(1); }
function say($msg)  { global $verbose; if ($verbose) echo "[health-agent] $msg\n"; }

if (!is_readable($CONF)) fail("missing config $CONF");
$cfg = parse_ini_file($CONF);
foreach (['db_host', 'db_name', 'db_user', 'db_pass', 'node'] as $k) {
    if (empty($cfg[$k])) fail("config key '$k' missing from $CONF");
}

/* ---- CPU: two /proc/stat samples 1s apart (same method the Java watchdog
   uses, so the numbers are comparable between nodes). ---- */
function cpu_sample() {
    $line = @file('/proc/stat')[0] ?? '';
    if (strpos($line, 'cpu ') !== 0) return null;
    $p = preg_split('/\s+/', trim($line));
    array_shift($p);
    $vals = array_map('intval', $p);
    $idle = ($vals[3] ?? 0) + ($vals[4] ?? 0);          // idle + iowait
    return ['idle' => $idle, 'total' => array_sum($vals)];
}
function cpu_usage_pct() {
    $a = cpu_sample(); if (!$a) return null;
    sleep(1);
    $b = cpu_sample(); if (!$b) return null;
    $dTotal = $b['total'] - $a['total'];
    $dIdle  = $b['idle']  - $a['idle'];
    if ($dTotal <= 0) return null;
    return round(100.0 * ($dTotal - $dIdle) / $dTotal, 1);
}

/* ---- RAM: MemAvailable is the honest "used" figure (MemFree alone counts
   reclaimable page cache as used and reads ~95% on any busy box). ---- */
function mem_mb() {
    $out = [];
    foreach (@file('/proc/meminfo') ?: [] as $l) {
        if (preg_match('/^(MemTotal|MemAvailable):\s+(\d+) kB/', $l, $m)) $out[$m[1]] = (int)$m[2];
    }
    if (empty($out['MemTotal'])) return null;
    $totalMb = intdiv($out['MemTotal'], 1024);
    $availMb = intdiv($out['MemAvailable'] ?? 0, 1024);
    return ['total' => $totalMb, 'used' => max(0, $totalMb - $availMb)];
}

/* ---- Disk: the root filesystem, matching what the Java watchdog reports. ---- */
function disk_gb() {
    $total = @disk_total_space('/');
    $free  = @disk_free_space('/');
    if (!$total) return null;
    $g = 1024 * 1024 * 1024;
    return ['total' => round($total / $g, 2), 'free' => round($free / $g, 2),
            'used'  => round(($total - $free) / $g, 2)];
}

/* ---- Services: recorded as JSON so the card can show what is actually up. ---- */
function services_json() {
    $want = ['freeswitch', 'postgresql', 'nginx', 'php8.3-fpm'];
    $out = [];
    foreach ($want as $s) {
        $rc = 0; $o = [];
        @exec('systemctl is-active ' . escapeshellarg($s) . ' 2>/dev/null', $o, $rc);
        $state = trim($o[0] ?? 'unknown');
        if ($state === 'unknown' && $rc !== 0) continue;   // not installed here
        $out[$s] = $state;
    }
    return $out ? json_encode($out) : null;
}

$cpu  = cpu_usage_pct();
$mem  = mem_mb();
$disk = disk_gb();
$svc  = services_json();

say(sprintf('node=%s cpu=%s ram=%s/%sMB disk=%s/%sGB', $cfg['node'],
    $cpu ?? '-', $mem['used'] ?? '-', $mem['total'] ?? '-',
    $disk['used'] ?? '-', $disk['total'] ?? '-'));

try {
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        $cfg['db_host'], (int)($cfg['db_port'] ?? 3306), $cfg['db_name']);
    $pdo = new PDO($dsn, $cfg['db_user'], $cfg['db_pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_TIMEOUT => 10,
    ]);
    $sql = 'INSERT INTO system_health_snapshot
            (node, collected_at, cpu_usage_pct, ram_used_mb, ram_total_mb,
             disk_used_gb, disk_total_gb, disk_free_gb, services_json)
            VALUES (:node, NOW(), :cpu, :ru, :rt, :du, :dt, :df, :svc)';
    $pdo->prepare($sql)->execute([
        ':node' => $cfg['node'], ':cpu' => $cpu,
        ':ru' => $mem['used'] ?? null, ':rt' => $mem['total'] ?? null,
        ':du' => $disk['used'] ?? null, ':dt' => $disk['total'] ?? null,
        ':df' => $disk['free'] ?? null, ':svc' => $svc,
    ]);
    say('inserted ok');
} catch (Exception $e) {
    fail('db write failed: ' . $e->getMessage());
}
