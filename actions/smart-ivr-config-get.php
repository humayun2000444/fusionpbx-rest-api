<?php
/*
 * Smart IVR - Get Configuration
 * Returns Smart IVR configuration for a domain
 */

$required_params = array();

function do_action($body) {
    global $domain_uuid;

    // Get domain_uuid from request or use global
    $req_domain_uuid = isset($body->domain_uuid) ? $body->domain_uuid : $domain_uuid;

    if (empty($req_domain_uuid)) {
        return array('error' => 'domain_uuid is required');
    }

    $database = new database;

    // Get configuration
    $sql = "SELECT * FROM v_smart_ivr_config WHERE domain_uuid = :domain_uuid LIMIT 1";
    $params = array(':domain_uuid' => $req_domain_uuid);
    $result = $database->select($sql, $params, 'row');

    if ($result) {
        return array(
            'success' => true,
            'config' => $result
        );
    } else {
        // Create default config if not exists
        $config_uuid = uuid();
        $sql = "INSERT INTO v_smart_ivr_config (
            smart_ivr_config_uuid,
            domain_uuid,
            enabled,
            google_tts_enabled,
            google_tts_language,
            welcome_message,
            goodbye_message,
            insert_date
        ) VALUES (
            :config_uuid,
            :domain_uuid,
            FALSE,
            TRUE,
            'en-US',
            'Welcome to Smart Student Information System',
            'Thank you for calling. Goodbye.',
            NOW()
        )";
        $params = array(
            ':config_uuid' => $config_uuid,
            ':domain_uuid' => $req_domain_uuid
        );
        $database->execute($sql, $params);

        return array(
            'success' => true,
            'message' => 'Default configuration created',
            'config' => array(
                'smart_ivr_config_uuid' => $config_uuid,
                'domain_uuid' => $req_domain_uuid,
                'enabled' => false,
                'google_tts_enabled' => true,
                'google_tts_language' => 'en-US'
            )
        );
    }
}
