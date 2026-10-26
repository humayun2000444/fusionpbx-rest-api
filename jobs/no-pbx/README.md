# no-pbx: a SIP profile for RouteSphere on the FusionPBX FreeSWITCH

One FreeSWITCH serves both FusionPBX and RouteSphere. FusionPBX keeps its own
profiles (`internal`, `external`) and its database-driven dialplan. RouteSphere
gets a profile of its own, `no-pbx`, on a separate IP:port:

- Every call that arrives on it **parks at once** (`dialplan=inline:park`).
  There is no XML, Lua or database lookup per call.
- **The parked call has no `Caller-Destination-Number`.** FreeSWITCH's inline
  dialplan ends by setting `destination_number` to `rdnis`
  (`mod_dptools.c`), and `rdnis` is empty on an inbound call. The dialled
  number is still in `variable_sip_req_user` (and `variable_sip_to_user`).
  - A `${...}` in the profile's `dialplan` cannot put it back. FreeSWITCH
    rewrites `${` to `%{` when it builds the caller profile
    (`profile_dup_clean`), so
    `set_profile_var:destination_number=${sip_req_user}` sets the literal
    text `%{sip_req_user}`. That was tried on 2026-10-25.
  - RouteSphere today reads `Caller-Destination-Number` and logged
    `called=null`.
  - So either RouteSphere falls back to `variable_sip_req_user`, or
    `no-pbx-xml-handler.lua` serves a static park extension for context
    `no-pbx-client`. The second costs one small Lua run per call but no
    database or FusionPBX lookup.
- RouteSphere controls the parked call over the **event socket** by UUID:
  answer, play, bridge, transfer, hang up.
- FusionPBX does not know the profile. It is not in Advanced > SIP Profiles,
  and FusionPBX's database and PHP are not changed.

## Why it needs a wrapper

On a FusionPBX box, FreeSWITCH asks FusionPBX's Lua handler (`app.lua
xml_handler`) for `sofia.conf`, and that handler builds it from the database
only. A static `sip_profiles/*.xml` file is never read. So
`no-pbx-xml-handler.lua` takes the handler's place in `lua.conf.xml`. It runs
the FusionPBX handler unchanged, and for `sofia.conf` only it adds
`/etc/freeswitch/no-pbx/no-pbx-profile.xml` before `</profiles>`. That happens
when FreeSWITCH starts or a profile is started or rescanned, never per call.

## Files

| In this folder | Installed as |
|---|---|
| `no-pbx-profile.xml` | `/etc/freeswitch/no-pbx/no-pbx-profile.xml`, with the IP and port filled in |
| `no-pbx-xml-handler.lua` | `/usr/share/freeswitch/scripts/no-pbx-xml-handler.lua` |
| (one line) | `/etc/freeswitch/autoload_configs/lua.conf.xml`: `xml-handler-script` changes from `app.lua xml_handler` to `no-pbx-xml-handler.lua`, after a backup to `/var/backups/fusionpbx-patches/<ts>/` |
| `install.sh`, `rollback.sh`, `test-park.py` | run from this folder, as root on the box |

## Install

    jobs/no-pbx/install.sh 10.9.9.18 5070              # files in place, not live yet
    jobs/no-pbx/install.sh 10.9.9.18 5070 --restart    # also restarts FreeSWITCH and checks

mod_lua reads `xml-handler-script` only when FreeSWITCH starts, so the first
install goes live at the next FreeSWITCH restart. **That restart drops every
call on the box**, so on a production PBX do it off-hours. `--restart` checks
that `no-pbx` is RUNNING on the IP:port and that every profile that was
running before came back.

Later changes to the profile alone do not need a FreeSWITCH restart. When the
wrapper is already live and `no-pbx` is running, `install.sh … --restart`
runs `sofia profile no-pbx restart` instead. That drops only no-pbx calls.

Then:

    jobs/no-pbx/test-park.py 10.9.9.18 5070

It connects to the event socket as RouteSphere will and originates one call
from the `internal` profile into `no-pbx`. It waits for `CHANNEL_PARK` with
`Caller-Context no-pbx-client` and hangs the call up. It then checks the
FreeSWITCH log for that call: there must be no `mod_dialplan_xml` or
`xml_handler` line, which proves nothing was looked up.

## Who can call it

`auth-calls` is off and registrations are disabled, so anyone who can reach
the IP:port can place a call. The call only parks, but it does take a channel.

To allow only RouteSphere's peers, add an access control list named
`no-pbx-peers` in Advanced > Access Controls. Then uncomment
`apply-inbound-acl` in the profile, run `install.sh` again, and run
`fs_cli -x 'sofia profile no-pbx restart'`. That restart drops only no-pbx
calls.

## RouteSphere on the event socket

RouteSphere shares the event socket with FusionPBX: `127.0.0.1:8021`, with the
password from `switch.event_socket.password` in `/etc/fusionpbx/config.conf`.
One FreeSWITCH has only one event socket listener.

    auth <password>
    event plain CHANNEL_PARK CHANNEL_ANSWER CHANNEL_HANGUP_COMPLETE DTMF
    filter Caller-Context no-pbx-client

- On `CHANNEL_PARK`, control the call by its `Unique-ID`: `api uuid_answer`,
  `uuid_broadcast`, `uuid_bridge`, `uuid_transfer`, `uuid_kill`, or
  `sendmsg <uuid>` to execute an application.
- The call parks **unanswered** (`Answer-State: ringing`). RouteSphere answers
  it with `uuid_answer`, or rejects it with `uuid_kill`.
- The dialled number is in `variable_sip_req_user`, not in
  `Caller-Destination-Number` (see above). `Channel-Name` is built from the
  caller
  (`sofia/no-pbx/<from-user>@<from-host>`), so do not parse the number from
  it. The caller is `Caller-Caller-ID-Number`.
- To call out on the profile:
  `bgapi originate sofia/no-pbx/<number>@<host:port> &park()`
- Only use `uuid_*` commands, and only on your own calls. Never send global
  commands (`reloadxml`, `sofia profile … restart`, `fsctl`, `shutdown`), and
  never subscribe to `event plain all`. FusionPBX and its services use the
  same socket.
- After a reconnect, send `event` and `filter` again. Then pick up calls that
  are already parked from `api show channels as json`, keeping the rows with
  `"context": "no-pbx-client"`.
- Do not change the event socket password without updating `config.conf`.
  FusionPBX's `switch_settings` class ignores `config.conf` and falls back to
  `ClueCon`, which leaves the switch directories blank on the next
  `upgrade.php --defaults` run (see the mail-VM install notes).

## Call detail records

FusionPBX's CDR importer loads every CDR that mod_xml_cdr writes. A call parked on this profile belongs to
RouteSphere, which writes its own CDR from the event socket, so the profile parks with `process_cdr=false` and
FusionPBX records nothing for that leg. Without it every RouteSphere call also shows up in the FusionPBX CDR page
as a call with no domain and no direction.

The dialplan value is `inline:m:^:set:process_cdr=false^park`. Use `m:^:` and not a comma: FreeSWITCH splits the
whole value on commas into a list of dialplans, so `set:process_cdr=false,park` makes `park` a dialplan name and
the call hangs up at once ("has executed the last dialplan instruction").

The legs RouteSphere bridges out are b-legs, which mod_xml_cdr does not log (`log-b-leg=false`). A call between a
FusionPBX extension and RouteSphere keeps the extension's own CDR in its domain, which is correct. When the bridge
target is FusionPBX itself (the testbed's echo test) the far end arrives as a normal inbound call and is recorded.

## Rollback

    jobs/no-pbx/rollback.sh              # profile stopped and removed now; wrapper stays until the next restart
    jobs/no-pbx/rollback.sh --restart    # also restarts FreeSWITCH and deletes the wrapper

Stopping the profile drops only no-pbx calls. Do not delete the wrapper
before FreeSWITCH restarts: mod_lua keeps calling it for every lookup until
then.

## After a FusionPBX upgrade

An upgrade does not touch `/etc/freeswitch/no-pbx` or the wrapper. Check that
`lua.conf.xml` still says `no-pbx-xml-handler.lua`; if it does not, run
`install.sh` again.

## Tested

On the BTCL mail VM, 2026-10-25: FusionPBX 5.4 (telcobright fork) and
FreeSWITCH 1.10.12 d8481a9, the same versions as the BTCL PBX. The profile was
on `10.9.9.18:5070`.

| Check | Result |
|---|---|
| `install.sh 10.9.9.18 5070 --restart` | `no-pbx` RUNNING on 10.9.9.18:5070; `internal` and `external` back on 5060/5080 |
| FusionPBX handler through the wrapper | `xml_locate` for sofia.conf returns external, internal and no-pbx; music on hold loads; GUI login page 200 |
| `test-park.py 10.9.9.18 5070` | PASS. `CHANNEL_PARK` arrived through the `Caller-Context` filter: context `no-pbx-client`, profile `no-pbx`, `Caller-Dialplan inline:park`, ringing. The call's 76 log lines had 0 `mod_dialplan_xml`/`xml_handler` lines. Hung up cleanly, 0 channels left |
| Wrapper logic, with stubs | Adds the profile once, never twice, keeps the FusionPBX profiles, leaves other sections alone, and survives an empty answer |
