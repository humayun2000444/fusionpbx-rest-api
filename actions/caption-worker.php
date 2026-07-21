<?php
/**
 * caption-worker.php — live-caption daemon (PoC).
 *
 * Tails the growing uuid_record WAV of each active caption job, slices ~4 s
 * chunks, sends speechy chunks to ElevenLabs Scribe (speech-to-text), and
 * stores caption rows for the dashboard to poll via caption-api.php.
 *
 * Run:  nohup /usr/bin/php /var/www/fusionpbx/app/rest_api/actions/caption-worker.php \
 *         > /var/log/caption_worker.log 2>&1 &
 * Stop: kill -TERM $(cat /var/run/fusionpbx/caption_worker.pid)
 */

if (php_sapi_name() !== 'cli') { http_response_code(403); exit; }

const DB_DSN  = 'pgsql:host=127.0.0.1;dbname=fusionpbx';
const DB_USER = 'fusionpbx';
const DB_PASS = 'Takay1takaane';
const XI_KEY  = 'sk_7d78907af456db3a031a893334fc328b23563d790de8ef6f';

// STT backend: 'whisper' (self-hosted Bangla fine-tune on .98) | 'scribe' (ElevenLabs)
const STT_PROVIDER = 'whisper';
const WHISPER_URL  = 'http://103.95.96.98:5090/transcribe';
const WHISPER_KEY  = 'whisper_ccl_key';

const CHUNK_SECONDS    = 3.0;   // audio per Scribe request
const MIN_TAIL_SECONDS = 0.6;   // flush remainder >= this on hangup
const RMS_GATE         = 380;   // int16 RMS below this = silence/noise, skip chunk
const JOB_MAX_AGE_MIN  = 30;    // hard stop per job
const PIDFILE          = '/var/run/fusionpbx/caption_worker.pid';
// Force STT language to avoid auto-LID mislabeling short telephony audio
// (Scribe otherwise guesses Hindi/Devanagari for Bangla/English clips).
// 'ben' = Bangla, 'eng' = English, '' = auto-detect.
const LANG_FORCE       = 'ben';

function logmsg($m) { echo '[' . date('Y-m-d H:i:s') . "] $m\n"; }

function db() {
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO(DB_DSN, DB_USER, DB_PASS, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
    }
    return $pdo;
}

function cap_uuid4() {
    $b = random_bytes(16);
    $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
    $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
}

function cap_esl_api($cmd) {
    $fp = @fsockopen('127.0.0.1', 8021, $errno, $errstr, 3);
    if (!$fp) return null;
    stream_set_timeout($fp, 3);
    stream_get_line($fp, 4096, "\n\n");
    fputs($fp, "auth ClueCon\n\n");
    $auth = stream_get_line($fp, 4096, "\n\n");
    if (strpos($auth, '+OK') === false) { fclose($fp); return null; }
    fputs($fp, "api $cmd\n\n");
    $headers = stream_get_line($fp, 4096, "\n\n");
    $body = '';
    if (preg_match('/Content-Length:\s*(\d+)/i', $headers, $m)) {
        $need = (int)$m[1];
        while (strlen($body) < $need && !feof($fp)) {
            $chunk = fread($fp, $need - strlen($body));
            if ($chunk === false) break;
            $body .= $chunk;
        }
    }
    fclose($fp);
    return $body;
}

/** null = ESL unavailable (assume alive), true/false otherwise. */
function call_alive($uuid) {
    $r = cap_esl_api("uuid_exists $uuid");
    if ($r === null) return null;
    return trim($r) === 'true';
}

/** Parse rate/channels/data offset from a (possibly still-growing) WAV. */
function wav_info($path) {
    $fh = @fopen($path, 'rb');
    if (!$fh) return null;
    $hdr = fread($fh, 512);
    fclose($fh);
    if (strlen($hdr) < 44 || substr($hdr, 0, 4) !== 'RIFF' || substr($hdr, 8, 4) !== 'WAVE') return null;
    $channels = unpack('v', substr($hdr, 22, 2))[1] ?: 1;
    $rate     = unpack('V', substr($hdr, 24, 4))[1] ?: 8000;
    $dpos = strpos($hdr, 'data');
    if ($dpos === false) return null;
    return array('channels' => $channels, 'rate' => $rate, 'data_offset' => $dpos + 8);
}

function make_wav($pcm, $rate, $channels) {
    $len = strlen($pcm);
    return 'RIFF' . pack('V', 36 + $len) . 'WAVEfmt ' . pack('V', 16)
        . pack('v', 1) . pack('v', $channels) . pack('V', $rate)
        . pack('V', $rate * $channels * 2) . pack('v', $channels * 2) . pack('v', 16)
        . 'data' . pack('V', $len) . $pcm;
}

/** Approximate int16 RMS over a sampled subset of the chunk. */
function rms16($pcm) {
    $n = intdiv(strlen($pcm), 2);
    if ($n === 0) return 0;
    $step = max(1, intdiv($n, 4000));
    $sum = 0.0; $cnt = 0;
    for ($i = 0; $i < $n; $i += $step) {
        $v = unpack('s', substr($pcm, $i * 2, 2))[1];
        $sum += $v * $v;
        $cnt++;
    }
    return (int)sqrt($sum / max(1, $cnt));
}

/** Deinterleave one channel (0=left,1=right) out of 16-bit stereo PCM. */
function deinterleave($pcm, $ch) {
    $n = intdiv(strlen($pcm), 4);
    $off = $ch * 2;
    $parts = array();
    for ($i = 0; $i < $n; $i++) {
        $b = $i * 4 + $off;
        $parts[] = $pcm[$b] . $pcm[$b + 1];
    }
    return implode('', $parts);
}

/**
 * Find a byte offset near $target to cut the chunk on the quietest ~20ms frame
 * (a speech pause), so words are not sliced mid-syllable. Returns a byte offset.
 */
function find_silence_cut($pcm, $bps, $target) {
    $len = strlen($pcm);
    if ($target >= $len) return $len;
    $frame  = max(64, (int)($bps * 0.02));   // ~20 ms
    $search = (int)($bps * 0.6);             // look back up to 600 ms
    $start  = max(0, $target - $search);
    $best = $target; $bestRms = PHP_INT_MAX;
    for ($p = $start; $p + $frame <= min($len, $target + $frame * 3); $p += $frame) {
        $r = rms16(substr($pcm, $p, $frame));
        if ($r < $bestRms) { $bestRms = $r; $best = $p + $frame; }
    }
    return $best;
}

/** Strip hallucinated audio-event annotations like "(phone sound)" / "[music]". */
function clean_caption($text) {
    if ($text === null) return '';
    $t = preg_replace('/[\(\[][^\)\]]*[\)\]]/u', ' ', $text); // drop (...) and [...]
    $t = preg_replace('/\s+/u', ' ', $t);
    return trim($t);
}

/** Send one WAV chunk to the self-hosted Whisper service. Returns [text, language, error]. */
function whisper($wav) {
    $ch = curl_init(WHISPER_URL . '?key=' . WHISPER_KEY);
    curl_setopt_array($ch, array(
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => array('Content-Type: application/octet-stream'),
        CURLOPT_POSTFIELDS     => $wav,
    ));
    $out  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $cerr = curl_error($ch);
    curl_close($ch);
    if ($code !== 200 || !$out) return array(null, null, "whisper http $code $cerr: " . substr((string)$out, 0, 200));
    $j = json_decode($out, true);
    if (!($j['ok'] ?? false)) return array(null, null, 'whisper: ' . ($j['error'] ?? 'unknown'));
    return array(trim($j['text'] ?? ''), $j['language'] ?? null, null);
}

/** Dispatch to the configured STT backend. Returns [text, language, error]. */
function stt_transcribe($wav) {
    return STT_PROVIDER === 'whisper' ? whisper($wav) : scribe($wav);
}

/** Send one WAV chunk to ElevenLabs Scribe. Returns [text, language, error]. */
function scribe($wav) {
    $tmp = tempnam(sys_get_temp_dir(), 'cap_') . '.wav';
    file_put_contents($tmp, $wav);
    $fields = array(
        'model_id'         => 'scribe_v1',
        'tag_audio_events' => 'false',   // stop "(laughter)/(phone sound)" hallucinations
        'diarize'          => 'false',
        'file'             => new CURLFile($tmp, 'audio/wav', 'chunk.wav'),
    );
    if (LANG_FORCE !== '') $fields['language_code'] = LANG_FORCE;
    $ch = curl_init('https://api.elevenlabs.io/v1/speech-to-text');
    curl_setopt_array($ch, array(
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_HTTPHEADER     => array('xi-api-key: ' . XI_KEY),
        CURLOPT_POSTFIELDS     => $fields,
    ));
    $out  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $cerr = curl_error($ch);
    curl_close($ch);
    @unlink($tmp);
    if ($code !== 200 || !$out) {
        return array(null, null, "http $code $cerr: " . substr((string)$out, 0, 200));
    }
    $j = json_decode($out, true);
    return array(trim($j['text'] ?? ''), $j['language_code'] ?? null, null);
}

function finish($job, $status) {
    // Best-effort stop of the recording bug (no-op if the call is gone).
    cap_esl_api("uuid_record {$job['call_uuid']} stop {$job['record_path']}");
    db()->prepare("UPDATE v_caption_jobs SET status = ?, updated = now() WHERE job_uuid = ?")
        ->execute(array($status, $job['job_uuid']));
    logmsg("job {$job['job_uuid']} -> $status");
}

function process_job($job) {
    $path = $job['record_path'];

    if (strtotime($job['created']) < time() - JOB_MAX_AGE_MIN * 60) { finish($job, 'done'); return; }

    if (!file_exists($path)) {
        // Recording not started yet; fail the job if it never appears.
        if (strtotime($job['created']) < time() - 20) finish($job, 'failed');
        return;
    }

    clearstatcache(true, $path);
    $size = filesize($path);
    $info = wav_info($path);
    if (!$info) return;

    $offset = (int)$job['byte_offset'];
    if ($offset < $info['data_offset']) $offset = $info['data_offset'];

    $bps         = $info['rate'] * $info['channels'] * 2;   // bytes per second
    $chunk_bytes = (int)($bps * CHUNK_SECONDS);
    $alive       = call_alive($job['call_uuid']);
    $avail       = $size - $offset;

    if ($avail >= $chunk_bytes || ($alive === false && $avail >= $bps * MIN_TAIL_SECONDS)) {
        $frame_bytes = $info['channels'] * 2;
        // Read the target chunk plus a little extra to find a silence boundary.
        $read_len = min($avail, $chunk_bytes + (int)($bps * 0.4));
        $fh = fopen($path, 'rb');
        fseek($fh, $offset);
        $win = fread($fh, $read_len);
        fclose($fh);

        // How much to consume this round: snap to a pause (live) or flush all (ended).
        if ($alive === false) {
            $cut = strlen($win);
        } else {
            $cut = find_silence_cut($win, $bps, min($chunk_bytes, strlen($win)));
        }
        $cut -= $cut % $frame_bytes;
        if ($cut < $frame_bytes) $cut = min(strlen($win), $chunk_bytes);
        $chunk = substr($win, 0, $cut);
        $offset += strlen($chunk);

        // Per-speaker streams for stereo; single stream for mono.
        $streams = ($info['channels'] === 2)
            ? array(array(0, deinterleave($chunk, 0)), array(1, deinterleave($chunk, 1)))
            : array(array(null, $chunk));

        foreach ($streams as $s) {
            list($spk, $mono) = $s;
            if (rms16($mono) < RMS_GATE) continue;            // this speaker silent this chunk
            // Pad ~300ms silence both sides so VAD doesn't clip the first/last word.
            $pad  = str_repeat("\x00", (int)($info['rate'] * 2 * 0.3));
            list($text, $lang, $err) = stt_transcribe(make_wav($pad . $mono . $pad, $info['rate'], 1));
            if ($err !== null) { logmsg("job {$job['job_uuid']} scribe error: $err"); continue; }
            $text = clean_caption($text);
            if ($text === '') continue;
            $seq = (int)$job['seq'] + 1;
            $job['seq'] = $seq;
            db()->prepare("INSERT INTO v_call_captions (caption_uuid, call_uuid, seq, speaker, caption_text, caption_language) VALUES (?, ?, ?, ?, ?, ?)")
                ->execute(array(cap_uuid4(), $job['call_uuid'], $seq, $spk, $text, $lang));
            logmsg("job {$job['job_uuid']} seq $seq spk " . ($spk === null ? '-' : $spk) . " [$lang] " . mb_substr($text, 0, 70));
        }

        db()->prepare("UPDATE v_caption_jobs SET seq = ?, byte_offset = ?, updated = now() WHERE job_uuid = ?")
            ->execute(array((int)$job['seq'], $offset, $job['job_uuid']));

        if ($alive === false && ($size - $offset) < $bps * MIN_TAIL_SECONDS) finish($job, 'done');
        return;
    }

    if ($alive === false && ($size - $offset) < $bps * MIN_TAIL_SECONDS) finish($job, 'done');
}

// ---- main ----
@mkdir(dirname(PIDFILE), 0755, true);
// Singleton guard: refuse to start if another instance holds the lock.
// Prevents duplicate workers racing the same jobs (duplicate captions).
$lockfp = fopen('/var/run/fusionpbx/caption_worker.lock', 'c');
if (!$lockfp || !flock($lockfp, LOCK_EX | LOCK_NB)) {
    logmsg('another caption worker is already running; exiting');
    exit(0);
}
file_put_contents(PIDFILE, getmypid());
logmsg('caption worker started, pid ' . getmypid());

while (true) {
    try {
        $jobs = db()->query("SELECT * FROM v_caption_jobs WHERE status = 'active'")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($jobs as $job) {
            try { process_job($job); }
            catch (Throwable $e) { logmsg("job {$job['job_uuid']} error: " . $e->getMessage()); }
        }
    } catch (Throwable $e) {
        logmsg('loop error: ' . $e->getMessage());
        sleep(3);
    }
    sleep(1);
}
