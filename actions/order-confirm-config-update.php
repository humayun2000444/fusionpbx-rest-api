<?php
/**
 * order-confirm-config-update.php
 * Upserts the Order Confirmation config for a domain. Accepts camelCase keys
 * (only the provided keys are changed).
 */

$required_params = array();

function do_action($body) {
    global $domain_uuid;
    $d = isset($body->domain_uuid) ? $body->domain_uuid :
         (isset($body->domainUuid) ? $body->domainUuid : $domain_uuid);
    if (empty($d)) return array("success" => false, "error" => "domain_uuid is required");

    // camelCase -> column, with a type: b(ool) i(nt) s(tring)
    $map = array(
        'enabled' => array('enabled','b'),
        'defaultLanguage' => array('default_language','s'),
        'voiceGender' => array('voice_gender','s'),
        'messageTemplateEn' => array('message_template_en','s'),
        'messageTemplateBn' => array('message_template_bn','s'),
        'confirmTextEn' => array('confirm_text_en','s'),
        'confirmTextBn' => array('confirm_text_bn','s'),
        'cancelTextEn' => array('cancel_text_en','s'),
        'cancelTextBn' => array('cancel_text_bn','s'),
        'callerIdName' => array('caller_id_name','s'),
        'callerIdNumber' => array('caller_id_number','s'),
        'defaultSupportNumber' => array('default_support_number','s'),
        'callTimeout' => array('call_timeout','i'),
        'amdEnabled' => array('amd_enabled','b'),
        'defaultConfirmUrl' => array('default_confirm_url','s'),
        'callbackAuthType' => array('callback_auth_type','s'),
        'callbackAuthToken' => array('callback_auth_token','s'),
        'callbackHmacSecret' => array('callback_hmac_secret','s'),
        'callbackHmacHeader' => array('callback_hmac_header','s'),
        'callbackTimeout' => array('callback_timeout','i'),
        'retryEnabled' => array('retry_enabled','b'),
        'retryMax' => array('retry_max','i'),
        'retryInterval' => array('retry_interval','i'),
        'retryOnNoAnswer' => array('retry_on_no_answer','b'),
        'retryOnBusy' => array('retry_on_busy','b'),
        'retryOnVoicemail' => array('retry_on_voicemail','b'),
        'retryOnFailed' => array('retry_on_failed','b'),
        'callbackRetryMax' => array('callback_retry_max','i'),
        'callbackRetryInterval' => array('callback_retry_interval','i'),
        'ttsProvider' => array('tts_provider','s'),
        'speechRate' => array('speech_rate','s'),
        'answerDelayMs' => array('answer_delay_ms','i'),
        'ttsGoogleKey' => array('tts_google_key','s'),
        'ttsAzureKey' => array('tts_azure_key','s'),
        'ttsAzureRegion' => array('tts_azure_region','s'),
        'ttsElevenlabsKey' => array('tts_elevenlabs_key','s'),
        'ttsElevenlabsVoiceId' => array('tts_elevenlabs_voice_id','s'),
        'ttsElevenlabsModel' => array('tts_elevenlabs_model','s'),
        'ttsElevenlabsLanguage' => array('tts_elevenlabs_language','s'),
        'ttsOpenaiKey' => array('tts_openai_key','s'),
        'ttsOpenaiVoice' => array('tts_openai_voice','s'),
        'ackTextEn' => array('ack_text_en','s'),
        'ackTextBn' => array('ack_text_bn','s'),
    );

    $cols = array(); $params = array('domain_uuid' => $d);
    foreach ($map as $key => $spec) {
        if (!isset($body->$key)) continue;
        list($col, $type) = $spec;
        $v = $body->$key;
        if ($type === 'b') $v = ($v === true || $v === 'true' || $v === 1 || $v === '1') ? 'true' : 'false';
        elseif ($type === 'i') $v = intval($v);
        else $v = (string)$v;
        $cols[$col] = $v;
        $params[$col] = $v;
    }

    // dtmfOptions is a JSON array -> JSONB column (needs an explicit cast).
    $has_dtmf = isset($body->dtmfOptions) && is_array($body->dtmfOptions);
    $dtmf_json = $has_dtmf ? json_encode(array_values($body->dtmfOptions)) : null;

    if (empty($cols) && !$has_dtmf) return array("success" => false, "error" => "No fields to update");

    $database = new database;

    // Does a row exist?
    $exists = $database->select("SELECT config_uuid FROM v_order_confirm_config WHERE domain_uuid = :domain_uuid",
        array('domain_uuid' => $d), 'row');

    if (!$exists) {
        // Ensure a row exists first (defaults), then update below.
        $database->execute("INSERT INTO v_order_confirm_config (domain_uuid, insert_date) VALUES (:domain_uuid, NOW())",
            array('domain_uuid' => $d));
    }

    if (!empty($cols)) {
        $set = array();
        foreach ($cols as $col => $_) $set[] = "$col = :$col";
        $set[] = "update_date = NOW()";
        $sql = "UPDATE v_order_confirm_config SET " . implode(', ', $set) . " WHERE domain_uuid = :domain_uuid";
        if ($database->execute($sql, $params) === false)
            return array("success" => false, "error" => "Failed to save config");
    }

    if ($has_dtmf) {
        $database->execute("UPDATE v_order_confirm_config SET dtmf_options = CAST(:v AS JSONB), update_date = NOW() WHERE domain_uuid = :domain_uuid",
            array('v' => $dtmf_json, 'domain_uuid' => $d));
    }

    return array("success" => true, "message" => "Configuration saved");
}
