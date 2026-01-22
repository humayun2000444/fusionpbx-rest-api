<?php
$required_params = array();

function do_action($body) {
    // Get profile filter if provided
    $profile_filter = isset($body->profile) ? $body->profile : 'all';

    // Get domain filter if provided
    $domain_filter = isset($body->domain_name) ? $body->domain_name : null;

    // Get show mode (all or local)
    $show = isset($body->show) ? $body->show : 'all';

    // Initialize registrations array
    $registrations = array();
    $id = 0;

    // Get SIP profiles from database
    $sql = "SELECT sip_profile_name FROM v_sip_profiles WHERE sip_profile_enabled = 'true'";
    $parameters = array();

    if ($profile_filter && $profile_filter != 'all') {
        $sql .= " AND sip_profile_name = :sip_profile_name";
        $parameters['sip_profile_name'] = $profile_filter;
    }

    $database = new database;
    $sip_profiles = $database->select($sql, !empty($parameters) ? $parameters : null, 'all');

    if (!$sip_profiles || !is_array($sip_profiles)) {
        return array("error" => "No SIP profiles found", "registrations" => array());
    }

    // Connect to event socket
    $esl = null;
    if (class_exists('event_socket')) {
        $esl = event_socket::create();
    }

    if (!$esl) {
        // Try fallback with fs_cli
        return get_registrations_via_cli($sip_profiles, $domain_filter, $show);
    }

    // Loop through SIP profiles and get registrations
    foreach ($sip_profiles as $profile) {
        $profile_name = $profile['sip_profile_name'];

        // Get sofia status for this profile
        $cmd = "api sofia xmlstatus profile '" . $profile_name . "' reg";
        $xml_response = trim(event_socket::api("sofia xmlstatus profile '" . $profile_name . "' reg"));

        if ($xml_response == "Invalid Profile!" || empty($xml_response)) {
            continue;
        }

        // Sanitize XML
        if (function_exists('iconv')) {
            $xml_response = iconv("utf-8", "utf-8//IGNORE", $xml_response);
        }
        $xml_response = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $xml_response);
        $xml_response = str_replace("<profile-info>", "<profile_info>", $xml_response);
        $xml_response = str_replace("</profile-info>", "</profile_info>", $xml_response);
        $xml_response = str_replace("&lt;", "", $xml_response);
        $xml_response = str_replace("&gt;", "", $xml_response);

        if (strlen($xml_response) > 101) {
            try {
                $xml = new SimpleXMLElement($xml_response);
                $array = json_decode(json_encode($xml), true);
            } catch (Exception $e) {
                continue;
            }
        } else {
            continue;
        }

        // Normalize the array
        if (!empty($array) && is_array($array) && isset($array['registrations']['registration'])) {
            if (!isset($array['registrations']['registration'][0]) || !is_array($array['registrations']['registration'][0])) {
                $row = $array['registrations']['registration'];
                unset($array['registrations']['registration']);
                $array['registrations']['registration'][0] = $row;
            }

            // Process each registration
            foreach ($array['registrations']['registration'] as $row) {
                $user_array = explode('@', $row['user'] ?? '');
                $realm = $row['sip-auth-realm'] ?? '';

                // Apply domain filter
                if ($domain_filter && $show != 'all') {
                    if ($realm != $domain_filter && (!isset($user_array[1]) || $user_array[1] != $domain_filter)) {
                        continue;
                    }
                }

                // Get LAN IP
                $lan_ip = '';
                $call_id_array = explode('@', $row['call-id'] ?? '');
                if (isset($call_id_array[1])) {
                    $agent = $row['agent'] ?? '';
                    $lan_ip = $call_id_array[1];
                    if (!empty($agent) && (false !== stripos($agent, 'grandstream') || false !== stripos($agent, 'ooma'))) {
                        $lan_ip = str_ireplace(
                            array('A','B','C','D','E','F','G','H','I','J'),
                            array('0','1','2','3','4','5','6','7','8','9'),
                            $lan_ip);
                    } elseif (!empty($agent) && 1 === preg_match('/\ACL750A/', $agent)) {
                        $lan_ip = preg_replace('/_/', '.', $lan_ip);
                    }
                } elseif (preg_match('/real=\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}/', $row['contact'] ?? '', $ip_match)) {
                    $lan_ip = str_replace('real=', '', $ip_match[0]);
                } elseif (preg_match('/\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}/', $row['contact'] ?? '', $ip_match)) {
                    $lan_ip = preg_replace('/_/', '.', $ip_match[0]);
                }

                // Parse status to get expiry info
                $status = $row['status'] ?? '';
                $status_clean = preg_replace('/[\d{4}-\d{2}-\d{2}\s\d{2}:\d{2}:\d{2}]/', '', $status);
                $status_clean = preg_replace('/\s+/', ' ', trim($status_clean));

                // Build registration record
                $registrations[$id] = array(
                    'user' => $row['user'] ?? '',
                    'call_id' => $row['call-id'] ?? '',
                    'contact' => $row['contact'] ?? '',
                    'sip_auth_user' => $row['sip-auth-user'] ?? '',
                    'sip_auth_realm' => $realm,
                    'agent' => $row['agent'] ?? '',
                    'host' => $row['host'] ?? '',
                    'network_ip' => $row['network-ip'] ?? '',
                    'network_port' => $row['network-port'] ?? '',
                    'lan_ip' => $lan_ip,
                    'mwi_account' => $row['mwi-account'] ?? '',
                    'status' => $status,
                    'ping_time' => $row['ping-time'] ?? '',
                    'ping_status' => $row['ping-status'] ?? '',
                    'sip_profile_name' => $profile_name
                );

                $id++;
            }
        }
    }

    return array(
        "count" => count($registrations),
        "registrations" => array_values($registrations)
    );
}

function get_registrations_via_cli($sip_profiles, $domain_filter, $show) {
    $registrations = array();
    $id = 0;

    foreach ($sip_profiles as $profile) {
        $profile_name = $profile['sip_profile_name'];

        // Use fs_cli to get registration data
        $cmd = "/usr/bin/fs_cli -x \"sofia xmlstatus profile '" . escapeshellarg($profile_name) . "' reg\" 2>&1";
        $xml_response = shell_exec($cmd);

        if (empty($xml_response) || strpos($xml_response, 'Invalid Profile') !== false) {
            continue;
        }

        // Sanitize XML
        if (function_exists('iconv')) {
            $xml_response = iconv("utf-8", "utf-8//IGNORE", $xml_response);
        }
        $xml_response = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $xml_response);
        $xml_response = str_replace("<profile-info>", "<profile_info>", $xml_response);
        $xml_response = str_replace("</profile-info>", "</profile_info>", $xml_response);
        $xml_response = str_replace("&lt;", "", $xml_response);
        $xml_response = str_replace("&gt;", "", $xml_response);

        if (strlen($xml_response) > 101) {
            try {
                $xml = new SimpleXMLElement($xml_response);
                $array = json_decode(json_encode($xml), true);
            } catch (Exception $e) {
                continue;
            }
        } else {
            continue;
        }

        // Normalize the array
        if (!empty($array) && is_array($array) && isset($array['registrations']['registration'])) {
            if (!isset($array['registrations']['registration'][0]) || !is_array($array['registrations']['registration'][0])) {
                $row = $array['registrations']['registration'];
                unset($array['registrations']['registration']);
                $array['registrations']['registration'][0] = $row;
            }

            // Process each registration
            foreach ($array['registrations']['registration'] as $row) {
                $user_array = explode('@', $row['user'] ?? '');
                $realm = $row['sip-auth-realm'] ?? '';

                // Apply domain filter
                if ($domain_filter && $show != 'all') {
                    if ($realm != $domain_filter && (!isset($user_array[1]) || $user_array[1] != $domain_filter)) {
                        continue;
                    }
                }

                // Get LAN IP
                $lan_ip = '';
                $call_id_array = explode('@', $row['call-id'] ?? '');
                if (isset($call_id_array[1])) {
                    $lan_ip = $call_id_array[1];
                } elseif (preg_match('/\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}/', $row['contact'] ?? '', $ip_match)) {
                    $lan_ip = $ip_match[0];
                }

                // Build registration record
                $registrations[$id] = array(
                    'user' => $row['user'] ?? '',
                    'call_id' => $row['call-id'] ?? '',
                    'contact' => $row['contact'] ?? '',
                    'sip_auth_user' => $row['sip-auth-user'] ?? '',
                    'sip_auth_realm' => $realm,
                    'agent' => $row['agent'] ?? '',
                    'host' => $row['host'] ?? '',
                    'network_ip' => $row['network-ip'] ?? '',
                    'network_port' => $row['network-port'] ?? '',
                    'lan_ip' => $lan_ip,
                    'mwi_account' => $row['mwi-account'] ?? '',
                    'status' => $row['status'] ?? '',
                    'ping_time' => $row['ping-time'] ?? '',
                    'ping_status' => $row['ping-status'] ?? '',
                    'sip_profile_name' => $profile_name
                );

                $id++;
            }
        }
    }

    return array(
        "count" => count($registrations),
        "registrations" => array_values($registrations)
    );
}
