#!/usr/bin/env python3
"""
caption-stream-worker.py — realtime live-caption daemon (Scribe v2 Realtime).

Replaces the batch caption-worker.php loop: instead of slicing the growing
uuid_record WAV into chunks and POSTing them, it streams each channel's PCM
continuously over a websocket to ElevenLabs Scribe v2 Realtime and inserts a
caption row the moment the server VAD commits an utterance (~0.5-1s behind
speech, vs ~2.5-5s for the batch pipeline).

On call end it assembles the whole transcript and asks Groq (llama-3.3-70b)
for a Bangla call summary, stored in v_call_summaries keyed by call_uuid so it
can be joined against the CDR / call logs. The Groq key is read from
v_order_confirm_config.tts_openai_key (where the ops team already keeps it).

Job lifecycle, tables and the caption-api.php polling contract are unchanged:
jobs come from v_caption_jobs (created by caption-api.php action=start),
captions go to v_call_captions with the same seq semantics.

Run via systemd: caption-stream-worker.service
(disable caption-worker.service first — both consume the same jobs table).
"""

import asyncio
import base64
import json
import logging
import os
import socket
import struct
import sys
import time
import urllib.request

import psycopg2
import psycopg2.extras
import websockets

# ---- config -----------------------------------------------------------------
DB_DSN = "host=127.0.0.1 dbname=fusionpbx user=fusionpbx password=Takay1takaane"
XI_KEY = "sk_7d78907af456db3a031a893334fc328b23563d790de8ef6f"

WS_BASE = "wss://api.elevenlabs.io/v1/speech-to-text/realtime"
STT_MODEL = "scribe_v2_realtime"
LANG = "ben"                      # forced language; server normalizes to 'bn'
VAD_SILENCE_SECS = 0.5            # server-side commit after this much silence

SUMMARY_MODEL = "llama-3.3-70b-versatile"
SUMMARY_URL = "https://api.groq.com/openai/v1/chat/completions"

JOB_MAX_AGE_MIN = 30              # hard stop per job
RECORD_WAIT_SECS = 20             # fail job if WAV never appears
POLL_SECS = 0.3                   # jobs-table poll interval
TAIL_SECS = 0.1                   # file tail interval (chunk pacing)
MAX_CHUNK_SECS = 1.0              # cap per ws message when draining backlog
ESL_HOST, ESL_PORT, ESL_PASS = "127.0.0.1", 8021, "ClueCon"

logging.basicConfig(level=logging.INFO, stream=sys.stdout,
                    format="[%(asctime)s] %(message)s", datefmt="%Y-%m-%d %H:%M:%S")
log = logging.getLogger("caption-stream")


# ---- small helpers ----------------------------------------------------------
def db():
    conn = psycopg2.connect(DB_DSN)
    conn.autocommit = True
    return conn


def uuid4():
    import uuid as _u
    return str(_u.uuid4())


def esl_api(cmd, timeout=3):
    """Run one ESL api command; returns body string or None on failure."""
    try:
        with socket.create_connection((ESL_HOST, ESL_PORT), timeout=timeout) as fp:
            fp.settimeout(timeout)
            buf = b""
            while b"\n\n" not in buf:
                buf += fp.recv(4096)
            fp.sendall(("auth %s\n\n" % ESL_PASS).encode())
            buf = b""
            while b"\n\n" not in buf:
                buf += fp.recv(4096)
            if b"+OK" not in buf:
                return None
            fp.sendall(("api %s\n\n" % cmd).encode())
            hdr = b""
            while b"\n\n" not in hdr:
                hdr += fp.recv(4096)
            head = hdr.split(b"\n\n", 1)[0].decode(errors="replace")
            body = hdr.split(b"\n\n", 1)[1]
            need = 0
            for line in head.split("\n"):
                if line.lower().startswith("content-length:"):
                    need = int(line.split(":", 1)[1].strip())
            while len(body) < need:
                more = fp.recv(need - len(body))
                if not more:
                    break
                body += more
            return body.decode(errors="replace")
    except OSError:
        return None


def call_alive(uuid):
    """True/False, or None when ESL is unreachable (assume alive)."""
    r = esl_api("uuid_exists %s" % uuid)
    if r is None:
        return None
    return r.strip() == "true"


def wav_info(path):
    try:
        with open(path, "rb") as fh:
            hdr = fh.read(512)
    except OSError:
        return None
    if len(hdr) < 44 or hdr[:4] != b"RIFF" or hdr[8:12] != b"WAVE":
        return None
    channels = struct.unpack("<H", hdr[22:24])[0] or 1
    rate = struct.unpack("<I", hdr[24:28])[0] or 8000
    dpos = hdr.find(b"data")
    if dpos < 0:
        return None
    return {"channels": channels, "rate": rate, "data_offset": dpos + 8}


def audio_format_for(rate):
    fmt = {8000: "pcm_8000", 16000: "pcm_16000", 22050: "pcm_22050",
           24000: "pcm_24000", 44100: "pcm_44100", 48000: "pcm_48000"}
    return fmt.get(rate, "pcm_16000")


def deinterleave(buf, channels, ch):
    """Extract one channel of 16-bit interleaved PCM."""
    if channels == 1:
        return buf
    out = bytearray()
    frame = channels * 2
    for i in range(0, len(buf) - frame + 1, frame):
        out += buf[i + ch * 2:i + ch * 2 + 2]
    return bytes(out)


# ---- caption + summary persistence -----------------------------------------
class JobState:
    def __init__(self, row):
        self.row = dict(row)
        self.seq = int(row["seq"] or 0)
        self.lock = asyncio.Lock()
        self.done = False


async def insert_caption(conn, job, speaker, text, lang):
    text = text.strip()
    if not text:
        return
    async with job.lock:
        job.seq += 1
        seq = job.seq
    with conn.cursor() as cur:
        cur.execute(
            "INSERT INTO v_call_captions (caption_uuid, call_uuid, seq, speaker,"
            " caption_text, caption_language) VALUES (%s,%s,%s,%s,%s,%s)",
            (uuid4(), job.row["call_uuid"], seq, speaker, text, lang))
        cur.execute("UPDATE v_caption_jobs SET seq=%s, updated=now() WHERE job_uuid=%s",
                    (seq, job.row["job_uuid"]))
    log.info("job %s seq %s spk %s [%s] %s",
             job.row["job_uuid"], seq, speaker, lang, text[:70])


def groq_key(conn):
    with conn.cursor() as cur:
        cur.execute("SELECT tts_openai_key FROM v_order_confirm_config"
                    " WHERE coalesce(tts_openai_key,'')<>'' LIMIT 1")
        r = cur.fetchone()
    return r[0] if r else None


def summarize(conn, job):
    """Assemble the transcript and store a Bangla summary (best-effort)."""
    with conn.cursor() as cur:
        cur.execute("SELECT speaker, caption_text FROM v_call_captions"
                    " WHERE call_uuid=%s ORDER BY seq", (job.row["call_uuid"],))
        rows = cur.fetchall()
    if not rows:
        return
    transcript = "\n".join(
        "%s: %s" % ("Speaker A" if (s or 0) == 0 else "Speaker B", t) for s, t in rows)
    key = groq_key(conn)
    summary, model_used = None, None
    sentiment, caller_mood, situation = None, None, None
    if key:
        prompt = (
            "আপনি একজন অভিজ্ঞ কল সেন্টার সুপারভাইজার। নিচের ফোন কল ট্রান্সক্রিপ্টটি"
            " পড়ে একজন মানুষ যেভাবে সহকর্মীকে গল্পের মতো করে বলে, সেভাবে স্বাভাবিক"
            " বাংলায় বর্ণনা করুন — কে ফোন করেছিল, কী চেয়েছিল, কথোপকথনের সময় তাদের"
            " মেজাজ/আবেগ কেমন ছিল (রাগ, বিরক্তি, খুশি, উদ্বেগ, স্বস্তি ইত্যাদি),"
            " এবং শেষে কী হলো। রোবটের মতো তালিকা নয় — প্রাণবন্ত, সহজ ভাষা।\n\n"
            "শুধু নিচের JSON ফরম্যাটে উত্তর দিন:\n"
            "{\"summary\": \"৪-৬ বাক্যের মানবিক বর্ণনা (আবেগসহ)\",\n"
            " \"sentiment\": \"positive|neutral|negative\",\n"
            " \"caller_mood\": \"কলারের মেজাজ ১-২ শব্দে (যেমন: বিরক্ত, শান্ত, খুশি,"
            " রাগান্বিত, উদ্বিগ্ন, হতাশ)\",\n"
            " \"situation\": \"কলারের পরিস্থিতি এক লাইনে\",\n"
            " \"action_items\": [\"করণীয় (থাকলে)\"]}\n\n"
            "ট্রান্সক্রিপ্ট (বাংলা-ইংরেজি মিশ্রিত হতে পারে):\n" + transcript[:12000])
        body = json.dumps({
            "model": SUMMARY_MODEL,
            "messages": [{"role": "user", "content": prompt}],
            "response_format": {"type": "json_object"},
            "temperature": 0.4, "max_tokens": 900,
        }).encode()
        req = urllib.request.Request(
            SUMMARY_URL, data=body,
            headers={"Authorization": "Bearer " + key,
                     "Content-Type": "application/json",
                     # Groq's edge 403s the default Python-urllib user agent
                     "User-Agent": "fusionpbx-caption-worker/1.0"})
        try:
            j = json.loads(urllib.request.urlopen(req, timeout=45).read().decode())
            raw = j["choices"][0]["message"]["content"].strip()
            try:
                data = json.loads(raw)
            except ValueError:   # model wrapped it in prose/fences — salvage
                data = json.loads(raw[raw.index("{"):raw.rindex("}") + 1])
            summary = (data.get("summary") or "").strip() or None
            sentiment = (data.get("sentiment") or "").strip().lower() or None
            caller_mood = (data.get("caller_mood") or "").strip() or None
            situation = (data.get("situation") or "").strip() or None
            items = [str(x).strip() for x in (data.get("action_items") or []) if str(x).strip()]
            if summary and items:
                summary += "\n\nকরণীয়:\n" + "\n".join("• " + x for x in items)
            model_used = SUMMARY_MODEL
        except Exception as e:  # noqa: BLE001 - summary is best-effort
            log.info("job %s summary error: %s", job.row["job_uuid"], str(e)[:180])
    with conn.cursor() as cur:
        cur.execute(
            "INSERT INTO v_call_summaries (summary_uuid, call_uuid, job_uuid,"
            " transcript, summary, summary_model, sentiment, caller_mood, situation)"
            " VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s)"
            " ON CONFLICT (call_uuid) DO UPDATE SET transcript=EXCLUDED.transcript,"
            " summary=EXCLUDED.summary, summary_model=EXCLUDED.summary_model,"
            " sentiment=EXCLUDED.sentiment, caller_mood=EXCLUDED.caller_mood,"
            " situation=EXCLUDED.situation, updated=now()",
            (uuid4(), job.row["call_uuid"], job.row["job_uuid"],
             transcript, summary, model_used, sentiment, caller_mood, situation))
    log.info("job %s summary %s", job.row["job_uuid"],
             "saved" if summary else "saved (transcript only, no LLM)")


def finish(conn, job, status):
    esl_api("uuid_record %s stop %s" % (job.row["call_uuid"], job.row["record_path"]))
    with conn.cursor() as cur:
        cur.execute("UPDATE v_caption_jobs SET status=%s, updated=now() WHERE job_uuid=%s",
                    (status, job.row["job_uuid"]))
    job.done = True
    log.info("job %s -> %s", job.row["job_uuid"], status)


# ---- websocket streaming ----------------------------------------------------
async def ws_connect(rate):
    url = (WS_BASE + "?model_id=%s&language_code=%s&audio_format=%s"
           "&commit_strategy=vad&vad_silence_threshold_secs=%s"
           % (STT_MODEL, LANG, audio_format_for(rate), VAD_SILENCE_SECS))
    try:
        return await websockets.connect(url, extra_headers={"xi-api-key": XI_KEY},
                                        max_size=None)
    except TypeError:  # websockets >= 14 renamed the kwarg
        return await websockets.connect(url, additional_headers={"xi-api-key": XI_KEY},
                                        max_size=None)


async def stream_channel(conn, job, info, ch, stop_evt):
    """Tail one channel of the growing WAV into a realtime STT session."""
    path = job.row["record_path"]
    rate = info["rate"]
    channels = info["channels"]
    frame = channels * 2
    speaker = ch if channels == 2 else None
    offset = info["data_offset"]
    max_chunk = int(rate * MAX_CHUNK_SECS) * frame

    ws = None
    try:
        while not stop_evt.is_set() or offset < os.path.getsize(path):
            try:
                size = os.path.getsize(path)
            except OSError:
                break
            avail = size - offset
            avail -= avail % frame
            if avail <= 0:
                if stop_evt.is_set():
                    break
                await asyncio.sleep(TAIL_SECS)
                continue
            take = min(avail, max_chunk)
            with open(path, "rb") as fh:
                fh.seek(offset)
                buf = fh.read(take)
            offset += len(buf)
            mono = deinterleave(buf, channels, ch)

            if ws is None:
                ws = await ws_connect(rate)
                asyncio.ensure_future(receiver(conn, job, ws, speaker, stop_evt))
            try:
                await ws.send(json.dumps({
                    "message_type": "input_audio_chunk",
                    "audio_base_64": base64.b64encode(mono).decode(),
                    "sample_rate": rate, "commit": False}))
            except websockets.exceptions.ConnectionClosed:
                log.info("job %s spk %s ws closed, reconnecting",
                         job.row["job_uuid"], speaker)
                ws = await ws_connect(rate)
                asyncio.ensure_future(receiver(conn, job, ws, speaker, stop_evt))
                await ws.send(json.dumps({
                    "message_type": "input_audio_chunk",
                    "audio_base_64": base64.b64encode(mono).decode(),
                    "sample_rate": rate, "commit": False}))
            await asyncio.sleep(TAIL_SECS)

        if ws is not None:
            try:
                await ws.send(json.dumps({"message_type": "input_audio_chunk",
                                          "audio_base_64": "", "sample_rate": rate,
                                          "commit": True}))
                await asyncio.sleep(2.0)   # let final commit arrive
            except websockets.exceptions.ConnectionClosed:
                pass
    finally:
        if ws is not None:
            try:
                await ws.close()
            except Exception:  # noqa: BLE001
                pass


async def receiver(conn, job, ws, speaker, stop_evt):
    lang_hint = LANG
    try:
        async for raw in ws:
            m = json.loads(raw)
            mt = m.get("message_type", "")
            if mt == "committed_transcript":
                await insert_caption(conn, job, speaker, m.get("text", ""), lang_hint)
            elif mt == "session_started":
                lang_hint = (m.get("config") or {}).get("language_code", LANG)
            elif mt.endswith("error") or mt in (
                    "quota_exceeded", "rate_limited", "queue_overflow",
                    "resource_exhausted", "session_time_limit_exceeded"):
                log.info("job %s spk %s ws %s: %s", job.row["job_uuid"], speaker,
                         mt, str(m)[:180])
    except websockets.exceptions.ConnectionClosed:
        pass


# ---- job orchestration ------------------------------------------------------
async def handle_job(conn, job):
    path = job.row["record_path"]
    t0 = time.time()
    while not os.path.exists(path):
        if time.time() - t0 > RECORD_WAIT_SECS:
            finish(conn, job, "failed")
            return
        await asyncio.sleep(0.3)
    info = None
    while info is None and time.time() - t0 < RECORD_WAIT_SECS + 10:
        info = wav_info(path)
        if info is None:
            await asyncio.sleep(0.3)
    if info is None:
        finish(conn, job, "failed")
        return
    log.info("job %s streaming %s (%sch %sHz)", job.row["job_uuid"],
             os.path.basename(path), info["channels"], info["rate"])

    stop_evt = asyncio.Event()

    async def watchdog():
        started = time.time()
        while True:
            if time.time() - started > JOB_MAX_AGE_MIN * 60:
                break
            alive = call_alive(job.row["call_uuid"])
            if alive is False:
                await asyncio.sleep(1.0)   # let FS flush the tail
                break
            # honor external stop (caption-api action=stop marks job done)
            with conn.cursor() as cur:
                cur.execute("SELECT status FROM v_caption_jobs WHERE job_uuid=%s",
                            (job.row["job_uuid"],))
                r = cur.fetchone()
            if not r or r[0] != "active":
                break
            await asyncio.sleep(1.0)
        stop_evt.set()

    chans = range(info["channels"]) if info["channels"] == 2 else [0]
    tasks = [asyncio.ensure_future(stream_channel(conn, job, info, c, stop_evt))
             for c in chans]
    tasks.append(asyncio.ensure_future(watchdog()))
    await asyncio.gather(*tasks, return_exceptions=True)

    if not job.done:
        finish(conn, job, "done")
    try:
        summarize(conn, job)
    except Exception as e:  # noqa: BLE001
        log.info("job %s summarize failed: %s", job.row["job_uuid"], str(e)[:180])


async def main():
    conn = db()
    log.info("caption stream worker started, pid %s (model=%s)", os.getpid(), STT_MODEL)
    running = {}
    while True:
        try:
            with conn.cursor(cursor_factory=psycopg2.extras.DictCursor) as cur:
                cur.execute("SELECT * FROM v_caption_jobs WHERE status='active'")
                rows = cur.fetchall()
            for row in rows:
                jid = row["job_uuid"]
                if jid not in running or running[jid].done():
                    job = JobState(row)
                    running[jid] = asyncio.ensure_future(handle_job(conn, job))
            for jid in [j for j, t in running.items() if t.done()]:
                running.pop(jid, None)
        except psycopg2.Error as e:
            log.info("db error: %s", str(e)[:150])
            try:
                conn.close()
            except Exception:  # noqa: BLE001
                pass
            await asyncio.sleep(2)
            conn = db()
        except Exception as e:  # noqa: BLE001
            log.info("loop error: %s", str(e)[:180])
            await asyncio.sleep(2)
        await asyncio.sleep(POLL_SECS)


if __name__ == "__main__":
    if sys.version_info >= (3, 7):
        try:
            asyncio.run(main())
        except AttributeError:
            asyncio.get_event_loop().run_until_complete(main())
    else:
        asyncio.get_event_loop().run_until_complete(main())
