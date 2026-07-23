<?php
/**
 * caption-api.php — standalone PoC endpoint for live call captions.
 *
 * Deployed at /var/www/fusionpbx/app/rest_api/caption-api.php (NOT via rest.php,
 * so it can serve CORS to the dashboard dev server directly).
 *
 * All requests are GET (avoids CORS preflight):
 *   ?key=<CAP_KEY>&action=start&call_uuid=<uuid>   start captioning a live call
 *   ?key=<CAP_KEY>&action=list&call_uuid=<uuid>&after=<seq>   poll new captions
 *   ?key=<CAP_KEY>&action=stop&call_uuid=<uuid>    stop captioning
 *   ?key=<CAP_KEY>&action=summary&call_uuid=<uuid> post-call summary + transcript
 *   ?key=<CAP_KEY>&action=history&limit=20         recent captioned calls + summaries
 *   ?key=<CAP_KEY>&action=health                   smoke check
 *
 * PoC ONLY: shared-key auth + open CORS. Replace with gateway auth for prod.
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { http_response_code(204); exit; }

const CAP_KEY  = 'cap_ccl_f31b9d2c7a55';
const DB_DSN   = 'pgsql:host=127.0.0.1;dbname=fusionpbx';
const DB_USER  = 'fusionpbx';
const DB_PASS  = 'Takay1takaane';
const REC_DIR  = '/var/lib/freeswitch/recordings/captions';

function respond($arr, $code = 200) {
    http_response_code($code);
    echo json_encode($arr, JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_GET['key'] ?? '') !== CAP_KEY) respond(array('ok' => false, 'error' => 'unauthorized'), 401);

$action = $_GET['action'] ?? '';

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

/** Run one ESL api command, return the body (or null on connect failure). */
function cap_esl_api($cmd) {
    $fp = @fsockopen('127.0.0.1', 8021, $errno, $errstr, 5);
    if (!$fp) return null;
    stream_set_timeout($fp, 5);
    stream_get_line($fp, 4096, "\n\n");                 // auth/request banner
    fputs($fp, "auth ClueCon\n\n");
    $auth = stream_get_line($fp, 4096, "\n\n");
    if (strpos($auth, '+OK') === false) { fclose($fp); return null; }
    fputs($fp, "api $cmd\n\n");
    $headers = stream_get_line($fp, 4096, "\n\n");      // api/response headers
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

try {
    if ($action === 'health') {
        $esl = cap_esl_api('status');
        db();
        respond(array('ok' => true, 'esl' => $esl !== null, 'db' => true));
    }

    if ($action === 'history') {
        // Recent captioned calls with their post-call summaries — the history
        // view. Per-call transcript/captions: action=summary / action=list.
        $limit = min(100, max(1, (int)($_GET['limit'] ?? 20)));
        $st = db()->prepare(
            "SELECT j.call_uuid, j.status, j.created,
                    (SELECT count(*) FROM v_call_captions c WHERE c.call_uuid = j.call_uuid) AS captions,
                    s.summary, s.summary_model, s.sentiment, s.caller_mood, s.situation, s.updated AS summary_updated
               FROM v_caption_jobs j
               LEFT JOIN v_call_summaries s ON s.call_uuid = j.call_uuid
              ORDER BY j.created DESC LIMIT ?");
        $st->execute(array($limit));
        $items = array();
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $items[] = array(
                'call_uuid' => $r['call_uuid'],
                'status'    => $r['status'],
                'started'   => $r['created'],
                'captions'  => (int)$r['captions'],
                'summary'   => $r['summary'],
                'sentiment' => $r['sentiment'],
                'caller_mood' => $r['caller_mood'],
                'situation' => $r['situation'],
                'model'     => $r['summary_model'],
            );
        }
        respond(array('ok' => true, 'items' => $items));
    }

    $call_uuid = strtolower(trim($_GET['call_uuid'] ?? ''));
    if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $call_uuid)) {
        respond(array('ok' => false, 'error' => 'invalid call_uuid'), 400);
    }

    if ($action === 'start') {
        $exists = cap_esl_api("uuid_exists $call_uuid");
        if ($exists === null) respond(array('ok' => false, 'error' => 'ESL connect failed'), 500);
        if (trim($exists) !== 'true') respond(array('ok' => false, 'error' => 'call not found (ended?)'), 404);

        // Already captioning? Return the existing job.
        $st = db()->prepare("SELECT job_uuid FROM v_caption_jobs WHERE call_uuid = ? AND status = 'active' LIMIT 1");
        $st->execute(array($call_uuid));
        if ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            respond(array('ok' => true, 'job_uuid' => $row['job_uuid'], 'already' => true));
        }

        if (!is_dir(REC_DIR)) { @mkdir(REC_DIR, 0777, true); @chmod(REC_DIR, 0777); }
        $path = REC_DIR . "/cap_{$call_uuid}.wav";
        // Stereo record: caller on one channel, callee on the other, so the STT
        // never hears overlapping speech (big accuracy win + per-speaker labels).
        cap_esl_api("uuid_setvar $call_uuid RECORD_STEREO true");
        $rec = cap_esl_api("uuid_record $call_uuid start $path");
        if ($rec === null || strpos($rec, '-ERR') !== false) {
            respond(array('ok' => false, 'error' => 'uuid_record failed: ' . trim((string)$rec)), 500);
        }

        $job = cap_uuid4();
        db()->prepare("INSERT INTO v_caption_jobs (job_uuid, call_uuid, record_path) VALUES (?, ?, ?)")
            ->execute(array($job, $call_uuid, $path));
        respond(array('ok' => true, 'job_uuid' => $job));
    }

    if ($action === 'list') {
        $after = (int)($_GET['after'] ?? 0);
        $st = db()->prepare("SELECT status FROM v_caption_jobs WHERE call_uuid = ? ORDER BY created DESC LIMIT 1");
        $st->execute(array($call_uuid));
        $jrow = $st->fetch(PDO::FETCH_ASSOC);
        $st = db()->prepare(
            "SELECT seq, speaker, caption_text, caption_language, created
               FROM v_call_captions WHERE call_uuid = ? AND seq > ?
              ORDER BY seq ASC LIMIT 50");
        $st->execute(array($call_uuid, $after));
        $items = array();
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $items[] = array(
                'seq'      => (int)$r['seq'],
                'speaker'  => $r['speaker'] === null ? null : (int)$r['speaker'],
                'text'     => $r['caption_text'],
                'language' => $r['caption_language'],
                'created'  => $r['created'],
            );
        }
        respond(array('ok' => true, 'status' => $jrow ? $jrow['status'] : null, 'items' => $items));
    }

    if ($action === 'summary') {
        // Post-call summary + transcript (written by caption-stream-worker.py
        // after the call ends). Join against call logs/CDR by call_uuid.
        $st = db()->prepare(
            "SELECT summary, transcript, summary_model, sentiment, caller_mood, situation, created, updated
               FROM v_call_summaries WHERE call_uuid = ? LIMIT 1");
        $st->execute(array($call_uuid));
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            // Captions may have run on the other leg of this call: match via the
            // CDR's bridge/originating leg uuids in both directions.
            $st = db()->prepare(
                "SELECT s.summary, s.transcript, s.summary_model, s.sentiment, s.caller_mood, s.situation, s.created, s.updated
                   FROM v_call_summaries s
                  WHERE s.call_uuid::text IN (
                        SELECT bridge_uuid::text FROM v_xml_cdr
                         WHERE xml_cdr_uuid::text = ? AND coalesce(bridge_uuid,'') <> ''
                        UNION
                        SELECT originating_leg_uuid::text FROM v_xml_cdr
                         WHERE xml_cdr_uuid::text = ? AND coalesce(originating_leg_uuid::text,'') <> ''
                        UNION
                        SELECT xml_cdr_uuid::text FROM v_xml_cdr WHERE bridge_uuid::text = ?
                        UNION
                        SELECT xml_cdr_uuid::text FROM v_xml_cdr WHERE originating_leg_uuid::text = ?)
                  LIMIT 1");
            $st->execute(array($call_uuid, $call_uuid, $call_uuid, $call_uuid));
            $row = $st->fetch(PDO::FETCH_ASSOC);
        }
        if ($row) {
            respond(array('ok' => true, 'ready' => $row['summary'] !== null,
                'summary' => $row['summary'], 'transcript' => $row['transcript'],
                'sentiment' => $row['sentiment'], 'caller_mood' => $row['caller_mood'],
                'situation' => $row['situation'],
                'model' => $row['summary_model'], 'created' => $row['created'],
                'updated' => $row['updated']));
        }
        respond(array('ok' => true, 'ready' => false, 'summary' => null,
            'transcript' => null));
    }

    if ($action === 'stop') {
        $st = db()->prepare("SELECT job_uuid, record_path FROM v_caption_jobs WHERE call_uuid = ? AND status = 'active' LIMIT 1");
        $st->execute(array($call_uuid));
        if ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            cap_esl_api("uuid_record $call_uuid stop " . $row['record_path']);
            db()->prepare("UPDATE v_caption_jobs SET status = 'done', updated = now() WHERE job_uuid = ?")
                ->execute(array($row['job_uuid']));
        }
        respond(array('ok' => true));
    }

    respond(array('ok' => false, 'error' => 'unknown action'), 400);
} catch (Throwable $e) {
    respond(array('ok' => false, 'error' => $e->getMessage()), 500);
}
