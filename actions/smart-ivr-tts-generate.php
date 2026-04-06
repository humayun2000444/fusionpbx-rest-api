<?php
/*
 * Smart IVR - Google TTS Generation
 * Generates speech audio files using Google Cloud Text-to-Speech
 */

// Includes
require_once __DIR__ . '/../resources/require.php';
require_once __DIR__ . '/../resources/check_auth.php';

// Get input
$data = json_decode(file_get_contents('php://input'), true);
$domain_uuid = $data['domain_uuid'] ?? $_SESSION['domain_uuid'] ?? null;
$text = $data['text'] ?? null;
$language = $data['language'] ?? 'en-US';
$voice_gender = $data['voice_gender'] ?? 'FEMALE'; // MALE, FEMALE, NEUTRAL

if (empty($domain_uuid) || empty($text)) {
    echo json_encode(['error' => 'domain_uuid and text are required']);
    exit;
}

// Get Smart IVR configuration
$sql = "SELECT google_tts_enabled, google_tts_language, google_tts_voice_name, google_tts_voice_gender FROM v_smart_ivr_config WHERE domain_uuid = :domain_uuid LIMIT 1";
$params = [':domain_uuid' => $domain_uuid];
$database = new database;
$config = $database->select($sql, $params, 'row');

if (!$config || !$config['google_tts_enabled']) {
    // Fallback to FreeSWITCH flite
    echo json_encode([
        'success' => true,
        'tts_type' => 'flite',
        'tts_string' => 'speak|flite|rms|' . $text
    ]);
    exit;
}

// Use configured language if not specified
if ($language == 'en-US') {
    $language = $config['google_tts_language'];
}

// Use configured voice gender if not specified
if ($voice_gender == 'FEMALE' && !empty($config['google_tts_voice_gender'])) {
    $voice_gender = $config['google_tts_voice_gender'];
}

// Get configured voice name (if set)
$voice_name = $config['google_tts_voice_name'] ?? null;

// Google Cloud TTS API
// Note: Requires GOOGLE_APPLICATION_CREDENTIALS environment variable
// or service account JSON key file

$google_api_key = getenv('GOOGLE_CLOUD_TTS_API_KEY');

if (empty($google_api_key)) {
    // Check if credentials file exists
    $creds_file = getenv('GOOGLE_APPLICATION_CREDENTIALS');
    if (empty($creds_file) || !file_exists($creds_file)) {
        // Fallback to flite
        echo json_encode([
            'success' => true,
            'tts_type' => 'flite',
            'tts_string' => 'speak|flite|rms|' . $text,
            'warning' => 'Google TTS credentials not configured, using flite'
        ]);
        exit;
    }
}

// Generate unique filename
$filename = 'smart_ivr_' . md5($text . $language) . '.wav';
$audio_dir = '/usr/share/freeswitch/sounds/en/custom/smart_ivr/';
$audio_path = $audio_dir . $filename;

// Create directory if not exists
if (!is_dir($audio_dir)) {
    mkdir($audio_dir, 0755, true);
}

// Check if file already exists (cache)
if (file_exists($audio_path)) {
    echo json_encode([
        'success' => true,
        'tts_type' => 'google',
        'audio_path' => $audio_path,
        'audio_file' => $filename,
        'from_cache' => true
    ]);
    exit;
}

// Call Google Cloud TTS API
$api_url = 'https://texttospeech.googleapis.com/v1/text:synthesize';

// Build voice configuration
$voice_config = [
    'languageCode' => $language,
    'ssmlGender' => $voice_gender
];

// If specific voice name is configured, use it for better quality (WaveNet/Neural2 voices)
// For Bengali, this enables high-quality natural voices like bn-IN-Wavenet-A, bn-IN-Wavenet-B
if (!empty($voice_name)) {
    $voice_config['name'] = $voice_name;
}

$request_data = [
    'input' => ['text' => $text],
    'voice' => $voice_config,
    'audioConfig' => [
        'audioEncoding' => 'LINEAR16', // WAV format
        'sampleRateHertz' => 8000 // Phone quality
    ]
];

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $api_url . '?key=' . $google_api_key);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($request_data));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json'
]);

$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curl_error = curl_error($ch);
curl_close($ch);

if ($curl_error || $http_code !== 200) {
    // Fallback to flite
    echo json_encode([
        'success' => true,
        'tts_type' => 'flite',
        'tts_string' => 'speak|flite|rms|' . $text,
        'warning' => 'Google TTS API error, using flite fallback',
        'error' => $curl_error ?: 'HTTP ' . $http_code
    ]);
    exit;
}

$result = json_decode($response, true);

if (isset($result['audioContent'])) {
    // Decode base64 audio and save
    $audio_content = base64_decode($result['audioContent']);
    file_put_contents($audio_path, $audio_content);

    echo json_encode([
        'success' => true,
        'tts_type' => 'google',
        'audio_path' => $audio_path,
        'audio_file' => $filename,
        'from_cache' => false
    ]);
} else {
    // Fallback to flite
    echo json_encode([
        'success' => true,
        'tts_type' => 'flite',
        'tts_string' => 'speak|flite|rms|' . $text,
        'warning' => 'Google TTS response invalid, using flite'
    ]);
}
