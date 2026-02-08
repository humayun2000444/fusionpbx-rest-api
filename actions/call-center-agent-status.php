<?php

$required_params = array();

function do_action($body) {
    global $domain_uuid;

    $db_domain_uuid = isset($body->domain_uuid) ? $body->domain_uuid : $domain_uuid;
    $agent_uuid = isset($body->call_center_agent_uuid) ? $body->call_center_agent_uuid : null;

    $database = new database;

    // Get domain name
    $sql_domain = "SELECT domain_name FROM v_domains WHERE domain_uuid = :domain_uuid";
    $domain_result = $database->select($sql_domain, array("domain_uuid" => $db_domain_uuid), "row");
    $domain_name = $domain_result ? $domain_result['domain_name'] : '';

    // Get agents from database
    $sql = "SELECT
                a.call_center_agent_uuid,
                a.agent_name,
                a.agent_contact,
                a.agent_status as db_status,
                a.agent_type,
                d.domain_name
            FROM v_call_center_agents a
            LEFT JOIN v_domains d ON a.domain_uuid = d.domain_uuid
            WHERE a.domain_uuid = :domain_uuid";

    $parameters = array("domain_uuid" => $db_domain_uuid);

    if ($agent_uuid) {
        $sql .= " AND a.call_center_agent_uuid = :agent_uuid";
        $parameters['agent_uuid'] = $agent_uuid;
    }

    $sql .= " ORDER BY a.agent_name ASC";

    $agents = $database->select($sql, $parameters, "all");

    if (!$agents || count($agents) == 0) {
        return array(
            "success" => true,
            "agents" => array(),
            "message" => "No agents found"
        );
    }

    // Try to get real-time status from FreeSWITCH Event Socket using FusionPBX class
    $esl = event_socket::create();

    $live_agents = array();
    $live_tiers = array();

    if ($esl) {
        // Get agent list from mod_callcenter
        $response = event_socket::api("callcenter_config agent list");
        if ($response) {
            $lines = explode("\n", trim($response));
            foreach ($lines as $line) {
                if (empty($line) || strpos($line, 'name|') === 0 || strpos($line, '-ERR') !== false) continue;
                $parts = explode('|', $line);
                if (count($parts) >= 6) {
                    $live_agents[$parts[0]] = array(
                        'name' => $parts[0],
                        'instance' => isset($parts[1]) ? $parts[1] : '',
                        'uuid' => isset($parts[2]) ? $parts[2] : '',
                        'type' => isset($parts[3]) ? $parts[3] : '',
                        'contact' => isset($parts[4]) ? $parts[4] : '',
                        'status' => isset($parts[5]) ? $parts[5] : '',
                        'state' => isset($parts[6]) ? $parts[6] : '',
                        'max_no_answer' => isset($parts[7]) ? $parts[7] : '',
                        'wrap_up_time' => isset($parts[8]) ? $parts[8] : '',
                        'reject_delay_time' => isset($parts[9]) ? $parts[9] : '',
                        'busy_delay_time' => isset($parts[10]) ? $parts[10] : '',
                        'no_answer_delay_time' => isset($parts[11]) ? $parts[11] : '',
                        'last_bridge_start' => isset($parts[12]) ? $parts[12] : '',
                        'last_bridge_end' => isset($parts[13]) ? $parts[13] : '',
                        'last_offered_call' => isset($parts[14]) ? $parts[14] : '',
                        'last_status_change' => isset($parts[15]) ? $parts[15] : '',
                        'no_answer_count' => isset($parts[16]) ? $parts[16] : '',
                        'calls_answered' => isset($parts[17]) ? $parts[17] : '',
                        'talk_time' => isset($parts[18]) ? $parts[18] : '',
                        'ready_time' => isset($parts[19]) ? $parts[19] : ''
                    );
                }
            }
        }

        // Get tier list from mod_callcenter
        $response = event_socket::api("callcenter_config tier list");
        if ($response) {
            $lines = explode("\n", trim($response));
            foreach ($lines as $line) {
                if (empty($line) || strpos($line, 'queue|') === 0 || strpos($line, '-ERR') !== false) continue;
                $parts = explode('|', $line);
                if (count($parts) >= 4) {
                    $agent_name = isset($parts[1]) ? $parts[1] : '';
                    if (!isset($live_tiers[$agent_name])) {
                        $live_tiers[$agent_name] = array();
                    }
                    $live_tiers[$agent_name][] = array(
                        'queue' => isset($parts[0]) ? $parts[0] : '',
                        'agent' => $agent_name,
                        'state' => isset($parts[2]) ? $parts[2] : '',
                        'level' => isset($parts[3]) ? $parts[3] : '',
                        'position' => isset($parts[4]) ? $parts[4] : ''
                    );
                }
            }
        }
    }

    // Merge database agents with live status
    $result_agents = array();
    foreach ($agents as $agent) {
        $agent_id = $agent['call_center_agent_uuid'];

        $result = array(
            'callCenterAgentUuid' => $agent_id,
            'agentName' => $agent['agent_name'],
            'agentContact' => $agent['agent_contact'],
            'agentType' => $agent['agent_type'],
            'domainName' => $agent['domain_name'],
            'dbStatus' => $agent['db_status']
        );

        // Add live status if available
        if (isset($live_agents[$agent_id])) {
            $live = $live_agents[$agent_id];
            $result['liveStatus'] = $live['status'];
            $result['liveState'] = $live['state'];
            $result['lastBridgeStart'] = $live['last_bridge_start'];
            $result['lastBridgeEnd'] = $live['last_bridge_end'];
            $result['lastOfferedCall'] = $live['last_offered_call'];
            $result['lastStatusChange'] = $live['last_status_change'];
            $result['noAnswerCount'] = $live['no_answer_count'];
            $result['callsAnswered'] = $live['calls_answered'];
            $result['talkTime'] = $live['talk_time'];
            $result['readyTime'] = $live['ready_time'];
            $result['isOnline'] = true;
        } else {
            $result['liveStatus'] = 'Logged Out';
            $result['liveState'] = 'Unknown';
            $result['isOnline'] = false;
        }

        // Add queue memberships
        if (isset($live_tiers[$agent_id])) {
            $result['activeQueues'] = $live_tiers[$agent_id];
        } else {
            $result['activeQueues'] = array();
        }

        $result_agents[] = $result;
    }

    return array(
        "success" => true,
        "domainUuid" => $db_domain_uuid,
        "domainName" => $domain_name,
        "agentCount" => count($result_agents),
        "agents" => $result_agents
    );
}
