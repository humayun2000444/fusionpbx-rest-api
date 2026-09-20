-- Retention notice support. Safe to re-run.

-- Where to send a domain's retention notices.
-- FusionPBX's v_users is NOT a usable source: only 10 rows across 52 domains on
-- BTCL, because portal users live in RTC's MySQL (auth_user), not here. So the
-- recipient is configured per domain rather than derived, and the job reports
-- domains that have none instead of silently skipping them.
ALTER TABLE v_recording_sftp ADD COLUMN IF NOT EXISTS notify_email text;

-- One row per notice actually sent. Without this a daily cron re-sends the same
-- warning every day, which is how a customer learns to filter your mail.
CREATE TABLE IF NOT EXISTS v_recording_notice_log (
    domain_uuid   uuid        NOT NULL,
    purge_date    date        NOT NULL,
    milestone     integer     NOT NULL,   -- 30 / 14 / 7 / 1 days before purge
    sent_at       timestamptz NOT NULL DEFAULT NOW(),
    recipients    text,
    pending_count bigint,
    PRIMARY KEY (domain_uuid, purge_date, milestone)
);
