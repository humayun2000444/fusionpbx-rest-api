<?php
/*
 * Smart IVR - Create Outbound Campaign
 * Creates a new outbound calling campaign
 */

// Includes
require_once __DIR__ . '/../resources/require.php';
require_once __DIR__ . '/../resources/check_auth.php';

// Check permissions
if (!permission_exists('smart_ivr_edit')) {
    echo json_encode(['error' => 'Permission denied']);
    exit;
}

// Get input
$data = json_decode(file_get_contents('php://input'), true);
$domain_uuid = $data['domain_uuid'] ?? $_SESSION['domain_uuid'] ?? null;
$campaign_name = $data['campaign_name'] ?? null;
$campaign_type = $data['campaign_type'] ?? null;
$message_template = $data['message_template'] ?? null;
$feedback_prompt = $data['feedback_prompt'] ?? 'Press 1 to confirm, 2 for more information, or hang up';
$tts_language = $data['tts_language'] ?? 'en-US';
$scheduled_time = $data['scheduled_time'] ?? null;

if (empty($domain_uuid) || empty($campaign_name) || empty($campaign_type) || empty($message_template)) {
    echo json_encode(['error' => 'domain_uuid, campaign_name, campaign_type, and message_template are required']);
    exit;
}

// Create campaign
$campaign_uuid = uuid();
$sql = "INSERT INTO v_smart_ivr_campaigns (
    campaign_uuid,
    domain_uuid,
    campaign_name,
    campaign_type,
    message_template,
    tts_language,
    require_feedback,
    feedback_prompt,
    scheduled_time,
    status,
    insert_date
) VALUES (
    :campaign_uuid,
    :domain_uuid,
    :campaign_name,
    :campaign_type,
    :message_template,
    :tts_language,
    TRUE,
    :feedback_prompt,
    :scheduled_time,
    'pending',
    NOW()
)";

$params = [
    ':campaign_uuid' => $campaign_uuid,
    ':domain_uuid' => $domain_uuid,
    ':campaign_name' => $campaign_name,
    ':campaign_type' => $campaign_type,
    ':message_template' => $message_template,
    ':tts_language' => $tts_language,
    ':feedback_prompt' => $feedback_prompt,
    ':scheduled_time' => $scheduled_time
];

$database = new database;
$result = $database->execute($sql, $params);

if ($result) {
    echo json_encode([
        'success' => true,
        'message' => 'Campaign created successfully',
        'campaign_uuid' => $campaign_uuid,
        'campaign_name' => $campaign_name,
        'status' => 'pending'
    ]);
} else {
    echo json_encode([
        'success' => false,
        'error' => 'Failed to create campaign'
    ]);
}
