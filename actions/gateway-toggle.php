<?php

/**
 * Enable or disable a gateway.
 *
 * The portal declares a gateways/toggle endpoint but rest.php resolves an
 * action by filename and no gateway-toggle.php existed, so the endpoint was a
 * 400. Nothing in the portal calls it today -- the Gateways page sets "enabled"
 * through the edit form, which goes to gateway-update.php -- so this makes the
 * declared endpoint real rather than repairing a visibly broken switch.
 *
 * Mirrors the GUI's toggle (app/gateways/resources/classes/gateways.php:357):
 * flip v_gateways.enabled, drop the cached sofia config, then rescan the
 * gateway's own SIP profile.
 *
 * Two deliberate differences from the GUI:
 *
 *  - save_gateway_xml() is not called. It writes per-gateway files into
 *    $_SESSION['switch']['sip_profiles']['dir'] and returns immediately when
 *    that is unset, which it is outside a GUI session. It would be a no-op
 *    anyway: these boxes serve sofia.conf from the database through
 *    mod_xml_curl (xml_curl.conf.xml binds "configuration"), and there are no
 *    v_<uuid>.xml files on disk to write or unlink.
 *
 *  - killgw is issued when switching a gateway OFF. "sofia profile <p> rescan"
 *    adds and updates gateways but never tears an existing one down, so without
 *    killgw the row reads disabled while the trunk stays registered and keeps
 *    carrying calls -- the same "reports success, switch unchanged" failure that
 *    outbound-route-update had. The GUI has this gap; its separate Stop button
 *    is what actually issues killgw. Sofia knows each gateway by its lowercased
 *    uuid (external::c2c42a33-...), which is what FusionPBX registers it under.
 */

$required_params = array("gateway_uuid");

function do_action($body) {

    $database = new database;

    $gateway = $database->select(
        "SELECT gateway_uuid, gateway, profile, enabled
         FROM v_gateways
         WHERE gateway_uuid = :gateway_uuid",
        array("gateway_uuid" => $body->gateway_uuid), "row");

    if (empty($gateway)) {
        return array("code" => 404, "error" => "Gateway not found");
    }

    $new_enabled = ($gateway['enabled'] === 'true') ? 'false' : 'true';
    $profile = !empty($gateway['profile']) ? $gateway['profile'] : 'external';
    $gateway_name = strtolower($gateway['gateway_uuid']);

    $database->execute(
        "UPDATE v_gateways SET enabled = :enabled, update_date = NOW()
         WHERE gateway_uuid = :gateway_uuid",
        array("enabled" => $new_enabled, "gateway_uuid" => $body->gateway_uuid));

    $responses = array();
    $applied = false;

    if (class_exists('event_socket')) {
        $esl = event_socket::create();
        if ($esl) {
            $hostname = trim(event_socket::api('switchname'));

            if (class_exists('cache')) {
                $cache = new cache;
                $cache->delete("configuration:sofia.conf:" . $hostname);
            }

            // Tear the gateway down first; rescan alone will not remove it.
            if ($new_enabled === 'false') {
                $responses['killgw'] = trim(
                    event_socket::api("sofia profile " . $profile . " killgw " . $gateway_name));
            }

            $responses['rescan'] = trim(
                event_socket::api("sofia profile " . $profile . " rescan"));
            $applied = true;
        }
    }

    if (!$applied) {
        if ($new_enabled === 'false') {
            $responses['killgw'] = trim((string) shell_exec(
                "/usr/bin/fs_cli -x 'sofia profile " . $profile . " killgw " . $gateway_name . "' 2>&1"));
        }
        $responses['rescan'] = trim((string) shell_exec(
            "/usr/bin/fs_cli -x 'sofia profile " . $profile . " rescan' 2>&1"));
        $applied = ($responses['rescan'] !== "");
    }

    return array(
        "success" => true,
        "gateway_uuid" => $gateway['gateway_uuid'],
        "gateway" => $gateway['gateway'],
        "profile" => $profile,
        "enabled" => $new_enabled,
        "applied" => $applied,
        "switch_response" => $responses,
        "message" => "Gateway " . ($new_enabled === 'true' ? 'enabled' : 'disabled')
    );
}
