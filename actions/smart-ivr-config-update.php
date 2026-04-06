<?php
/*
 * Smart IVR - Update Configuration
 * Enable/disable Smart IVR and update settings
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

    // Build update fields
    $update_fields = array();
    $params = array(':domain_uuid' => $req_domain_uuid);

    if (isset($body->enabled)) {
        $update_fields[] = "enabled = :enabled";
        $params[':enabled'] = $body->enabled ? 'TRUE' : 'FALSE';
    }

    if (isset($body->hotline_number)) {
        $update_fields[] = "hotline_number = :hotline_number";
        $params[':hotline_number'] = $body->hotline_number;
    }

    if (isset($body->backend_api_url)) {
        $update_fields[] = "backend_api_url = :backend_api_url";
        $params[':backend_api_url'] = $body->backend_api_url;
    }

    if (isset($body->backend_api_key)) {
        $update_fields[] = "backend_api_key = :backend_api_key";
        $params[':backend_api_key'] = $body->backend_api_key;
    }

    if (isset($body->google_tts_enabled)) {
        $update_fields[] = "google_tts_enabled = :google_tts_enabled";
        $params[':google_tts_enabled'] = $body->google_tts_enabled ? 'TRUE' : 'FALSE';
    }

    if (isset($body->google_tts_language)) {
        $update_fields[] = "google_tts_language = :google_tts_language";
        $params[':google_tts_language'] = $body->google_tts_language;
    }

    if (isset($body->google_tts_voice_name)) {
        $update_fields[] = "google_tts_voice_name = :google_tts_voice_name";
        $params[':google_tts_voice_name'] = $body->google_tts_voice_name;
    }

    if (isset($body->google_tts_voice_gender)) {
        $update_fields[] = "google_tts_voice_gender = :google_tts_voice_gender";
        $params[':google_tts_voice_gender'] = $body->google_tts_voice_gender;
    }

    if (isset($body->welcome_message)) {
        $update_fields[] = "welcome_message = :welcome_message";
        $params[':welcome_message'] = $body->welcome_message;
    }

    if (isset($body->goodbye_message)) {
        $update_fields[] = "goodbye_message = :goodbye_message";
        $params[':goodbye_message'] = $body->goodbye_message;
    }

    if (empty($update_fields)) {
        return array('error' => 'No fields to update');
    }

    // Update configuration
    $update_fields[] = "update_date = NOW()";
    $sql = "UPDATE v_smart_ivr_config SET " . implode(', ', $update_fields) . " WHERE domain_uuid = :domain_uuid";

    $result = $database->execute($sql, $params);

    if ($result) {
        // Get updated config
        $sql = "SELECT * FROM v_smart_ivr_config WHERE domain_uuid = :domain_uuid LIMIT 1";
        $config = $database->select($sql, array(':domain_uuid' => $req_domain_uuid), 'row');

        return array(
            'success' => true,
            'message' => 'Smart IVR configuration updated successfully',
            'config' => $config
        );
    } else {
        return array(
            'success' => false,
            'error' => 'Failed to update configuration'
        );
    }
}
