<?php
/**
 * Delete a callback configuration.
 *
 * Refuses while callbacks raised by this config are still pending or in
 * flight. Deleting the config would orphan them: v_callback_queue rows keep a
 * callback_config_uuid that would no longer resolve, and the worker would have
 * no retry policy to apply to a customer who is still waiting for a call.
 * Cancel them first, deliberately, or disable the config instead.
 *
 * Scoped by domain, like the other actions in this family.
 */
require_once(__DIR__ . '/callback-helper.php');

$required_params = array("callbackConfigUuid");

function do_action($body) {
    global $domain_uuid;
    ensure_callback_tables_exist();
    $database = new database;

    $req_domain_uuid = isset($body->domainUuid) ? $body->domainUuid : $domain_uuid;
    $uuid = $body->callbackConfigUuid;

    $sql = "SELECT * FROM v_callback_configs WHERE callback_config_uuid = :uuid";
    $params = array("uuid" => $uuid);
    if (!empty($req_domain_uuid)) {
        $sql .= " AND domain_uuid = :domain_uuid";
        $params["domain_uuid"] = $req_domain_uuid;
    }
    $config = $database->select($sql, $params, 'row');
    if (!$config) {
        return array("success" => false, "error" => "Callback configuration not found");
    }

    $live = $database->select(
        "SELECT COUNT(*) AS n FROM v_callback_queue
          WHERE callback_config_uuid = :uuid AND status IN ('pending','calling')",
        array("uuid" => $uuid), 'row');
    $n = (int) (is_array($live) ? $live['n'] : 0);
    if ($n > 0) {
        return array(
            "success" => false,
            "error"   => "$n callback(s) from this configuration are still pending or in progress. "
                       . "Cancel them first, or disable the configuration instead of deleting it.",
            "pendingCallbacks" => $n);
    }

    $database->execute(
        "DELETE FROM v_callback_configs WHERE callback_config_uuid = :uuid",
        array("uuid" => $uuid));

    return array(
        "success" => true,
        "message" => "Callback configuration \"" . $config['config_name'] . "\" deleted",
        "callbackConfigUuid" => $uuid);
}
?>
