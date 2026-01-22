<?php
$required_params = array();

function do_action($body) {
    // Get profile filter if provided
    $profile_filter = isset($body->profile) ? $body->profile : 'all';

    // Get domain filter if provided
    $domain_filter = isset($body->domain_name) ? $body->domain_name : null;

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
        return array("count" => 0, "profiles" => array());
    }

    $total_count = 0;
    $profile_counts = array();

    // Connect to event socket
    $esl = null;
    if (class_exists('event_socket')) {
        $esl = event_socket::create();
    }

    foreach ($sip_profiles as $profile) {
        $profile_name = $profile['sip_profile_name'];
        $count = 0;

        if ($esl) {
            $xml_response = trim(event_socket::api("sofia xmlstatus profile '" . $profile_name . "' reg"));
        } else {
            $cmd = "/usr/bin/fs_cli -x \"sofia xmlstatus profile '" . escapeshellarg($profile_name) . "' reg\" 2>&1";
            $xml_response = shell_exec($cmd);
        }

        if (empty($xml_response) || $xml_response == "Invalid Profile!") {
            $profile_counts[$profile_name] = 0;
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

                if (!empty($array) && is_array($array) && isset($array['registrations']['registration'])) {
                    if (!isset($array['registrations']['registration'][0])) {
                        $count = 1;
                    } else {
                        $count = count($array['registrations']['registration']);
                    }

                    // Apply domain filter if specified
                    if ($domain_filter) {
                        $filtered_count = 0;
                        $regs = isset($array['registrations']['registration'][0])
                            ? $array['registrations']['registration']
                            : array($array['registrations']['registration']);

                        foreach ($regs as $reg) {
                            $realm = $reg['sip-auth-realm'] ?? '';
                            $user_parts = explode('@', $reg['user'] ?? '');
                            if ($realm == $domain_filter || (isset($user_parts[1]) && $user_parts[1] == $domain_filter)) {
                                $filtered_count++;
                            }
                        }
                        $count = $filtered_count;
                    }
                }
            } catch (Exception $e) {
                $count = 0;
            }
        }

        $profile_counts[$profile_name] = $count;
        $total_count += $count;
    }

    return array(
        "count" => $total_count,
        "profiles" => $profile_counts
    );
}
