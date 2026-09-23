# Smart IVR — known gaps

The outbound IVR engine behind **Order Confirmation** and **CSAT**: what is
broken, what is merely awkward, and what it costs. Written 2026-09-22 after
diagnosing a CSAT test call that reached the customer and played silence.

Each item says what you would observe, what is actually happening, and what
fixing it involves. Nothing here is speculative — every claim has a log line,
a row, or a query behind it.

---

## 1. `api_hangup_hook` never fires — hangup causes are not recorded

**Severity: medium. Affects every outbound IVR call on both platforms.**

FreeSWITCH logs this on the originate, on every single call:

```
[CRIT] switch_channel.c:1508 Invalid data (${api_hangup_hook} contains a variable)
```

`order-confirm-helper.php:657` sets the hook with unexpanded variables:

```php
$vars .= ",api_hangup_hook='lua order-confirm-hangup.lua " . '${oc_call_uuid} ${hangup_cause}' . "'";
```

The intent (documented in the comment above that line) is for FreeSWITCH to
expand them at hangup. It refuses, because it will not set a channel variable
whose value itself contains `${...}`. So the hook is never installed and
`order-confirm-hangup.lua` never runs.

**What you observe:** `hangup_cause` is empty or `NO_CDR` on rows that
otherwise completed normally. An *answered* call still records its result,
because the IVR writes that itself — so this hides well. An **unanswered**
call is what suffers: it waits for the worker's CDR backstop (~60–90s) instead
of being closed out immediately with its real cause, which is the whole reason
the hook exists.

**Evidence:** `CSAT-1790065347288` (2026-09-22 08:22) completed correctly with
`dtmf_pressed=1` and an empty `hangup_cause`; the `[CRIT]` line appears at
08:22:27 on that same channel.

**Fixing it** means resolving FreeSWITCH's expansion timing, not just editing
the string. `oc_call_uuid` is known in PHP and can be substituted literally;
`${hangup_cause}` genuinely must defer, so the options are escaping it so the
originate parser leaves it alone, or dropping the argument and having the Lua
read the cause from the channel. **This needs testing against a real call, not
a blind edit** — there is now a known-good call to verify against.

---

## 2. One config per domain, shared by every kind of IVR

**Severity: medium. This is the design issue the other symptoms come from.**

`v_order_confirm_config` holds one row per domain: caller ID, language,
prompts, retry policy and the DTMF map. Order Confirmation and CSAT share it,
and CSAT rows are separable from confirmation rows only by a `CSAT-` prefix on
`order_id`.

They want genuinely different settings. A survey asks for **1–5**; a
confirmation offers **Confirm / Cancel**. Retrying an unanswered confirmation
three times is correct; retrying a satisfaction survey three times is rude.

**What it already cost:** `CSAT-1790065487007` ended `no_input` because the
survey inherited the confirmation keypad — the caller was asked to press 1 to
5, pressed a score above 2, and the IVR had never been told to accept it.

**Worked around, not fixed.** Two per-call overrides now exist:

| column | on | meaning |
|---|---|---|
| `dtmf_options` | `v_order_confirm_calls` | this call's keypad; NULL = domain config |
| `prompt_recording_uuid` | `v_order_confirm_calls` | this call's audio; NULL = domain config |

That is a *campaign of one*, pushed into every API request instead of stored
once. It works and it migrates cleanly.

**The proper shape** is to share the engine and separate the configuration:

```
v_outbound_ivr_campaigns   uuid, domain_uuid, type ('confirm' | 'survey' | …),
                           prompt, dtmf_options, caller_id, retry policy
v_order_confirm_calls      + campaign_uuid
```

Do **not** fork the calling engine. Originate, answer detection, DTMF capture,
retry and hangup accounting are legitimately shared, and they are the
bug-prone part — item 1 would need fixing twice.

**Worth doing when** you need a second survey with different settings, or
reporting that does not filter on a string prefix. Until then the overrides
hold.

---

## 3. TTS is on the answered-call path, with a hard ceiling

**Severity: reduced, not eliminated.**

`order-confirm-ivr.lua` runs the synthesiser *after* the customer answers, so
unanswered calls cost nothing. The trade is that generation happens while
someone is holding a live phone to their ear, under a timeout.

**What it cost:** `CSAT-1790063984266`, 2026-09-22 —

```
08:05:52  originate -> 01789896378
08:05:54  Pre-Answer (ringing)
08:06:05  answered
08:06:22  [order-confirm] tts-cli popen failed     <- 15s ceiling reached
          hangup, nothing played
```

The customer answered and heard **silence**, which reads as "the call never
arrived". Zero wav files were written that day, confirming nothing was cached.

**Mitigated two ways:**

- the ceiling is now **45s** (was 15s), so a cold cache is slow rather than silent
- a **recorded prompt** can replace synthesis entirely — see below

**Still true:** with TTS selected and a cold cache, the caller waits. The log
line is also misleading — `tts-cli popen failed` is emitted whenever output is
**empty**, including a timeout kill. `popen` itself is usually fine.

---

## 4. Recorded prompts have nowhere server-side to be the default

**Severity: low. Usability, not correctness.**

A recorded prompt can be chosen **per call** (CSAT → *What they hear*), and
that path is confirmed working on a live call: `CSAT-1790065347288` played
`/var/lib/freeswitch/recordings/<domain>/…` and took the keypress.

There is no way to set it as a survey's **default**:

- CSAT's Settings tab writes to `localStorage` and says *"Saved on this
  computer"*. It never calls `configUpdate`. A default stored there is
  per-browser — a colleague on another machine gets a different survey.
- The only server-side slot is `v_order_confirm_config.prompt_recording_uuid`,
  which is the **shared domain config** from item 2, so setting a CSAT default
  would change what order-confirmation calls play.

**Resolve with item 2**, not before. A second browser-local setting on a page
whose settings already do not leave the browser makes things worse.

`oc_prompt_audio()` falls back to synthesis whenever a recording is unusable —
missing row, wrong domain, file absent — so a bad default degrades to TTS,
never to silence.

---

## 5. CCL standby cannot run the IVR at all

**Severity: medium. Invisible until a failover.**

`.105` has no `/usr/share/freeswitch/scripts/order-confirm-ivr.lua`. The
primary `.109` does.

CCL is HA behind a floating address: `103.95.96.100` moves to whichever node is
live, `.109` is always the primary, `.105` the standby. Deploy scripts that
target `.100` land on whichever node happens to hold it — `ccl_pbx.sh` warns
about exactly this.

**On a failover, outbound IVR stops working**, with nothing in the UI to say
why. The database is shared, so the portal still shows the feature as enabled.

Same class as the SFTP gap fixed on 2026-09-22: infrastructure installed on
the primary only. Fix by mirroring the Lua scripts, then check whatever else
lives outside the shared database.

---

## Quick reference

```sql
-- why did a specific call fail?
SELECT order_id, phone, status, disposition, hangup_cause, dtmf_pressed,
       attempts, fs_call_uuid, answered_date, complete_date
  FROM v_order_confirm_calls ORDER BY insert_date DESC LIMIT 10;
```

```bash
# what the IVR will accept, and whether its audio exists
php /var/www/fusionpbx/app/rest_api/actions/order-confirm-tts-cli.php <call_uuid>
#   valid=12  -> only digits 1 and 2 are accepted
#   msg_b64   -> base64; decode and confirm every '!'-separated file is readable

# trace one call end to end
grep <fs_call_uuid> /var/log/freeswitch/freeswitch.log \
  | grep -iE "Pre-Answer|has been answered|order-confirm|playing|Hangup"
```

**Dispositions worth recognising:** `tts_error` — audio never generated (item 3);
`no_input` — caller pressed a digit the IVR was not told to accept (item 2);
`no_gateway` — the domain has no outbound gateway, and the call was never placed.

---

## Appendix: never put a secret containing `%` on a cron line

**cron treats an unescaped `%` in the command as a newline.** Everything after
it becomes stdin, so the job silently does nothing — no error, no log file, no
entry in the journal. The line looks perfectly correct when you read it.

BTCL's service key ends `#$%&`. Adding it to `/etc/cron.d/recording-export` on
2026-09-20 killed every line that carried it, and it went unnoticed for three
days because the failure is invisible:

```
export-status.log      2026-09-23 02:10   line has NO key  -> still running
retention-notify.log   never              line HAS the key -> dead on arrival
sftp-provision.log     2026-09-20 23:15   line HAS the key -> stopped that day
```

The tell is a log file that never appears, next to sibling jobs in the same
file that log fine.

**Keep per-platform secrets in `v_default_settings`**, category `recordings`,
subcategory `system_access_key`. The jobs resolve environment first, then that
setting, so a cron line needs no key at all and no escaping question arises.

If a key must go on a cron line, escape it as `\%` — but prefer not to. CCL's
key is 32 hex characters precisely to avoid this class of problem; BTCL's
predates that choice.
