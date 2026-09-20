<?php
$required_params = array("domain_uuid", "public_key");

/**
 * recording-sftp-key — register a tenant's SSH PUBLIC key for SFTP pickup.
 *
 * ── Rules this endpoint exists to enforce ───────────────────────────────────
 *  * PUBLIC keys only. A private key is never accepted, never stored, never
 *    returned. If a customer pastes one, we reject it AND tell them to treat it
 *    as compromised, because they just sent their private key over the wire.
 *  * Writes a request row, not the authorized_keys file. A PHP process running
 *    as www-data must not write into /etc/ssh. A root-owned installer picks
 *    these up -- see jobs/install-sftp-keys.php.
 *  * Domain comes from the pinned scope, so one tenant can never install a key
 *    on another tenant's account. That is the whole ballgame here.
 */
function do_action($body) {
    $domain_uuid = trim((string) $body->domain_uuid);
    if (!preg_match('/^[0-9a-fA-F-]{36}$/', $domain_uuid)) {
        return array("success" => false, "error" => "domain_uuid must be a uuid");
    }

    $key = trim((string) $body->public_key);
    if ($key === '' || strlen($key) > 16384) {
        return array("success" => false, "error" => "public_key missing or too large");
    }

    // Catch a pasted PRIVATE key before it is stored anywhere.
    if (stripos($key, 'PRIVATE KEY') !== false || stripos($key, '-----BEGIN') !== false) {
        return array(
            "success" => false,
            "error"   => "that looks like a PRIVATE key. Never share it. "
                       . "Treat it as compromised, generate a new key pair, and "
                       . "paste only the .pub file (one line starting ssh-ed25519 or ssh-rsa).",
        );
    }

    // One line, known type, base64 body. Keeps anything shell-ish out of a file
    // that sshd parses.
    $key = preg_replace('/\s+/', ' ', $key);
    if (!preg_match('/^(ssh-ed25519|ssh-rsa|ecdsa-sha2-nistp(256|384|521)) ([A-Za-z0-9+\/=]+)( [^\r\n]{0,200})?$/', $key, $m)) {
        return array("success" => false, "error" => "not a valid OpenSSH public key line");
    }
    $type = $m[1];
    $blob = $m[3];

    if ($type === 'ssh-rsa') {
        // An RSA key shorter than 2048 bits is not worth accepting in 2026.
        $raw = base64_decode($blob, true);
        if ($raw === false || strlen($raw) < 180) {
            return array("success" => false, "error" => "RSA key too short — use ssh-ed25519");
        }
    }

    $fingerprint = 'SHA256:' . rtrim(base64_encode(hash('sha256', base64_decode($blob, true), true)), '=');

    $database = new database;
    $database->execute(
        "INSERT INTO v_recording_sftp (domain_uuid, public_key_fingerprint, public_key_installed, updated_at)
         VALUES (:domain_uuid, :fp, false, NOW())
         ON CONFLICT (domain_uuid) DO UPDATE
            SET public_key_fingerprint = EXCLUDED.public_key_fingerprint,
                public_key_installed   = false,
                updated_at             = NOW()",
        array("domain_uuid" => $domain_uuid, "fp" => $fingerprint));

    // Staged for the root-owned installer. Kept out of /etc/ssh on purpose.
    $spool = '/var/spool/fusionpbx/sftp-keys';
    if (!is_dir($spool)) { @mkdir($spool, 0750, true); }
    $written = @file_put_contents($spool . '/' . $domain_uuid . '.pub', $key . "\n");

    return array(
        "success"     => $written !== false,
        "fingerprint" => $fingerprint,
        "type"        => $type,
        // Deliberately honest: nothing works until the installer has run.
        "status"      => "pending_install",
        "message"     => "Key received. It becomes active after the next key install run.",
    );
}
