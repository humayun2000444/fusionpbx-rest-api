<?php
/*
 * Smart IVR - Save Feedback
 * Saves DTMF or voice feedback from IVR calls
 */

// Includes
require_once __DIR__ . '/../resources/require.php';
require_once __DIR__ . '/../resources/check_auth.php';

// Get input
$data = json_decode(file_get_contents('php://input'), true);
$domain_uuid = $data['domain_uuid'] ?? $_SESSION['domain_uuid'] ?? null;
$log_uuid = $data['log_uuid'] ?? null;
$queue_uuid = $data['queue_uuid'] ?? null;
$student_id = $data['student_id'] ?? null;
$feedback_type = $data['feedback_type'] ?? 'dtmf';
$feedback_value = $data['feedback_value'] ?? null;
$question = $data['question'] ?? null;

if (empty($domain_uuid) || empty($feedback_value)) {
    echo json_encode(['error' => 'domain_uuid and feedback_value are required']);
    exit;
}

$database = new database;

// Save feedback
$feedback_uuid = uuid();
$sql = "INSERT INTO v_smart_ivr_feedback (
    feedback_uuid,
    log_uuid,
    domain_uuid,
    student_id,
    feedback_type,
    feedback_value,
    question,
    insert_date
) VALUES (
    :feedback_uuid,
    :log_uuid,
    :domain_uuid,
    :student_id,
    :feedback_type,
    :feedback_value,
    :question,
    NOW()
)";

$params = [
    ':feedback_uuid' => $feedback_uuid,
    ':log_uuid' => $log_uuid,
    ':domain_uuid' => $domain_uuid,
    ':student_id' => $student_id,
    ':feedback_type' => $feedback_type,
    ':feedback_value' => $feedback_value,
    ':question' => $question
];

$result = $database->execute($sql, $params);

// Update call log if log_uuid provided
if ($log_uuid) {
    $sql = "UPDATE v_smart_ivr_call_logs SET feedback = :feedback WHERE log_uuid = :log_uuid";
    $database->execute($sql, [':feedback' => $feedback_value, ':log_uuid' => $log_uuid]);
}

// Update queue if queue_uuid provided
if ($queue_uuid) {
    $sql = "UPDATE v_smart_ivr_queue SET feedback = :feedback, status = 'answered' WHERE queue_uuid = :queue_uuid";
    $database->execute($sql, [':feedback' => $feedback_value, ':queue_uuid' => $queue_uuid]);
}

if ($result) {
    echo json_encode([
        'success' => true,
        'message' => 'Feedback saved successfully',
        'feedback_uuid' => $feedback_uuid
    ]);
} else {
    echo json_encode([
        'success' => false,
        'error' => 'Failed to save feedback'
    ]);
}
