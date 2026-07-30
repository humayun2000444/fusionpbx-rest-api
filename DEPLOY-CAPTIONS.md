# Deploying Live Captions + Call Summaries on a New Server

What you get: live Bangla/English captions ~1s behind speech on any active
call, and an automatic human-style Bangla summary (with caller mood/sentiment/
situation) saved when the call ends — browsable per call from the dashboard.

## Architecture (all on the PBX box except the cloud APIs)

```
browser dashboard ──poll──▶ caption-api.php  (start/list/stop/summary/history)
                                 │ creates job row + uuid_record (stereo WAV)
caption-stream-worker.py ──tail──┘
        │ streams PCM over websocket
        ▼
ElevenLabs Scribe v2 Realtime  ──committed captions──▶ v_call_captions
        (on hangup) transcript ──▶ Groq llama-3.3 ──▶ v_call_summaries
```

## Prerequisites on the target server

- FusionPBX / FreeSWITCH with ESL on 127.0.0.1:8021 (default ClueCon)
- PostgreSQL with the fusionpbx DB; PHP (the rest_api app already runs)
- python3 with: `pip3 install websockets` and `apt install python3-psycopg2`
- Outbound HTTPS + WSS to api.elevenlabs.io and api.groq.com
- An ElevenLabs API key and a Groq API key

## Install steps

1. **Code** — this repo at `/var/www/fusionpbx/app/rest_api` (branch master).
   The needed files: `caption-api.php`, `actions/caption-stream-worker.py`,
   `actions/caption_prosody.py` (voice-emotion; pure stdlib, ship it beside the
   worker — the worker does `import caption_prosody`),
   `actions/caption-stream-worker.service`, `actions/caption-schema.sql`.

2. **Schema**
   ```bash
   psql -h 127.0.0.1 -U fusionpbx -d fusionpbx -f actions/caption-schema.sql
   ```

3. **Config** — copy `caption.conf.example` to `/etc/fusionpbx/caption.conf`,
   set CAP_KEY (fresh random!), DB_PASS (from /etc/fusionpbx/config.conf),
   XI_KEY, GROQ_KEY, STT_LANGUAGE. `chmod 640` it (contains secrets).

4. **Recordings dir**
   ```bash
   mkdir -p /var/lib/freeswitch/recordings/captions
   chown www-data:www-data /var/lib/freeswitch/recordings/captions
   ```

5. **Daemon**
   ```bash
   cp actions/caption-stream-worker.service /etc/systemd/system/
   # edit the unit if your pip --user path differs (Environment=PYTHONPATH=...)
   systemctl daemon-reload
   systemctl enable --now caption-stream-worker
   tail -f /var/log/caption_stream_worker.log
   ```

6. **Dashboard** — in btcl-hosted-pbx `src/config/index.js` set
   `captionApi: { url: 'https://<this-server>/app/rest_api/caption-api.php',
   key: '<CAP_KEY>' }`. The server needs a browser-trusted TLS cert, otherwise
   captions fail with ERR_CERT_AUTHORITY_INVALID (accept the cert once, or
   install a real one).

## Verify

```bash
curl -k "https://127.0.0.1/app/rest_api/caption-api.php?key=<CAP_KEY>&action=health"
# -> {"ok":true,"esl":true,"db":true}
```
Then: place a call → open captions in the dashboard (words ~1s behind speech)
→ hang up → `action=history` shows the call with summary/sentiment in ~10s.

## Notes / gotchas

- **Cost**: realtime streams BOTH audio channels → ~2× call duration billed
  per captioned call on ElevenLabs, plus a small Groq fee per summary.
- **Voice emotion** (`caption_prosody.py`) needs no extra deps, no GPU and no
  cloud — pure Python on audio already captured. On by default; set
  `VOICE_EMOTION = "false"` in caption.conf to turn it off.
- The old batch worker (`actions/caption-worker.php`, `caption-worker.service`)
  is a fallback; do NOT run both services at once (same jobs table).
- Summary lookup matches across call legs via v_xml_cdr bridge/originating
  uuids, so the dashboard can query by either leg's xml_cdr_uuid.
- Security is PoC-level (shared key + open CORS). Put the endpoint behind real
  auth before exposing it beyond a trusted network.
- Git on the box: keep repo + .git owned by www-data and add the path to
  `git config --system safe.directory`.
