#!/usr/bin/env python3
"""
emergency_classify.py — PROTOTYPE 999 emergency mood/intent classifier.

Pipeline (per the spec):
  transcript --> [keyword safety-net]  +  [Groq LLM intent/mood]  -->
     merge --> deterministic priority + recommended_services + human-escalation
     --> JSON.

Design decisions:
- The LLM detects MOODS/TYPES + a text emotion (never rely on keywords alone).
- A keyword net force-includes obvious categories the LLM might miss (a clear
  "আগুন"/"বাঁচান" must never be dropped). Both are merged; max confidence wins.
- priority + recommended_services + requires_human_operator are computed IN CODE
  from the spec's tables — auditable, not LLM freelancing.
- Bias toward escalation: any P1, any escalate-state, low confidence, or a silent
  call -> requires_human_operator = true.
- Voice emotion (prosody) is stubbed here (prototype runs off transcripts);
  in production it feeds/överrides the `emotion` field, esp. for silent/panic.

NOT wired into the daemon — standalone so we can eyeball the JSON on real calls.
"""

import json
import re
import sys
import urllib.request

GROQ_URL = "https://api.groq.com/openai/v1/chat/completions"
GROQ_MODEL = "llama-3.3-70b-versatile"

# ---------------------------------------------------------------------------
# Taxonomy: canonical name -> label, kind, base priority, services, keywords.
# kind: "type" (drives services) | "state" (emotion/behaviour) | "call" (call type)
# priority: 1..4 (1 = most critical). CRITICAL_KW can bump some types to P1.
# ---------------------------------------------------------------------------
P1, P2, P3, P4 = 1, 2, 3, 4

CATEGORIES = {
    "fire_emergency": dict(label="Fire Emergency", kind="type", priority=P1,
        services=["Fire Service"],
        kw=["আগুন লেগেছে", "আগুন", "fire", "smoke", "burning", "বিস্ফোরণ",
            "gas leakage", "explosion", "পুড়ে"]),
    "medical_emergency": dict(label="Medical Emergency", kind="type", priority=P2,
        services=["Ambulance"],
        kw=["শ্বাস নিতে পারছি না", "heart attack", "stroke", "unconscious",
            "bleeding", "প্রচুর রক্ত", "বুকে ব্যথা", "seizure", "অজ্ঞান",
            "emergency medical", "হার্ট"]),
    "accident_emergency": dict(label="Accident Emergency", kind="type", priority=P2,
        services=["Ambulance", "Police"],
        kw=["accident", "এক্সিডেন্ট", "গাড়ি উল্টে", "পড়ে গেছে", "bike accident",
            "বাস চাপা", "আহত", "hit and run"]),
    "crime_victim": dict(label="Crime Victim", kind="type", priority=P2,
        services=["Police"],
        kw=["আমাকে মারছে", "ছিনতাই", "আক্রমণ করেছে", "assault", "robbery",
            "mugging", "attacked me", "broke into my house", "ডাকাত"]),
    "domestic_violence": dict(label="Domestic Violence", kind="type", priority=P1,
        services=["Police", "Victim Support"],
        kw=["স্বামী মারছে", "wife is beating", "domestic violence",
            "family is threatening", "physical abuse", "নির্যাতন"]),
    "child_emergency": dict(label="Child Emergency", kind="type", priority=P1,
        services=["Police", "Ambulance"],
        kw=["বাচ্চা হারিয়ে", "missing child", "child is trapped", "বাচ্চাটা",
            "baby is unconscious", "child abuse", "শিশু"]),
    "suicide_risk": dict(label="Suicide Risk", kind="type", priority=P1,
        services=["Police", "Medical Support", "Mental Health Crisis Response"],
        kw=["আত্মহত্যা", "মরতে চাই", "kill myself", "suicide",
            "don't want to live", "want to die", "মরে যাব"]),
    "sexual_assault": dict(label="Sexual Assault", kind="type", priority=P1,
        services=["Police", "Medical Support"],
        kw=["ধর্ষণ", "sexual assault", "rape", "molestation", "শ্লীলতাহানি"]),
    "missing_person": dict(label="Missing Person", kind="type", priority=P3,
        services=["Police"],
        kw=["missing person", "নিখোঁজ", "cannot find my family", "child missing"]),
    "harassment": dict(label="Harassment", kind="type", priority=P2,
        services=["Police"],
        kw=["আমাকে ফলো করছে", "harassing me", "threatening me", "blackmail",
            "stalking", "উত্ত্যক্ত"]),
    "suspicious_activity": dict(label="Suspicious Activity", kind="type", priority=P3,
        services=["Police"],
        kw=["সন্দেহজনক", "suspicious person", "suspicious vehicle",
            "strange activity", "someone is watching"]),
    "natural_disaster": dict(label="Natural Disaster", kind="type", priority=P2,
        services=["Rescue Services"],
        kw=["building collapse", "flood", "cyclone", "earthquake",
            "ভবন ধসে", "ঝড়", "বন্যা", "ভূমিকম্প"]),
    # states / emotions (no direct service; affect priority + escalation)
    "panic": dict(label="Panic", kind="state", priority=P2,
        services=["Human Agent Escalation"], escalate=True,
        kw=["বাঁচান", "বাঁচাও", "please save me", "কেউ বাঁচান", "আমি শেষ",
            "i need help", "জরুরি"]),
    "fear": dict(label="Fear", kind="state", priority=P2, services=[],
        kw=["ভয় লাগছে", "আমাকে মেরে ফেলবে", "someone is following me",
            "i am scared", "অনুসরণ করছে", "বন্দুক", "ছুরি"]),
    "angry_caller": dict(label="Angry Caller", kind="state", priority=P3, services=[],
        kw=["nobody is helping", "police is not responding", "unacceptable",
            "complaint", "furious", "রাগ"]),
    "crying_distressed": dict(label="Crying / Distressed", kind="state", priority=P2,
        services=[], kw=["crying", "কাঁদছে", "distressed", "কান্না"]),
    "confused_caller": dict(label="Confused Caller", kind="state", priority=P3,
        services=[], kw=["কী করবো বুঝতে পারছি না", "don't know what to do",
                         "please guide me", "help me"]),
    "calm_information": dict(label="Calm Information Call", kind="call", priority=P3,
        services=[], kw=["want to report", "information only", "reporting an incident"]),
    "silent_call": dict(label="Silent Call", kind="call", priority=P2,
        services=["Human Agent Escalation"], escalate=True, kw=[]),
    "drunk_intoxicated": dict(label="Drunk / Intoxicated", kind="call", priority=P3,
        services=[], kw=["drunk", "মাতাল", "নেশা"]),
    "fake_prank": dict(label="Fake / Prank Call", kind="call", priority=P4,
        services=[], kw=["prank", "just joking", "মজা করছি"]),
    "non_emergency": dict(label="Non-Emergency", kind="call", priority=P4,
        services=[], kw=["general inquiry", "non-urgent"]),
}

# keywords that push a normally-P2 type to P1 (critical severity)
CRITICAL_KW = ["শ্বাস নিতে পারছি না", "heart attack", "stroke", "unconscious",
               "অজ্ঞান", "not breathing", "building collapse", "ভবন ধসে",
               "গাড়ি উল্টে", "প্রচুর রক্ত", "hit and run", "চাপা"]

ALWAYS_ESCALATE = {"panic", "silent_call", "suicide_risk", "sexual_assault",
                   "domestic_violence"}
LOW_CONF = 60   # below this max confidence -> escalate to a human


def norm(s):
    return (s or "").lower()


def kw_match(kw, text_lower):
    """English (ASCII) keywords match on word boundaries so "attack" doesn't hit
    "heart attack"; Bangla keywords match as substring (script has no such traps)."""
    k = kw.lower()
    if not k:
        return False
    if k.isascii():
        return re.search(r"\b" + re.escape(k) + r"\b", text_lower) is not None
    return k in text_lower


def keyword_hits(transcript):
    """Return {category: matched_keyword} for every category whose keyword appears."""
    t = norm(transcript)
    hits = {}
    for cat, meta in CATEGORIES.items():
        for kw in meta["kw"]:
            if kw_match(kw, t):
                hits[cat] = kw
                break
    return hits


def is_critical(transcript):
    t = norm(transcript)
    return any(kw_match(k, t) for k in CRITICAL_KW)


LLM_CATS = "\n".join(
    "- %s (%s): %s" % (c, m["kind"], m["label"]) for c, m in CATEGORIES.items())


def llm_classify(transcript, groq_key):
    """Ask Groq for detected moods + a text emotion. Returns dict or {} on failure."""
    prompt = (
        "You are a 999 emergency-call triage AI for Bangladesh. The transcript is "
        "Bangla/English (Banglish). Detect ALL applicable categories from this list "
        "(a caller can match several at once), each with a 0-100 confidence and a "
        "short evidence quote. Also give the caller's dominant emotion.\n\n"
        "Categories:\n" + LLM_CATS + "\n\n"
        "Return ONLY JSON:\n"
        "{\"moods\":[{\"name\":\"<category key>\",\"confidence\":<0-100>,"
        "\"evidence\":\"<quote>\"}],"
        "\"emotion\":{\"name\":\"calm|fear|panic|crying|angry|nervous|shock|"
        "confused|aggressive|helpless|silent\",\"confidence\":<0-100>},"
        "\"critical\":<true|false>,\"reason\":\"<one line>\"}\n\n"
        "Rules: use ONLY the exact category keys above. If nothing is an emergency, "
        "return non_emergency. Be sensitive: err toward flagging.\n\n"
        "Transcript:\n" + transcript[:8000])
    body = json.dumps({
        "model": GROQ_MODEL,
        "messages": [{"role": "user", "content": prompt}],
        "response_format": {"type": "json_object"},
        "temperature": 0.2, "max_tokens": 900,
    }).encode()
    req = urllib.request.Request(GROQ_URL, data=body, headers={
        "Authorization": "Bearer " + groq_key, "Content-Type": "application/json",
        "User-Agent": "fusionpbx-999-proto/1.0"})
    try:
        raw = urllib.request.urlopen(req, timeout=45).read().decode()
        content = json.loads(raw)["choices"][0]["message"]["content"]
        try:
            return json.loads(content)
        except ValueError:
            return json.loads(content[content.index("{"):content.rindex("}") + 1])
    except Exception as e:  # noqa: BLE001
        print("  [LLM error: %s]" % str(e)[:160], file=sys.stderr)
        return {}


def classify(transcript, groq_key, caller_id="999XXXXXXX", voice_emotion=None):
    llm = llm_classify(transcript, groq_key)
    kw = keyword_hits(transcript)
    critical = is_critical(transcript) or bool(llm.get("critical"))

    # merge moods: start from the LLM, fold in keyword-net hits it missed
    moods = {}
    for m in llm.get("moods", []):
        name = m.get("name")
        if name in CATEGORIES:
            moods[name] = {"name": name, "confidence": int(m.get("confidence", 70)),
                           "source": "llm", "evidence": m.get("evidence", "")}
    for cat, matched in kw.items():
        if cat not in moods:
            moods[cat] = {"name": cat, "confidence": 85, "source": "keyword",
                          "evidence": matched}
        else:
            moods[cat]["source"] = "llm+keyword"
            moods[cat]["confidence"] = max(moods[cat]["confidence"], 80)

    # silent call: no transcript at all
    if not transcript.strip() and not moods:
        moods["silent_call"] = {"name": "silent_call", "confidence": 90,
                                "source": "no-speech", "evidence": ""}

    detected = list(moods.values())
    detected.sort(key=lambda x: -x["confidence"])

    # deterministic priority: most-urgent among detected (critical bumps types to P1)
    def prio(cat):
        p = CATEGORIES[cat]["priority"]
        if critical and CATEGORIES[cat]["kind"] == "type":
            p = min(p, P1)
        return p
    best_p = min((prio(m["name"]) for m in detected), default=P4)
    priority = "P%d" % best_p

    # services = union across detected TYPES + escalation states (spec's tables)
    services, seen = [], set()
    for m in detected:
        for s in CATEGORIES[m["name"]]["services"]:
            if s not in seen:
                seen.add(s); services.append(s)

    max_conf = max((m["confidence"] for m in detected), default=0)
    escalate = (best_p == P1
                or any(m["name"] in ALWAYS_ESCALATE for m in detected)
                or max_conf < LOW_CONF
                or any(m["name"] == "silent_call" for m in detected))

    emotion = voice_emotion or llm.get("emotion") or {"name": "calm", "confidence": 50}

    return {
        "caller_id": caller_id,
        "current_moods": [{"name": m["name"], "confidence": m["confidence"],
                           "source": m["source"]} for m in detected],
        "emotion": emotion,
        "priority": priority,
        "recommended_services": services,
        "requires_human_operator": escalate,
        "_debug": {"critical": critical, "keyword_hits": list(kw.keys()),
                   "llm_reason": llm.get("reason", "")},
    }


if __name__ == "__main__":
    key = open("groq_key.txt").read().strip()
    text = sys.stdin.read()
    print(json.dumps(classify(text, key), ensure_ascii=False, indent=2))
