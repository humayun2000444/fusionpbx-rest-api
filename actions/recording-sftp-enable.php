<?php
$required_params = array("domain_uuid");

/**
 * recording-sftp-enable — request an SFTP pickup account for this domain.
 *
 * Writes a request. It does NOT create the account: this runs as www-data, and
 * a web process that can add Unix users and write /etc/ssh is a far worse
 * problem than the friction it would save. A root-owned worker
 * (provision-pending.sh, hourly) picks the request up and runs
 * provision-tenant.sh.
 *
 * Self-service is safe here because the account it creates is chrooted to the
 * domain's own recordings, has no shell, and cannot log in at all until the
 * customer supplies a public key. Enabling it grants access to nothing the
 * caller could not already download through the portal.
 *
 * Domain comes from the pinned scope, so one tenant cannot request an account
 * on another tenant's recordings.
 */
function do_action($body) {
    $domain_uuid = trim((string) $body->domain_uuid);
    if (!preg_match('/^[0-9a-fA-F-]{36}$/', $domain_uuid)) {
        return array("success" => false, "error" => "domain_uuid must be a uuid");
    }

    $database = new database;
    $domain = $database->select(
        "SELECT domain_name FROM v_domains WHERE domain_uuid = :d",
        array("d" => $domain_uuid), 'row');
    if (empty($domain['domain_name'])) {
        return array("success" => false, "error" => "unknown domain");
    }

    $existing = $database->select(
        "SELECT enabled, username FROM v_recording_sftp WHERE domain_uuid = :d",
        array("d" => $domain_uuid), 'row');
    if (!empty($existing['enabled'])) {
        return array(
            "success"  => true,
            "status"   => "already_enabled",
            "username" => $existing['username'],
            "message"  => "Automatic download is already enabled for this domain.",
        );
    }

    // Row first, so the portal can show "being set up" immediately rather than
    // looking unchanged until the worker runs.
    $database->execute(
        "INSERT INTO v_recording_sftp (domain_uuid, enabled, updated_at)
         VALUES (:d, false, NOW())
         ON CONFLICT (domain_uuid) DO UPDATE SET updated_at = NOW()",
        array("d" => $domain_uuid));

    $spool = '/var/spool/fusionpbx/sftp-provision';
    if (!is_dir($spool)) { @mkdir($spool, 0750, true); }
    $written = @file_put_contents($spool . '/' . $domain_uuid, $domain['domain_name'] . "\n");

    return array(
        "success" => $written !== false,
        "status"  => "pending",
        "domain"  => $domain['domain_name'],
        // Deliberately honest about the wait. Claiming it is ready when the
        // worker has not run yet produces a support call within the minute.
        "message" => "Automatic download is being set up. It is usually ready "
                   . "within the hour; your connection details will appear here "
                   . "once it is.",
    );
}
