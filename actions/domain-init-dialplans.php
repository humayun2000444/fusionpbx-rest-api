<?php

// Initialize default dialplans for a domain (same as FusionPBX GUI does when creating a domain)
// This reads XML templates from /app/dialplans/resources/switch/conf/dialplan/ and imports them

$required_params = array('domainUuid');

function do_action($body) {
    $domain_uuid = $body->domainUuid;

    // Verify domain exists
    $database = new database;
    $sql = "SELECT domain_uuid, domain_name FROM v_domains WHERE domain_uuid = :domain_uuid";
    $domain = $database->select($sql, array('domain_uuid' => $domain_uuid), 'row');
    if (empty($domain)) {
        return array('success' => false, 'message' => 'Domain not found');
    }

    $domain_name = $domain['domain_name'];

    // Check if dialplans already exist for this domain
    $sql = "SELECT COUNT(*) as cnt FROM v_dialplans WHERE domain_uuid = :domain_uuid";
    $existing = $database->select($sql, array('domain_uuid' => $domain_uuid), 'row');
    $existing_count = $existing ? (int)$existing['cnt'] : 0;

    // Use FusionPBX's built-in dialplan import class
    $dialplan_class_path = $_SERVER["DOCUMENT_ROOT"] . "/app/dialplans/resources/classes/dialplan.php";
    if (!file_exists($dialplan_class_path)) {
        return array('success' => false, 'message' => 'Dialplan class not found at: ' . $dialplan_class_path);
    }

    // Include the dialplan class if not already loaded
    if (!class_exists('dialplan')) {
        require_once $dialplan_class_path;
    }

    // Build the domains array that the import method expects
    $domains = array(
        array(
            'domain_uuid' => $domain_uuid,
            'domain_name' => $domain_name
        )
    );

    // Import default dialplans (same as domain_edit.php does)
    $dialplan = new dialplan;
    $dialplan->import($domains);

    // Generate XML for any dialplans that have empty dialplan_xml
    $dialplans = new dialplan;
    $dialplans->source = "details";
    $dialplans->destination = "database";
    $dialplans->context = $domain_name;
    $dialplans->is_empty = "dialplan_xml";
    $dialplans->xml();

    // Count dialplans after import
    $sql = "SELECT COUNT(*) as cnt FROM v_dialplans WHERE domain_uuid = :domain_uuid";
    $after = $database->select($sql, array('domain_uuid' => $domain_uuid), 'row');
    $after_count = $after ? (int)$after['cnt'] : 0;
    $new_count = $after_count - $existing_count;

    // Get list of dialplans created
    $sql = "SELECT dialplan_name, dialplan_order, dialplan_enabled FROM v_dialplans WHERE domain_uuid = :domain_uuid ORDER BY dialplan_order, dialplan_name";
    $dialplan_list = $database->select($sql, array('domain_uuid' => $domain_uuid), 'all');

    // Clear the dialplan cache
    $cache = new cache;
    $cache->delete("dialplan:" . $domain_name);

    // Reload XML via ESL
    $esl = event_socket::create();
    if ($esl) {
        event_socket::api("reloadxml");
    }

    return array(
        'success' => true,
        'message' => $new_count . ' default dialplans created for ' . $domain_name,
        'domainName' => $domain_name,
        'previousCount' => $existing_count,
        'totalCount' => $after_count,
        'newCount' => $new_count,
        'dialplans' => $dialplan_list
    );
}
