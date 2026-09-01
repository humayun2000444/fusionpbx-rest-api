<?php

$required_params = array("ring_group_uuid");

function do_action($body) {
    global $domain_uuid;

    $ring_group_uuid = $body->ring_group_uuid;

    // Get current ring group details
    $sql = "SELECT rg.*, d.domain_name FROM v_ring_groups rg
            JOIN v_domains d ON rg.domain_uuid = d.domain_uuid
            WHERE rg.ring_group_uuid = :ring_group_uuid";
    $parameters = array("ring_group_uuid" => $ring_group_uuid);

    $database = new database;
    $rg = $database->select($sql, $parameters, "row");

    if (!$rg) {
        return array("error" => "Ring group not found");
    }

    $rg_domain_uuid = $rg["domain_uuid"];
    $domain_name = $rg["domain_name"];
    $dialplan_uuid = $rg["dialplan_uuid"];

    // Get updated values or keep existing
    $ring_group_name = isset($body->ring_group_name) ? preg_replace("/[^A-Za-z0-9\- ]/", "", $body->ring_group_name) : $rg["ring_group_name"];
    $ring_group_extension = isset($body->ring_group_extension) ? $body->ring_group_extension : $rg["ring_group_extension"];
    $ring_group_greeting = isset($body->ring_group_greeting) ? $body->ring_group_greeting : $rg["ring_group_greeting"];

    // The greeting is stored as the recording FILENAME: find_file.lua looks it
    // up as <recordings_dir>/<domain>/<value>. A client that sends the
    // recording's display name instead produces a ring group that answers and
    // then plays nothing, which is exactly what happened on pbx-ls-1353. Map a
    // name onto its filename before saving, scoped to this ring group's own
    // domain so one tenant cannot point at another tenant's audio.
    if (!empty($ring_group_greeting) && is_string($ring_group_greeting)) {
        $rg_greeting = trim($ring_group_greeting);
        if ($rg_greeting !== '' && strpos($rg_greeting, '/') === false) {
            $rg_is_filename = $database->select(
                "SELECT recording_uuid FROM v_recordings "
                ."WHERE domain_uuid = :domain_uuid AND recording_filename = :greeting LIMIT 1",
                array("domain_uuid" => $rg_domain_uuid, "greeting" => $rg_greeting), "row");
            if (empty($rg_is_filename)) {
                $rg_by_name = $database->select(
                    "SELECT recording_filename FROM v_recordings "
                    ."WHERE domain_uuid = :domain_uuid AND recording_name = :greeting LIMIT 1",
                    array("domain_uuid" => $rg_domain_uuid, "greeting" => $rg_greeting), "row");
                if (!empty($rg_by_name['recording_filename'])) {
                    $ring_group_greeting = $rg_by_name['recording_filename'];
                }
            }
        }
    }

    $ring_group_strategy = isset($body->ring_group_strategy) ? $body->ring_group_strategy : $rg["ring_group_strategy"];
    $ring_group_call_timeout = isset($body->ring_group_call_timeout) ? (int)$body->ring_group_call_timeout : $rg["ring_group_call_timeout"];
    $ring_group_forward_destination = isset($body->ring_group_forward_destination) ? $body->ring_group_forward_destination : $rg["ring_group_forward_destination"];
    $ring_group_caller_id_name = isset($body->ring_group_caller_id_name) ? $body->ring_group_caller_id_name : $rg["ring_group_caller_id_name"];
    $ring_group_caller_id_number = isset($body->ring_group_caller_id_number) ? $body->ring_group_caller_id_number : $rg["ring_group_caller_id_number"];
    $ring_group_cid_name_prefix = isset($body->ring_group_cid_name_prefix) ? $body->ring_group_cid_name_prefix : $rg["ring_group_cid_name_prefix"];
    $ring_group_cid_number_prefix = isset($body->ring_group_cid_number_prefix) ? $body->ring_group_cid_number_prefix : $rg["ring_group_cid_number_prefix"];
    $ring_group_timeout_app = isset($body->ring_group_timeout_app) ? $body->ring_group_timeout_app : $rg["ring_group_timeout_app"];
    $ring_group_timeout_data = isset($body->ring_group_timeout_data) ? $body->ring_group_timeout_data : $rg["ring_group_timeout_data"];
    $ring_group_distinctive_ring = isset($body->ring_group_distinctive_ring) ? $body->ring_group_distinctive_ring : $rg["ring_group_distinctive_ring"];
    $ring_group_ringback = isset($body->ring_group_ringback) ? $body->ring_group_ringback : $rg["ring_group_ringback"];
    $ring_group_missed_call_app = isset($body->ring_group_missed_call_app) ? $body->ring_group_missed_call_app : $rg["ring_group_missed_call_app"];
    $ring_group_missed_call_data = isset($body->ring_group_missed_call_data) ? $body->ring_group_missed_call_data : $rg["ring_group_missed_call_data"];
    $ring_group_description = isset($body->ring_group_description) ? $body->ring_group_description : $rg["ring_group_description"];
    $ring_group_forward_toll_allow = isset($body->ring_group_forward_toll_allow) ? $body->ring_group_forward_toll_allow : $rg["ring_group_forward_toll_allow"];
    $ring_group_context = $domain_name;

    // Normalise the timeout destination into exactly what the FusionPBX GUI
    // writes: "transfer" + "<number> XML <domain_name>". The portal only holds
    // the domain UUID, never the domain name, so it sends the bare number and
    // the context is appended here.
    //
    // Note "voicemail" is NOT a valid app: FusionPBX routes voicemail through
    // the global *99[ext] dialplan and a Lua app, and mod_voicemail is not
    // loaded. Executing it directly drops the caller with
    // DESTINATION_OUT_OF_ORDER, which is what ring group 8000 was doing.
    if (!empty($ring_group_timeout_app) && $ring_group_timeout_app === 'voicemail') {
        $vm_box = trim((string)$ring_group_timeout_data);
        if ($vm_box === '') {
            return array("success" => false,
                "error" => "A voicemail timeout destination needs a mailbox number");
        }
        $ring_group_timeout_app  = 'transfer';
        $ring_group_timeout_data = '*' . '99' . preg_replace('/\D/', '', $vm_box);
    }

    if (!empty($ring_group_timeout_app) && $ring_group_timeout_app === 'transfer') {
        $rg_to = trim((string)$ring_group_timeout_data);
        if ($rg_to === '') {
            return array("success" => false,
                "error" => "A transfer timeout destination needs a number");
        }
        // Validate a *99 mailbox actually exists in this domain, so the ring
        // group cannot time out into a box that was never created.
        if (preg_match('/^\*99(\d+)/', $rg_to, $vm_m)) {
            $vm_row = $database->select(
                "SELECT voicemail_uuid FROM v_voicemails "
                ."WHERE domain_uuid = :domain_uuid AND voicemail_id = :vm_id "
                ."AND voicemail_enabled = 'true' LIMIT 1",
                array("domain_uuid" => $rg_domain_uuid, "vm_id" => $vm_m[1]), "row");
            if (empty($vm_row)) {
                return array("success" => false,
                    "error" => "No enabled voicemail box for extension " . $vm_m[1]);
            }
        }
        if (stripos($rg_to, ' XML ') === false) {
            $rg_to = $rg_to . ' XML ' . $domain_name;
        }
        $ring_group_timeout_data = $rg_to;
    }


    // Handle boolean fields
    if (isset($body->ring_group_forward_enabled)) {
        $ring_group_forward_enabled = ($body->ring_group_forward_enabled === true || $body->ring_group_forward_enabled === "true") ? "true" : "false";
    } else {
        $ring_group_forward_enabled = $rg["ring_group_forward_enabled"];
    }

    if (isset($body->ring_group_call_screen_enabled)) {
        $ring_group_call_screen_enabled = ($body->ring_group_call_screen_enabled === true || $body->ring_group_call_screen_enabled === "true") ? "true" : "false";
    } else {
        $ring_group_call_screen_enabled = $rg["ring_group_call_screen_enabled"];
    }

    if (isset($body->ring_group_call_forward_enabled)) {
        $ring_group_call_forward_enabled = ($body->ring_group_call_forward_enabled === true || $body->ring_group_call_forward_enabled === "true") ? "true" : "false";
    } else {
        $ring_group_call_forward_enabled = $rg["ring_group_call_forward_enabled"];
    }

    if (isset($body->ring_group_follow_me_enabled)) {
        $ring_group_follow_me_enabled = ($body->ring_group_follow_me_enabled === true || $body->ring_group_follow_me_enabled === "true") ? "true" : "false";
    } else {
        $ring_group_follow_me_enabled = $rg["ring_group_follow_me_enabled"];
    }

    if (isset($body->ring_group_enabled)) {
        $ring_group_enabled = ($body->ring_group_enabled === true || $body->ring_group_enabled === "true") ? "true" : "false";
    } else {
        $ring_group_enabled = $rg["ring_group_enabled"];
    }

    // Build the dialplan XML
    $dialplan_xml = "<extension name=\"" . htmlspecialchars($ring_group_name) . "\" continue=\"\" uuid=\"" . $dialplan_uuid . "\">\n";
    $dialplan_xml .= "\t<condition field=\"destination_number\" expression=\"^" . htmlspecialchars($ring_group_extension) . "$\">\n";
    $dialplan_xml .= "\t\t<action application=\"ring_ready\" data=\"\"/>\n";
    $dialplan_xml .= "\t\t<action application=\"set\" data=\"ring_group_uuid=" . $ring_group_uuid . "\"/>\n";
    $dialplan_xml .= "\t\t<action application=\"lua\" data=\"app.lua ring_groups\"/>\n";
    $dialplan_xml .= "\t</condition>\n";
    $dialplan_xml .= "</extension>\n";

    // Update ring group record using direct SQL
    $sql = "UPDATE v_ring_groups SET
            ring_group_name = :ring_group_name,
            ring_group_extension = :ring_group_extension,
            ring_group_greeting = :ring_group_greeting,
            ring_group_context = :ring_group_context,
            ring_group_strategy = :ring_group_strategy,
            ring_group_call_timeout = :ring_group_call_timeout,
            ring_group_forward_destination = :ring_group_forward_destination,
            ring_group_forward_enabled = :ring_group_forward_enabled,
            ring_group_caller_id_name = :ring_group_caller_id_name,
            ring_group_caller_id_number = :ring_group_caller_id_number,
            ring_group_cid_name_prefix = :ring_group_cid_name_prefix,
            ring_group_cid_number_prefix = :ring_group_cid_number_prefix,
            ring_group_timeout_app = :ring_group_timeout_app,
            ring_group_timeout_data = :ring_group_timeout_data,
            ring_group_distinctive_ring = :ring_group_distinctive_ring,
            ring_group_ringback = :ring_group_ringback,
            ring_group_call_screen_enabled = :ring_group_call_screen_enabled,
            ring_group_call_forward_enabled = :ring_group_call_forward_enabled,
            ring_group_follow_me_enabled = :ring_group_follow_me_enabled,
            ring_group_missed_call_app = :ring_group_missed_call_app,
            ring_group_missed_call_data = :ring_group_missed_call_data,
            ring_group_enabled = :ring_group_enabled,
            ring_group_description = :ring_group_description,
            ring_group_forward_toll_allow = :ring_group_forward_toll_allow,
            update_date = NOW()
            WHERE ring_group_uuid = :ring_group_uuid";

    $parameters = array();
    $parameters["ring_group_uuid"] = $ring_group_uuid;
    $parameters["ring_group_name"] = $ring_group_name;
    $parameters["ring_group_extension"] = $ring_group_extension;
    $parameters["ring_group_greeting"] = $ring_group_greeting;
    $parameters["ring_group_context"] = $ring_group_context;
    $parameters["ring_group_strategy"] = $ring_group_strategy;
    $parameters["ring_group_call_timeout"] = $ring_group_call_timeout;
    $parameters["ring_group_forward_destination"] = $ring_group_forward_destination;
    $parameters["ring_group_forward_enabled"] = $ring_group_forward_enabled;
    $parameters["ring_group_caller_id_name"] = $ring_group_caller_id_name;
    $parameters["ring_group_caller_id_number"] = $ring_group_caller_id_number;
    $parameters["ring_group_cid_name_prefix"] = $ring_group_cid_name_prefix;
    $parameters["ring_group_cid_number_prefix"] = $ring_group_cid_number_prefix;
    $parameters["ring_group_timeout_app"] = $ring_group_timeout_app;
    $parameters["ring_group_timeout_data"] = $ring_group_timeout_data;
    $parameters["ring_group_distinctive_ring"] = $ring_group_distinctive_ring;
    $parameters["ring_group_ringback"] = $ring_group_ringback;
    $parameters["ring_group_call_screen_enabled"] = $ring_group_call_screen_enabled;
    $parameters["ring_group_call_forward_enabled"] = $ring_group_call_forward_enabled;
    $parameters["ring_group_follow_me_enabled"] = $ring_group_follow_me_enabled;
    $parameters["ring_group_missed_call_app"] = $ring_group_missed_call_app;
    $parameters["ring_group_missed_call_data"] = $ring_group_missed_call_data;
    $parameters["ring_group_enabled"] = $ring_group_enabled;
    $parameters["ring_group_description"] = $ring_group_description;
    $parameters["ring_group_forward_toll_allow"] = $ring_group_forward_toll_allow;

    $database = new database;
    $database->execute($sql, $parameters);
    unset($parameters);


    // Replace the destination list when the client sends one. The GUI edits
    // destinations inline, so the REST update has to as well - without this the
    // portal could only offer "delete the ring group and create a new one".
    // Omitting "destinations" entirely leaves the existing rows untouched, so
    // callers that only change a name or timeout are unaffected.
    if (isset($body->destinations) && is_array($body->destinations)) {
        $rg_dests = array();
        foreach ($body->destinations as $dest) {
            if (is_object($dest)) { $dest = (array) $dest; }

            $dest_number = null;
            if (isset($dest['destination_number'])) {
                $dest_number = $dest['destination_number'];
            } elseif (isset($dest['destinationNumber'])) {
                $dest_number = $dest['destinationNumber'];
            }
            if (empty($dest_number)) { continue; }

            $dest_delay = 0;
            if (isset($dest['destination_delay'])) {
                $dest_delay = (int)$dest['destination_delay'];
            } elseif (isset($dest['destinationDelay'])) {
                $dest_delay = (int)$dest['destinationDelay'];
            }

            $dest_timeout = 30;
            if (isset($dest['destination_timeout'])) {
                $dest_timeout = (int)$dest['destination_timeout'];
            } elseif (isset($dest['destinationTimeout'])) {
                $dest_timeout = (int)$dest['destinationTimeout'];
            }

            $dest_enabled = "true";
            if (isset($dest['destination_enabled'])) {
                $dest_enabled = $dest['destination_enabled'] ? "true" : "false";
            } elseif (isset($dest['destinationEnabled'])) {
                $dest_enabled = $dest['destinationEnabled'] ? "true" : "false";
            }

            // destination_prompt is numeric: null or a number, never an empty string
            $dest_prompt = null;
            if (isset($dest['destination_prompt']) && $dest['destination_prompt'] !== '' && $dest['destination_prompt'] !== null) {
                $dest_prompt = (int)$dest['destination_prompt'];
            } elseif (isset($dest['destinationPrompt']) && $dest['destinationPrompt'] !== '' && $dest['destinationPrompt'] !== null) {
                $dest_prompt = (int)$dest['destinationPrompt'];
            }

            $dest_description = null;
            if (isset($dest['destination_description']) && $dest['destination_description'] !== '') {
                $dest_description = $dest['destination_description'];
            } elseif (isset($dest['destinationDescription']) && $dest['destinationDescription'] !== '') {
                $dest_description = $dest['destinationDescription'];
            }

            $rg_dests[] = array(
                "number" => $dest_number, "delay" => $dest_delay,
                "timeout" => $dest_timeout, "enabled" => $dest_enabled,
                "prompt" => $dest_prompt, "description" => $dest_description,
            );
        }

        // A ring group with no destinations answers and then drops the caller,
        // so refuse an empty list rather than writing one.
        if (count($rg_dests) === 0) {
            return array("success" => false, "error" => "At least one destination is required");
        }

        $database = new database;
        $database->execute(
            "DELETE FROM v_ring_group_destinations "
            ."WHERE ring_group_uuid = :ring_group_uuid AND domain_uuid = :domain_uuid",
            array("ring_group_uuid" => $ring_group_uuid, "domain_uuid" => $rg_domain_uuid));

        foreach ($rg_dests as $d) {
            $database->execute(
                "INSERT INTO v_ring_group_destinations ("
                ."ring_group_destination_uuid, domain_uuid, ring_group_uuid, "
                ."destination_number, destination_delay, destination_timeout, "
                ."destination_enabled, destination_prompt, destination_description, insert_date"
                .") VALUES ("
                .":dest_uuid, :domain_uuid, :ring_group_uuid, "
                .":destination_number, :destination_delay, :destination_timeout, "
                .":destination_enabled, :destination_prompt, :destination_description, NOW())",
                array(
                    "dest_uuid" => uuid(),
                    "domain_uuid" => $rg_domain_uuid,
                    "ring_group_uuid" => $ring_group_uuid,
                    "destination_number" => $d["number"],
                    "destination_delay" => $d["delay"],
                    "destination_timeout" => $d["timeout"],
                    "destination_enabled" => $d["enabled"],
                    "destination_prompt" => $d["prompt"],
                    "destination_description" => $d["description"],
                ));
        }
        unset($parameters);
    }

    // Update dialplan record using direct SQL
    $sql = "UPDATE v_dialplans SET
            dialplan_name = :dialplan_name,
            dialplan_number = :dialplan_number,
            dialplan_context = :dialplan_context,
            dialplan_xml = :dialplan_xml,
            dialplan_enabled = :dialplan_enabled,
            dialplan_description = :dialplan_description,
            update_date = NOW()
            WHERE dialplan_uuid = :dialplan_uuid";

    $parameters = array();
    $parameters["dialplan_uuid"] = $dialplan_uuid;
    $parameters["dialplan_name"] = $ring_group_name;
    $parameters["dialplan_number"] = $ring_group_extension;
    $parameters["dialplan_context"] = $ring_group_context;
    $parameters["dialplan_xml"] = $dialplan_xml;
    $parameters["dialplan_enabled"] = $ring_group_enabled;
    $parameters["dialplan_description"] = $ring_group_description;

    $database = new database;
    $database->execute($sql, $parameters);
    unset($parameters);

    // Clear the dialplan cache
    if (class_exists('cache')) {
        $cache = new cache;
        $cache->delete("dialplan:" . $domain_name);
    }

    return array(
        "success" => true,
        "message" => "Ring group updated successfully",
        "ringGroupUuid" => $ring_group_uuid,
        "ringGroupName" => $ring_group_name,
        "ringGroupExtension" => $ring_group_extension,
        "ringGroupStrategy" => $ring_group_strategy,
        "ringGroupEnabled" => $ring_group_enabled === "true"
    );
}
