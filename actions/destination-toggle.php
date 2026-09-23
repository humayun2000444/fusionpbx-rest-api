<?php

/**
 * Enable or disable an inbound route (destination).
 *
 * The portal's Inbound Routes list has an on/off switch that posts to
 * destinations/toggle. rest.php resolves an action by filename, no
 * destination-toggle.php existed, so every flip came back "unknown action"
 * (400) and the switch sprang back with no explanation.
 *
 * A destination is two rows: the v_destinations record the portal lists, and
 * the v_dialplans record FreeSWITCH actually runs. destination-update.php moves
 * both together (it copies destination_enabled into dialplan_enabled), and this
 * has to do the same -- flipping only v_destinations would grey the row out in
 * the portal while the DID kept on routing calls.
 *
 * Like outbound-route-toggle.php this does NOT rebuild dialplan_xml. The enabled
 * flag is not in the blob: resources/switch.php:354 filters the dialplan it
 * serves FreeSWITCH with "d.dialplan_enabled = 'true'", so flipping the column
 * is what takes effect, and regenerating would rewrite a route the caller never
 * asked to change.
 */

$required_params = array("destination_uuid");

function do_action($body) {

    $database = new database;

    $destination = $database->select(
        "SELECT destination_uuid, destination_number, destination_context,
                destination_enabled, dialplan_uuid
         FROM v_destinations
         WHERE destination_uuid = :destination_uuid",
        array("destination_uuid" => $body->destination_uuid), "row");

    if (empty($destination)) {
        return array("code" => 404, "error" => "Destination not found");
    }

    $new_enabled = ($destination['destination_enabled'] === 'true') ? 'false' : 'true';

    $database->execute(
        "UPDATE v_destinations SET destination_enabled = :enabled, update_date = NOW()
         WHERE destination_uuid = :destination_uuid",
        array("enabled" => $new_enabled, "destination_uuid" => $body->destination_uuid));

    // Keep the dialplan in step, and take the cache context from the dialplan
    // itself rather than the destination -- they are normally both "public",
    // but the dialplan is the row that decides what gets served.
    $context = $destination['destination_context'];

    if (!empty($destination['dialplan_uuid'])) {
        $database->execute(
            "UPDATE v_dialplans SET dialplan_enabled = :enabled, update_date = NOW()
             WHERE dialplan_uuid = :dialplan_uuid",
            array("enabled" => $new_enabled, "dialplan_uuid" => $destination['dialplan_uuid']));

        $dp = $database->select(
            "SELECT dialplan_context FROM v_dialplans WHERE dialplan_uuid = :dialplan_uuid",
            array("dialplan_uuid" => $destination['dialplan_uuid']), "row");
        if (!empty($dp['dialplan_context'])) {
            $context = $dp['dialplan_context'];
        }
    }

    $reload_success = false;

    if (class_exists('event_socket')) {
        $esl = event_socket::create();
        if ($esl) {
            if (class_exists('cache') && !empty($context)) {
                $cache = new cache;
                $cache->delete("dialplan:" . $context);
            }
            event_socket::api('reloadxml');
            $reload_success = true;
        }
    }

    if (!$reload_success) {
        $out = shell_exec("/usr/bin/fs_cli -x 'reloadxml' 2>&1");
        $reload_success = ($out !== null);
    }

    return array(
        "success" => true,
        "destination_uuid" => $destination['destination_uuid'],
        "destination_number" => $destination['destination_number'],
        "destination_enabled" => $new_enabled,
        "dialplan_enabled" => $new_enabled,
        "reloaded" => $reload_success,
        "message" => "Inbound route " . ($new_enabled === 'true' ? 'enabled' : 'disabled')
    );
}
