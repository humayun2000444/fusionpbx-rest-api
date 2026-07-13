<?php
/**
 * order-confirm-call.php
 *
 * WEBHOOK ENTRY POINT. An e-commerce backend calls this after an order is
 * placed. It creates a job and immediately dials the customer with an IVR
 * ("press 1 confirm, 2 cancel, 0 support"). The pressed digit is POSTed back
 * to the merchant's confirm_url by the background worker.
 *
 * Request body:
 *   domain_uuid     (required) tenant
 *   phone           (required) customer number to call
 *   order_id        (required) order reference
 *   customer_name   (optional) used in the greeting
 *   language        (optional) 'en' | 'bn'   (default: domain config)
 *   confirm_url     (optional) override the domain default callback URL
 *   support_number  (optional) override the "press 0" transfer target
 *   metadata        (optional) object echoed back in the callback
 */

require_once __DIR__ . '/order-confirm-helper.php';

$required_params = array("phone", "order_id");

function do_action($body) {
    global $domain_uuid;

    $db_domain_uuid = isset($body->domain_uuid) ? $body->domain_uuid :
                      (isset($body->domainUuid) ? $body->domainUuid : $domain_uuid);
    $phone    = isset($body->phone) ? trim($body->phone) : null;
    // Generic reference id. Accepts reference/ref (industry-neutral) as well as
    // order_id/orderId (legacy ecommerce) — all map to the same stored column, so
    // a hospital sends "reference": appointment-id, an ecommerce site "order_id".
    $order_id = isset($body->reference) ? trim($body->reference) :
                (isset($body->ref) ? trim($body->ref) :
                (isset($body->order_id) ? trim($body->order_id) :
                (isset($body->orderId) ? trim($body->orderId) : null)));

    if (empty($db_domain_uuid) || empty($phone) || empty($order_id)) {
        return array("success" => false, "error" => "domain_uuid, phone and reference (order_id) are required");
    }

    // Recipient name: recipient/contact (neutral) or customer_name (legacy).
    $name     = isset($body->recipient) ? $body->recipient :
                (isset($body->recipientName) ? $body->recipientName :
                (isset($body->customer_name) ? $body->customer_name :
                (isset($body->customerName) ? $body->customerName : '')));
    $language = isset($body->language) ? $body->language : null;
    $confirm_url    = isset($body->confirm_url) ? $body->confirm_url :
                      (isset($body->confirmUrl) ? $body->confirmUrl : '');
    $support_number = isset($body->support_number) ? $body->support_number :
                      (isset($body->supportNumber) ? $body->supportNumber : '');
    $metadata = isset($body->metadata) ? json_encode($body->metadata) : '{}';

    $database = new database;

    // Load config; if the domain has no language override on the request, use it.
    $config = oc_get_config($database, $db_domain_uuid);
    // Overlay any system-wide TTS keys the TelcoREST gateway injected (per-profile
    // application properties). Non-empty values win; blank ones keep domain config.
    $config = oc_apply_system_tts_keys($config, $body);
    if (empty($language)) $language = $config['default_language'] ?: 'en';
    if ($config['enabled'] === 'false' || $config['enabled'] === false || $config['enabled'] === 'f') {
        return array("success" => false, "error" => "Order confirmation calling is disabled for this domain");
    }

    $call_uuid = uuid();
    $max_attempts = ($config['retry_enabled'] === 'true' || $config['retry_enabled'] === true)
        ? max(1, intval($config['retry_max'])) : 1;

    $ok = $database->execute(
        "INSERT INTO v_order_confirm_calls
            (call_uuid, domain_uuid, order_id, customer_name, phone, language,
             confirm_url, support_number, metadata, status, max_attempts, next_attempt_date, insert_date)
         VALUES
            (:call_uuid, :domain_uuid, :order_id, :name, :phone, :language,
             :confirm_url, :support_number, CAST(:metadata AS JSONB), 'pending', :max_attempts, NOW(), NOW())",
        array(
            'call_uuid' => $call_uuid, 'domain_uuid' => $db_domain_uuid,
            'order_id' => $order_id, 'name' => $name, 'phone' => $phone, 'language' => $language,
            'confirm_url' => $confirm_url, 'support_number' => $support_number,
            'metadata' => $metadata, 'max_attempts' => $max_attempts,
        )
    );
    if ($ok === false) {
        return array("success" => false, "error" => "Failed to create call job (is the schema installed? run order-confirm-install.sql)");
    }

    // Place the call now. If ESL isn't reachable the worker will retry.
    $call = $database->select("SELECT * FROM v_order_confirm_calls WHERE call_uuid = :c",
        array('c' => $call_uuid), 'row');
    $res = oc_originate($database, $call, $config);

    return array(
        "success" => true,
        "message" => $res['ok'] ? "Call placed" : "Job queued (call will be retried by worker)",
        "callUuid" => $call_uuid,
        "orderId" => $order_id,
        "phone" => $phone,
        "language" => $language,
        "originated" => $res['ok'],
    );
}
