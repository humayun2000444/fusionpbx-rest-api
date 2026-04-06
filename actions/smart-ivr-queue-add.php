<?php
/*
 * Smart IVR - Add to Outbound Queue
 * Adds students to outbound calling queue
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
$campaign_uuid = $data['campaign_uuid'] ?? null;
$students = $data['students'] ?? []; // Array of student objects

if (empty($domain_uuid) || empty($students) || !is_array($students)) {
    echo json_encode(['error' => 'domain_uuid and students array are required']);
    exit;
}

$database = new database;
$added_count = 0;
$failed_count = 0;
$queue_uuids = [];

foreach ($students as $student) {
    $student_id = $student['student_id'] ?? null;
    $phone_number = $student['phone'] ?? $student['phone_number'] ?? null;
    $student_name = $student['name'] ?? $student['student_name'] ?? null;
    $message = $student['message'] ?? null;
    $custom_data = $student['custom_data'] ?? null;
    $scheduled_time = $student['scheduled_time'] ?? null;

    if (empty($phone_number)) {
        $failed_count++;
        continue;
    }

    // Check if already in queue
    $sql = "SELECT queue_uuid FROM v_smart_ivr_queue
            WHERE domain_uuid = :domain_uuid
            AND phone_number = :phone_number
            AND campaign_uuid = :campaign_uuid
            AND status IN ('pending', 'calling')
            LIMIT 1";
    $params = [
        ':domain_uuid' => $domain_uuid,
        ':phone_number' => $phone_number,
        ':campaign_uuid' => $campaign_uuid
    ];
    $existing = $database->select($sql, $params, 'row');

    if ($existing) {
        $failed_count++;
        continue; // Already in queue
    }

    // Add to queue
    $queue_uuid = uuid();
    $sql = "INSERT INTO v_smart_ivr_queue (
        queue_uuid,
        campaign_uuid,
        domain_uuid,
        student_id,
        phone_number,
        student_name,
        message,
        custom_data,
        status,
        scheduled_time,
        insert_date
    ) VALUES (
        :queue_uuid,
        :campaign_uuid,
        :domain_uuid,
        :student_id,
        :phone_number,
        :student_name,
        :message,
        :custom_data,
        'pending',
        :scheduled_time,
        NOW()
    )";

    $params = [
        ':queue_uuid' => $queue_uuid,
        ':campaign_uuid' => $campaign_uuid,
        ':domain_uuid' => $domain_uuid,
        ':student_id' => $student_id,
        ':phone_number' => $phone_number,
        ':student_name' => $student_name,
        ':message' => $message,
        ':custom_data' => $custom_data ? json_encode($custom_data) : null,
        ':scheduled_time' => $scheduled_time
    ];

    $result = $database->execute($sql, $params);

    if ($result) {
        $added_count++;
        $queue_uuids[] = $queue_uuid;
    } else {
        $failed_count++;
    }
}

// Update campaign total_calls
if ($campaign_uuid && $added_count > 0) {
    $sql = "UPDATE v_smart_ivr_campaigns
            SET total_calls = total_calls + :count
            WHERE campaign_uuid = :campaign_uuid";
    $database->execute($sql, [':count' => $added_count, ':campaign_uuid' => $campaign_uuid]);
}

echo json_encode([
    'success' => true,
    'message' => 'Students added to queue',
    'added_count' => $added_count,
    'failed_count' => $failed_count,
    'queue_uuids' => $queue_uuids
]);
