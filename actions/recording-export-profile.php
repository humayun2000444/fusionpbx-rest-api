<?php
$required_params = array("domain_uuid");

/**
 * recording-export-profile — everything the portal's retention panel needs, in
 * one call.
 *
 * Reads the nightly summary table, never v_xml_cdr. Counting 9.9M rows on every
 * page load is how you take the database down, and this database has already
 * run out of connections once.
 *
 * SECURITY: domain_uuid arrives here already pinned by the tenant-scope
 * backstop (resources/tenant_scope.php) to the caller's own domain. This action
 * must never widen that -- no "all domains" mode, no partner-level listing.
 */
function do_action($body) {
    $domain_uuid = trim((string) $body->domain_uuid);
    if (!preg_match('/^[0-9a-fA-F-]{36}$/', $domain_uuid)) {
        return array("success" => false, "error" => "domain_uuid must be a uuid");
    }

    $database = new database;

    // What customers are told. Kept in settings so it can change without a
    // deploy, and separate from what the purge actually uses.
    $display_days = 90;
    $row_dd = $database->select(
        "SELECT default_setting_value FROM v_default_settings
          WHERE default_setting_category='recordings' AND default_setting_subcategory='display_days'
            AND default_setting_enabled=true LIMIT 1", array(), 'row');
    if (!empty($row_dd['default_setting_value'])) { $display_days = (int) $row_dd['default_setting_value']; }

    $domain = $database->select(
        "SELECT domain_name FROM v_domains WHERE domain_uuid = :domain_uuid",
        array("domain_uuid" => $domain_uuid), "row");
    if (empty($domain)) {
        return array("success" => false, "error" => "unknown domain");
    }

    $status = $database->select(
        "SELECT * FROM v_recording_export_status WHERE domain_uuid = :domain_uuid",
        array("domain_uuid" => $domain_uuid), "row");

    $sftp = $database->select(
        "SELECT * FROM v_recording_sftp WHERE domain_uuid = :domain_uuid",
        array("domain_uuid" => $domain_uuid), "row");

    $ack = $database->select(
        "SELECT sop_version, acknowledged_at, acknowledged_by
           FROM v_recording_sop_ack
          WHERE domain_uuid = :domain_uuid
          ORDER BY sop_version DESC LIMIT 1",
        array("domain_uuid" => $domain_uuid), "row");

    // Current SOP version lives in default settings so it can be bumped without
    // a deploy; bumping it makes the panel re-prompt every tenant.
    $sop_version = 1;
    $setting = $database->select(
        "SELECT default_setting_value FROM v_default_settings
          WHERE default_setting_category = 'recordings'
            AND default_setting_subcategory = 'sop_version'
            AND default_setting_enabled = true LIMIT 1", array(), "row");
    if (!empty($setting['default_setting_value'])) {
        $sop_version = (int) $setting['default_setting_value'];
    }

    $days_until = null;
    if (!empty($status['purge_date'])) {
        $days_until = (int) floor(
            (strtotime($status['purge_date']) - strtotime(date('Y-m-d'))) / 86400);
    }

    return array(
        "success" => true,
        "domain"  => $domain['domain_name'],
        "retention" => array(
            // `days` is what customers are TOLD; `keepDays` is what we actually
            // keep. They differ deliberately -- we keep longer than we promise,
            // so "at least N days" is always true and nobody is surprised by an
            // early deletion. Anything customer-facing must use `days`.
            "policy"         => "at_least_" . $display_days . "_days",
            "days"           => $display_days,
            "keepDays"       => (int) ($status['retention_days'] ?? 95),
            "purgeDate"      => $status['purge_date']  ?? null,
            "cutoffDate"     => $status['cutoff_date'] ?? null,
            "daysUntilPurge" => $days_until,
        ),
        "atRisk" => array(
            "count"  => (int) ($status['at_risk_count'] ?? 0),
            "bytes"  => (int) ($status['at_risk_bytes'] ?? 0),
            "oldest" => $status['at_risk_oldest'] ?? null,
        ),
        "pending" => array(
            "count"  => (int) ($status['pending_count'] ?? 0),
            "bytes"  => (int) ($status['pending_bytes'] ?? 0),
            "oldest" => $status['pending_oldest'] ?? null,
        ),
        "total" => array(
            "count" => (int) ($status['total_count'] ?? 0),
            "bytes" => (int) ($status['total_bytes'] ?? 0),
        ),
        "sftp" => array(
            // Host and port come from the SERVER. The frontend must not know
            // infrastructure, and it differs per platform.
            "enabled"            => !empty($sftp['enabled']),
            "host"               => $sftp['host'] ?? null,
            "port"               => isset($sftp['port']) ? (int) $sftp['port'] : null,
            "username"           => $sftp['username'] ?? null,
            "path"               => $sftp['chroot_path'] ?? '/recordings',
            "hostKeyFingerprint" => $sftp['host_key_fingerprint'] ?? null,
            "publicKeyInstalled" => !empty($sftp['public_key_installed']),
            "lastLoginAt"        => $sftp['last_login_at'] ?? null,
            "lastDownloadAt"     => $sftp['last_download_at'] ?? null,
        ),
        "sop" => array(
            "version"        => $sop_version,
            "acknowledged"   => !empty($ack) && (int) $ack['sop_version'] >= $sop_version,
            "acknowledgedAt" => $ack['acknowledged_at'] ?? null,
            "acknowledgedBy" => $ack['acknowledged_by'] ?? null,
        ),
        "computedAt" => $status['computed_at'] ?? null,
        // Null means the nightly job has not run for this domain yet; the UI
        // should say "calculating" rather than confidently showing zeros.
        "ready" => !empty($status),
    );
}
