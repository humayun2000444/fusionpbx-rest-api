-- ============================================================
-- Order Confirmation Call system - Auto Install
-- Outbound IVR that calls e-commerce customers after an order,
-- captures a DTMF choice, POSTs it to the merchant's callback URL,
-- and optionally transfers to customer support.
-- Idempotent: safe to run multiple times.
-- ============================================================

-- ---------- Per-domain configuration ----------
CREATE TABLE IF NOT EXISTS v_order_confirm_config (
    config_uuid          UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    domain_uuid          UUID NOT NULL,
    enabled              BOOLEAN DEFAULT TRUE,

    -- Voice / language
    default_language     VARCHAR(10)  DEFAULT 'en',        -- 'en' | 'bn'
    voice_gender         VARCHAR(10)  DEFAULT 'FEMALE',    -- MALE | FEMALE | NEUTRAL

    -- Message templates (placeholders: {name} {order_id}); one per language.
    message_template_en  TEXT DEFAULT 'Dear customer {name}, your order number is {order_id}. To confirm press 1, to cancel press 2, to talk to customer service press 0.',
    message_template_bn  TEXT DEFAULT 'প্রিয় গ্রাহক {name}, আপনার অর্ডার নম্বর {order_id}। নিশ্চিত করতে ১ চাপুন, বাতিল করতে ২ চাপুন, কাস্টমার সার্ভিসের সাথে কথা বলতে ০ চাপুন।',
    confirm_text_en      TEXT DEFAULT 'Thank you. Your order has been confirmed.',
    confirm_text_bn      TEXT DEFAULT 'ধন্যবাদ। আপনার অর্ডার নিশ্চিত করা হয়েছে।',
    cancel_text_en       TEXT DEFAULT 'Your order has been cancelled.',
    cancel_text_bn       TEXT DEFAULT 'আপনার অর্ডার বাতিল করা হয়েছে।',

    -- Outbound call settings
    caller_id_name       VARCHAR(150) DEFAULT 'Order Confirmation',
    caller_id_number     VARCHAR(50)  DEFAULT '',
    default_support_number VARCHAR(50) DEFAULT '',
    call_timeout         INTEGER DEFAULT 40,               -- seconds to ring
    amd_enabled          BOOLEAN DEFAULT TRUE,             -- skip answering machines

    -- Industry-neutral display labels (dashboard shows these instead of the
    -- hardcoded Order/Customer terms; e.g. Appointment/Patient, Invoice/Recipient)
    reference_label      VARCHAR(40) DEFAULT 'Order ID',
    recipient_label      VARCHAR(40) DEFAULT 'Customer',
    entity_label         VARCHAR(40) DEFAULT 'Order',

    -- Merchant callback (per-domain default; can be overridden per request)
    default_confirm_url  TEXT DEFAULT '',
    callback_auth_type   VARCHAR(10) DEFAULT 'none',       -- none | bearer | basic | hmac
    callback_auth_token  TEXT DEFAULT '',                  -- bearer token OR "user:pass" for basic
    callback_hmac_secret TEXT DEFAULT '',                  -- shared secret for hmac (sha256)
    callback_hmac_header VARCHAR(80) DEFAULT 'X-Signature',
    callback_timeout     INTEGER DEFAULT 15,

    -- Retry policy (for no-answer / busy / failed originate)
    retry_enabled        BOOLEAN DEFAULT TRUE,
    retry_max            INTEGER DEFAULT 3,
    retry_interval       INTEGER DEFAULT 300,              -- seconds between attempts
    retry_on_no_answer   BOOLEAN DEFAULT TRUE,
    retry_on_busy        BOOLEAN DEFAULT TRUE,
    retry_on_voicemail   BOOLEAN DEFAULT TRUE,
    retry_on_failed      BOOLEAN DEFAULT TRUE,
    -- callback delivery retries (if merchant endpoint is down)
    callback_retry_max   INTEGER DEFAULT 5,
    callback_retry_interval INTEGER DEFAULT 60,

    insert_date          TIMESTAMP DEFAULT NOW(),
    update_date          TIMESTAMP,
    UNIQUE (domain_uuid)
);

-- ---------- Per-call job / log ----------
CREATE TABLE IF NOT EXISTS v_order_confirm_calls (
    call_uuid            UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    domain_uuid          UUID NOT NULL,

    -- order context (from the webhook)
    order_id             VARCHAR(120) NOT NULL,
    customer_name        VARCHAR(150) DEFAULT '',
    phone                VARCHAR(50)  NOT NULL,
    language             VARCHAR(10)  DEFAULT 'en',

    -- per-request overrides
    confirm_url          TEXT DEFAULT '',
    support_number       VARCHAR(50) DEFAULT '',
    metadata             JSONB DEFAULT '{}'::jsonb,        -- passed through to callback

    -- lifecycle
    status               VARCHAR(20) DEFAULT 'pending',
        -- pending | calling | answered | confirmed | cancelled
        -- | transferred | no_answer | busy | voicemail | failed | done
    dtmf_pressed         VARCHAR(4)  DEFAULT NULL,          -- '1' | '2' | '0'
    disposition          VARCHAR(40) DEFAULT NULL,
    hangup_cause         VARCHAR(40) DEFAULT NULL,

    -- voice-SMS sizing: chars actually spoken this call + 230-char billing units
    char_count           INTEGER DEFAULT 0,
    voice_units          INTEGER DEFAULT 0,

    -- attempts / retry
    attempts             INTEGER DEFAULT 0,
    max_attempts         INTEGER DEFAULT 3,
    next_attempt_date    TIMESTAMP DEFAULT NOW(),

    -- merchant callback tracking
    callback_pending     BOOLEAN DEFAULT FALSE,
    callback_attempts    INTEGER DEFAULT 0,
    callback_status      VARCHAR(20) DEFAULT NULL,          -- ok | failed | http_4xx | http_5xx
    callback_http_code   INTEGER DEFAULT NULL,
    callback_response    TEXT DEFAULT NULL,
    callback_date        TIMESTAMP DEFAULT NULL,

    -- freeswitch linkage
    fs_call_uuid         VARCHAR(80) DEFAULT NULL,
    tts_spec             TEXT DEFAULT NULL,                 -- resolved playback string

    insert_date          TIMESTAMP DEFAULT NOW(),
    last_attempt_date    TIMESTAMP DEFAULT NULL,
    answered_date        TIMESTAMP DEFAULT NULL,
    complete_date        TIMESTAMP DEFAULT NULL
);

CREATE INDEX IF NOT EXISTS idx_oc_calls_domain   ON v_order_confirm_calls (domain_uuid);
CREATE INDEX IF NOT EXISTS idx_oc_calls_status   ON v_order_confirm_calls (status);
CREATE INDEX IF NOT EXISTS idx_oc_calls_next     ON v_order_confirm_calls (next_attempt_date);
CREATE INDEX IF NOT EXISTS idx_oc_calls_cb       ON v_order_confirm_calls (callback_pending);
CREATE INDEX IF NOT EXISTS idx_oc_calls_fsuuid   ON v_order_confirm_calls (fs_call_uuid);
CREATE INDEX IF NOT EXISTS idx_oc_calls_order    ON v_order_confirm_calls (order_id);
