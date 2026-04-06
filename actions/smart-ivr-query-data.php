<?php
/*
 * Smart IVR - Query Student Data
 * Fetches student data from backend API (payment, attendance, academic records, etc.)
 */

// Includes
require_once __DIR__ . '/../resources/require.php';
require_once __DIR__ . '/../resources/check_auth.php';

// Get input
$data = json_decode(file_get_contents('php://input'), true);
$domain_uuid = $data['domain_uuid'] ?? $_SESSION['domain_uuid'] ?? null;
$student_id = $data['student_id'] ?? null;
$query_type = $data['query_type'] ?? null; // 'payment', 'attendance', 'academic', 'exam', 'schedule'
$log_uuid = $data['log_uuid'] ?? null;

if (empty($domain_uuid) || empty($student_id) || empty($query_type)) {
    echo json_encode(['error' => 'domain_uuid, student_id, and query_type are required']);
    exit;
}

// Get Smart IVR configuration
$sql = "SELECT backend_api_url, backend_api_key, enabled FROM v_smart_ivr_config WHERE domain_uuid = :domain_uuid LIMIT 1";
$params = [':domain_uuid' => $domain_uuid];
$database = new database;
$config = $database->select($sql, $params, 'row');

if (!$config || !$config['enabled']) {
    echo json_encode(['error' => 'Smart IVR is not enabled']);
    exit;
}

// Map query type to API endpoint
$endpoint_map = [
    'payment' => '/student/' . $student_id . '/payment-status',
    'attendance' => '/student/' . $student_id . '/attendance',
    'academic' => '/student/' . $student_id . '/academic-records',
    'exam' => '/student/' . $student_id . '/exam-results',
    'schedule' => '/student/' . $student_id . '/schedule'
];

if (!isset($endpoint_map[$query_type])) {
    echo json_encode(['error' => 'Invalid query_type']);
    exit;
}

// Check cache first (optional - 5 minute cache)
$cache_key = md5($domain_uuid . $student_id . $query_type);
$sql = "SELECT cache_data FROM v_smart_ivr_api_cache
        WHERE cache_key = :cache_key
        AND expires_at > NOW()
        LIMIT 1";
$cache_result = $database->select($sql, [':cache_key' => $cache_key], 'row');

if ($cache_result && !empty($cache_result['cache_data'])) {
    $cached_data = json_decode($cache_result['cache_data'], true);
    echo json_encode([
        'success' => true,
        'from_cache' => true,
        'query_type' => $query_type,
        'data' => $cached_data
    ]);
    exit;
}

// Call backend API
$api_url = rtrim($config['backend_api_url'], '/') . $endpoint_map[$query_type];

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $api_url);
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
        'error' => 'API error: ' . $http_code,
        'response' => $response
    ]);
    exit;
}

$result = json_decode($response, true);

// Cache the result
$cache_uuid = uuid();
$sql = "INSERT INTO v_smart_ivr_api_cache (
    cache_uuid,
    domain_uuid,
    student_id,
    api_endpoint,
    cache_key,
    cache_data,
    expires_at,
    insert_date
) VALUES (
    :cache_uuid,
    :domain_uuid,
    :student_id,
    :api_endpoint,
    :cache_key,
    :cache_data,
    NOW() + INTERVAL '5 minutes',
    NOW()
)";
$params = [
    ':cache_uuid' => $cache_uuid,
    ':domain_uuid' => $domain_uuid,
    ':student_id' => $student_id,
    ':api_endpoint' => $endpoint_map[$query_type],
    ':cache_key' => $cache_key,
    ':cache_data' => json_encode($result)
];
$database->execute($sql, $params);

// Update call log with query made
if ($log_uuid) {
    $sql = "UPDATE v_smart_ivr_call_logs
            SET queries_made = COALESCE(queries_made, '[]'::jsonb) || :query::jsonb
            WHERE log_uuid = :log_uuid";
    $query_data = json_encode([$query_type => date('Y-m-d H:i:s')]);
    $database->execute($sql, [':query' => $query_data, ':log_uuid' => $log_uuid]);
}

echo json_encode([
    'success' => true,
    'from_cache' => false,
    'query_type' => $query_type,
    'data' => $result
]);
