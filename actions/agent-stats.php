<?php
$required_params = array("call_center_agent_uuid");

function do_action($body) {
    global $domain_uuid;

    // Get agent UUID - support both camelCase and snake_case
    $agent_uuid = isset($body->callCenterAgentUuid) ? $body->callCenterAgentUuid :
                  (isset($body->call_center_agent_uuid) ? $body->call_center_agent_uuid : null);

    if (empty($agent_uuid)) {
        return array(
            "success" => false,
            "error" => "call_center_agent_uuid is required"
        );
    }

    // Get date range - default to today
    $start_date = isset($body->startDate) ? $body->startDate :
                  (isset($body->start_date) ? $body->start_date : date('Y-m-d'));
    $end_date = isset($body->endDate) ? $body->endDate :
                (isset($body->end_date) ? $body->end_date : date('Y-m-d'));

    $database = new database;

    // Get agent details including extension
    $sql = "SELECT a.*, d.domain_name
            FROM v_call_center_agents a
            LEFT JOIN v_domains d ON a.domain_uuid = d.domain_uuid
            WHERE a.call_center_agent_uuid = :agent_uuid";
    $agent = $database->select($sql, array("agent_uuid" => $agent_uuid), "row");

    if (empty($agent)) {
        return array(
            "success" => false,
            "error" => "Agent not found"
        );
    }

    // Extract extension number from agent_contact (e.g., "user/1001@domain" -> "1001")
    $agent_contact = $agent['agent_contact'];
    $extension = '';
    if (preg_match('/\/(\d+)@/', $agent_contact, $matches)) {
        $extension = $matches[1];
    } elseif (preg_match('/^(\d+)$/', $agent_contact, $matches)) {
        $extension = $matches[1];
    }

    $agent_domain_uuid = $agent['domain_uuid'];
    $domain_name = $agent['domain_name'];

    // Initialize stats
    $stats = array(
        "callsHandled" => 0,
        "callsOffered" => 0,
        "missedCalls" => 0,
        "inboundCalls" => 0,
        "outboundCalls" => 0,
        "avgHandleTime" => "0:00",
        "avgHandleTimeSeconds" => 0,
        "longestCall" => "0:00",
        "longestCallSeconds" => 0,
        "shortestCall" => "0:00",
        "shortestCallSeconds" => 0,
        "totalTalkTime" => "0:00",
        "totalTalkTimeSeconds" => 0,
        "avgWaitTime" => "0:00",
        "avgWaitTimeSeconds" => 0,
        "avgRingTime" => "0:00",
        "avgRingTimeSeconds" => 0,
        "shortCalls" => 0,
        "answerRate" => 0,
        "callsPerHour" => 0,
        "peakHour" => null,
        "peakHourCalls" => 0,
        "firstCallTime" => null,
        "lastCallTime" => null,
        "totalHoldTime" => "0:00",
        "totalHoldTimeSeconds" => 0,
        "transferredCalls" => 0
    );

    if (empty($extension)) {
        return array(
            "success" => true,
            "agent" => array(
                "call_center_agent_uuid" => $agent_uuid,
                "agent_name" => $agent['agent_name'],
                "agent_contact" => $agent_contact,
                "extension" => $extension,
                "domain_name" => $domain_name
            ),
            "dateRange" => array(
                "startDate" => $start_date,
                "endDate" => $end_date
            ),
            "stats" => $stats,
            "message" => "Could not extract extension from agent contact"
        );
    }

    // Query CDR for agent's calls
    // Inbound calls: destination_number = extension (answered by agent)
    // Outbound calls: caller_id_number = extension (made by agent)
    $sql = "SELECT
                xml_cdr_uuid,
                direction,
                caller_id_number,
                destination_number,
                start_stamp,
                answer_stamp,
                end_stamp,
                duration,
                billsec,
                hangup_cause,
                cc_queue,
                cc_agent,
                cc_queue_joined_epoch,
                cc_queue_answered_epoch,
                waitsec
            FROM v_xml_cdr
            WHERE domain_uuid = :domain_uuid
            AND start_stamp >= :start_date
            AND start_stamp < :end_date_next
            AND (
                destination_number = :extension1
                OR caller_id_number = :extension2
                OR cc_agent LIKE :agent_contact
            )
            ORDER BY start_stamp DESC";

    $params = array(
        "domain_uuid" => $agent_domain_uuid,
        "start_date" => $start_date . " 00:00:00",
        "end_date_next" => date('Y-m-d', strtotime($end_date . ' +1 day')) . " 00:00:00",
        "extension1" => $extension,
        "extension2" => $extension,
        "agent_contact" => "%" . $extension . "%"
    );

    $cdr_records = $database->select($sql, $params, "all");
    if (!is_array($cdr_records)) {
        $cdr_records = array();
    }

    // Process CDR records
    $total_billsec = 0;
    $longest_billsec = 0;
    $shortest_billsec = PHP_INT_MAX;
    $answered_calls = array();
    $missed_calls = array();
    $inbound_calls = 0;
    $outbound_calls = 0;
    $total_wait_time = 0;
    $wait_count = 0;
    $total_ring_time = 0;
    $ring_count = 0;
    $short_calls = 0;
    $total_hold_time = 0;
    $transferred_calls = 0;
    $hourly_calls = array();

    foreach ($cdr_records as $cdr) {
        $billsec = intval($cdr['billsec']);
        $duration = intval($cdr['duration']);
        $hangup_cause = $cdr['hangup_cause'];
        $direction = $cdr['direction'];
        $dest = $cdr['destination_number'];
        $caller = $cdr['caller_id_number'];

        // Calculate ring time (duration - billsec = ring/wait time before answer)
        $ring_time = $duration - $billsec;
        if ($ring_time < 0) $ring_time = 0;

        // Determine if this call was answered by the agent
        $is_answered = ($billsec > 0 && in_array($hangup_cause, array(
            'NORMAL_CLEARING',
            'USER_BUSY',
            'NORMAL_TEMPORARY_FAILURE',
            'ORIGINATOR_CANCEL'
        )));

        // Check for transferred calls
        if (in_array($hangup_cause, array('ATTENDED_TRANSFER', 'BLIND_TRANSFER'))) {
            $transferred_calls++;
        }

        // For inbound calls to agent extension
        if ($dest == $extension) {
            if ($is_answered) {
                $answered_calls[] = $cdr;
                $inbound_calls++;
                $total_billsec += $billsec;

                // Track longest and shortest
                if ($billsec > $longest_billsec) {
                    $longest_billsec = $billsec;
                }
                if ($billsec < $shortest_billsec && $billsec > 0) {
                    $shortest_billsec = $billsec;
                }

                // Track short calls (under 30 seconds)
                if ($billsec < 30) {
                    $short_calls++;
                }

                // Ring time
                if ($ring_time > 0) {
                    $total_ring_time += $ring_time;
                    $ring_count++;
                }

                // Track hourly distribution
                $hour = date('H', strtotime($cdr['start_stamp']));
                if (!isset($hourly_calls[$hour])) {
                    $hourly_calls[$hour] = 0;
                }
                $hourly_calls[$hour]++;
            } else {
                // Missed/abandoned call
                $missed_calls[] = $cdr;
            }
        }
        // For outbound calls from agent extension
        elseif ($caller == $extension && $direction == 'outbound') {
            if ($is_answered) {
                $answered_calls[] = $cdr;
                $outbound_calls++;
                $total_billsec += $billsec;

                if ($billsec > $longest_billsec) {
                    $longest_billsec = $billsec;
                }
                if ($billsec < $shortest_billsec && $billsec > 0) {
                    $shortest_billsec = $billsec;
                }
                if ($billsec < 30) {
                    $short_calls++;
                }

                // Track hourly distribution
                $hour = date('H', strtotime($cdr['start_stamp']));
                if (!isset($hourly_calls[$hour])) {
                    $hourly_calls[$hour] = 0;
                }
                $hourly_calls[$hour]++;
            }
        }
        // For queue calls (cc_agent contains extension)
        elseif (!empty($cdr['cc_agent']) && strpos($cdr['cc_agent'], $extension) !== false) {
            if ($is_answered) {
                $answered_calls[] = $cdr;
                $inbound_calls++;
                $total_billsec += $billsec;

                if ($billsec > $longest_billsec) {
                    $longest_billsec = $billsec;
                }
                if ($billsec < $shortest_billsec && $billsec > 0) {
                    $shortest_billsec = $billsec;
                }
                if ($billsec < 30) {
                    $short_calls++;
                }

                // Track hourly distribution
                $hour = date('H', strtotime($cdr['start_stamp']));
                if (!isset($hourly_calls[$hour])) {
                    $hourly_calls[$hour] = 0;
                }
                $hourly_calls[$hour]++;
            }
        }

        // Calculate wait time for queue calls
        $waitsec = intval($cdr['waitsec']);
        if ($waitsec > 0) {
            $total_wait_time += $waitsec;
            $wait_count++;
        }

        // Track hold time if available
        if (isset($cdr['hold_accum']) && intval($cdr['hold_accum']) > 0) {
            $total_hold_time += intval($cdr['hold_accum']);
        }
    }

    // Reset shortest if no calls
    if ($shortest_billsec == PHP_INT_MAX) {
        $shortest_billsec = 0;
    }

    $calls_handled = count($answered_calls);
    $calls_missed = count($missed_calls);
    $calls_offered = $calls_handled + $calls_missed;

    // Calculate averages
    $avg_handle_time = 0;
    if ($calls_handled > 0) {
        $avg_handle_time = round($total_billsec / $calls_handled);
    }

    $avg_wait_time = 0;
    if ($wait_count > 0) {
        $avg_wait_time = round($total_wait_time / $wait_count);
    }

    $avg_ring_time = 0;
    if ($ring_count > 0) {
        $avg_ring_time = round($total_ring_time / $ring_count);
    }

    // Calculate answer rate
    $answer_rate = 0;
    if ($calls_offered > 0) {
        $answer_rate = round(($calls_handled / $calls_offered) * 100, 1);
    }

    // Find peak hour
    $peak_hour = null;
    $peak_hour_calls = 0;
    foreach ($hourly_calls as $hour => $count) {
        if ($count > $peak_hour_calls) {
            $peak_hour_calls = $count;
            $peak_hour = $hour . ':00';
        }
    }

    // Calculate calls per hour (based on time range)
    $calls_per_hour = 0;
    $start_ts = strtotime($start_date . " 00:00:00");
    $end_ts = strtotime($end_date . " 23:59:59");
    $hours_diff = max(1, ($end_ts - $start_ts) / 3600);
    if ($calls_handled > 0 && $hours_diff > 0) {
        $calls_per_hour = round($calls_handled / $hours_diff, 1);
    }

    // Get first and last call times
    $first_call_time = null;
    $last_call_time = null;
    if (count($answered_calls) > 0) {
        // Records are ordered DESC, so last element is first call
        $first_call_time = $answered_calls[count($answered_calls) - 1]['start_stamp'];
        $last_call_time = $answered_calls[0]['start_stamp'];
    }

    // Format times
    $stats = array(
        "callsHandled" => $calls_handled,
        "callsOffered" => $calls_offered,
        "missedCalls" => $calls_missed,
        "inboundCalls" => $inbound_calls,
        "outboundCalls" => $outbound_calls,
        "avgHandleTime" => format_duration($avg_handle_time),
        "avgHandleTimeSeconds" => $avg_handle_time,
        "longestCall" => format_duration($longest_billsec),
        "longestCallSeconds" => $longest_billsec,
        "shortestCall" => format_duration($shortest_billsec),
        "shortestCallSeconds" => $shortest_billsec,
        "totalTalkTime" => format_duration($total_billsec),
        "totalTalkTimeSeconds" => $total_billsec,
        "avgWaitTime" => format_duration($avg_wait_time),
        "avgWaitTimeSeconds" => $avg_wait_time,
        "avgRingTime" => format_duration($avg_ring_time),
        "avgRingTimeSeconds" => $avg_ring_time,
        "shortCalls" => $short_calls,
        "answerRate" => $answer_rate,
        "callsPerHour" => $calls_per_hour,
        "peakHour" => $peak_hour,
        "peakHourCalls" => $peak_hour_calls,
        "firstCallTime" => $first_call_time,
        "lastCallTime" => $last_call_time,
        "totalHoldTime" => format_duration($total_hold_time),
        "totalHoldTimeSeconds" => $total_hold_time,
        "transferredCalls" => $transferred_calls,
        "hourlyDistribution" => $hourly_calls
    );

    // Also get call center specific stats if available (from mod_callcenter)
    $cc_stats = get_call_center_agent_stats($agent_uuid, $agent_contact, $start_date, $end_date);
    if ($cc_stats) {
        $stats = array_merge($stats, $cc_stats);
    }

    return array(
        "success" => true,
        "agent" => array(
            "call_center_agent_uuid" => $agent_uuid,
            "agent_name" => $agent['agent_name'],
            "agent_contact" => $agent_contact,
            "agent_status" => $agent['agent_status'],
            "extension" => $extension,
            "domain_name" => $domain_name
        ),
        "dateRange" => array(
            "startDate" => $start_date,
            "endDate" => $end_date
        ),
        "stats" => $stats
    );
}

function format_duration($seconds) {
    $seconds = intval($seconds);
    if ($seconds < 0) $seconds = 0;

    $hours = floor($seconds / 3600);
    $minutes = floor(($seconds % 3600) / 60);
    $secs = $seconds % 60;

    if ($hours > 0) {
        return sprintf("%d:%02d:%02d", $hours, $minutes, $secs);
    }
    return sprintf("%d:%02d", $minutes, $secs);
}

function get_call_center_agent_stats($agent_uuid, $agent_contact, $start_date, $end_date) {
    // Try to get stats from FreeSWITCH mod_callcenter via event socket
    $stats = array();

    if (class_exists('event_socket')) {
        $esl = event_socket::create();
        if ($esl) {
            // Get agent status from mod_callcenter
            $cmd = "callcenter_config agent list " . $agent_contact;
            $response = event_socket::api($cmd);

            if ($response && strpos($response, 'error') === false) {
                // Parse response - format varies by FreeSWITCH version
                $lines = explode("\n", trim($response));
                foreach ($lines as $line) {
                    if (strpos($line, '|') !== false) {
                        $parts = explode('|', $line);
                        // Typical format: name|instance_id|uuid|type|contact|status|state|
                        //                 max_no_answer|wrap_up_time|reject_delay_time|
                        //                 busy_delay_time|no_answer_delay_time|
                        //                 last_bridge_start|last_bridge_end|
                        //                 last_offered_call|last_status_change|
                        //                 no_answer_count|calls_answered|talk_time|ready_time
                        if (count($parts) >= 19) {
                            $stats['noAnswerCount'] = intval(trim($parts[16]));
                            $stats['callsAnsweredLive'] = intval(trim($parts[17]));
                            $stats['talkTimeLive'] = intval(trim($parts[18]));
                            $stats['talkTimeLiveFormatted'] = format_duration(intval(trim($parts[18])));
                            if (isset($parts[19])) {
                                $stats['readyTime'] = intval(trim($parts[19]));
                                $stats['readyTimeFormatted'] = format_duration(intval(trim($parts[19])));
                            }
                        }
                    }
                }
            }
        }
    }

    return !empty($stats) ? $stats : null;
}
