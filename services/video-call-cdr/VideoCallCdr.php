<?php
/**
 * Video calls -> FusionPBX CDR.
 *
 * The WebRTC softphone sends video peer-to-peer through the Janus VideoCall
 * plugin (browser -> Janus -> browser). FreeSWITCH never sees those calls, so
 * until this existed they left no CDR at all: on 2026-10-06 a 1234 -> 1235
 * video call on tb.com produced not one line in the FreeSWITCH log.
 *
 * Janus reports what the plugin does through its event handlers. This class
 * turns that stream into one finished call per video call and hands it to a
 * writer that inserts a v_xml_cdr row.
 *
 * The events are thin, which shapes everything below:
 *   - "calling" (on the caller's handle) does not say who is being called
 *   - "accepted" (on the callee's handle) does not say who called
 *   - "hangup" is sent for BOTH handles, and by then the plugin has already
 *     forgotten the peer
 * So the peer is read from the Janus Admin API (handle_info) at the moment of
 * "calling"/"accepted", and the open call is remembered here until it ends.
 *
 * Identity: the VideoCall plugin takes whatever username the browser claims.
 * Nothing stops a browser registering as "100@someone-elses-domain". The SIP
 * registration on the SAME Janus session, though, was authenticated by
 * FreeSWITCH with the extension's password - so the caller is only believed
 * when that session also holds a registered SIP handle for the same extension.
 */

final class VideoCallCdr
{
    const PLUGIN = 'janus.plugin.videocall';
    const SIP_PLUGIN = 'janus.plugin.sip';

    const TYPE_HANDLE = 2;
    const TYPE_PLUGIN = 64;
    const TYPE_CORE = 256;

    /** An answered call nobody has heard from for this long is closed by the sweep. */
    const MAX_OPEN_SECONDS = 6 * 3600;

    private $state;
    private $admin;
    private $writer;
    private $log;
    private $enforceIdentity;

    /**
     * @param array    $state            persisted between requests; modified in place
     * @param callable $admin            fn(string $path, array $body): ?array  (Janus Admin API)
     * @param callable $writer           fn(array $cdr): bool  - false means "retry later"
     * @param callable $log              fn(string $line)
     * @param bool     $enforceIdentity  drop calls whose caller fails the SIP check
     */
    public function __construct(array &$state, callable $admin, callable $writer, callable $log, $enforceIdentity = true)
    {
        $state += ['calls' => [], 'users' => [], 'pending' => [], 'last_event_us' => 0, 'last_sweep' => 0];
        $this->state = &$state;
        $this->admin = $admin;
        $this->writer = $writer;
        $this->log = $log;
        $this->enforceIdentity = (bool) $enforceIdentity;
    }

    /** Process one POST from the sampleevh handler (a single event, or a batch). */
    public function handle(array $payload)
    {
        $events = isset($payload['type']) ? [$payload] : $payload;
        foreach ($events as $event) {
            if (is_array($event)) {
                $this->handleEvent($event);
            }
        }
        $this->flushPending();
    }

    private function handleEvent(array $e)
    {
        $type = (int) ($e['type'] ?? 0);
        $ts = (int) ($e['timestamp'] ?? 0) ?: self::nowUs();
        $session = (string) ($e['session_id'] ?? '');
        $handle = (string) ($e['handle_id'] ?? '');
        $hk = $session . ':' . $handle;
        $body = $e['event'] ?? [];
        $this->state['last_event_us'] = max($this->state['last_event_us'], $ts);

        if ($type === self::TYPE_CORE) {
            // Janus started or is stopping: every peer connection is gone with it.
            $status = $body['status'] ?? '';
            if ($status === 'started' || $status === 'shutdown') {
                $this->closeAll($ts, 'janus ' . $status);
            }
            return;
        }

        if ($type === self::TYPE_HANDLE) {
            if (($body['name'] ?? '') === 'detached' && ($body['plugin'] ?? '') === self::PLUGIN) {
                unset($this->state['users'][$hk]);
                // A tab closed or reloaded mid-call. The plugin also sends a
                // hangup, but this is the one that cannot be missed.
                $id = $this->findByHandle($hk);
                if ($id !== null) {
                    $this->close($id, $ts, $this->sideOf($id, $hk), 'handle detached');
                }
            }
            return;
        }

        if ($type !== self::TYPE_PLUGIN || ($body['plugin'] ?? '') !== self::PLUGIN) {
            return;
        }

        $data = $body['data'] ?? [];
        switch ($data['event'] ?? '') {
            case 'registered':
                if (!empty($data['username'])) {
                    $this->state['users'][$hk] = (string) $data['username'];
                }
                break;
            case 'calling':
                $this->onCalling($session, $handle, $ts);
                break;
            case 'accepted':
                $this->onAccepted($session, $handle, $ts);
                break;
            case 'hangup':
                $this->onHangup($hk, (string) ($data['reason'] ?? ''), $ts);
                break;
        }
    }

    private function onCalling($session, $handle, $ts)
    {
        $hk = $session . ':' . $handle;
        $info = $this->handleInfo($session, $handle);
        $caller = $info['username'] ?? ($this->state['users'][$hk] ?? null);
        $callee = $info['peer'] ?? null;
        if (!$caller || !$callee) {
            // Cancelled before we could ask - the plugin has already let go of
            // the peer. Nothing reliable to record.
            $this->say("calling on $hk: peer unknown (caller=" . ($caller ?: '?') . "), not recorded");
            return;
        }
        $this->state['users'][$hk] = $caller;

        list($ok, $why) = $this->verifyIdentity($session, $caller);
        if (!$ok) {
            $this->say("identity check FAILED for $caller on session $session: $why"
                . ($this->enforceIdentity ? ' - call not recorded' : ' - recording anyway (identity_check=log)'));
            if ($this->enforceIdentity) {
                return;
            }
        }

        $previous = $this->findByHandle($hk);
        if ($previous !== null) {
            $this->close($previous, $ts, 'caller', 'superseded by a new call');
        }

        $id = $hk . ':' . $ts;
        $this->state['calls'][$id] = [
            'caller' => $caller,
            'callee' => $callee,
            'caller_hk' => $hk,
            'callee_hk' => null,
            'start_us' => $ts,
            'answer_us' => null,
            'audio_codec' => null,
            'video_codec' => null,
        ];
        $this->say("calling  $caller -> $callee");
    }

    private function onAccepted($session, $handle, $ts)
    {
        $hk = $session . ':' . $handle;
        $info = $this->handleInfo($session, $handle);
        $callee = $info['username'] ?? ($this->state['users'][$hk] ?? null);
        $caller = $info['peer'] ?? null;
        if (!$callee) {
            $this->say("accepted on $hk: callee unknown, ignored");
            return;
        }

        // Latest ringing call to this callee (from this caller, when known).
        $match = null;
        foreach ($this->state['calls'] as $id => $c) {
            if ($c['answer_us'] !== null || $c['callee'] !== $callee) continue;
            if ($caller !== null && $c['caller'] !== $caller) continue;
            if ($match === null || $c['start_us'] > $this->state['calls'][$match]['start_us']) $match = $id;
        }
        if ($match === null) {
            $this->say("accepted by $callee: no ringing call found (caller=" . ($caller ?: '?') . ')');
            return;
        }

        $call = &$this->state['calls'][$match];
        $call['answer_us'] = $ts;
        $call['callee_hk'] = $hk;
        $call['audio_codec'] = $info['audio_codec'] ?? null;
        $call['video_codec'] = $info['video_codec'] ?? null;
        $this->say("answered {$call['caller']} -> $callee");
    }

    private function onHangup($hk, $reason, $ts)
    {
        $id = $this->findByHandle($hk);
        if ($id === null) {
            // The second notification of a pair, or a callee declining before
            // we knew its handle (the caller's "Remote hangup" follows).
            return;
        }
        $side = $this->sideOf($id, $hk);
        // "Explicit hangup" is reported on the handle that hung up; "Remote
        // hangup" / "Remote WebRTC hangup" on the handle that was hung up ON.
        $endedBy = $reason === 'Explicit hangup' ? $side : self::other($side);
        $this->close($id, $ts, $endedBy, $reason ?: 'hangup');
    }

    /**
     * Close calls whose handles have gone without a word - the safety net for
     * an event lost in transit. Run from the timer; cheap when nothing is open.
     */
    public function sweep($nowUs = null)
    {
        $nowUs = $nowUs ?: self::nowUs();
        $this->state['last_sweep'] = intdiv($nowUs, 1000000);
        foreach (array_keys($this->state['calls']) as $id) {
            $c = $this->state['calls'][$id];
            list($s, $h) = explode(':', $c['caller_hk']);
            $info = $this->handleInfo($s, $h);
            // The plugin links the two sessions when the call is placed, so a
            // live call - ringing or answered - always shows its peer here.
            $alive = $info !== null && (($info['peer'] ?? null) === $c['callee']);
            $tooOld = ($nowUs - $c['start_us']) > self::MAX_OPEN_SECONDS * 1000000;
            if (!$alive || $tooOld) {
                $this->close($id, $nowUs, 'caller', $tooOld ? 'open too long' : 'sweep: handle gone');
            }
        }
        $this->flushPending();
    }

    private function closeAll($ts, $why)
    {
        foreach (array_keys($this->state['calls']) as $id) {
            $this->close($id, $ts, null, $why);
        }
        $this->state['users'] = [];
    }

    private function close($id, $endUs, $endedBy, $why)
    {
        $c = $this->state['calls'][$id];
        unset($this->state['calls'][$id]);
        $answered = $c['answer_us'] !== null;

        if ($answered) {
            $status = 'answered'; $cause = 'NORMAL_CLEARING'; $q850 = 16; $missed = false;
        } elseif ($endedBy === null) {
            $status = 'failed'; $cause = 'NORMAL_TEMPORARY_FAILURE'; $q850 = 41; $missed = false;
        } elseif ($endedBy === 'callee') {
            $status = 'no_answer'; $cause = 'CALL_REJECTED'; $q850 = 21; $missed = false;
        } else {
            $status = 'missed'; $cause = 'ORIGINATOR_CANCEL'; $q850 = 487; $missed = true;
        }

        $endUs = max($endUs, $c['answer_us'] ?? $c['start_us'], $c['start_us']);
        $cdr = [
            'xml_cdr_uuid' => self::stableUuid('janus-videocall:' . $c['caller_hk'] . ':' . $c['start_us']),
            'sip_call_id' => 'janus-videocall-' . str_replace(':', '-', $c['caller_hk']),
            'caller' => $c['caller'],
            'callee' => $c['callee'],
            'start_us' => $c['start_us'],
            'answer_us' => $c['answer_us'],
            'end_us' => $endUs,
            'audio_codec' => $c['audio_codec'],
            'video_codec' => $c['video_codec'],
            'status' => $status,
            'hangup_cause' => $cause,
            'hangup_cause_q850' => $q850,
            'missed_call' => $missed,
            // From the caller leg's point of view, as FreeSWITCH writes it.
            'sip_hangup_disposition' => $answered ? ($endedBy === 'callee' ? 'send_bye' : 'recv_bye') : null,
        ];
        $this->say(sprintf('ended    %s -> %s  %s  %ds  (%s)', $c['caller'], $c['callee'], $status,
            $answered ? intdiv($endUs - $c['answer_us'], 1000000) : 0, $why));
        $this->state['pending'][$cdr['xml_cdr_uuid']] = $cdr;
    }

    /** Write finished calls; anything the database refused is kept for the next request. */
    private function flushPending()
    {
        foreach ($this->state['pending'] as $uuid => $cdr) {
            $ok = false;
            try {
                $ok = (bool) call_user_func($this->writer, $cdr);
            } catch (Throwable $t) {
                $this->say("write failed for $uuid: " . $t->getMessage());
            }
            if ($ok) {
                unset($this->state['pending'][$uuid]);
            }
        }
    }

    /** Does this Janus session hold a registered SIP handle for the same extension? */
    private function verifyIdentity($session, $username)
    {
        list($user, $domain) = self::splitUser($username);
        if ($user === '' || $domain === '') {
            return [false, "username '$username' is not ext@domain"];
        }
        $list = $this->admin("/$session", ['janus' => 'list_handles']);
        if (!is_array($list) || ($list['janus'] ?? '') !== 'success') {
            return [false, 'list_handles failed'];
        }
        $seen = [];
        foreach ((array) ($list['handles'] ?? []) as $h) {
            $resp = $this->admin("/$session/$h", ['janus' => 'handle_info']);
            $info = $resp['info'] ?? [];
            if (($info['plugin'] ?? '') !== self::SIP_PLUGIN) continue;
            $ps = $info['plugin_specific'] ?? [];
            // "identity" is the full SIP URI (sip:103@callcenter.cosmocom.net);
            // "username" is only the user part ("103"), so it cannot tell two
            // domains apart.
            $identity = (string) ($ps['identity'] ?? '');
            list($sipUser, $sipHost) = self::splitUser(preg_replace('/^sips?:/i', '', $identity));
            $reg = (string) ($ps['registration_status'] ?? '');
            $seen[] = ($identity ?: '?') . " ($reg)";
            if ($sipUser === $user && $sipHost === $domain && $reg === 'registered') {
                return [true, ''];
            }
        }
        return [false, $seen ? 'SIP handles on that session: ' . implode(', ', $seen) : 'no SIP handle on that session'];
    }

    private function handleInfo($session, $handle)
    {
        $resp = $this->admin("/$session/$handle", ['janus' => 'handle_info']);
        if (!is_array($resp) || ($resp['janus'] ?? '') !== 'success') {
            return null;
        }
        $info = $resp['info'] ?? [];
        if (($info['plugin'] ?? self::PLUGIN) !== self::PLUGIN) {
            return null;
        }
        return (array) ($info['plugin_specific'] ?? []);
    }

    private function admin($path, array $body)
    {
        try {
            return call_user_func($this->admin, $path, $body);
        } catch (Throwable $t) {
            $this->say("admin $path failed: " . $t->getMessage());
            return null;
        }
    }

    private function findByHandle($hk)
    {
        foreach ($this->state['calls'] as $id => $c) {
            if ($c['caller_hk'] === $hk || $c['callee_hk'] === $hk) return $id;
        }
        return null;
    }

    private function sideOf($id, $hk)
    {
        return $this->state['calls'][$id]['caller_hk'] === $hk ? 'caller' : 'callee';
    }

    private static function other($side)
    {
        return $side === 'caller' ? 'callee' : 'caller';
    }

    private function say($line)
    {
        call_user_func($this->log, $line);
    }

    /** "1234@tb.com" -> ["1234", "tb.com"]; a :port on the host is dropped. */
    public static function splitUser($username)
    {
        $parts = explode('@', (string) $username, 2);
        if (count($parts) !== 2) return ['', ''];
        return [trim($parts[0]), strtolower(preg_replace('/:\d+$/', '', trim($parts[1])))];
    }

    /** Same call -> same uuid, so a replayed event can never insert a second row. */
    public static function stableUuid($seed)
    {
        $h = md5($seed);
        return sprintf('%s-%s-5%s-%x%s-%s', substr($h, 0, 8), substr($h, 8, 4), substr($h, 13, 3),
            (hexdec($h[16]) & 0x3) | 0x8, substr($h, 17, 3), substr($h, 20, 12));
    }

    public static function nowUs()
    {
        return (int) round(microtime(true) * 1000000);
    }
}
