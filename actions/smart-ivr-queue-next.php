<?php
/*
 * Smart IVR - Get Next Queue Item
 * Returns the next pending outbound call from queue
 */

// Includes
require_once __DIR__ . '/../resources/require.php';
require_once __DIR__ . '/../resources/check_auth.php';

// Get input
$data = json_decode(file_get_contents('php://input'), true);
$domain_uuid = $data['domain_uuid'] ?? $_SESSION['domain_uuid'] ?? null;
$campaign_uuid = $data['campaign_uuid'] ?? null;

if (empty($domain_uuid)) {
    echo json_encode(['error' => 'domain_uuid is required']);
    exit;
}

$database = new database;

// Build WHERE clause
$where = "domain_uuid = :domain_uuid AND status = 'pending' AND attempts < max_attempts";
$params = [':domain_uuid' => $domain_uuid];

if ($campaign_uuid) {
    $where .= " AND campaign_uuid = :campaign_uuid";
    $params[':campaign_uuid'] = $campaign_uuid;
}

// Add scheduled time check
$where .= " AND (scheduled_time IS NULL OR scheduled_time <= NOW())";

// Get next item
$sql = "SELECT * FROM v_smart_ivr_queue WHERE $where ORDER BY scheduled_time ASC, insert_date ASC LIMIT 1";
$queue_item = $database->select($sql, $params, 'row');

if (!$queue_item) {
    echo json_encode([
        'success' => false,
        'message' => 'No pending calls in queue'
    ]);
    exit;
}

// Mark as calling
$queue_uuid = $queue_item['queue_uuid'];
$sql = "UPDATE v_smart_ivr_queue
        SET status = 'calling',
            attempts = attempts + 1,
            called_time = NOW()
        WHERE queue_uuid = :queue_uuid";
$database->execute($sql, [':queue_uuid' => $queue_uuid]);

// Get campaign details
$campaign = null;
if ($queue_item['campaign_uuid']) {
    $sql = "SELECT * FROM v_smart_ivr_campaigns WHERE campaign_uuid = :campaign_uuid LIMIT 1";
    $campaign = $database->select($sql, [':campaign_uuid' => $queue_item['campaign_uuid']], 'row');
}

echo json_encode([
    'success' => true,
    'queue_item' => $queue_item,
    'campaign' => $campaign
]);
