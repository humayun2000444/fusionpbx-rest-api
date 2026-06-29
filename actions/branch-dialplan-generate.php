<?php

// Generate inter-branch dialplans for all members of a branch group
// Each domain gets dialplan entries to route calls to other branch domains via prefix

$required_params = array("branchGroupUuid");

define('BRANCH_APP_UUID', 'b0555ec4-e7a4-4000-b0a4-000000000001');

function do_action($body) {
    global $domain_uuid;

    $group_uuid = isset($body->branchGroupUuid) ? $body->branchGroupUuid :
                  (isset($body->branch_group_uuid) ? $body->branch_group_uuid : null);

    if (empty($group_uuid)) {
        return array("success" => false, "error" => "branchGroupUuid is required");
    }

    $database = new database;

    // Get branch group
    $group = $database->select(
        "SELECT * FROM v_branch_groups WHERE branch_group_uuid = :uuid",
        array("uuid" => $group_uuid), "row"
    );
    if (empty($group)) {
        return array("success" => false, "error" => "Branch group not found");
    }

    // Get all enabled members
    $members = $database->select(
        "SELECT bm.*, d.domain_name FROM v_branch_members bm
         JOIN v_domains d ON d.domain_uuid = bm.domain_uuid
         WHERE bm.branch_group_uuid = :group_uuid
         AND bm.branch_member_enabled = 'true'
         ORDER BY bm.branch_prefix",
        array("group_uuid" => $group_uuid), "all"
    );

    if (!is_array($members) || count($members) < 2) {
        return array("success" => false, "error" => "At least 2 enabled branch members are needed to generate dialplans");
    }

    $generated = array();
    $errors = array();

    // For each member domain, create dialplan rules to reach ALL other member domains
    foreach ($members as $source) {
        $source_domain_uuid = $source['domain_uuid'];
        $source_domain_name = $source['domain_name'];

        // First, remove old inter-branch dialplans for this domain
        $database->execute(
            "DELETE FROM v_dialplan_details WHERE dialplan_uuid IN
                (SELECT dialplan_uuid FROM v_dialplans
                 WHERE domain_uuid = :domain_uuid AND app_uuid = :app_uuid)",
            array("domain_uuid" => $source_domain_uuid, "app_uuid" => BRANCH_APP_UUID)
        );
        $database->execute(
            "DELETE FROM v_dialplans WHERE domain_uuid = :domain_uuid AND app_uuid = :app_uuid",
            array("domain_uuid" => $source_domain_uuid, "app_uuid" => BRANCH_APP_UUID)
        );

        // Build dialplan XML with routes to each OTHER branch
        $xml = '';
        $detail_order = 10;

        foreach ($members as $target) {
            if ($target['domain_uuid'] === $source_domain_uuid) {
                continue; // Skip self
            }

            $prefix = $target['branch_prefix'];
            $target_domain = $target['domain_name'];
            $target_label = $target['branch_label'];

            // Pattern: prefix + 3-5 digit extension (e.g., 20 + 1001 = 201001)
            $pattern = '^' . $prefix . '(\d{3,5})$';

            $xml .= '<extension name="Branch: ' . $target_label . ' (' . $prefix . 'xxxx)" continue="false">' . "\n";
            $xml .= '  <condition field="destination_number" expression="' . $pattern . '">' . "\n";
            $xml .= '    <action application="set" data="domain_name=' . $target_domain . '"/>' . "\n";
            $xml .= '    <action application="set" data="domain_uuid=' . $target['domain_uuid'] . '"/>' . "\n";
            $xml .= '    <action application="set" data="hangup_after_bridge=true"/>' . "\n";
            $xml .= '    <action application="set" data="continue_on_fail=false"/>' . "\n";
            $xml .= '    <action application="set" data="effective_caller_id_name=[' . $source['branch_label'] . '] ${caller_id_name}"/>' . "\n";
            $xml .= '    <action application="bridge" data="user/$1@' . $target_domain . '"/>' . "\n";
            $xml .= '  </condition>' . "\n";
            $xml .= '</extension>' . "\n";
        }

        if (empty($xml)) continue;

        // Insert the dialplan
        $dialplan_uuid = uuid();
        $dialplan_name = 'Inter-Branch: ' . $group['branch_group_name'];

        $dp_sql = "INSERT INTO v_dialplans (
                dialplan_uuid, domain_uuid, app_uuid, dialplan_name,
                dialplan_context, dialplan_continue, dialplan_order, dialplan_enabled,
                dialplan_description, dialplan_xml, insert_date, insert_user
            ) VALUES (
                :dialplan_uuid, :domain_uuid, :app_uuid, :dialplan_name,
                :dialplan_context, 'false', :dialplan_order, 'true',
                :dialplan_description, :dialplan_xml, NOW(), :insert_user
            )";

        $dp_params = array(
            "dialplan_uuid" => $dialplan_uuid,
            "domain_uuid" => $source_domain_uuid,
            "app_uuid" => BRANCH_APP_UUID,
            "dialplan_name" => $dialplan_name,
            "dialplan_context" => $source_domain_name,
            "dialplan_order" => "310",
            "dialplan_description" => "Auto-generated inter-branch routing for " . $group['branch_group_name'],
            "dialplan_xml" => $xml,
            "insert_user" => $domain_uuid
        );

        try {
            $result = $database->execute($dp_sql, $dp_params);
            if ($result === false) {
                $errors[] = "Failed to insert dialplan for domain: " . $source_domain_name;
                continue;
            }

            // Also insert dialplan details for FusionPBX UI compatibility
            $detail_order = 10;
            foreach ($members as $target) {
                if ($target['domain_uuid'] === $source_domain_uuid) continue;

                $prefix = $target['branch_prefix'];
                $detail_uuid = uuid();
                $pattern = '^' . $prefix . '(\d{3,5})$';

                // Condition: destination_number match
                $database->execute(
                    "INSERT INTO v_dialplan_details (dialplan_detail_uuid, dialplan_uuid, domain_uuid,
                        dialplan_detail_tag, dialplan_detail_type, dialplan_detail_data,
                        dialplan_detail_group, dialplan_detail_order)
                     VALUES (:uuid, :dp_uuid, :domain_uuid, 'condition', 'destination_number', :pattern,
                        :grp, :ord)",
                    array(
                        "uuid" => $detail_uuid,
                        "dp_uuid" => $dialplan_uuid,
                        "domain_uuid" => $source_domain_uuid,
                        "pattern" => $pattern,
                        "grp" => $detail_order,
                        "ord" => "10"
                    )
                );

                // Action: bridge to target domain
                $database->execute(
                    "INSERT INTO v_dialplan_details (dialplan_detail_uuid, dialplan_uuid, domain_uuid,
                        dialplan_detail_tag, dialplan_detail_type, dialplan_detail_data,
                        dialplan_detail_group, dialplan_detail_order)
                     VALUES (:uuid, :dp_uuid, :domain_uuid, 'action', 'bridge', :data,
                        :grp, :ord)",
                    array(
                        "uuid" => uuid(),
                        "dp_uuid" => $dialplan_uuid,
                        "domain_uuid" => $source_domain_uuid,
                        "data" => 'user/$1@' . $target['domain_name'],
                        "grp" => $detail_order,
                        "ord" => "20"
                    )
                );

                $detail_order += 10;
            }

            $generated[] = array(
                "domainName" => $source_domain_name,
                "dialplanUuid" => $dialplan_uuid,
                "routeCount" => count($members) - 1
            );

            // Clear dialplan cache for this domain
            $cache_file = '/var/cache/fusionpbx/dialplan.' . $source_domain_name;
            if (file_exists($cache_file)) {
                @unlink($cache_file);
            }

        } catch (Exception $e) {
            $errors[] = "Error for domain " . $source_domain_name . ": " . $e->getMessage();
        }
    }

    // Reload XML on FreeSWITCH
    $esl_result = reload_freeswitch_xml();

    return array(
        "success" => count($generated) > 0,
        "message" => count($generated) . " domain dialplans generated" . (count($errors) > 0 ? " with " . count($errors) . " errors" : ""),
        "generated" => $generated,
        "errors" => $errors,
        "eslReload" => $esl_result
    );
}

function reload_freeswitch_xml() {
    // Try ESL connection to reload XML
    $esl_password = 'ClueCon';
    // Try to read event_socket.conf.xml for actual password
    $esl_conf = '/etc/freeswitch/autoload_configs/event_socket.conf.xml';
    if (file_exists($esl_conf)) {
        $conf_content = file_get_contents($esl_conf);
        if (preg_match('/name="password"\s+value="([^"]+)"/', $conf_content, $matches)) {
            $esl_password = $matches[1];
        }
    }

    $fp = @fsockopen('127.0.0.1', 8021, $errno, $errstr, 3);
    if (!$fp) {
        return "Could not connect to ESL: $errstr";
    }

    // Read banner
    $banner = '';
    while (($line = fgets($fp, 1024)) !== false) {
        $banner .= $line;
        if (trim($line) === '') break;
    }

    // Auth
    fwrite($fp, "auth $esl_password\n\n");
    $auth_response = '';
    while (($line = fgets($fp, 1024)) !== false) {
        $auth_response .= $line;
        if (trim($line) === '') break;
    }

    if (strpos($auth_response, '+OK') === false) {
        fclose($fp);
        return "ESL auth failed";
    }

    // Reload XML
    fwrite($fp, "api reloadxml\n\n");
    $reload_response = '';
    while (($line = fgets($fp, 1024)) !== false) {
        $reload_response .= $line;
        if (trim($line) === '') break;
    }

    fclose($fp);
    return trim($reload_response);
}
