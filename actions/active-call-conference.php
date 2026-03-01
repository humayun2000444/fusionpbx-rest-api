<?php
/**
 * active-call-conference.php
 * Creates a 3-way conference: transfers current call into ad-hoc conference
 * and originates a new call to the third party into the same conference
 */

$required_params = array("call_uuid", "destination");

function do_action($body) {
    $call_uuid = isset($body->call_uuid) ? $body->call_uuid : (isset($body->uuid) ? $body->uuid : null);
    $destination = isset($body->destination) ? $body->destination : null;
    $domain_uuid = isset($body->domain_uuid) ? $body->domain_uuid : null;

    if (empty($call_uuid)) {
        return array("error" => "Call UUID is required");
    }

    if (empty($destination)) {
        return array("error" => "Destination is required");
    }

    if (!preg_match("/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i", $call_uuid)) {
        return array("error" => "Invalid call UUID format");
    }

    try {
        $domain_name = "";
        if ($domain_uuid) {
            $database = new database;
            $sql = "SELECT domain_name FROM v_domains WHERE domain_uuid = :domain_uuid";
            $result = $database->select($sql, array("domain_uuid" => $domain_uuid), "column");
            $domain_name = $result ? $result : "";
        }

        $event_socket_ip = isset($_SESSION["event_socket_ip_address"]) ? $_SESSION["event_socket_ip_address"] : "127.0.0.1";
        $event_socket_port = isset($_SESSION["event_socket_port"]) ? $_SESSION["event_socket_port"] : "8021";
        $event_socket_password = isset($_SESSION["event_socket_password"]) ? $_SESSION["event_socket_password"] : "ClueCon";

        if (function_exists("event_socket_create")) {
            $fp = @event_socket_create($event_socket_ip, $event_socket_port, $event_socket_password);

            if ($fp && is_resource($fp)) {
                $exists_result = event_socket_request($fp, "api uuid_exists " . $call_uuid);
                if (trim($exists_result) !== "true") {
                    fclose($fp);
                    return array("error" => "Call not found or no longer active", "uuid" => $call_uuid);
                }

                $cid_cmd = "uuid_getvar " . $call_uuid . " effective_caller_id_number";
                $cid = trim(event_socket_request($fp, "api " . $cid_cmd));
                if (empty($cid) || strpos($cid, "-ERR") !== false) {
                    $cid = "Conference";
                }

                $conf_name = "conf_" . substr(str_replace("-", "", $call_uuid), 0, 8);

                $transfer_cmd = "uuid_transfer " . $call_uuid . " conference:" . $conf_name . " inline";
                $transfer_result = event_socket_request($fp, "api " . $transfer_cmd);

                usleep(500000);

                $domain_suffix = !empty($domain_name) ? "@" . $domain_name : "";
                $originate_cmd = "originate {origination_caller_id_number=" . $cid . ",originate_timeout=30}user/" . $destination . $domain_suffix . " &conference(" . $conf_name . ")";
                $originate_result = event_socket_request($fp, "api " . $originate_cmd);

                fclose($fp);

                $success = (strpos($originate_result, "+OK") !== false);

                return array(
                    "success" => $success,
                    "message" => $success ? "Conference created successfully" : "Failed to add third party",
                    "uuid" => $call_uuid,
                    "conference" => $conf_name,
                    "destination" => $destination,
                    "transfer_result" => trim($transfer_result),
                    "originate_result" => trim($originate_result)
                );
            }
        }

        return array("error" => "Could not connect to FreeSWITCH event socket");

    } catch (Exception $e) {
        return array("error" => "Error creating conference: " . $e->getMessage());
    }
}
?>
