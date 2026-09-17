<?php
$required_params = array("domain_uuid");

/**
 * cdr-mark-exported — record that rows have been archived off this server.
 *
 * The point of this endpoint is to make deletion safe. Retention that removes
 * rows by AGE alone is what customers reasonably object to: it can delete a
 * call nobody has a second copy of. Retention keyed on `exported_at` cannot,
 * because a row is only ever eligible once the puller has confirmed receiving
 * it.
 *
 * So the contract is: list -> download -> verify -> mark. Nothing here writes
 * exported_at on the caller's behalf during a list or a download, precisely so
 * that a failed or partial transfer leaves the rows still marked un-exported
 * and they come back on the next pass.
 *
 * Scoped to the caller's domain, so one tenant can never mark another's rows.
 *
 *   domain_uuid  required
 *   uuids        array of xml_cdr_uuid, max 1000 per call
 *   unmark       optional bool — clear the marker again (for re-exports)
 */
function do_action($body) {
    $domain_uuid = trim((string) $body->domain_uuid);
    if (!preg_match('/^[0-9a-fA-F-]{36}$/', $domain_uuid)) {
        return array("success" => false, "error" => "domain_uuid must be a uuid");
    }

    $uuids = isset($body->uuids) ? $body->uuids : null;
    if (!is_array($uuids) || count($uuids) === 0) {
        return array("success" => false, "error" => "uuids must be a non-empty array");
    }
    if (count($uuids) > 1000) {
        return array("success" => false, "error" => "at most 1000 uuids per call, got " . count($uuids));
    }

    // Validate every uuid before building the statement: a malformed value is a
    // Postgres type error mid-batch, which would abort the whole thing.
    $clean = array();
    foreach ($uuids as $u) {
        $u = trim((string) $u);
        if (!preg_match('/^[0-9a-fA-F-]{36}$/', $u)) {
            return array("success" => false, "error" => "not a uuid: " . substr($u, 0, 40));
        }
        $clean[] = $u;
    }
    $clean = array_values(array_unique($clean));

    $unmark = !empty($body->unmark);
    $parameters = array("domain_uuid" => $domain_uuid);
    $placeholders = array();
    foreach ($clean as $i => $u) {
        $placeholders[] = ":u" . $i;
        $parameters["u" . $i] = $u;
    }

    // Already-marked rows are left alone, so a repeated call is harmless and
    // the reported count is the number genuinely changed by THIS call.
    $sql = "UPDATE v_xml_cdr SET exported_at = " . ($unmark ? "NULL" : "NOW()")
         . " WHERE domain_uuid = :domain_uuid"
         . " AND xml_cdr_uuid IN (" . implode(", ", $placeholders) . ")"
         . " AND exported_at IS " . ($unmark ? "NOT NULL" : "NULL");

    $database = new database;
    $database->execute($sql, $parameters);

    // Report back what is now true, rather than trusting the update count.
    $check_sql = "SELECT count(*) AS marked FROM v_xml_cdr"
               . " WHERE domain_uuid = :domain_uuid"
               . " AND xml_cdr_uuid IN (" . implode(", ", $placeholders) . ")"
               . " AND exported_at IS NOT NULL";
    $row = $database->select($check_sql, $parameters, "row");

    return array(
        "success"   => true,
        "requested" => count($clean),
        "marked"    => isset($row['marked']) ? (int) $row['marked'] : 0,
        "unmark"    => $unmark,
    );
}
