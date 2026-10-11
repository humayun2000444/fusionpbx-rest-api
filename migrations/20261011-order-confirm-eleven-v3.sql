-- Smart IVR (order-confirm): eleven_v3 everywhere, for Bangla and English.
--
-- oc_generate_tts() already sends model_id eleven_v3 for every domain; the
-- stored tts_elevenlabs_model was only shown in settings, and still said
-- eleven_multilingual_v2 on some domains (and as the column default), which
-- read as though English calls used another model. Stored value and default
-- now match what is spoken. Idempotent.
ALTER TABLE v_order_confirm_config
    ALTER COLUMN tts_elevenlabs_model SET DEFAULT 'eleven_v3';
UPDATE v_order_confirm_config
   SET tts_elevenlabs_model = 'eleven_v3', update_date = NOW()
 WHERE tts_elevenlabs_model IS DISTINCT FROM 'eleven_v3';
