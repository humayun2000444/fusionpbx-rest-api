<?php

$required_params = array("callCenterQueueUuid");

function do_action($body) {
    global $domain_uuid;

    $queue_uuid = isset($body->callCenterQueueUuid) ? $body->callCenterQueueUuid : $body->call_center_queue_uuid;

    $database = new database;

    // Get queue details
    $sql = "SELECT q.*, d.domain_name FROM v_call_center_queues q
            LEFT JOIN v_domains d ON q.domain_uuid = d.domain_uuid
            WHERE q.call_center_queue_uuid = :queue_uuid";
    $queue = $database->select($sql, array("queue_uuid" => $queue_uuid), "row");

    if (!$queue) {
        return array("error" => "Queue not found");
    }

    $queue_extension = $queue['queue_extension'];
    $domain_name = $queue['domain_name'];
    $queue_id = $queue_extension . '@' . $domain_name;

    // Get all agents from database for this domain
    $sql = "SELECT * FROM v_call_center_agents WHERE domain_uuid = :domain_uuid";
    $db_agents = $database->select($sql, array("domain_uuid" => $queue['domain_uuid']), "all");
    $agents_by_uuid = array();
    if ($db_agents) {
        foreach ($db_agents as $agent) {
            $agents_by_uuid[$agent['call_center_agent_uuid']] = $agent;
        }
    }

    // Create event socket connection
    $esl = event_socket::create();

    if (!$esl) {
        return array("error" => "Event socket connection failed");
    }

    // Get queue tiers (agents assigned to this queue)
    $cmd = "callcenter_config queue list tiers $queue_id";
    $tier_str = trim(event_socket::api($cmd));
    $tiers = str_to_named_array($tier_str, '|');

    // Get queue agents with their status
    $cmd = "callcenter_config queue list agents $queue_id";
    $agent_str = trim(event_socket::api($cmd));
    $agents = str_to_named_array($agent_str, '|');

    // Get queue members (waiting callers)
    $cmd = "callcenter_config queue list members $queue_id";
    $member_str = trim(event_socket::api($cmd));
    $members = str_to_named_array($member_str, '|');

    // Build agents response with tier info
    $agents_response = array();
    $stats = array(
        'totalAgents' => 0,
        'availableAgents' => 0,
        'onBreakAgents' => 0,
        'loggedOutAgents' => 0,
        'busyAgents' => 0,
        'waitingCallers' => 0,
        'tryingCallers' => 0,
        'answeredCallers' => 0,
        'abandonedCallers' => 0
    );

    if ($tiers) {
        foreach ($tiers as $tier) {
            $agent_uuid = $tier['agent'];
            $tier_state = trim($tier['state']);
            $tier_level = $tier['level'];
            $tier_position = $tier['position'];

            // Find agent details
            $agent_data = null;
            if ($agents) {
                foreach ($agents as $agent) {
                    if ($agent['name'] == $agent_uuid) {
                        $agent_data = $agent;
                        break;
                    }
                }
            }

            if ($agent_data) {
                $contact = $agent_data['contact'];
                $extension = preg_replace("/user\//", "", $contact);
                $extension = preg_replace("/@.*/", "", $extension);
                $extension = preg_replace("/{.*}/", "", $extension);

                $status = $agent_data['status'];
                $state = $agent_data['state'];
                $last_bridge_end = intval($agent_data['last_bridge_end']);
                $wrap_up_time = intval($agent_data['wrap_up_time']);
                $last_status_change = intval($agent_data['last_status_change']);

                // Calculate time since status change
                $status_change_seconds = time() - $last_status_change;
                $bridge_end_seconds = $last_bridge_end > 0 ? time() - $last_bridge_end : 0;

                // Check if in wrap up time
                if ($last_bridge_end > 0 && $bridge_end_seconds < $wrap_up_time) {
                    $state = 'Wrap Up Time';
                }

                // Get agent name from database
                $agent_name = isset($agents_by_uuid[$agent_uuid]) ? $agents_by_uuid[$agent_uuid]['agent_name'] : $agent_uuid;

                $agents_response[] = array(
                    'agentUuid' => $agent_uuid,
                    'agentName' => $agent_name,
                    'extension' => $extension,
                    'status' => $status,
                    'state' => $state,
                    'tierState' => $tier_state,
                    'tierLevel' => intval($tier_level),
                    'tierPosition' => intval($tier_position),
                    'lastStatusChange' => $last_status_change,
                    'lastStatusChangeSeconds' => $status_change_seconds,
                    'lastStatusChangeFormatted' => format_seconds($status_change_seconds),
                    'lastBridgeEnd' => $last_bridge_end,
                    'lastBridgeEndSeconds' => $bridge_end_seconds,
                    'lastBridgeEndFormatted' => format_seconds($bridge_end_seconds),
                    'noAnswerCount' => intval($agent_data['no_answer_count']),
                    'callsAnswered' => intval($agent_data['calls_answered']),
                    'talkTime' => intval($agent_data['talk_time']),
                    'readyTime' => intval($agent_data['ready_time'])
                );

                // Update stats
                $stats['totalAgents']++;
                if ($status == 'Available') $stats['availableAgents']++;
                if ($status == 'On Break') $stats['onBreakAgents']++;
                if ($status == 'Logged Out') $stats['loggedOutAgents']++;
                if ($state == 'In a queue call' || $state == 'Receiving') $stats['busyAgents']++;
            }
        }
    }

    // Build members (waiting callers) response
    $members_response = array();
    if ($members) {
        foreach ($members as $member) {
            $joined_epoch = intval($member['joined_epoch']);
            $wait_seconds = time() - $joined_epoch;
            $state = $member['state'];

            // Get serving agent name
            $serving_agent = $member['serving_agent'];
            $serving_agent_name = '';
            if ($serving_agent && isset($agents_by_uuid[$serving_agent])) {
                $serving_agent_name = $agents_by_uuid[$serving_agent]['agent_name'];
            }

            $members_response[] = array(
                'uuid' => $member['uuid'],
                'sessionUuid' => $member['session_uuid'],
                'callerNumber' => $member['cid_number'],
                'callerName' => $member['cid_name'],
                'state' => $state,
                'joinedEpoch' => $joined_epoch,
                'waitSeconds' => $wait_seconds,
                'waitFormatted' => format_seconds($wait_seconds),
                'servingAgent' => $serving_agent,
                'servingAgentName' => $serving_agent_name,
                'baseScore' => $member['base_score'],
                'skillScore' => $member['skill_score']
            );

            // Update stats
            if ($state == 'Waiting') $stats['waitingCallers']++;
            if ($state == 'Trying') $stats['tryingCallers']++;
            if ($state == 'Answered') $stats['answeredCallers']++;
            if ($state == 'Abandoned') $stats['abandonedCallers']++;
        }
    }

    // Separate active members from abandoned
    $active_members = array_filter($members_response, function($m) {
        return $m['state'] != 'Abandoned';
    });
    $active_members = array_values($active_members);

    return array(
        'success' => true,
        'queue' => array(
            'callCenterQueueUuid' => $queue_uuid,
            'queueName' => $queue['queue_name'],
            'queueExtension' => $queue_extension,
            'queueStrategy' => $queue['queue_strategy'],
            'domainName' => $domain_name
        ),
        'stats' => $stats,
        'agents' => $agents_response,
        'activeMembers' => $active_members,
        'allMembers' => $members_response,
        'timestamp' => time()
    );
}

// Helper function to parse ESL response
function str_to_named_array($tmp_str, $tmp_delimiter) {
    $tmp_array = explode("\n", $tmp_str);
    $result = array();
    if (trim(strtoupper($tmp_array[0])) != "+OK" && count($tmp_array) > 1) {
        $tmp_field_name_array = explode($tmp_delimiter, $tmp_array[0]);
        $x = 0;
        foreach ($tmp_array as $row) {
            if ($x > 0 && !empty(trim($row))) {
                $tmp_field_value_array = explode($tmp_delimiter, $row);
                $y = 0;
                foreach ($tmp_field_value_array as $tmp_value) {
                    if (isset($tmp_field_name_array[$y])) {
                        $tmp_name = $tmp_field_name_array[$y];
                        if (trim(strtoupper($tmp_value)) != "+OK") {
                            $result[$x][$tmp_name] = $tmp_value;
                        }
                    }
                    $y++;
                }
            }
            $x++;
        }
    }
    return $result;
}

// format_seconds() is available from FusionPBX resources/functions.php
