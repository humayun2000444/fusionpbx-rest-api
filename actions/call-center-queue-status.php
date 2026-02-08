<?php

$required_params = array();

function do_action($body) {
    global $domain_uuid;

    $db_domain_uuid = isset($body->domain_uuid) ? $body->domain_uuid : $domain_uuid;
    $queue_uuid = isset($body->call_center_queue_uuid) ? $body->call_center_queue_uuid : null;

    $database = new database;

    // Get domain name
    $sql_domain = "SELECT domain_name FROM v_domains WHERE domain_uuid = :domain_uuid";
    $domain_result = $database->select($sql_domain, array("domain_uuid" => $db_domain_uuid), "row");
    $domain_name = $domain_result ? $domain_result['domain_name'] : '';

    // Get queues from database
    $sql = "SELECT
                q.call_center_queue_uuid,
                q.queue_name,
                q.queue_extension,
                q.queue_strategy,
                q.queue_max_wait_time,
                d.domain_name
            FROM v_call_center_queues q
            LEFT JOIN v_domains d ON q.domain_uuid = d.domain_uuid
            WHERE q.domain_uuid = :domain_uuid";

    $parameters = array("domain_uuid" => $db_domain_uuid);

    if ($queue_uuid) {
        $sql .= " AND q.call_center_queue_uuid = :queue_uuid";
        $parameters['queue_uuid'] = $queue_uuid;
    }

    $sql .= " ORDER BY q.queue_name ASC";

    $queues = $database->select($sql, $parameters, "all");

    if (!$queues || count($queues) == 0) {
        return array(
            "success" => true,
            "queues" => array(),
            "message" => "No queues found"
        );
    }

    // Try to get real-time status from FreeSWITCH Event Socket using FusionPBX class
    $esl = event_socket::create();

    $result_queues = array();

    foreach ($queues as $queue) {
        $queue_id = $queue['queue_extension'] . '@' . $queue['domain_name'];

        $result = array(
            'callCenterQueueUuid' => $queue['call_center_queue_uuid'],
            'queueName' => $queue['queue_name'],
            'queueExtension' => $queue['queue_extension'],
            'queueStrategy' => $queue['queue_strategy'],
            'queueMaxWaitTime' => $queue['queue_max_wait_time'],
            'domainName' => $queue['domain_name']
        );

        if ($esl) {
            // Get queue members (waiting callers)
            $cmd = "callcenter_config queue list members $queue_id";
            $response = event_socket::api($cmd);
            $members = parse_queue_members($response);
            $result['members'] = $members;
            $result['memberCount'] = count($members);

            // Get queue agents
            $cmd = "callcenter_config queue list agents $queue_id";
            $response = event_socket::api($cmd);
            $agents = parse_queue_agents($response);
            $result['agents'] = $agents;
            $result['agentCount'] = count($agents);

            // Count agents by status
            $available = 0;
            $on_break = 0;
            $logged_out = 0;
            $in_call = 0;

            foreach ($agents as $agent) {
                if (isset($agent['status'])) {
                    if ($agent['status'] == 'Available') $available++;
                    elseif ($agent['status'] == 'On Break') $on_break++;
                    elseif ($agent['status'] == 'Logged Out') $logged_out++;
                }
                if (isset($agent['state']) && $agent['state'] == 'In a queue call') $in_call++;
            }

            $result['agentsAvailable'] = $available;
            $result['agentsOnBreak'] = $on_break;
            $result['agentsLoggedOut'] = $logged_out;
            $result['agentsInCall'] = $in_call;

            // Get waiting callers count
            $result['waitingCallers'] = count($members);
        } else {
            $result['members'] = array();
            $result['memberCount'] = 0;
            $result['agents'] = array();
            $result['agentCount'] = 0;
            $result['agentsAvailable'] = 0;
            $result['agentsOnBreak'] = 0;
            $result['agentsLoggedOut'] = 0;
            $result['agentsInCall'] = 0;
            $result['waitingCallers'] = 0;
            $result['eslError'] = 'Event socket not available';
        }

        $result_queues[] = $result;
    }

    return array(
        "success" => true,
        "domainUuid" => $db_domain_uuid,
        "domainName" => $domain_name,
        "queueCount" => count($result_queues),
        "queues" => $result_queues
    );
}

function parse_queue_members($response) {
    $members = array();
    if (empty($response)) return $members;

    $lines = explode("\n", trim($response));
    foreach ($lines as $line) {
        if (empty($line) || strpos($line, 'queue|') === 0 || strpos($line, '+OK') !== false || strpos($line, '-ERR') !== false) continue;
        $parts = explode('|', $line);
        if (count($parts) >= 5) {
            $members[] = array(
                'uuid' => isset($parts[1]) ? $parts[1] : '',
                'sessionUuid' => isset($parts[2]) ? $parts[2] : '',
                'cidNumber' => isset($parts[3]) ? $parts[3] : '',
                'cidName' => isset($parts[4]) ? $parts[4] : '',
                'systemEpoch' => isset($parts[5]) ? $parts[5] : '',
                'joinedEpoch' => isset($parts[6]) ? $parts[6] : '',
                'rejoinedEpoch' => isset($parts[7]) ? $parts[7] : '',
                'bridgeEpoch' => isset($parts[8]) ? $parts[8] : '',
                'abandonedEpoch' => isset($parts[9]) ? $parts[9] : '',
                'state' => isset($parts[11]) ? $parts[11] : ''
            );
        }
    }
    return $members;
}

function parse_queue_agents($response) {
    $agents = array();
    if (empty($response)) return $agents;

    $lines = explode("\n", trim($response));
    foreach ($lines as $line) {
        if (empty($line) || strpos($line, 'name|') === 0 || strpos($line, '+OK') !== false || strpos($line, '-ERR') !== false) continue;
        $parts = explode('|', $line);
        if (count($parts) >= 6) {
            $agents[] = array(
                'name' => isset($parts[0]) ? $parts[0] : '',
                'instance' => isset($parts[1]) ? $parts[1] : '',
                'uuid' => isset($parts[2]) ? $parts[2] : '',
                'type' => isset($parts[3]) ? $parts[3] : '',
                'contact' => isset($parts[4]) ? $parts[4] : '',
                'status' => isset($parts[5]) ? $parts[5] : '',
                'state' => isset($parts[6]) ? $parts[6] : ''
            );
        }
    }
    return $agents;
}
