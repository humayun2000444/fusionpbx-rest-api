#!/usr/bin/php
<?php
/**
 * order-confirm-tts-cli.php
 *
 * On-demand TTS generator for the Order-Confirmation IVR. Invoked by
 * order-confirm-ivr.lua AFTER a real human answers:
 *
 *     php order-confirm-tts-cli.php <call_uuid>
 *
 * Loads the call + its domain config, runs oc_build_playback() (the ElevenLabs
 * synthesis), and prints a simple key=value bundle the Lua parses. Running the
 * synthesis ONLY here (post-answer) is what keeps unanswered / ringing /
 * voicemail calls at zero TTS credits.
 *
 * Output (stdout, one key=value per line; base64 values contain no newline):
 *     ok=1
 *     valid=120
 *     msg_chars=181
 *     ack_chars=38
 *     msg_b64=<base64 of the main-message playback spec>
 *     ack_b64=<base64 of the ack playback spec>
 *     opts_b64=<base64 of the digit~action~dest~label~sayChars~sayB64 map>
 * On failure: ok=0 and error=<reason>.
 */

ob_start();                                   // swallow anything the bootstrap prints
$document_root = '/var/www/fusionpbx';
require_once $document_root . '/resources/require.php';
require_once __DIR__ . '/order-confirm-helper.php';
@ob_end_clean();                              // keep stdout clean for the Lua parser

function oc_cli_out($arr) { foreach ($arr as $k => $v) echo $k . '=' . $v . "\n"; }

$call_uuid = isset($argv[1]) ? trim($argv[1]) : '';
if ($call_uuid === '' || !preg_match('/^[0-9a-fA-F\-]{36}$/', $call_uuid)) {
    oc_cli_out(array('ok' => 0, 'error' => 'bad_call_uuid'));
    exit(0);
}

try {
    $database = new database;
    $call = $database->select(
        "SELECT * FROM v_order_confirm_calls WHERE call_uuid = :c LIMIT 1",
        array('c' => $call_uuid), 'row');
    if (!$call) { oc_cli_out(array('ok' => 0, 'error' => 'call_not_found')); exit(0); }

    $config = oc_get_config($database, $call['domain_uuid']);
    $pb = oc_build_playback($config, $call);

    oc_cli_out(array(
        'ok'        => 1,
        'valid'     => $pb['valid'],
        'msg_chars' => intval($pb['msg_chars']),
        'ack_chars' => intval($pb['ack_chars']),
        'msg_b64'   => base64_encode($pb['msg']),
        'ack_b64'   => base64_encode($pb['ack']),
        'opts_b64'  => base64_encode($pb['opts_enc']),
    ));
} catch (Exception $e) {
    oc_cli_out(array('ok' => 0, 'error' => str_replace(array("\n", "\r"), ' ', $e->getMessage())));
}
