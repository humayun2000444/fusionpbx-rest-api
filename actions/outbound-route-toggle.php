<?php

/**
 * Enable or disable an outbound route.
 *
 * The portal's route list has an on/off switch that posted to
 * outbound-routes/toggle, but no action file existed, so every flip came back
 * "unknown action" (400) and the switch silently sprang back. The FusionPBX GUI
 * can enable and disable a route, so the API has to as well.
 *
 * Unlike outbound-route-update.php this does NOT rebuild dialplan_xml, and
 * deliberately so: the enabled flag is not part of the blob. FreeSWITCH is
 * served the dialplan by resources/switch.php, which filters with
 * "d.dialplan_enabled = 'true'" -- so flipping the column is what takes effect,
 * and regenerating here would only risk rewriting a route the caller never
 * asked to change.
 */

$required_params = array("dialplanUuid");

// Outbound routes app_uuid -- same constant outbound-route-list.php filters on.
$outbound_app_uuid = '8c914ec3-9fc0-8ab5-4cda-6c9288bdc9a3';

function do_action($body) {
    global $outbound_app_uuid;

    $dialplan_uuid = isset($body->dialplanUuid) ? $body->dialplanUuid : $body->dialplan_uuid;

    $database = new database;

    // Scope to outbound routes: without the app_uuid check this would happily
    // toggle an inbound route, a time condition or a call flow that happened to
    // share the uuid space.
    $sql = "SELECT dialplan_enabled, dialplan_name, dialplan_number, dialplan_context
            FROM v_dialplans
            WHERE dialplan_uuid = :dialplan_uuid
            AND app_uuid = :app_uuid";
    $existing = $database->select($sql, array(
        "dialplan_uuid" => $dialplan_uuid,
        "app_uuid" => $outbound_app_uuid
    ), "row");

    if (empty($existing)) {
        return array("code" => 404, "error" => "Outbound route not found");
    }

    $new_enabled = ($existing['dialplan_enabled'] === 'true') ? 'false' : 'true';

    $database->execute(
        "UPDATE v_dialplans SET dialplan_enabled = :enabled, update_date = NOW()
         WHERE dialplan_uuid = :dialplan_uuid",
        array("enabled" => $new_enabled, "dialplan_uuid" => $dialplan_uuid)
    );

    // Clear the cached dialplan for this context and reload, the same way
    // outbound-route-update.php does.
    $context = $existing['dialplan_context'];
    $reload_success = false;

    if (class_exists('event_socket')) {
        $esl = event_socket::create();
        if ($esl) {
            if (class_exists('cache')) {
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
        "dialplanUuid" => $dialplan_uuid,
        "name" => $existing['dialplan_name'],
        "number" => $existing['dialplan_number'],
        "enabled" => $new_enabled,
        "reloaded" => $reload_success,
        "message" => "Outbound route " . ($new_enabled === 'true' ? 'enabled' : 'disabled')
    );
}
