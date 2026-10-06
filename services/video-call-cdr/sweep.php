<?php
/**
 * Close video calls whose handles vanished without an event, and retry any
 * CDR the database refused. Run every minute by video-call-cdr-sweep.timer.
 *
 *   php sweep.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/VideoCallCdr.php';
require_once __DIR__ . '/CdrWriter.php';

$conf = Config::load();
$log = Config::logger();

Config::withState($conf['state_file'], function (array &$state) use ($conf, $log) {
    $cdr = new VideoCallCdr(
        $state,
        Config::adminClient($conf),
        new CdrWriter($conf['fusionpbx_config'], $log),
        $log,
        $conf['identity_check'] !== 'log'
    );
    $cdr->sweep();
});
