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

const MIN_TAIL_SECONDS = 0.6;   // flush remainder >= this on hangup
const RMS_GATE         = 380;   // int16 RMS below this = silence/noise, skip chunk
const JOB_MAX_AGE_MIN  = 30;    // hard stop per job
const PIDFILE          = '/var/run/fusionpbx/caption_worker.pid';

// --- VAD utterance segmentation (replaces fixed-time chunking) ---
// A caption is emitted at the END of each spoken utterance — i.e. when the
// speaker pauses for >= SILENCE_HANG_MS — instead of on a fixed 3 s clock.
// Short phrases flush the moment the speaker pauses (much lower latency), and
// words are never sliced mid-syllable (much higher accuracy: Whisper gets a
// whole utterance with context, not a 0.7 s fragment). Non-stop speech is
// force-cut at MAX_UTTERANCE_S so latency stays bounded.
const FRAME_MS         = 30;      // VAD analysis frame length
const SPEECH_RMS       = 380;     // frame int16 RMS >= this = speech
const SILENCE_HANG_MS  = 500;     // trailing silence that ends an utterance
const MIN_SPEECH_MS    = 350;     // ignore utterances with less speech than this
const MAX_UTTERANCE_S  = 6.0;     // hard cut for non-stop speech
const PRE_ROLL_MS      = 200;     // keep this much audio before speech onset
const LOOP_SLEEP_US    = 300000;  // main poll interval, µs (was sleep(1))
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

/** Average all channels down to mono int16 PCM (used only for VAD analysis). */
function mono_mix($pcm, $channels) {
    if ($channels === 1) return $pcm;
    $frame = $channels * 2;
    $n = intdiv(strlen($pcm), $frame);
    $out = '';
    for ($i = 0; $i < $n; $i++) {
        $base = $i * $frame;
        $sum = 0;
        for ($c = 0; $c < $channels; $c++) {
            $sum += unpack('s', substr($pcm, $base + $c * 2, 2))[1];
        }
        $out .= pack('s', (int)($sum / $channels));
    }
    return $out;
}

/**
 * Voice-activity segmentation over mono int16 PCM. Finds ONE utterance boundary
 * per call and returns, in mono-sample units:
 *   array('wait')                                  incomplete utterance, need more audio
 *   array('drop', $consume)                        leading silence / blip to skip, emit nothing
 *   array('emit', $start, $cut, $consume)          transcribe samples [$start,$cut), advance by $consume
 * An utterance ends at >= SILENCE_HANG_MS of trailing silence, or is force-cut
 * once it reaches MAX_UTTERANCE_S of non-stop speech.
 */
function vad_segment($mono, $rate, $alive) {
    $frame     = max(1, (int)($rate * FRAME_MS / 1000.0));   // samples per frame
    $total     = intdiv(strlen($mono), 2);
    $hang      = (int)($rate * SILENCE_HANG_MS / 1000.0);
    $minSpeech = (int)($rate * MIN_SPEECH_MS / 1000.0);
    $maxUtt    = (int)($rate * MAX_UTTERANCE_S);
    $preroll   = (int)($rate * PRE_ROLL_MS / 1000.0);

    // 1) Locate speech onset.
    $onset = -1;
    for ($p = 0; $p + $frame <= $total; $p += $frame) {
        if (rms16(substr($mono, $p * 2, $frame * 2)) >= SPEECH_RMS) { $onset = $p; break; }
    }
    if ($onset < 0) {
        // Entire window is silence: consume it (keep one frame in case speech
        // begins right at the tail).
        return array('drop', max(0, $total - $frame));
    }
    // 2) Trim long leading silence first so the utterance starts near the head.
    if ($onset > $preroll + $frame) {
        return array('drop', $onset - $preroll);
    }
    // 3) Walk forward looking for an end-of-utterance pause or the max cap.
    $silRun = 0; $lastSpeechEnd = $onset + $frame; $cut = -1;
    for ($p = $onset; $p + $frame <= $total; $p += $frame) {
        if (rms16(substr($mono, $p * 2, $frame * 2)) >= SPEECH_RMS) {
            $silRun = 0;
            $lastSpeechEnd = $p + $frame;
        } else {
            $silRun += $frame;
            if ($silRun >= $hang) { $cut = $lastSpeechEnd; break; }   // utterance ended
        }
        if (($p + $frame - $onset) >= $maxUtt) { $cut = $p + $frame; break; }  // force cut
    }
    if ($cut < 0) {
        // No confirmed end within the window.
        if ($alive === false) {
            // Call is over — flush whatever speech we have.
            if ($lastSpeechEnd - $onset >= $minSpeech) {
                return array('emit', max(0, $onset - $preroll), $lastSpeechEnd, $total);
            }
            return array('drop', $total);
        }
        return array('wait');   // still live: let more audio arrive
    }
    if ($lastSpeechEnd - $onset < $minSpeech) {
        return array('drop', $cut);   // too little speech, skip it
    }
    // Consume up to $cut only (leave the trailing pause; next pass trims it as
    // leading silence). Never eats the following utterance's onset.
    return array('emit', max(0, $onset - $preroll), $cut, $cut);
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

    $ch          = $info['channels'];
    $rate        = $info['rate'];
    $frame_bytes = $ch * 2;
    $bps         = $rate * $frame_bytes;                    // bytes per second
    $alive       = call_alive($job['call_uuid']);
    $avail       = $size - $offset;

    // Read at most one max-length utterance (+ hang + slack) per pass.
    $max_read = (int)($bps * (MAX_UTTERANCE_S + SILENCE_HANG_MS / 1000.0 + 0.5));
    // While the call is live, wait until enough audio exists to possibly hold a
    // complete short utterance; otherwise we'd rescan the same bytes every tick.
    $min_read = (int)($bps * (MIN_SPEECH_MS / 1000.0 + SILENCE_HANG_MS / 1000.0 + 0.1));
    if ($alive !== false && $avail < $min_read) return;

    $read_len = min($avail, $max_read);
    $read_len -= $read_len % $frame_bytes;
    if ($read_len < $frame_bytes) {
        if ($alive === false && ($size - $offset) < $bps * MIN_TAIL_SECONDS) finish($job, 'done');
        return;
    }

    $fh = fopen($path, 'rb');
    fseek($fh, $offset);
    $win = fread($fh, $read_len);
    fclose($fh);

    // Segment on the speech activity of the summed (mono) audio.
    $mono = mono_mix($win, $ch);
    $seg  = vad_segment($mono, $rate, $alive);

    if ($seg[0] === 'wait') {
        return;   // incomplete utterance, call still live — retry next tick
    }

    if ($seg[0] === 'drop') {
        $offset += $seg[1] * $frame_bytes;
        db()->prepare("UPDATE v_caption_jobs SET byte_offset = ?, updated = now() WHERE job_uuid = ?")
            ->execute(array($offset, $job['job_uuid']));
        if ($alive === false && ($size - $offset) < $bps * MIN_TAIL_SECONDS) finish($job, 'done');
        return;
    }

    // $seg = array('emit', $startSample, $cutSample, $consumeSample) — mono-sample units.
    $chunk = substr($win, $seg[1] * $frame_bytes, ($seg[2] - $seg[1]) * $frame_bytes);
    $offset += $seg[3] * $frame_bytes;

    // Per-speaker streams for stereo; single stream for mono.
    $streams = ($ch === 2)
        ? array(array(0, deinterleave($chunk, 0)), array(1, deinterleave($chunk, 1)))
        : array(array(null, $chunk));

    foreach ($streams as $s) {
        list($spk, $sig) = $s;
        if (rms16($sig) < RMS_GATE) continue;               // this speaker silent this utterance
        // Pad ~300ms silence both sides so VAD doesn't clip the first/last word.
        $pad = str_repeat("\x00", (int)($rate * 2 * 0.3));
        list($text, $lang, $err) = stt_transcribe(make_wav($pad . $sig . $pad, $rate, 1));
        if ($err !== null) { logmsg("job {$job['job_uuid']} stt error: $err"); continue; }
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
    usleep(LOOP_SLEEP_US);
}
