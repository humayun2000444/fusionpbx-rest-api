#!/usr/bin/env python3
"""Place one test call into the no-pbx profile and check it parks without any dialplan lookup.

Run as root on the FreeSWITCH box, after install.sh --restart:

    test-park.py <sip-ip> [sip-port]

It talks to the event socket the way RouteSphere will: one inbound connection, CHANNEL_PARK only,
filtered to Caller-Context no-pbx-client. It originates a call from the internal profile to
<sip-ip>:<sip-port>, waits for the park event, hangs the call up, and then reads the FreeSWITCH log
for that call. PASS needs the event, context no-pbx-client on profile no-pbx, a park, and no
mod_dialplan_xml or xml_handler line for the call.
"""
import os
import socket
import sys
import time
import urllib.parse

LOG = "/var/log/freeswitch/freeswitch.log"


def esl_settings():
    """Host, port and password as FusionPBX uses them: config.conf, then fs_cli.conf, then defaults."""
    found = {}
    try:
        with open("/etc/fusionpbx/config.conf") as f:
            for line in f:
                key, sep, value = line.partition("=")
                if sep and key.strip().startswith("switch.event_socket."):
                    found[key.strip().rsplit(".", 1)[1]] = value.strip()
    except OSError:
        pass
    if "password" not in found:
        try:
            with open("/etc/fs_cli.conf") as f:
                for line in f:
                    key, sep, value = line.partition("=>")
                    if sep and key.strip() == "password":
                        found["password"] = value.strip()
        except OSError:
            pass
    return found.get("host", "127.0.0.1"), int(found.get("port", "8021")), found.get("password", "ClueCon")


class EventSocket:
    def __init__(self, host, port, password):
        self.sock = socket.create_connection((host, port), timeout=15)
        self.buf = b""
        self.events = []
        headers, _ = self.read()
        if headers.get("Content-Type") != "auth/request":
            raise RuntimeError("no auth request from the event socket")
        reply, _ = self.command("auth " + password)
        if not reply.get("Reply-Text", "").startswith("+OK"):
            raise RuntimeError("event socket auth failed: " + reply.get("Reply-Text", ""))

    def _line(self):
        while b"\n" not in self.buf:
            chunk = self.sock.recv(65536)
            if not chunk:
                raise RuntimeError("event socket closed")
            self.buf += chunk
        line, self.buf = self.buf.split(b"\n", 1)
        return line.decode()

    def _exact(self, n):
        while len(self.buf) < n:
            chunk = self.sock.recv(65536)
            if not chunk:
                raise RuntimeError("event socket closed")
            self.buf += chunk
        data, self.buf = self.buf[:n], self.buf[n:]
        return data.decode()

    def read(self):
        headers = {}
        while True:
            line = self._line()
            if line == "":
                if headers:
                    break
                continue
            key, _, value = line.partition(": ")
            headers[key] = value
        body = self._exact(int(headers["Content-Length"])) if "Content-Length" in headers else ""
        return headers, body

    def command(self, cmd):
        self.sock.sendall((cmd + "\n\n").encode())
        while True:
            headers, body = self.read()
            if headers.get("Content-Type") in ("command/reply", "api/response"):
                return headers, body
            self.events.append((headers, body))

    def api(self, cmd):
        return self.command("api " + cmd)[1].strip()

    def next_event(self, timeout):
        if self.events:
            return self.events.pop(0)
        self.sock.settimeout(timeout)
        try:
            return self.read()
        finally:
            self.sock.settimeout(15)


def event_fields(body):
    fields = {}
    for line in body.split("\n"):
        key, sep, value = line.partition(": ")
        if sep:
            fields[key] = urllib.parse.unquote(value)
    return fields


def main():
    if len(sys.argv) < 2:
        print(__doc__)
        return 2
    ip = sys.argv[1]
    port = sys.argv[2] if len(sys.argv) > 2 else "5070"
    tag = "nopbxtest%d" % int(time.time())
    log_start = os.path.getsize(LOG) if os.path.exists(LOG) else 0

    esl = EventSocket(*esl_settings())
    esl.command("event plain CHANNEL_PARK")
    print(esl.command("filter Caller-Context no-pbx-client")[0].get("Reply-Text"))
    print(esl.command("bgapi originate {origination_caller_id_number=nopbxcaller,origination_caller_id_name=no-pbx-test}"
                      "sofia/internal/%s@%s:%s &park()" % (tag, ip, port))[0].get("Reply-Text"))

    park = None
    deadline = time.time() + 15
    while time.time() < deadline and park is None:
        try:
            headers, body = esl.next_event(max(0.1, deadline - time.time()))
        except socket.timeout:
            break
        fields = event_fields(body)
        #the channel name is built from the caller; the dialled user is variable_sip_req_user
        if fields.get("Event-Name") == "CHANNEL_PARK" and fields.get("variable_sip_req_user") == tag:
            park = fields

    if park is None:
        print("FAIL: no CHANNEL_PARK for %s within 15 s" % tag)
        return 1

    uuid = park["Unique-ID"]
    print("CHANNEL_PARK  uuid=%s" % uuid)
    for key in ("Channel-Name", "Caller-Context", "variable_sofia_profile_name", "Caller-Dialplan",
                "Caller-Caller-ID-Number", "Channel-State", "Answer-State"):
        print("  %-28s %s" % (key, park.get(key)))
    #where RouteSphere finds the dialled number
    for key in sorted(k for k, v in park.items() if v == tag):
        print("  %-32s %s   (dialled number)" % (key, park.get(key)))
    print("uuid_kill: " + esl.api("uuid_kill %s NORMAL_CLEARING" % uuid))
    time.sleep(2)

    with open(LOG, errors="replace") as f:
        f.seek(log_start)
        lines = [line.rstrip() for line in f if line.startswith(uuid)]
    lookups = [line for line in lines if "mod_dialplan_xml" in line or "xml_handler" in line]
    parked = [line for line in lines if "park()" in line or "park_state" in line or "PARK" in line]
    print("log lines for the call: %d, dialplan/xml_handler lines: %d, park lines: %d"
          % (len(lines), len(lookups), len(parked)))
    for line in lookups[:5]:
        print("  " + line[37:200])

    ok = (park.get("Caller-Context") == "no-pbx-client"
          and park.get("variable_sofia_profile_name") == "no-pbx"
          and park.get("variable_sip_req_user") == tag
          and not lookups)
    #inline:park leaves Caller-Destination-Number empty (README); report it so a fix shows up here
    print("Caller-Destination-Number: %r (dialled %r)" % (park.get("Caller-Destination-Number"), tag))
    print("PASS" if ok else "FAIL")
    return 0 if ok else 1


if __name__ == "__main__":
    sys.exit(main())
