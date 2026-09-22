-- Let a survey or confirmation play a recorded prompt instead of synthesising
-- one on every call.
--
-- Why: the IVR runs TTS only after a human answers, under a 15s ceiling. On a
-- cold cache that ceiling is reached and the caller hears silence -- which is
-- exactly what happened to CSAT-1790063984266 on 2026-09-22: answered 08:06:05,
-- "tts-cli popen failed" 08:06:22, no audio, hangup. A recorded prompt removes
-- the provider call from the answered-call path entirely, so there is nothing
-- to time out, nothing to bill, and the wording is whatever was approved.
--
-- TTS stays the default and the fallback: if a recording is selected but its
-- file is missing, the IVR synthesises as before rather than playing nothing.

ALTER TABLE v_order_confirm_config
    ADD COLUMN IF NOT EXISTS prompt_source         VARCHAR(10) DEFAULT 'tts',
    ADD COLUMN IF NOT EXISTS prompt_recording_uuid UUID;

COMMENT ON COLUMN v_order_confirm_config.prompt_source IS
    'tts | recording -- where the main prompt comes from. Anything other than "recording" means TTS.';
COMMENT ON COLUMN v_order_confirm_config.prompt_recording_uuid IS
    'v_recordings.recording_uuid used when prompt_source = recording.';

-- Per-call override, so one survey can use its own recording without changing
-- the domain default. NULL means "use the config".
ALTER TABLE v_order_confirm_calls
    ADD COLUMN IF NOT EXISTS prompt_recording_uuid UUID;

COMMENT ON COLUMN v_order_confirm_calls.prompt_recording_uuid IS
    'Overrides the config prompt for this call only. NULL = use the domain config.';
