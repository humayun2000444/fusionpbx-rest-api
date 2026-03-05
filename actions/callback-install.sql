-- ============================================
-- FusionPBX Callback System - Auto Install
-- Creates tables if they don't exist
-- ============================================

-- Callback Configuration Table
CREATE TABLE IF NOT EXISTS v_callback_configs (
    callback_config_uuid UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    domain_uuid UUID NOT NULL,
    queue_uuid UUID,  -- NULL means domain-wide default
    config_name VARCHAR(100) NOT NULL,
    enabled BOOLEAN DEFAULT TRUE,

    -- Trigger Settings
    trigger_on_timeout BOOLEAN DEFAULT TRUE,
    trigger_on_abandoned BOOLEAN DEFAULT TRUE,
    trigger_on_no_answer BOOLEAN DEFAULT FALSE,
    trigger_on_busy BOOLEAN DEFAULT FALSE,
    trigger_after_hours BOOLEAN DEFAULT FALSE,

    -- Retry Settings
    max_attempts INTEGER DEFAULT 3,
    retry_interval INTEGER DEFAULT 300,  -- seconds between attempts

    -- Callback Timing
    immediate_callback BOOLEAN DEFAULT FALSE,
    wait_for_agent BOOLEAN DEFAULT TRUE,

    -- Schedule (stored as JSON for flexibility)
    schedules JSONB DEFAULT '[]'::jsonb,
    -- Example: [{"days": [1,2,3,4,5], "start_time": "09:00", "end_time": "18:00"}]

    -- Customer Experience
    play_announcement BOOLEAN DEFAULT TRUE,
    announcement_text TEXT DEFAULT 'Thank you for calling. We are connecting you to an agent.',

    -- Priority
    default_priority INTEGER DEFAULT 5,

    -- Limits
    max_callbacks_per_hour INTEGER DEFAULT 100,
    max_callbacks_per_day INTEGER DEFAULT 500,

    -- Audit
    insert_date TIMESTAMP DEFAULT NOW(),
    insert_user UUID,
    update_date TIMESTAMP,
    update_user UUID
);

-- Callback Queue Table
CREATE TABLE IF NOT EXISTS v_callback_queue (
    callback_uuid UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    domain_uuid UUID NOT NULL,
    callback_config_uuid UUID,

    -- Caller Information
    caller_id_name VARCHAR(150),
    caller_id_number VARCHAR(50) NOT NULL,
    destination_number VARCHAR(50) NOT NULL,

    -- Queue Information
    queue_uuid UUID,
    queue_name VARCHAR(100),

    -- Original Call Information
    original_call_uuid UUID,
    original_call_time TIMESTAMP,
    hangup_cause VARCHAR(50),

    -- Callback Status
    status VARCHAR(20) DEFAULT 'pending',  -- pending, calling, completed, failed, cancelled
    priority INTEGER DEFAULT 5,

    -- Attempt Tracking
    attempts INTEGER DEFAULT 0,
    max_attempts INTEGER DEFAULT 3,
    last_attempt_time TIMESTAMP,
    next_attempt_time TIMESTAMP,

    -- Schedule
    scheduled_time TIMESTAMP,

    -- Result
    callback_call_uuid UUID,
    callback_start_time TIMESTAMP,
    callback_answer_time TIMESTAMP,
    callback_end_time TIMESTAMP,
    callback_result VARCHAR(50),  -- answered, no_answer, busy, failed

    -- Notes
    notes TEXT,

    -- Audit
    created_date TIMESTAMP DEFAULT NOW(),
    created_by VARCHAR(50) DEFAULT 'system',
    updated_date TIMESTAMP,
    completed_date TIMESTAMP
);

-- Create indexes if they don't exist
DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_indexes WHERE indexname = 'idx_callback_queue_status') THEN
        CREATE INDEX idx_callback_queue_status ON v_callback_queue(status, next_attempt_time);
    END IF;

    IF NOT EXISTS (SELECT 1 FROM pg_indexes WHERE indexname = 'idx_callback_queue_domain') THEN
        CREATE INDEX idx_callback_queue_domain ON v_callback_queue(domain_uuid, status);
    END IF;

    IF NOT EXISTS (SELECT 1 FROM pg_indexes WHERE indexname = 'idx_callback_queue_caller') THEN
        CREATE INDEX idx_callback_queue_caller ON v_callback_queue(caller_id_number, created_date);
    END IF;

    IF NOT EXISTS (SELECT 1 FROM pg_indexes WHERE indexname = 'idx_callback_configs_domain') THEN
        CREATE INDEX idx_callback_configs_domain ON v_callback_configs(domain_uuid, enabled);
    END IF;
END $$;

-- Create view for active callbacks
CREATE OR REPLACE VIEW v_callback_queue_active AS
SELECT * FROM v_callback_queue
WHERE status IN ('pending', 'calling')
ORDER BY priority DESC, next_attempt_time ASC;
