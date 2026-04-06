<?php
/*
 * Smart IVR - Student Verification
 * Verifies student via backend API using student ID or phone number
 */

// Includes
require_once __DIR__ . '/../resources/require.php';
require_once __DIR__ . '/../resources/check_auth.php';

// Get input
$data = json_decode(file_get_contents('php://input'), true);
$domain_uuid = $data['domain_uuid'] ?? $_SESSION['domain_uuid'] ?? null;
$student_id = $data['student_id'] ?? null;
$phone_number = $data['phone_number'] ?? null;

if (empty($domain_uuid)) {
    echo json_encode(['error' => 'domain_uuid is required']);
    exit;
}

if (empty($student_id) && empty($phone_number)) {
    echo json_encode(['error' => 'student_id or phone_number is required']);
    exit;
}

// Get Smart IVR configuration
$sql = "SELECT backend_api_url, backend_api_key, enabled FROM v_smart_ivr_config WHERE domain_uuid = :domain_uuid LIMIT 1";
$params = [':domain_uuid' => $domain_uuid];
$database = new database;
$config = $database->select($sql, $params, 'row');

if (!$config || !$config['enabled']) {
    echo json_encode(['error' => 'Smart IVR is not enabled for this domain']);
    exit;
}

if (empty($config['backend_api_url'])) {
    echo json_encode(['error' => 'Backend API URL not configured']);
    exit;
}

// Call backend API to verify student
$api_url = rtrim($config['backend_api_url'], '/') . '/student/verify';
$api_data = [];
if ($student_id) {
    $api_data['student_id'] = $student_id;
}
if ($phone_number) {
    $api_data['phone'] = $phone_number;
}

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $api_url);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($api_data));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Authorization: Bearer ' . $config['backend_api_key']
]);

$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curl_error = curl_error($ch);
curl_close($ch);

if ($curl_error) {
    echo json_encode([
        'success' => false,
        'error' => 'API connection failed: ' . $curl_error
    ]);
    exit;
}

if ($http_code !== 200) {
    echo json_encode([
        'success' => false,
        'error' => 'API returned error code: ' . $http_code,
        'response' => $response
    ]);
    exit;
}

$result = json_decode($response, true);

if ($result && isset($result['verified']) && $result['verified']) {
    // Log successful verification
    $log_uuid = uuid();
    $sql = "INSERT INTO v_smart_ivr_call_logs (
        log_uuid,
        domain_uuid,
        call_direction,
        student_id,
        student_name,
        call_start_time,
        insert_date
    ) VALUES (
        :log_uuid,
        :domain_uuid,
        'inbound',
        :student_id,
        :student_name,
        NOW(),
        NOW()
    )";
    $params = [
        ':log_uuid' => $log_uuid,
        ':domain_uuid' => $domain_uuid,
        ':student_id' => $result['student_id'] ?? $student_id,
        ':student_name' => $result['name'] ?? $result['student_name'] ?? 'Unknown'
    ];
    $database->execute($sql, $params);

    echo json_encode([
        'success' => true,
        'verified' => true,
        'student_id' => $result['student_id'] ?? $student_id,
        'student_name' => $result['name'] ?? $result['student_name'] ?? null,
        'department' => $result['department'] ?? null,
        'log_uuid' => $log_uuid
    ]);
} else {
    echo json_encode([
        'success' => false,
        'verified' => false,
        'message' => 'Student verification failed'
    ]);
}
