# Follow me to an outside number: present the partner's DID

`index.lua` replaces FreeSWITCH's `scripts/app/follow_me/index.lua`. It is the
stock FusionPBX script (md5 8f48898f, as on BTCL and CCL .105) with one change:
when an **outside** call is followed out to an external number, the leg presents
one of the partner's own DIDs as its caller ID.

## Why

The SBC refuses any outbound call whose caller number is not one of the
partner's own DIDs, and answers `480` within about 40 ms. The same check broke
call forwarding on 2026-10-26; see `jobs/forward-caller-id.php`.

Follow me does not go through those dialplans. The script dials each external
destination as its own `loopback/<number>` leg and sets that leg's caller ID
itself:

- **Stock script:** passes the outside caller's number through, so the SBC
  rejects it.
- **CCL .109, edited on the server on 2026-04-21** (in no repo): used
  `${caller_destination}`. On CCL that holds the *extension* (the global
  caller-details rule fills it in), so the call went out as `10001`.

Found on 2026-11-08 for nochinta.cosmocom.net ext 10001 (DID 09646750750):

- Every leg to the mobile got `480`.
- None had connected since the CDRs start (2026-08-10).
- Normal outbound calls from the same extension work, because they present
  09646750750.

## What it presents

The change applies to inbound calls only. It takes the first of these that is a
real number (8 or more digits, optional `+`):

1. **`${forward_caller_id_did}`**: the DID the inbound route matched. The
   `forward-caller-id-did` public dialplan from `jobs/forward-caller-id.php` sets
   it on every inbound call, on BTCL and CCL.
2. **`${caller_destination}`**: on BTCL this already holds the DID; on CCL it
   holds the extension, which the length check rejects.
3. **The extension's `outbound_caller_id_number`**: read from `v_extensions` in
   the same query the script already makes.

An extension number never goes out as a caller ID. If none of the three
qualifies, the stock behaviour stays. Local calls (one extension calling another
that follows out) are not touched; the stock code already uses the caller's
outbound caller ID there.

The value goes into `origination_`, `outbound_` and `effective_caller_id_*`. The
outbound route copies `outbound_caller_id_number` into the caller ID it sends.

The 2026-04-21 edit also set `sip_cid_type=none`. That is dropped, so the leg goes
out the same way as an ordinary outbound call, which the SBC accepts.

## Install / rollback (as root, on each PBX node)

    bash install.sh --check   # show the diff, change nothing
    bash install.sh           # back up the live script, install this one
    bash rollback.sh <dir>    # put the backup back (newest one if omitted)

- **Where to run it from:** anywhere, for example a copy in `/tmp`.
- **Refusals:** `install.sh` refuses a live script it does not recognise, so it
  cannot silently wipe someone's edit. Pass `--force` to override.
- **Backups:** go to `/var/backups/fusionpbx-patches/<timestamp>-follow-me/`.
- **No reload:** mod_lua reads the script on every call, so the next call uses it
  and calls in progress are not touched.

A FusionPBX upgrade that re-copies the switch scripts overwrites this file
without warning. After an upgrade, run `install.sh` again; it says "already
installed" when nothing changed.
