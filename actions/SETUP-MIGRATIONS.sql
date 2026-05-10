-- =============================================================================
-- FusionPBX REST API - Database Migrations
-- Run these on any new server to set up required tables and columns
-- =============================================================================

-- 1. Boss-Secretary table
CREATE TABLE IF NOT EXISTS v_boss_secretary (
    boss_secretary_uuid UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    domain_uuid UUID NOT NULL,
    boss_extension VARCHAR(32) NOT NULL,
    boss_name VARCHAR(128),
    secretary_extension VARCHAR(32) NOT NULL,
    secretary_name VARCHAR(128),
    mode VARCHAR(20) DEFAULT 'filter_all',
    vip_list TEXT,
    ring_timeout INTEGER DEFAULT 20,
    cid_prefix VARCHAR(32) DEFAULT 'Boss: ',
    enabled VARCHAR(8) DEFAULT 'true',
    dialplan_uuid UUID,
    insert_date TIMESTAMPTZ DEFAULT NOW(),
    update_date TIMESTAMPTZ
);

-- 2. Predictive Dialer - pacing columns on v_call_broadcasts
ALTER TABLE v_call_broadcasts ADD COLUMN IF NOT EXISTS broadcast_pacing_mode VARCHAR(20) DEFAULT 'power';
ALTER TABLE v_call_broadcasts ADD COLUMN IF NOT EXISTS broadcast_dial_ratio NUMERIC(4,2) DEFAULT 1.50;
ALTER TABLE v_call_broadcasts ADD COLUMN IF NOT EXISTS broadcast_max_abandon_rate NUMERIC(5,2) DEFAULT 3.00;
ALTER TABLE v_call_broadcasts ADD COLUMN IF NOT EXISTS broadcast_current_dial_ratio NUMERIC(4,2) DEFAULT 1.50;
ALTER TABLE v_call_broadcasts ADD COLUMN IF NOT EXISTS broadcast_total_answered INTEGER DEFAULT 0;
ALTER TABLE v_call_broadcasts ADD COLUMN IF NOT EXISTS broadcast_total_abandoned INTEGER DEFAULT 0;
ALTER TABLE v_call_broadcasts ADD COLUMN IF NOT EXISTS broadcast_avg_talk_time INTEGER DEFAULT 0;

-- 3. Predictive Dialer - call_uuid + abandoned on leads
ALTER TABLE v_call_broadcast_leads ADD COLUMN IF NOT EXISTS call_uuid UUID;
ALTER TABLE v_call_broadcast_leads ADD COLUMN IF NOT EXISTS abandoned BOOLEAN DEFAULT false;
