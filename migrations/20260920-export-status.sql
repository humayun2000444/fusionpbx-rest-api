-- Recording export / retention support tables.
-- Safe to re-run. No rewrite of v_xml_cdr: every ALTER here is catalogue-only
-- (PG 11+ adds a nullable column with no default without touching the heap),
-- which matters on a 9.9M-row table on a box already under pressure.

-- 1. Per-domain nightly summary. -------------------------------------------
-- The portal must NEVER count against v_xml_cdr on page load: that is 9.9M rows
-- on a database that ran out of connections on 2026-09-20. Precompute, read one
-- row.
CREATE TABLE IF NOT EXISTS v_recording_export_status (
    domain_uuid     uuid PRIMARY KEY,
    computed_at     timestamptz NOT NULL DEFAULT NOW(),
    retention_days  integer     NOT NULL DEFAULT 120,
    purge_date      date,          -- next month-end purge
    cutoff_date     date,          -- purge_date - retention_days
    at_risk_count   bigint NOT NULL DEFAULT 0,   -- older than cutoff
    at_risk_bytes   bigint NOT NULL DEFAULT 0,
    at_risk_oldest  date,
    pending_count   bigint NOT NULL DEFAULT 0,   -- at risk AND never downloaded
    pending_bytes   bigint NOT NULL DEFAULT 0,
    pending_oldest  date,
    total_count     bigint NOT NULL DEFAULT 0,
    total_bytes     bigint NOT NULL DEFAULT 0
);

-- 2. SFTP pickup account per domain. ---------------------------------------
CREATE TABLE IF NOT EXISTS v_recording_sftp (
    domain_uuid           uuid PRIMARY KEY,
    username              text,
    host                  text,
    port                  integer DEFAULT 22,
    chroot_path           text DEFAULT '/recordings',
    host_key_fingerprint  text,
    enabled               boolean NOT NULL DEFAULT false,
    public_key_installed  boolean NOT NULL DEFAULT false,
    public_key_fingerprint text,
    last_login_at         timestamptz,
    last_download_at      timestamptz,
    updated_at            timestamptz NOT NULL DEFAULT NOW()
);

-- 3. SOP acknowledgement. ---------------------------------------------------
-- You are deleting customer data on a schedule. The day someone says "nobody
-- told us", this is the answer. Cheap to keep, valuable exactly once.
CREATE TABLE IF NOT EXISTS v_recording_sop_ack (
    domain_uuid      uuid NOT NULL,
    sop_version      integer NOT NULL,
    acknowledged_at  timestamptz NOT NULL DEFAULT NOW(),
    acknowledged_by  text,
    PRIMARY KEY (domain_uuid, sop_version)
);

-- 4. Index supporting the nightly rollup and the purge safety gate. ---------
-- Partial: only rows that actually have a recording, which is a small slice of
-- v_xml_cdr. CREATE INDEX CONCURRENTLY cannot run inside a transaction, so this
-- statement must be sent ON ITS OWN -- `psql -c` with several statements wraps
-- them in one and it will fail instantly.
--
--   PGOPTIONS='-c maintenance_work_mem=512MB' psql -c "<just this line>"
--
CREATE INDEX CONCURRENTLY IF NOT EXISTS v_xml_cdr_recording_retention_idx
    ON v_xml_cdr (domain_uuid, start_stamp)
    WHERE record_name IS NOT NULL AND record_name <> '';
