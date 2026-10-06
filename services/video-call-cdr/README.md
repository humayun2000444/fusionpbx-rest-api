# Video call CDRs (CCL)

Video calls in the WebRTC softphone (`CCL_WEBRTC`) run peer-to-peer through the
Janus **VideoCall** plugin. FreeSWITCH never sees them, so they left no CDR.
Audio calls go through the Janus SIP plugin to FreeSWITCH and were always
recorded. This service gives video calls a CDR too.

```
browser ──video──> Janus VideoCall plugin ──> browser
                        │ events (sampleevh, HTTP)
                        v
            127.0.0.1:7099  receiver.php ──admin API (127.0.0.1:7088)──> who called whom
                        │
                        v
                   v_xml_cdr  (one row per video call)
```

Janus runs on the PBX box itself (103.95.96.109 is a second address on the
CCL PBX), so everything here stays on localhost.

## What a video CDR looks like

Like a local extension-to-extension call: `direction = local`, caller and
destination extension, start/answer/end, billsec, the caller's extension_uuid.

| How to recognise it | `last_app = 'videocall'`, `last_arg = 'video VP8'` (the negotiated video codec), `sip_call_id` starts `janus-videocall-` |
|---|---|
| Answered | `answered`, NORMAL_CLEARING |
| Caller gave up while ringing | `missed`, ORIGINATOR_CANCEL, missed_call = true |
| Callee declined | `no_answer`, CALL_REJECTED |
| Janus restarted mid-ring | `failed`, NORMAL_TEMPORARY_FAILURE |

There is no recording: the media never passes through FreeSWITCH.

Not recorded, on purpose:
- a caller whose VideoCall username is not backed by a **registered SIP handle
  for the same extension on the same Janus session**. The VideoCall plugin
  accepts any username a browser claims; the SIP registration is the part
  FreeSWITCH authenticated. Logged as `identity check FAILED`. Set
  `identity_check = log` in `/etc/video-call-cdr.conf` to record those anyway.
- calls between two different domains (tenants are separate organisations).
- "User busy" rejections, and a call cancelled before Janus could be asked who
  the peer was (the plugin has already let go of it).

## Deploy (on CCL, from the rest_api checkout)

```
cd /var/www/fusionpbx/app/rest_api && git pull
cd services/video-call-cdr
sudo ./deploy.sh install            # receiver + sweep timer; Janus untouched
sudo ./deploy.sh test               # 39 checks incl. a rolled-back DB insert
sudo ./deploy.sh enable-janus --yes # RESTARTS JANUS - quiet window only
# place a 1234 -> 1235 video call, then:
sudo ./deploy.sh status
```

`enable-janus` restarts Janus: every WebRTC softphone disconnects and
re-registers within a few seconds, and a call going through Janus at that
moment is cut. It backs up the three Janus files to
`/var/backups/video-call-cdr/janus-<timestamp>/` first and rolls itself back
if the admin API does not come up.

Undo everything: `sudo ./deploy.sh rollback --yes` (restores the Janus files,
restarts Janus, stops the receiver; CDRs already written stay).

## Files

| | |
|---|---|
| `VideoCallCdr.php` | event stream -> finished calls (no I/O of its own) |
| `CdrWriter.php` | finished call -> `v_xml_cdr` row, `ON CONFLICT DO NOTHING` |
| `receiver.php` | `POST /janus-events`, `GET /health`; refuses to run outside `php -S` (it is inside the web root) |
| `sweep.php` | every minute: closes calls whose handles vanished, retries unwritten rows |
| `janus_config.py` | sets single keys in Janus `.jcfg` blocks, leaves the rest alone |
| `tests/run-tests.php` | `--db` adds a real insert inside a rolled-back transaction |

State (open calls, rows the database refused) is
`/var/lib/video-call-cdr/state.json`; a row that fails to insert is retried on
the next event or sweep, never dropped. Each call's uuid is derived from the
Janus handle and start time, so a replay cannot insert it twice.
