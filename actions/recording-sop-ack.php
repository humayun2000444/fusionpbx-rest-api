<?php
$required_params = array("domain_uuid");

/**
 * recording-sop-ack — record that a domain admin has read the download SOP.
 *
 * Exists for one reason: you delete customer recordings on a schedule. When a
 * customer later says "nobody told us", this row is the answer. It is not a
 * consent mechanism and must not be treated as one -- it records that the
 * instructions were shown, nothing more.
 */
function do_action($body) {
    $domain_uuid = trim((string) $body->domain_uuid);
    if (!preg_match('/^[0-9a-fA-F-]{36}$/', $domain_uuid)) {
        return array("success" => false, "error" => "domain_uuid must be a uuid");
    }

    $version = isset($body->sop_version) ? (int) $body->sop_version : 1;
    if ($version < 1 || $version > 9999) {
        return array("success" => false, "error" => "sop_version out of range");
    }

    // Identity comes from the authenticated request, never the body -- a
    // caller must not be able to attribute an acknowledgement to someone else.
    $who = null;
    if (!empty($_SERVER['HTTP_X_AUTH_ID_PARTNER'])) {
        $who = 'partner:' . preg_replace('/[^0-9]/', '', $_SERVER['HTTP_X_AUTH_ID_PARTNER']);
    }

    $database = new database;
    $database->execute(
        "INSERT INTO v_recording_sop_ack (domain_uuid, sop_version, acknowledged_by)
         VALUES (:domain_uuid, :sop_version, :who)
         ON CONFLICT (domain_uuid, sop_version) DO NOTHING",
        array("domain_uuid" => $domain_uuid, "sop_version" => $version, "who" => $who));

    $row = $database->select(
        "SELECT acknowledged_at, acknowledged_by FROM v_recording_sop_ack
          WHERE domain_uuid = :domain_uuid AND sop_version = :sop_version",
        array("domain_uuid" => $domain_uuid, "sop_version" => $version), "row");

    return array(
        "success"        => true,
        "sopVersion"     => $version,
        "acknowledgedAt" => $row['acknowledged_at'] ?? null,
        "acknowledgedBy" => $row['acknowledged_by'] ?? null,
    );
}
