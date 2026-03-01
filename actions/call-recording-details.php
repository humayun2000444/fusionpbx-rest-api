<?php

$required_params = array("call_recording_uuid");

function do_action($body) {
    global $domain_uuid;

    $call_recording_uuid = $body->call_recording_uuid;

    // Get recording details from v_xml_cdr
    $sql = "SELECT
                xml_cdr_uuid as call_recording_uuid,
                domain_uuid,
                domain_name,
                extension_uuid,
                sip_call_id,
                direction as call_direction,
                caller_id_name,
                caller_id_number,
                caller_destination,
                source_number,
                destination_number,
                start_stamp as call_recording_date,
                answer_stamp,
                end_stamp,
                duration as call_recording_length,
                billsec,
                record_path as call_recording_path,
                record_name as call_recording_name,
                record_transcription as call_recording_transcription,
                record_length,
                read_codec,
                write_codec,
                network_addr,
                remote_media_ip,
                leg,
                missed_call,
                voicemail_message,
                hangup_cause,
                hangup_cause_q850
            FROM v_xml_cdr
            WHERE xml_cdr_uuid = :call_recording_uuid";

    $parameters = array("call_recording_uuid" => $call_recording_uuid);

    $database = new database;
    $recording = $database->select($sql, $parameters, "row");

    if (!$recording) {
        return array("error" => "Call recording not found");
    }

    // Format the result
    $result = array(
        "callRecordingUuid" => $recording["call_recording_uuid"],
        "domainUuid" => $recording["domain_uuid"],
        "domainName" => $recording["domain_name"],
        "extensionUuid" => $recording["extension_uuid"],
        "sipCallId" => $recording["sip_call_id"],
        "callDirection" => $recording["call_direction"],
        "callerIdName" => $recording["caller_id_name"],
        "callerIdNumber" => $recording["caller_id_number"],
        "callerDestination" => $recording["caller_destination"],
        "sourceNumber" => $recording["source_number"],
        "destinationNumber" => $recording["destination_number"],
        "callRecordingDate" => $recording["call_recording_date"],
        "answerStamp" => $recording["answer_stamp"],
        "endStamp" => $recording["end_stamp"],
        "callRecordingLength" => $recording["call_recording_length"],
        "billsec" => $recording["billsec"],
        "callRecordingPath" => $recording["call_recording_path"],
        "callRecordingName" => $recording["call_recording_name"],
        "callRecordingTranscription" => $recording["call_recording_transcription"],
        "recordLength" => $recording["record_length"],
        "readCodec" => $recording["read_codec"],
        "writeCodec" => $recording["write_codec"],
        "networkAddr" => $recording["network_addr"],
        "remoteMediaIp" => $recording["remote_media_ip"],
        "leg" => $recording["leg"],
        "missedCall" => $recording["missed_call"] === true || $recording["missed_call"] === 't',
        "voicemailMessage" => $recording["voicemail_message"] === true || $recording["voicemail_message"] === 't',
        "hangupCause" => $recording["hangup_cause"],
        "hangupCauseQ850" => $recording["hangup_cause_q850"]
    );

    // Build the full file path for the recording
    if (!empty($recording["call_recording_path"]) && !empty($recording["call_recording_name"])) {
        $result["fullRecordingPath"] = $recording["call_recording_path"] . "/" . $recording["call_recording_name"];
    }

    return array(
        "success" => true,
        "callRecording" => $result
    );
}
