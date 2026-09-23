-- Per-domain CDR retention status, mirroring v_recording_export_status.
--
-- Customers are told the same story for call records as for recordings: kept
-- for at least N days, warned before removal, and nothing uncollected is ever
-- deleted (v_xml_cdr.exported_at is the interlock, set when a customer
-- actually downloads).
--
-- Deliberately a rollup table rather than counting live: v_xml_cdr is 14M rows
-- on BTCL, and the notice job walks every domain. Counting per domain per send
-- would be a full scan each time.
CREATE TABLE IF NOT EXISTS v_cdr_export_status (
    domain_uuid     uuid PRIMARY KEY,
    computed_at     timestamptz NOT NULL DEFAULT NOW(),
    retention_days  integer     NOT NULL DEFAULT 95,   -- what the purge uses
    display_days    integer     NOT NULL DEFAULT 90,   -- what customers are told
    purge_date      date,           -- next month-end purge
    cutoff_date     date,           -- purge_date - retention_days
    at_risk_count   bigint NOT NULL DEFAULT 0,   -- older than cutoff
    at_risk_oldest  date,
    pending_count   bigint NOT NULL DEFAULT 0,   -- at risk AND never exported
    pending_oldest  date,
    total_count     bigint NOT NULL DEFAULT 0
);

COMMENT ON TABLE v_cdr_export_status IS
    'Nightly rollup of CDR retention per domain. pending_count is what the purge would refuse to delete.';
COMMENT ON COLUMN v_cdr_export_status.pending_count IS
    'Older than cutoff AND exported_at IS NULL -- the customer has never taken a copy.';

-- One notice per domain per purge cycle per milestone, so a re-run cannot
-- email the same customer twice about the same deletion.
CREATE TABLE IF NOT EXISTS v_cdr_notice_log (
    domain_uuid  uuid        NOT NULL,
    purge_date   date        NOT NULL,
    milestone    integer     NOT NULL,   -- days before purge this notice was for
    sent_at      timestamptz NOT NULL DEFAULT NOW(),
    recipients   text,
    PRIMARY KEY (domain_uuid, purge_date, milestone)
);

-- The purge and the rollup both filter on this shape.
CREATE INDEX IF NOT EXISTS v_xml_cdr_retention_idx
    ON v_xml_cdr (domain_uuid, start_stamp)
    WHERE exported_at IS NULL;
