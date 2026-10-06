<?php
/**
 * Janus event handler backend: POST /janus-events
 *
 * Runs under PHP's built-in server bound to 127.0.0.1:7099 (see
 * systemd/video-call-cdr.service) and receives what Janus's sampleevh handler
 * posts. GET /health reports open and unwritten calls.
 *
 * This directory sits inside the web root, so the first thing this file does
 * is refuse to run under nginx/php-fpm.
 */

if (PHP_SAPI !== 'cli-server') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/VideoCallCdr.php';
require_once __DIR__ . '/CdrWriter.php';

$conf = Config::load();
$log = Config::logger();
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

if ($_SERVER['REQUEST_METHOD'] === 'GET' && $path === '/health') {
    $summary = Config::withState($conf['state_file'], function (array &$state) {
        return [
            'open_calls' => count($state['calls'] ?? []),
            'unwritten' => count($state['pending'] ?? []),
            'last_event' => !empty($state['last_event_us']) ? date('c', intdiv($state['last_event_us'], 1000000)) : null,
        ];
    });
    header('Content-Type: application/json');
    echo json_encode($summary), "\n";
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || $path !== '/janus-events') {
    http_response_code(404);
    exit;
}

$raw = file_get_contents('php://input');
// sampleevh can compress; it sets the header when it does.
if (stripos($_SERVER['HTTP_CONTENT_ENCODING'] ?? '', 'gzip') !== false) {
    $raw = @gzdecode($raw);
}
$payload = json_decode((string) $raw, true, 512, JSON_BIGINT_AS_STRING);
if (!is_array($payload)) {
    http_response_code(400);
    exit;
}

try {
    Config::withState($conf['state_file'], function (array &$state) use ($conf, $log, $payload) {
        $cdr = new VideoCallCdr(
            $state,
            Config::adminClient($conf),
            new CdrWriter($conf['fusionpbx_config'], $log),
            $log,
            $conf['identity_check'] !== 'log'
        );
        $cdr->handle($payload);
    });
} catch (Throwable $t) {
    // Answer 200 anyway: a 5xx makes sampleevh resend the batch, and a batch
    // that breaks us once will break us every time.
    $log('event batch failed: ' . $t->getMessage());
}
http_response_code(200);
