<?php
/**
 * IVR Delete - Deletes IVR menu matching FusionPBX GUI behavior
 * Clears cache so changes take effect immediately
 */
$required_params = array("ivr_menu_uuid");

function do_action($body) {
    $database = new database;

    // Check if IVR menu exists and get details
    $sql = "SELECT m.ivr_menu_uuid, m.ivr_menu_name, m.ivr_menu_context, m.dialplan_uuid, m.domain_uuid, d.domain_name
            FROM v_ivr_menus m
            JOIN v_domains d ON m.domain_uuid = d.domain_uuid
            WHERE m.ivr_menu_uuid = :ivr_menu_uuid";
    $existing = $database->select($sql, array("ivr_menu_uuid" => $body->ivr_menu_uuid), "row");

    if (!$existing) {
        return array("error" => "IVR menu not found");
    }

    $ivr_menu_uuid = $existing['ivr_menu_uuid'];
    $dialplan_uuid = $existing['dialplan_uuid'];
    $ivr_name = $existing['ivr_menu_name'];
    $ivr_menu_context = $existing['ivr_menu_context'];
    if (empty($ivr_menu_context)) {
        $ivr_menu_context = $existing['domain_name'];
    }

    // Delete IVR menu options first
    $sql = "DELETE FROM v_ivr_menu_options WHERE ivr_menu_uuid = :ivr_menu_uuid";
    $database->execute($sql, array("ivr_menu_uuid" => $ivr_menu_uuid));

    // Delete IVR menu
    $sql = "DELETE FROM v_ivr_menus WHERE ivr_menu_uuid = :ivr_menu_uuid";
    $database->execute($sql, array("ivr_menu_uuid" => $ivr_menu_uuid));

    // Delete associated dialplan if exists
    if ($dialplan_uuid) {
        // Delete dialplan details first
        $sql = "DELETE FROM v_dialplan_details WHERE dialplan_uuid = :dialplan_uuid";
        $database->execute($sql, array("dialplan_uuid" => $dialplan_uuid));

        // Delete dialplan
        $sql = "DELETE FROM v_dialplans WHERE dialplan_uuid = :dialplan_uuid";
        $database->execute($sql, array("dialplan_uuid" => $dialplan_uuid));
    }

    // Clear the cache - CRITICAL for changes to take effect immediately
    clear_ivr_cache($ivr_menu_uuid, $ivr_menu_context);

    return array(
        "success" => true,
        "message" => "IVR menu deleted successfully",
        "ivr_menu_uuid" => $ivr_menu_uuid,
        "name" => $ivr_name,
        "cache_cleared" => true
    );
}

/**
 * Clear IVR and dialplan cache so changes take effect immediately
 */
function clear_ivr_cache($ivr_menu_uuid, $context) {
    // Use FusionPBX cache class
    if (class_exists('cache')) {
        $cache = new cache;
        $cache->delete("dialplan:" . $context);
        $cache->delete("configuration:ivr.conf:" . $ivr_menu_uuid);
    }

    // Also try direct memcache delete via event socket
    if (class_exists('event_socket')) {
        $esl = event_socket::create();
        if ($esl) {
            // Clear dialplan cache
            $command = "memcache delete dialplan." . str_replace(":", ".", $context);
            event_socket::api($command);

            // Clear IVR config cache
            $command = "memcache delete configuration.ivr.conf." . $ivr_menu_uuid;
            event_socket::api($command);

            // Reload XML
            event_socket::api("reloadxml");
        }
    }
}
