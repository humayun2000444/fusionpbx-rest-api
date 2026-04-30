#!/usr/bin/php
<?php
/**
 * Migration: Add predictive dialer columns to v_call_broadcasts
 * Run once: php /var/www/fusionpbx/app/rest_api/actions/call-broadcast-migrate-predictive.php
 */

$document_root = '/var/www/fusionpbx';
require_once $document_root . '/resources/require.php';
require_once $document_root . '/resources/classes/database.php';

$database = new database;

$migrations = array(
    // Pacing mode: 'power' (fire all at once) or 'predictive' (agent-based pacing)
    "ALTER TABLE v_call_broadcasts ADD COLUMN IF NOT EXISTS broadcast_pacing_mode VARCHAR(20) DEFAULT 'power'",

    // Starting dial ratio (calls per available agent) - e.g., 1.5 means dial 1.5 calls per idle agent
    "ALTER TABLE v_call_broadcasts ADD COLUMN IF NOT EXISTS broadcast_dial_ratio NUMERIC(4,2) DEFAULT 1.50",

    // Maximum abandon rate percentage before reducing dial ratio
    "ALTER TABLE v_call_broadcasts ADD COLUMN IF NOT EXISTS broadcast_max_abandon_rate NUMERIC(5,2) DEFAULT 3.00",

    // Current live dial ratio (adjusted dynamically by the dialer engine)
    "ALTER TABLE v_call_broadcasts ADD COLUMN IF NOT EXISTS broadcast_current_dial_ratio NUMERIC(4,2) DEFAULT 1.50",

    // Stats tracked by dialer engine
    "ALTER TABLE v_call_broadcasts ADD COLUMN IF NOT EXISTS broadcast_total_answered INTEGER DEFAULT 0",
    "ALTER TABLE v_call_broadcasts ADD COLUMN IF NOT EXISTS broadcast_total_abandoned INTEGER DEFAULT 0",
    "ALTER TABLE v_call_broadcasts ADD COLUMN IF NOT EXISTS broadcast_avg_talk_time INTEGER DEFAULT 0",

    // Lead-level: track if call was abandoned (answered but no agent available)
    "ALTER TABLE v_call_broadcast_leads ADD COLUMN IF NOT EXISTS abandoned BOOLEAN DEFAULT false",
);

echo "Running predictive dialer migration...\n";

foreach ($migrations as $sql) {
    echo "  Running: " . substr($sql, 0, 80) . "...\n";
    try {
        $result = $database->execute($sql, array());
        if ($result === false) {
            echo "  WARNING: May have failed (column might already exist)\n";
        } else {
            echo "  OK\n";
        }
    } catch (Exception $e) {
        echo "  ERROR: " . $e->getMessage() . "\n";
    }
}

echo "\nMigration complete.\n";
