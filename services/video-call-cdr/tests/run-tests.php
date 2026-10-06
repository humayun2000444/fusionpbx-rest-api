<?php
/**
 * php tests/run-tests.php            logic only: fake Janus, fake database
 * php tests/run-tests.php --db       also runs the real insert against
 *                                    FusionPBX's database inside a transaction
 *                                    that is rolled back - nothing is kept
 *
 * The event shapes are copied from Janus (commit 2c1ca63, the build on CCL):
 * plugins/janus_videocall.c notify_event calls, ice.c handle events, janus.c
 * core events.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/Config.php';
require_once dirname(__DIR__) . '/VideoCallCdr.php';
require_once dirname(__DIR__) . '/CdrWriter.php';

/** A Janus with sessions, handles, and the plugin-specific info handle_info returns. */
final class FakeJanus
{
    public $handles = [];   // "s:h" => ['plugin' => ..., 'ps' => [...]]

    /** As janus_sip reports it on CCL: username is the bare user part, identity the full URI. */
    public function sipUser($s, $h, $user, $status = 'registered')
    {
        $this->handles["$s:$h"] = ['plugin' => VideoCallCdr::SIP_PLUGIN,
            'ps' => ['username' => explode('@', $user)[0], 'identity' => "sip:$user", 'registration_status' => $status]];
    }

    /** The softphone's call-waiting helper: a second SIP handle with no identity of its own. */
    public function sipHelper($s, $h, $user)
    {
        $this->handles["$s:$h"] = ['plugin' => VideoCallCdr::SIP_PLUGIN,
            'ps' => ['username' => explode('@', $user)[0], 'identity' => null, 'registration_status' => 'disabled', 'helper' => true]];
    }

    public function videoUser($s, $h, $user)
    {
        $this->handles["$s:$h"] = ['plugin' => VideoCallCdr::PLUGIN, 'ps' => ['state' => 'idle', 'username' => $user]];
    }

    public function link($callerHk, $calleeHk, $answered = false)
    {
        $this->handles[$callerHk]['ps']['peer'] = $this->handles[$calleeHk]['ps']['username'];
        $this->handles[$calleeHk]['ps']['peer'] = $this->handles[$callerHk]['ps']['username'];
        $this->handles[$callerHk]['ps']['state'] = $this->handles[$calleeHk]['ps']['state'] = 'incall';
        if ($answered) {
            foreach ([$callerHk, $calleeHk] as $hk) {
                $this->handles[$hk]['ps'] += ['audio_codec' => 'opus', 'video_codec' => 'vp8'];
            }
        }
    }

    public function unlink(...$hks)
    {
        foreach ($hks as $hk) {
            unset($this->handles[$hk]['ps']['peer']);
            $this->handles[$hk]['ps']['state'] = 'idle';
        }
    }

    public function __invoke($path, array $body)
    {
        $parts = array_values(array_filter(explode('/', $path), 'strlen'));
        if ($body['janus'] === 'list_handles') {
            $ids = [];
            foreach (array_keys($this->handles) as $hk) {
                list($s, $h) = explode(':', $hk);
                if ($s === $parts[0]) $ids[] = $h;
            }
            return ['janus' => 'success', 'handles' => $ids];
        }
        $hk = $parts[0] . ':' . ($parts[1] ?? '');
        if (!isset($this->handles[$hk])) {
            return ['janus' => 'error', 'error' => ['code' => 459, 'reason' => 'No such handle']];
        }
        return ['janus' => 'success', 'info' => ['plugin' => $this->handles[$hk]['plugin'], 'plugin_specific' => $this->handles[$hk]['ps']]];
    }
}

function ev($s, $h, $ts, $event, $extra = [])
{
    return ['type' => 64, 'timestamp' => $ts, 'session_id' => $s, 'handle_id' => $h,
        'event' => ['plugin' => VideoCallCdr::PLUGIN, 'data' => ['event' => $event] + $extra]];
}

function detached($s, $h, $ts)
{
    return ['type' => 2, 'timestamp' => $ts, 'session_id' => $s, 'handle_id' => $h,
        'event' => ['name' => 'detached', 'plugin' => VideoCallCdr::PLUGIN]];
}

const T = 1791281673000000; // 2026-10-06 10:14:33 UTC, in microseconds
function at($seconds) { return T + (int) ($seconds * 1000000); }

/** Two agents on tb.com, each with a SIP and a VideoCall handle on one session. */
function world()
{
    $j = new FakeJanus();
    $j->sipHelper('100', '3', '1234@tb.com');
    $j->sipUser('100', '1', '1234@tb.com');
    $j->videoUser('100', '2', '1234@tb.com');
    $j->sipUser('200', '1', '1235@tb.com');
    $j->videoUser('200', '2', '1235@tb.com');
    return $j;
}

function run(FakeJanus $j, array $batches, &$state = null, $enforce = true, $failWrites = 0)
{
    $state = $state ?: [];
    $rows = [];
    $logs = [];
    $writer = function ($cdr) use (&$rows, &$failWrites) {
        if ($failWrites-- > 0) return false;
        $rows[] = $cdr;
        return true;
    };
    $c = new VideoCallCdr($state, $j, $writer, function ($l) use (&$logs) { $logs[] = $l; }, $enforce);
    foreach ($batches as $b) {
        if (is_callable($b)) { $b($c); continue; }
        $c->handle($b);
    }
    return [$rows, $logs, $state];
}

$failures = 0;
$checks = 0;
function check($name, $cond, $detail = '')
{
    global $failures, $checks;
    $checks++;
    if ($cond) {
        echo "  ok    $name\n";
    } else {
        $failures++;
        echo "  FAIL  $name" . ($detail !== '' ? "  -- $detail" : '') . "\n";
    }
}

echo "answered call, caller hangs up\n";
$j = world();
list($rows) = run($j, [
    [ev('100', '2', at(0), 'registered', ['username' => '1234@tb.com']), ev('200', '2', at(0), 'registered', ['username' => '1235@tb.com'])],
    function () use ($j) { $j->link('100:2', '200:2'); },
    [ev('100', '2', at(1), 'calling')],
    function () use ($j) { $j->link('100:2', '200:2', true); },
    [ev('200', '2', at(7), 'accepted')],
    function () use ($j) { $j->unlink('100:2', '200:2'); },
    // As the plugin sends it: Explicit on the hanger-up, Remote on the other.
    [ev('100', '2', at(29), 'hangup', ['reason' => 'Explicit hangup']), ev('200', '2', at(29), 'hangup', ['reason' => 'Remote hangup'])],
]);
$r = $rows[0] ?? [];
check('exactly one CDR', count($rows) === 1, count($rows) . ' rows');
check('caller and callee', ($r['caller'] ?? '') === '1234@tb.com' && ($r['callee'] ?? '') === '1235@tb.com');
check('answered / NORMAL_CLEARING', ($r['status'] ?? '') === 'answered' && ($r['hangup_cause'] ?? '') === 'NORMAL_CLEARING');
check('talk time 22 s', (($r['end_us'] - $r['answer_us']) / 1e6) == 22, ($r['end_us'] - $r['answer_us']) / 1e6);
check('caller hung up -> recv_bye', ($r['sip_hangup_disposition'] ?? '') === 'recv_bye');
check('codecs from the callee handle', ($r['audio_codec'] ?? '') === 'opus' && ($r['video_codec'] ?? '') === 'vp8');
$firstUuid = $r['xml_cdr_uuid'] ?? '';
check('uuid is well formed', (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-5[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $firstUuid), $firstUuid);

echo "the same events again give the same uuid (no duplicate row)\n";
$j = world();
list($rows) = run($j, [
    function () use ($j) { $j->link('100:2', '200:2'); },
    [ev('100', '2', at(1), 'calling')],
    function () use ($j) { $j->link('100:2', '200:2', true); },
    [ev('200', '2', at(7), 'accepted')],
    [ev('100', '2', at(29), 'hangup', ['reason' => 'Explicit hangup'])],
]);
check('stable uuid', ($rows[0]['xml_cdr_uuid'] ?? '') === $firstUuid);

echo "callee hangs up an answered call\n";
$j = world();
list($rows) = run($j, [
    function () use ($j) { $j->link('100:2', '200:2'); },
    [ev('100', '2', at(1), 'calling')],
    function () use ($j) { $j->link('100:2', '200:2', true); },
    [ev('200', '2', at(4), 'accepted')],
    [[ 'type' => 64, 'timestamp' => at(10), 'session_id' => '200', 'handle_id' => '2',
       'event' => ['plugin' => VideoCallCdr::PLUGIN, 'data' => ['event' => 'hangup', 'reason' => 'Explicit hangup']]],
     ev('100', '2', at(10), 'hangup', ['reason' => 'Remote hangup'])],
]);
check('callee hung up -> send_bye', ($rows[0]['sip_hangup_disposition'] ?? '') === 'send_bye');
check('one row only', count($rows) === 1);

echo "caller cancels while ringing\n";
$j = world();
list($rows) = run($j, [
    function () use ($j) { $j->link('100:2', '200:2'); },
    [ev('100', '2', at(1), 'calling')],
    [ev('100', '2', at(9), 'hangup', ['reason' => 'Explicit hangup']), ev('200', '2', at(9), 'hangup', ['reason' => 'Remote hangup'])],
]);
$r = $rows[0] ?? [];
check('missed / ORIGINATOR_CANCEL / missed_call', ($r['status'] ?? '') === 'missed' && ($r['hangup_cause'] ?? '') === 'ORIGINATOR_CANCEL' && ($r['missed_call'] ?? false) === true);
check('not answered', array_key_exists('answer_us', $r) && $r['answer_us'] === null);

echo "callee declines\n";
$j = world();
list($rows) = run($j, [
    function () use ($j) { $j->link('100:2', '200:2'); },
    [ev('100', '2', at(1), 'calling')],
    // The callee's handle is unknown to us until it accepts.
    [ev('200', '2', at(5), 'hangup', ['reason' => 'Explicit hangup']), ev('100', '2', at(5), 'hangup', ['reason' => 'Remote hangup'])],
]);
$r = $rows[0] ?? [];
check('no_answer / CALL_REJECTED', ($r['status'] ?? '') === 'no_answer' && ($r['hangup_cause'] ?? '') === 'CALL_REJECTED');
check('not counted as missed', ($r['missed_call'] ?? true) === false);

echo "tab closed mid-call (handle detached, hangups arrive after)\n";
$j = world();
list($rows) = run($j, [
    function () use ($j) { $j->link('100:2', '200:2'); },
    [ev('100', '2', at(1), 'calling')],
    function () use ($j) { $j->link('100:2', '200:2', true); },
    [ev('200', '2', at(3), 'accepted')],
    [detached('200', '2', at(40)), ev('100', '2', at(41), 'hangup', ['reason' => 'Remote WebRTC hangup'])],
]);
check('closed at the detach', count($rows) === 1 && $rows[0]['end_us'] === at(40));
check('still answered', ($rows[0]['status'] ?? '') === 'answered');

echo "two calls at once are paired correctly\n";
$j = world();
$j->sipUser('300', '1', '1236@tb.com'); $j->videoUser('300', '2', '1236@tb.com');
$j->sipUser('400', '1', '1237@tb.com'); $j->videoUser('400', '2', '1237@tb.com');
list($rows) = run($j, [
    function () use ($j) { $j->link('100:2', '200:2'); $j->link('300:2', '400:2'); },
    [ev('100', '2', at(1), 'calling'), ev('300', '2', at(2), 'calling')],
    function () use ($j) { $j->link('100:2', '200:2', true); $j->link('300:2', '400:2', true); },
    [ev('400', '2', at(5), 'accepted'), ev('200', '2', at(6), 'accepted')],
    [ev('300', '2', at(20), 'hangup', ['reason' => 'Explicit hangup'])],
    [ev('200', '2', at(30), 'hangup', ['reason' => 'Explicit hangup'])],
]);
$by = [];
foreach ($rows as $row) { $by[$row['caller']] = $row; }
check('1236 -> 1237, 15 s', isset($by['1236@tb.com']) && $by['1236@tb.com']['callee'] === '1237@tb.com'
    && ($by['1236@tb.com']['end_us'] - $by['1236@tb.com']['answer_us']) / 1e6 == 15);
check('1234 -> 1235, 24 s, callee hung up', isset($by['1234@tb.com']) && $by['1234@tb.com']['callee'] === '1235@tb.com'
    && ($by['1234@tb.com']['end_us'] - $by['1234@tb.com']['answer_us']) / 1e6 == 24
    && $by['1234@tb.com']['sip_hangup_disposition'] === 'send_bye');

echo "identity: VideoCall username not backed by a SIP registration\n";
$j = world();
$j->sipUser('100', '1', '9999@tb.com');      // the session is really 9999
list($rows, $logs) = run($j, [
    function () use ($j) { $j->link('100:2', '200:2'); },
    [ev('100', '2', at(1), 'calling')],
    [ev('100', '2', at(5), 'hangup', ['reason' => 'Explicit hangup'])],
]);
check('enforce: not recorded', count($rows) === 0);
check('enforce: logged', (bool) preg_grep('/identity check FAILED for 1234@tb.com/', $logs));
$j = world();
$j->sipUser('100', '1', '1234@other.com');
list($rows) = run($j, [
    function () use ($j) { $j->link('100:2', '200:2'); },
    [ev('100', '2', at(1), 'calling')],
    [ev('100', '2', at(5), 'hangup', ['reason' => 'Explicit hangup'])],
]);
check('enforce: same extension, other domain -> not recorded', count($rows) === 0);
$j = world();
$j->sipUser('100', '1', '1234@tb.com', 'unregistered');
list($rows) = run($j, [
    function () use ($j) { $j->link('100:2', '200:2'); },
    [ev('100', '2', at(1), 'calling')],
    [ev('100', '2', at(5), 'hangup', ['reason' => 'Explicit hangup'])],
]);
check('enforce: SIP handle not registered -> not recorded', count($rows) === 0);
$j = new FakeJanus();
$j->videoUser('100', '2', '1234@tb.com'); $j->videoUser('200', '2', '1235@tb.com');
$j->sipHelper('100', '3', '1234@tb.com');   // only the helper, no real registration
list($rows) = run($j, [
    function () use ($j) { $j->link('100:2', '200:2'); },
    [ev('100', '2', at(1), 'calling')],
    [ev('100', '2', at(5), 'hangup', ['reason' => 'Explicit hangup'])],
]);
check('enforce: a helper handle alone does not vouch', count($rows) === 0);
$j = world();
$j->sipUser('100', '1', '9999@tb.com');
$st = null;
list($rows) = run($j, [
    function () use ($j) { $j->link('100:2', '200:2'); },
    [ev('100', '2', at(1), 'calling')],
    [ev('100', '2', at(5), 'hangup', ['reason' => 'Explicit hangup'])],
], $st, false);
check('identity_check=log: recorded anyway', count($rows) === 1);

echo "Janus restarts with a call ringing\n";
$j = world();
list($rows) = run($j, [
    function () use ($j) { $j->link('100:2', '200:2'); },
    [ev('100', '2', at(1), 'calling')],
    [['type' => 256, 'subtype' => 2, 'timestamp' => at(8), 'event' => ['status' => 'shutdown']]],
]);
check('failed / NORMAL_TEMPORARY_FAILURE', ($rows[0]['status'] ?? '') === 'failed' && $rows[0]['end_us'] === at(8));

echo "database down: the call is kept and written later\n";
$j = world();
$state = null;
list($rows, , $state) = run($j, [
    function () use ($j) { $j->link('100:2', '200:2'); },
    [ev('100', '2', at(1), 'calling')],
    [ev('100', '2', at(5), 'hangup', ['reason' => 'Explicit hangup'])],
], $state, true, 1);
check('nothing written while down', count($rows) === 0);
check('kept as unwritten', count($state['pending']) === 1);
list($rows, , $state) = run($j, [[ev('100', '2', at(60), 'registered', ['username' => '1234@tb.com'])]], $state);
check('written on the next event', count($rows) === 1 && count($state['pending']) === 0);

echo "sweep: the handle vanished without an event\n";
$j = world();
$state = null;
list($rows, , $state) = run($j, [
    function () use ($j) { $j->link('100:2', '200:2'); },
    [ev('100', '2', at(1), 'calling')],
    function () use ($j) { $j->link('100:2', '200:2', true); },
    [ev('200', '2', at(3), 'accepted')],
    function (VideoCallCdr $c) { $c->sweep(at(30)); },
], $state);
check('live call left alone', count($rows) === 0 && count($state['calls']) === 1);
unset($j->handles['100:2']);
list($rows, , $state) = run($j, [function (VideoCallCdr $c) { $c->sweep(at(90)); }], $state);
check('gone call closed', count($rows) === 1 && count($state['calls']) === 0);

echo "calling with the peer already gone is not recorded (no guessing)\n";
$j = world();
list($rows, $logs) = run($j, [[ev('100', '2', at(1), 'calling')]]);
check('nothing recorded', count($rows) === 0);

echo "other plugins and event types are ignored\n";
$j = world();
list($rows, , $state) = run($j, [[
    ['type' => 64, 'timestamp' => at(1), 'session_id' => '100', 'handle_id' => '1',
     'event' => ['plugin' => 'janus.plugin.sip', 'data' => ['event' => 'calling']]],
    ['type' => 2, 'timestamp' => at(1), 'session_id' => '100', 'handle_id' => '1',
     'event' => ['name' => 'detached', 'plugin' => 'janus.plugin.sip']],
]]);
check('no calls, no rows', count($rows) === 0 && count($state['calls']) === 0);

if (in_array('--db', $argv, true)) {
    echo "database: real insert into v_xml_cdr, rolled back\n";
    $c = Config::fusionpbxDatabase('/etc/fusionpbx/config.conf');
    $pdo = new PDO("pgsql:host={$c['host']};port={$c['port']};dbname={$c['name']}", $c['username'], $c['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => true]);
    $domain = getenv('TEST_DOMAIN') ?: 'tb.com';
    $from = getenv('TEST_FROM') ?: '1234';
    $to = getenv('TEST_TO') ?: '1235';
    $logs = [];
    $writer = new CdrWriter('/etc/fusionpbx/config.conf', function ($l) use (&$logs) { $logs[] = $l; }, $pdo);
    $uuid = VideoCallCdr::stableUuid('test-' . microtime(true));
    $pdo->beginTransaction();
    try {
        $ok = $writer([
            'xml_cdr_uuid' => $uuid, 'sip_call_id' => 'janus-videocall-test',
            'caller' => "$from@$domain", 'callee' => "$to@$domain",
            'start_us' => at(0), 'answer_us' => at(6), 'end_us' => at(28),
            'audio_codec' => 'opus', 'video_codec' => 'vp8',
            'status' => 'answered', 'hangup_cause' => 'NORMAL_CLEARING', 'hangup_cause_q850' => 16,
            'missed_call' => false, 'sip_hangup_disposition' => 'recv_bye',
        ]);
        $row = $pdo->query("SELECT domain_name, extension_uuid IS NOT NULL AS has_ext,
                (SELECT extension FROM v_extensions e WHERE e.extension_uuid = v_xml_cdr.extension_uuid) AS filed_under, direction, caller_id_number,
                destination_number, billsec, duration, waitsec, status, missed_call, hangup_cause, read_codec,
                last_app, last_arg, start_stamp, answer_stamp, end_stamp
              FROM v_xml_cdr WHERE xml_cdr_uuid = " . $pdo->quote($uuid))->fetch(PDO::FETCH_ASSOC);
        check('writer reports success', $ok === true, implode(' | ', $logs));
        check('row inserted', is_array($row), implode(' | ', $logs));
        if ($row) {
            echo '        ' . json_encode($row) . "\n";
            check('billsec 22, duration 28, waitsec 6', $row['billsec'] == 22 && $row['duration'] == 28 && $row['waitsec'] == 6);
            check('extension resolved', $row['has_ext'] === true || $row['has_ext'] === 't');
            check('filed under the callee, like FusionPBX', $row['filed_under'] === $to, (string) $row['filed_under']);
            check('marked as video', $row['last_app'] === 'videocall' && $row['last_arg'] === 'video VP8');
        }
        $again = $writer([
            'xml_cdr_uuid' => $uuid, 'sip_call_id' => 'x', 'caller' => "$from@$domain", 'callee' => "$to@$domain",
            'start_us' => at(0), 'answer_us' => null, 'end_us' => at(1), 'audio_codec' => null, 'video_codec' => null,
            'status' => 'missed', 'hangup_cause' => 'ORIGINATOR_CANCEL', 'hangup_cause_q850' => 487,
            'missed_call' => true, 'sip_hangup_disposition' => null,
        ]);
        $n = $pdo->query("SELECT count(*) FROM v_xml_cdr WHERE xml_cdr_uuid = " . $pdo->quote($uuid))->fetchColumn();
        check('same uuid twice -> still one row', $again === true && (int) $n === 1);
        $skip = $writer(['caller' => "$from@$domain", 'callee' => "$to@elsewhere.example"] + ['xml_cdr_uuid' => VideoCallCdr::stableUuid('x2')]);
        check('cross-domain call skipped', $skip === true && (bool) preg_grep('/not one domain/', $logs));
    } finally {
        $pdo->rollBack();
    }
    $left = $pdo->query("SELECT count(*) FROM v_xml_cdr WHERE xml_cdr_uuid = " . $pdo->quote($uuid))->fetchColumn();
    check('rolled back, nothing left behind', (int) $left === 0);
}

echo "\n$checks checks, $failures failed\n";
exit($failures ? 1 : 0);
