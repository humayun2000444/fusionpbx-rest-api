-- Smart IVR (order-confirm): the ElevenLabs model and language columns.
--
-- order-confirm-config-update.php writes ttsElevenlabsModel / ttsElevenlabsLanguage
-- to these columns, and config-get reads them back. They were added by hand on
-- the CCL PBX and never written down here, so the BTCL PBX (114.130.145.82) did
-- not have them: every Smart IVR save from the CRM failed with
--   column "tts_elevenlabs_model" of relation "v_order_confirm_config" does not exist
-- surfacing to the user as "Failed to save config" (2026-10-11).
--
-- Same types and defaults as CCL's. Additive and idempotent; on PostgreSQL 11+
-- a column with a constant default is added without rewriting the table.
ALTER TABLE v_order_confirm_config
    ADD COLUMN IF NOT EXISTS tts_elevenlabs_model    varchar(40) DEFAULT 'eleven_multilingual_v2',
    ADD COLUMN IF NOT EXISTS tts_elevenlabs_language varchar(10) DEFAULT '';
