<?php
/**
 * Settings for the video-call CDR receiver, plus the pieces shared by the
 * receiver, the sweep and the tests.
 */

final class Config
{
    const DEFAULTS = [
        'admin_url' => 'http://127.0.0.1:7088/admin',
        'admin_secret' => '',
        'identity_check' => 'enforce',          // enforce | log
        'state_file' => '/var/lib/video-call-cdr/state.json',
        'fusionpbx_config' => '/etc/fusionpbx/config.conf',
    ];

    public static function load($path = null)
    {
        $path = $path ?: (getenv('VIDEO_CALL_CDR_CONF') ?: '/etc/video-call-cdr.conf');
        $ini = is_readable($path) ? parse_ini_file($path, false, INI_SCANNER_RAW) : [];
        return array_merge(self::DEFAULTS, is_array($ini) ? $ini : []);
    }

    /** database.0.* from FusionPBX's own config.conf - the same credentials the GUI uses. */
    public static function fusionpbxDatabase($path)
    {
        $db = ['host' => '127.0.0.1', 'port' => '5432', 'name' => 'fusionpbx', 'username' => 'fusionpbx', 'password' => ''];
        foreach (@file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            if (preg_match('/^\s*database\.0\.(host|port|name|username|password)\s*=\s*(.*?)\s*$/', $line, $m)) {
                $db[$m[1]] = $m[2];
            }
        }
        return $db;
    }

    /** POST to the Janus Admin API. Returns the decoded reply, or null. */
    public static function adminClient(array $conf)
    {
        return function ($path, array $body) use ($conf) {
            $body += ['transaction' => bin2hex(random_bytes(6)), 'admin_secret' => $conf['admin_secret']];
            $ch = curl_init(rtrim($conf['admin_url'], '/') . $path);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($body),
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT_MS => 1000,
                CURLOPT_TIMEOUT_MS => 3000,
            ]);
            $raw = curl_exec($ch);
            curl_close($ch);
            return $raw === false ? null : json_decode($raw, true, 512, JSON_BIGINT_AS_STRING);
        };
    }

    public static function logger()
    {
        return function ($line) {
            file_put_contents('php://stderr', '[video-call-cdr] ' . $line . "\n");
        };
    }

    /**
     * Run $fn with the persisted state, under an exclusive lock, and save it.
     * The receiver and the sweep timer both go through here.
     */
    public static function withState($file, callable $fn)
    {
        $dir = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }
        $lock = fopen($file . '.lock', 'c');
        flock($lock, LOCK_EX);
        try {
            $raw = @file_get_contents($file);
            $state = $raw ? json_decode($raw, true, 512, JSON_BIGINT_AS_STRING) : [];
            $state = is_array($state) ? $state : [];
            $result = $fn($state);
            $tmp = $file . '.tmp';
            file_put_contents($tmp, json_encode($state, JSON_PRETTY_PRINT));
            rename($tmp, $file);
            return $result;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
