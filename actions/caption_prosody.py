#!/usr/bin/env python3
"""
caption_prosody.py — lightweight, dependency-free voice-emotion (prosody) analysis
for the live-caption daemon.

The live captions already tell us *what* was said; the Groq summary infers mood
from the *words*. This module adds the missing signal: *how* it was said — read
straight from the audio (loudness, pitch, pitch instability, pace). That is
language-independent (works for Bangla with zero language model) and catches
what text misses: a calm sentence delivered in a shaking, rising, angry voice.

Honest scope: prosody reliably measures AROUSAL (calm <-> agitated/distressed),
which is exactly the "caller is escalating" signal a call centre cares about.
It is weak on VALENCE (happy vs angry both look "loud + high-pitched") — for
that the transcript is better, so the daemon fuses this with the text summary.

Design choices that matter on a live prod PBX (8 cores, no GPU, no numpy):
  * pure Python stdlib only — keeps the feature exactly as portable as it is today
  * per-utterance pitch cost is hard-bounded (decimate to ~8 kHz + cap frames)
    so worst case is ~100-200 ms; the daemon runs it in a thread executor so it
    never blocks the asyncio audio loop
  * every judgement is RELATIVE to the speaker's own rolling baseline, so it
    does not depend on absolute levels tuned to one recording (loud vs soft
    talker, male vs female pitch all self-calibrate)

Public API:
    feats = analyze_utterance(pcm_int16_le_bytes, rate)          # pure DSP
    tone, arousal, detail = SpeakerBaseline().classify(feats, text)
    baseline.update(feats)                                       # after classify
"""

import array
import math
import sys

# ---- tunables ---------------------------------------------------------------
VOICE_FLOOR = 55.0        # int16 RMS below this frame counts as silence
GATE_FRAC = 0.18          # voiced gate = GATE_FRAC * p95(frame_rms), min VOICE_FLOOR
PITCH_MIN_HZ = 75.0       # human voice F0 floor
PITCH_MAX_HZ = 400.0      # human voice F0 ceiling
PITCH_CONF = 0.30         # normalized autocorr peak needed to accept a pitch
MAX_PITCH_FRAMES = 90     # cap frames we run autocorrelation on (bounds CPU)
TARGET_PITCH_RATE = 8000  # decimate toward this for pitch (F0 << 4 kHz Nyquist)
FRAME_MS = 32.0
HOP_MS = 16.0
BASELINE_KEEP = 10        # rolling window of past utterances per speaker

# canonical tone enum (stored in v_call_captions.voice_tone; UI localizes/colors)
TONE_CALM = "calm"
TONE_TENSE = "tense"
TONE_AGITATED = "agitated"
TONE_DISTRESSED = "distressed"
TONE_SUBDUED = "subdued"


# ---- primitives -------------------------------------------------------------
def _to_floats(pcm_bytes):
    """int16 little-endian PCM bytes -> array('d') of sample values."""
    n = len(pcm_bytes) & ~1  # even
    a = array.array("h")
    a.frombytes(pcm_bytes[:n])
    if sys.byteorder == "big":
        a.byteswap()
    return array.array("d", a)


def _decimate(samples, factor):
    """Box-filter + decimate by integer factor (cheap anti-alias for pitch)."""
    if factor <= 1:
        return samples
    n = len(samples)
    out = array.array("d")
    i = 0
    inv = 1.0 / factor
    while i + factor <= n:
        s = 0.0
        for j in range(factor):
            s += samples[i + j]
        out.append(s * inv)
        i += factor
    return out


def _frame_rms(samples, win, hop):
    """RMS per frame; returns list of (start_index, rms)."""
    out = []
    n = len(samples)
    i = 0
    while i + win <= n:
        s = 0.0
        for k in range(i, i + win):
            v = samples[k]
            s += v * v
        out.append((i, math.sqrt(s / win)))
        i += hop
    return out


def _hann(n):
    if n <= 1:
        return [1.0] * n
    c = math.pi * 2.0 / (n - 1)
    return [0.5 - 0.5 * math.cos(c * i) for i in range(n)]


def _frame_pitch(samples, start, win, rate, window):
    """Autocorrelation F0 for one frame, or None if unvoiced/unreliable."""
    seg = samples[start:start + win]
    m = sum(seg) / win
    x = [(seg[i] - m) * window[i] for i in range(win)]
    r0 = 0.0
    for v in x:
        r0 += v * v
    if r0 < 1e-6:
        return None
    lag_min = max(2, int(rate / PITCH_MAX_HZ))
    lag_max = min(win - 1, int(rate / PITCH_MIN_HZ))
    rr = []
    for lag in range(lag_min, lag_max + 1):
        s = 0.0
        limit = win - lag
        for i in range(limit):
            s += x[i] * x[i + lag]
        rr.append(s / r0)
    if not rr:
        return None
    bi = max(range(len(rr)), key=lambda i: rr[i])
    if rr[bi] < PITCH_CONF:
        return None
    lag = float(lag_min + bi)
    # parabolic interpolation of the autocorr peak -> sub-lag precision. At 8 kHz
    # one integer lag step is ~6-7 Hz near 230 Hz, which otherwise quantizes F0
    # into stripes and hides real pitch movement; this recovers a continuous F0.
    if 0 < bi < len(rr) - 1:
        a, b, c = rr[bi - 1], rr[bi], rr[bi + 1]
        denom = a - 2.0 * b + c
        if denom != 0.0:
            lag += 0.5 * (a - c) / denom
    if lag <= 0:
        return None
    return rate / lag


def _percentile(sorted_vals, p):
    if not sorted_vals:
        return 0.0
    if len(sorted_vals) == 1:
        return sorted_vals[0]
    idx = p * (len(sorted_vals) - 1)
    lo = int(math.floor(idx))
    hi = min(lo + 1, len(sorted_vals) - 1)
    frac = idx - lo
    return sorted_vals[lo] * (1 - frac) + sorted_vals[hi] * frac


def _median(vals):
    if not vals:
        return 0.0
    s = sorted(vals)
    return _percentile(s, 0.5)


def _stdev(vals):
    n = len(vals)
    if n < 2:
        return 0.0
    m = sum(vals) / n
    return math.sqrt(sum((v - m) ** 2 for v in vals) / (n - 1))


# ---- feature extraction -----------------------------------------------------
def analyze_utterance(pcm_bytes, rate):
    """
    Extract prosodic features from one utterance's mono int16 PCM.

    Returns a dict (all floats; safe defaults on silence/too-short):
        dur_s        utterance length in seconds
        voiced_ratio fraction of frames above the voiced gate
        energy_mean  mean RMS over voiced frames (loudness)
        energy_dyn   loudness dynamics (p90-p10)/mean
        f0_mean      median voiced pitch in Hz (0 if none)
        f0_std       stdev of voiced pitch (instability/tremor)
        f0_range     p90-p10 of voiced pitch
        pitch_var    f0_std / f0_mean (0 if no pitch)
        n_voiced     number of voiced frames
    """
    samples = _to_floats(pcm_bytes)
    dur_s = len(samples) / float(rate) if rate else 0.0
    blank = {"dur_s": dur_s, "voiced_ratio": 0.0, "voiced_secs": 0.0,
             "energy_mean": 0.0, "energy_dyn": 0.0, "f0_mean": 0.0,
             "f0_std": 0.0, "f0_range": 0.0, "pitch_var": 0.0, "n_voiced": 0}
    if len(samples) < int(rate * FRAME_MS / 1000.0) or rate <= 0:
        return blank

    # --- energy on full-rate frames ---
    win = max(1, int(rate * FRAME_MS / 1000.0))
    hop = max(1, int(rate * HOP_MS / 1000.0))
    rms = _frame_rms(samples, win, hop)
    if not rms:
        return blank
    vals = sorted(r for _, r in rms)
    p95 = _percentile(vals, 0.95)
    gate = max(VOICE_FLOOR, GATE_FRAC * p95)
    voiced = [(i, r) for (i, r) in rms if r > gate]
    voiced_ratio = len(voiced) / float(len(rms))
    if not voiced:
        return dict(blank, voiced_ratio=0.0)
    v_rms = sorted(r for _, r in voiced)
    energy_mean = sum(v_rms) / len(v_rms)
    e_p90 = _percentile(v_rms, 0.90)
    e_p10 = _percentile(v_rms, 0.10)
    energy_dyn = (e_p90 - e_p10) / (energy_mean + 1e-9)

    # --- pitch on a decimated copy, only over voiced spans, frame-capped ---
    factor = max(1, int(round(rate / float(TARGET_PITCH_RATE))))
    prate = rate / factor
    dsamp = _decimate(samples, factor) if factor > 1 else samples
    pwin = max(8, int(prate * FRAME_MS / 1000.0))
    phop = max(1, int(prate * HOP_MS / 1000.0))
    window = _hann(pwin)
    # candidate voiced frame starts in decimated index space
    starts = [int(i / factor) for (i, _) in voiced]
    starts = [s for s in starts if s + pwin <= len(dsamp)]
    if len(starts) > MAX_PITCH_FRAMES:  # even subsample to bound CPU
        step = len(starts) / float(MAX_PITCH_FRAMES)
        starts = [starts[int(k * step)] for k in range(MAX_PITCH_FRAMES)]
    f0s = []
    for s in starts:
        f0 = _frame_pitch(dsamp, s, pwin, prate, window)
        if f0:
            f0s.append(f0)
    if f0s:
        f0_mean = _median(f0s)
        f0_std = _stdev(f0s)
        sf = sorted(f0s)
        f0_range = _percentile(sf, 0.90) - _percentile(sf, 0.10)
        pitch_var = f0_std / (f0_mean + 1e-9)
    else:
        f0_mean = f0_std = f0_range = pitch_var = 0.0

    return {"dur_s": dur_s, "voiced_ratio": voiced_ratio,
            "voiced_secs": len(voiced) * HOP_MS / 1000.0,
            "energy_mean": energy_mean, "energy_dyn": energy_dyn,
            "f0_mean": f0_mean, "f0_std": f0_std, "f0_range": f0_range,
            "pitch_var": pitch_var, "n_voiced": len(voiced)}


# ---- per-speaker baseline + classification ----------------------------------
def _sig(x):
    """logistic squash to 0..1, centred at 0."""
    if x < -30:
        return 0.0
    if x > 30:
        return 1.0
    return 1.0 / (1.0 + math.exp(-x))


class SpeakerBaseline:
    """
    Rolling per-speaker reference. classify() scores an utterance's arousal
    relative to this speaker's own calm baseline, then update() folds it in.
    Keeps the last BASELINE_KEEP utterances' energy / pitch / rate.
    """

    def __init__(self):
        self._energy = []
        self._f0 = []
        self._rate = []

    def _base(self, lst):
        return _median(lst) if lst else 0.0

    def has_baseline(self):
        return len(self._energy) >= 2

    def update(self, feats, speech_rate=0.0):
        if feats.get("energy_mean", 0) > 0:
            self._energy.append(feats["energy_mean"])
            self._energy = self._energy[-BASELINE_KEEP:]
        if feats.get("f0_mean", 0) > 0:
            self._f0.append(feats["f0_mean"])
            self._f0 = self._f0[-BASELINE_KEEP:]
        if speech_rate > 0:
            self._rate.append(speech_rate)
            self._rate = self._rate[-BASELINE_KEEP:]

    def classify(self, feats, text="", char_count=None):
        """
        Return (tone, arousal, detail).
          tone     one of the TONE_* enum strings
          arousal  0.0 (calm) .. 1.0 (highly agitated)
          detail   dict of the relative signals (for logging/debug)
        Fully relative to this speaker's baseline; before a baseline exists
        (<2 prior utterances) it falls back to absolute shape cues at low
        confidence and will not fire high-arousal labels.
        """
        dur = max(0.1, feats.get("dur_s", 0.0))
        e = feats.get("energy_mean", 0.0)
        f0 = feats.get("f0_mean", 0.0)
        pvar = feats.get("pitch_var", 0.0)
        dyn = feats.get("energy_dyn", 0.0)
        n = char_count if char_count is not None else len(
            [c for c in text if not c.isspace()])
        # rate over *voiced* time (buffer includes surrounding silence)
        vsecs = feats.get("voiced_secs", 0.0) or dur
        speech_rate = n / vsecs if vsecs > 0 else 0.0

        be = self._base(self._energy)
        bf = self._base(self._f0)
        br = self._base(self._rate)
        have = self.has_baseline()

        # relative ratios (1.0 == on par with own baseline)
        e_ratio = (e / be) if be > 0 else 1.0
        f_ratio = (f0 / bf) if bf > 0 else 1.0
        r_ratio = (speech_rate / br) if br > 0 else 1.0

        detail = {"e_ratio": round(e_ratio, 2), "f_ratio": round(f_ratio, 2),
                  "r_ratio": round(r_ratio, 2), "pitch_var": round(pvar, 3),
                  "dyn": round(dyn, 2), "rate": round(speech_rate, 1),
                  "have_base": have}

        if e <= 0 or feats.get("voiced_ratio", 0) < 0.05:
            return TONE_CALM, 0.0, detail  # essentially silence

        if have:
            # elevation above the speaker's own calm; each term ~0 at baseline
            z = (3.2 * (e_ratio - 1.0)          # louder than usual
                 + 3.0 * (f_ratio - 1.0)        # higher pitched than usual
                 + 1.6 * (r_ratio - 1.0)        # faster than usual
                 + 2.4 * (pvar - 0.06)          # pitch instability / tremor
                 + 0.8 * (dyn - 0.9))           # punchy loudness swings
            arousal = _sig(z - 0.4)
        else:
            # no baseline yet: judge only intrinsic agitation cues, damped
            z = 2.6 * (pvar - 0.08) + 0.7 * (dyn - 1.0)
            arousal = min(0.55, _sig(z - 0.6))  # cannot claim high arousal blind

        # map arousal (+ shape) to a tone label
        if arousal >= 0.66:
            tone = TONE_DISTRESSED if pvar >= 0.14 else TONE_AGITATED
        elif arousal >= 0.42:
            tone = TONE_TENSE
        elif arousal <= 0.22 and e_ratio < 0.85 and r_ratio < 0.92:
            tone = TONE_SUBDUED  # quieter + slower than own norm = flat/withdrawn
        else:
            tone = TONE_CALM
        return tone, round(arousal, 3), detail


# ---- call-level aggregation -------------------------------------------------
_TONE_RANK = {TONE_SUBDUED: 0, TONE_CALM: 1, TONE_TENSE: 2,
              TONE_AGITATED: 3, TONE_DISTRESSED: 4}
_TONE_BN = {TONE_CALM: "শান্ত", TONE_TENSE: "টানটান", TONE_AGITATED: "উত্তেজিত",
            TONE_DISTRESSED: "বিচলিত", TONE_SUBDUED: "নিস্তেজ"}


def aggregate(seq):
    """
    Fold a time-ordered list of (tone, arousal) for one speaker into a
    call-level voice read.
    Returns dict: dominant tone, mean arousal, peak arousal, and trend
    (rising|falling|steady) comparing the first third vs the last third.
    """
    seq = [(t, a) for (t, a) in seq if t]
    if not seq:
        return {"voice_emotion": None, "voice_arousal": None,
                "voice_trend": None, "voice_emotion_bn": None}
    ars = [a for _, a in seq]
    mean_a = sum(ars) / len(ars)
    peak_a = max(ars)
    # dominant = tone carrying the most arousal "mass", so sustained high-arousal
    # stretches surface over a merely long calm stretch
    mass = {}
    for t, a in seq:
        mass[t] = mass.get(t, 0.0) + a
    dominant = max(mass, key=lambda k: (mass[k], _TONE_RANK[k]))
    # headline a genuine escalation: if the caller clearly reached agitation
    # (>=2 hot utterances, or one very strong peak), surface that rather than
    # letting a long calm opening average it away. A lone mild blip won't flip it.
    hot = [a for _, a in seq if a >= 0.60]
    if len(hot) >= 2 or peak_a >= 0.80:
        peak_tone = max(seq, key=lambda x: x[1])[0]
        if _TONE_RANK[peak_tone] > _TONE_RANK[dominant]:
            dominant = peak_tone
    trend = "steady"
    if len(ars) >= 3:
        third = max(1, len(ars) // 3)
        first = sum(ars[:third]) / third
        last = sum(ars[-third:]) / third
        if last - first >= 0.15:
            trend = "rising"
        elif first - last >= 0.15:
            trend = "falling"
    return {"voice_emotion": dominant, "voice_arousal": round(mean_a, 3),
            "voice_peak": round(peak_a, 3), "voice_trend": trend,
            "voice_emotion_bn": _TONE_BN.get(dominant)}


def bn_label(tone):
    return _TONE_BN.get(tone, tone)
