-- Live-caption + post-call summary schema (idempotent).
-- Run as the fusionpbx DB user:  psql -h 127.0.0.1 -U fusionpbx -d fusionpbx -f caption-schema.sql

CREATE TABLE IF NOT EXISTS v_caption_jobs (
    job_uuid     uuid PRIMARY KEY,
    call_uuid    uuid NOT NULL,
    domain_name  text,
    record_path  text NOT NULL,
    status       text NOT NULL DEFAULT 'active',
    byte_offset  bigint NOT NULL DEFAULT 0,
    seq          integer NOT NULL DEFAULT 0,
    created      timestamptz NOT NULL DEFAULT now(),
    updated      timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS idx_caption_jobs_call   ON v_caption_jobs (call_uuid);
CREATE INDEX IF NOT EXISTS idx_caption_jobs_status ON v_caption_jobs (status);

CREATE TABLE IF NOT EXISTS v_call_captions (
    caption_uuid     uuid PRIMARY KEY,
    call_uuid        uuid NOT NULL,
    seq              integer NOT NULL,
    speaker          smallint,
    caption_text     text,
    caption_language text,
    created          timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS idx_call_captions_call ON v_call_captions (call_uuid, seq);

CREATE TABLE IF NOT EXISTS v_call_summaries (
    summary_uuid  uuid PRIMARY KEY,
    call_uuid     uuid NOT NULL UNIQUE,
    job_uuid      uuid,
    transcript    text,
    summary       text,
    summary_model text,
    sentiment     text,
    caller_mood   text,
    situation     text,
    created       timestamptz DEFAULT now(),
    updated       timestamptz DEFAULT now()
);

-- Voice-emotion (prosody) columns, added idempotently so upgrades are safe.
-- Per-utterance read straight from the audio (loudness/pitch/pace):
ALTER TABLE v_call_captions  ADD COLUMN IF NOT EXISTS voice_tone     text;   -- calm|tense|agitated|distressed|subdued
ALTER TABLE v_call_captions  ADD COLUMN IF NOT EXISTS voice_arousal  real;   -- 0.0 (calm) .. 1.0 (agitated)
-- Call-level fold of the above (dominant tone / mean arousal / rising|falling|steady):
ALTER TABLE v_call_summaries ADD COLUMN IF NOT EXISTS voice_emotion  text;
ALTER TABLE v_call_summaries ADD COLUMN IF NOT EXISTS voice_arousal  real;
ALTER TABLE v_call_summaries ADD COLUMN IF NOT EXISTS voice_trend    text;
