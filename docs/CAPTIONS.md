# Live Call Captions & Post-Call Summaries

Real-time speech-to-text captions on active calls (~1s behind speech, Bangla +
English code-switching) and an automatic human-style Bangla summary — with
caller mood, sentiment and situation — saved when the call ends.

New-server installation: see [`DEPLOY-CAPTIONS.md`](../DEPLOY-CAPTIONS.md).

## Architecture

```
dashboard (ActiveCalls / CallHistory)
   │  GET caption-api.php?action=start          ── begins captioning a call
   │  GET caption-api.php?action=list  (300ms)  ── polls live captions
   │  GET caption-api.php?action=summary        ── post-call summary + transcript
   │  GET caption-api.php?action=history        ── recent calls + summaries
   ▼
caption-api.php ──────────── creates v_caption_jobs row
   │                         uuid_setvar RECORD_STEREO true
   │                         uuid_record → growing stereo WAV
   ▼
actions/caption-stream-worker.py  (systemd: caption-stream-worker.service)
   │  tails the WAV, one websocket per channel (caller / callee)
   ▼
ElevenLabs Scribe v2 Realtime  (wss://api.elevenlabs.io/v1/speech-to-text/realtime)
   │  server-side VAD commits an utterance ~0.5s after each pause
   ▼
v_call_captions (one row per utterance, speaker-labelled)
   │  on hangup: transcript assembled
   ▼
Groq llama-3.3-70b  →  v_call_summaries (summary, sentiment, caller_mood, situation)
```

Speaker separation comes from stereo recording: channel 0 = caller (Speaker A),
channel 1 = callee (Speaker B). The STT never hears overlapping speech.

## API reference

Base: `https://<server>/app/rest_api/caption-api.php` — all requests are GET
with `?key=<CAP_KEY>` (shared key, see Config). Responses are JSON with
`ok: true|false`.

| action | params | purpose |
|---|---|---|
| `health` | — | smoke check → `{ok, esl, db}` |
| `start` | `call_uuid` | begin captioning a live call (idempotent) |
| `list` | `call_uuid`, `after` (seq) | poll captions → `{status, items:[{seq, speaker, text, language, created}]}` |
| `stop` | `call_uuid` | stop captioning mid-call |
| `summary` | `call_uuid` | post-call result → `{ready, summary, transcript, sentiment, caller_mood, situation, model}` |
| `history` | `limit` (≤100) | recent captioned calls, each with summary fields |

Notes:
- `summary` matches across call legs: if the given uuid is the other leg of a
  captioned call, it is resolved via `v_xml_cdr.bridge_uuid` /
  `originating_leg_uuid`, so the dashboard can query by either leg's
  `xml_cdr_uuid`.
- `list` works during AND after the call (captions are persisted).

## Database tables (DDL: `actions/caption-schema.sql`)

- **v_caption_jobs** — one row per captioning session; `status`
  active→done/failed, `byte_offset`/`seq` are worker progress.
- **v_call_captions** — one row per committed utterance; `speaker` 0/1/null.
- **v_call_summaries** — one row per call (`call_uuid` unique): `transcript`,
  `summary` (story-style Bangla incl. emotions + করণীয় bullets),
  `sentiment` (positive|neutral|negative), `caller_mood` (Bangla word),
  `situation` (one line), `summary_model`.

## Configuration — `/etc/fusionpbx/caption.conf`

Plain `KEY = "value"` lines (template: `caption.conf.example`). Missing file or
keys fall back to built-in defaults. Main keys: `CAP_KEY`, `DB_HOST/NAME/USER/
PASS`, `XI_KEY` (ElevenLabs), `GROQ_KEY` (summaries; empty → legacy fallback to
`v_order_confirm_config.tts_openai_key`), `STT_LANGUAGE` (ISO 639-3, e.g.
`ben`), optional `STT_MODEL`, `SUMMARY_MODEL`, `REC_DIR`, `ESL_*`.

## Operations

```bash
systemctl status caption-stream-worker        # the realtime daemon
tail -f /var/log/caption_stream_worker.log    # per-caption + summary logs
psql ... -c "SELECT * FROM v_caption_jobs WHERE status='active'"   # live jobs
```

- Restart after config changes: `systemctl restart caption-stream-worker`.
- The legacy batch worker (`actions/caption-worker.php`,
  `caption-worker.service`) is a fallback — never run both at once (they
  consume the same jobs table).
- Jobs hard-stop after 30 min; a job whose WAV never appears fails after 20s.

## Troubleshooting

| symptom | likely cause |
|---|---|
| browser: `ERR_CERT_AUTHORITY_INVALID` | self-signed TLS — accept cert once or install a real one |
| captions garbled, first letters missing | you're on the legacy `.98` whisper path — use scribe |
| summary null, log `HTTP 403` from Groq | blocked User-Agent (fixed) or bad GROQ_KEY |
| `dubious ownership` on git pull | repo+`.git` must be www-data-owned and path in `git config --system safe.directory` |
| daemon `activating` loop | check log; usually missing python deps (`websockets`, `psycopg2`) or empty worker file |

## Cost & privacy

- Realtime streams **both** channels → ≈2× call duration billed on ElevenLabs
  per captioned call, plus a small Groq fee per summary.
- Call audio and transcripts go to ElevenLabs and Groq clouds.
- Auth is a shared key with open CORS (PoC) — front with real auth before
  exposing beyond a trusted network.
