<?php

// List messages for a voicemail box

$required_params = array('voicemailUuid');

function do_action($body) {
    $voicemail_uuid = $body->voicemailUuid;
    $database = new database;

    // Get voicemail info
    $sql = "SELECT voicemail_id, domain_uuid FROM v_voicemails WHERE voicemail_uuid = :voicemail_uuid";
    $vm = $database->select($sql, array('voicemail_uuid' => $voicemail_uuid), 'row');
    if (empty($vm)) {
        return array('success' => false, 'message' => 'Voicemail not found');
    }

    // Get messages
    $sql = "SELECT voicemail_message_uuid, created_epoch, read_epoch,
                   caller_id_name, caller_id_number, message_length,
                   message_status, message_priority, message_transcription
            FROM v_voicemail_messages
            WHERE voicemail_uuid = :voicemail_uuid
            ORDER BY created_epoch DESC";

    $result = $database->select($sql, array('voicemail_uuid' => $voicemail_uuid), 'all');

    if (!is_array($result)) {
        $result = array();
    }

    $messages = array();
    foreach ($result as $row) {
        $messages[] = array(
            'messageUuid' => $row['voicemail_message_uuid'],
            'createdEpoch' => (int)$row['created_epoch'],
            'createdDate' => date('Y-m-d H:i:s', (int)$row['created_epoch']),
            'readEpoch' => $row['read_epoch'] ? (int)$row['read_epoch'] : null,
            'callerIdName' => $row['caller_id_name'],
            'callerIdNumber' => $row['caller_id_number'],
            'messageLengthSeconds' => (int)$row['message_length'],
            'status' => empty($row['message_status']) ? 'new' : $row['message_status'],
            'priority' => $row['message_priority'],
            'transcription' => $row['message_transcription']
        );
    }

    return array(
        'success' => true,
        'voicemailId' => $vm['voicemail_id'],
        'total' => count($messages),
        'messages' => $messages
    );
}
