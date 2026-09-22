-- Let one call carry its own keypad options.
--
-- The IVR builds its valid-digit set from the DOMAIN config, so a CSAT survey
-- asking for 1-5 was offered only the order-confirmation digits (1=Confirm,
-- 2=cancle). Pressing 3, 4 or 5 did nothing and the call ended "no_input" --
-- see CSAT-1790065487007 on 2026-09-22. The customer hears "press 1 to 5" and
-- three of those five are silently ignored.
--
-- NULL means "use the domain config", so nothing changes for order confirmation.
ALTER TABLE v_order_confirm_calls
    ADD COLUMN IF NOT EXISTS dtmf_options JSONB;

COMMENT ON COLUMN v_order_confirm_calls.dtmf_options IS
    'Per-call keypad map, same shape as v_order_confirm_config.dtmf_options. NULL = use the domain config.';
