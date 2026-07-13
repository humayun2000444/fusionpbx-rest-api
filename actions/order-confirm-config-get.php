<?php
/**
 * order-confirm-config-get.php
 * Returns the Order Confirmation config for a domain (defaults if not saved yet).
 */

require_once __DIR__ . '/order-confirm-helper.php';

$required_params = array();

function do_action($body) {
    global $domain_uuid;
    $d = isset($body->domain_uuid) ? $body->domain_uuid :
         (isset($body->domainUuid) ? $body->domainUuid : $domain_uuid);
    if (empty($d)) return array("success" => false, "error" => "domain_uuid is required");

    $database = new database;
    try {
        $c = oc_get_config($database, $d);
    } catch (Exception $e) {
        return array("success" => false, "error" => "Schema not installed. Run order-confirm-install.", "schemaInstalled" => false);
    }

    $b = function ($v) { return ($v === true || $v === 'true' || $v === 't' || $v === '1'); };

    return array(
        "success" => true,
        "schemaInstalled" => true,
        "config" => array(
            "enabled"              => $b($c['enabled']),
            "defaultLanguage"      => $c['default_language'],
            "voiceGender"          => $c['voice_gender'],
            "messageTemplateEn"    => $c['message_template_en'],
            "messageTemplateBn"    => $c['message_template_bn'],
            "confirmTextEn"        => $c['confirm_text_en'],
            "confirmTextBn"        => $c['confirm_text_bn'],
            "cancelTextEn"         => $c['cancel_text_en'],
            "cancelTextBn"         => $c['cancel_text_bn'],
            "callerIdName"         => $c['caller_id_name'],
            "callerIdNumber"       => $c['caller_id_number'],
            "defaultSupportNumber" => $c['default_support_number'],
            "callTimeout"          => intval($c['call_timeout']),
            "amdEnabled"           => $b($c['amd_enabled']),
            "defaultConfirmUrl"    => $c['default_confirm_url'],
            "callbackAuthType"     => $c['callback_auth_type'],
            "callbackAuthToken"    => $c['callback_auth_token'],
            "callbackHmacSecret"   => $c['callback_hmac_secret'],
            "callbackHmacHeader"   => $c['callback_hmac_header'],
            "callbackTimeout"      => intval($c['callback_timeout']),
            "retryEnabled"         => $b($c['retry_enabled']),
            "retryMax"             => intval($c['retry_max']),
            "retryInterval"        => intval($c['retry_interval']),
            "retryOnNoAnswer"      => $b($c['retry_on_no_answer']),
            "retryOnBusy"          => $b($c['retry_on_busy']),
            "retryOnVoicemail"     => $b($c['retry_on_voicemail']),
            "retryOnFailed"        => $b($c['retry_on_failed']),
            "callbackRetryMax"     => intval($c['callback_retry_max']),
            "callbackRetryInterval"=> intval($c['callback_retry_interval']),
            "ttsProvider"          => isset($c['tts_provider']) ? $c['tts_provider'] : 'google',
            "speechRate"           => isset($c['speech_rate']) ? $c['speech_rate'] : 'slow',
            "answerDelayMs"        => isset($c['answer_delay_ms']) ? intval($c['answer_delay_ms']) : 2000,
            "ttsGoogleKey"         => isset($c['tts_google_key']) ? $c['tts_google_key'] : '',
            "ttsAzureKey"          => isset($c['tts_azure_key']) ? $c['tts_azure_key'] : '',
            "ttsAzureRegion"       => isset($c['tts_azure_region']) ? $c['tts_azure_region'] : 'southeastasia',
            "ttsElevenlabsKey"     => isset($c['tts_elevenlabs_key']) ? $c['tts_elevenlabs_key'] : '',
            "ttsElevenlabsVoiceId" => isset($c['tts_elevenlabs_voice_id']) ? $c['tts_elevenlabs_voice_id'] : '',
            "ttsElevenlabsModel"   => isset($c['tts_elevenlabs_model']) ? $c['tts_elevenlabs_model'] : 'eleven_multilingual_v2',
            "ttsElevenlabsLanguage"=> isset($c['tts_elevenlabs_language']) ? $c['tts_elevenlabs_language'] : '',
            "ttsOpenaiKey"         => isset($c['tts_openai_key']) ? $c['tts_openai_key'] : '',
            "ttsOpenaiVoice"       => isset($c['tts_openai_voice']) ? $c['tts_openai_voice'] : 'nova',
            "ackTextEn"            => isset($c['ack_text_en']) ? $c['ack_text_en'] : '',
            "ackTextBn"            => isset($c['ack_text_bn']) ? $c['ack_text_bn'] : '',
            // Industry-neutral labels: the dashboard renders these instead of the
            // hardcoded "Order ID" / "Customer" / "Order" so a hospital shows
            // "Appointment ID" / "Patient", a utility "Invoice" / "Recipient", etc.
            "referenceLabel"       => (isset($c['reference_label']) && $c['reference_label'] !== '') ? $c['reference_label'] : 'Order ID',
            "recipientLabel"       => (isset($c['recipient_label']) && $c['recipient_label'] !== '') ? $c['recipient_label'] : 'Customer',
            "entityLabel"          => (isset($c['entity_label']) && $c['entity_label'] !== '') ? $c['entity_label'] : 'Order',
            "dtmfOptions"          => (function($v){
                                          $a = is_array($v) ? $v : json_decode($v ?: '[]', true);
                                          return is_array($a) ? $a : array();
                                      })(isset($c['dtmf_options']) ? $c['dtmf_options'] : '[]'),
        ),
    );
}
