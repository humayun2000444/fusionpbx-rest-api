<?php

// Download/stream a voicemail message audio file

$required_params = array('messageUuid');

function do_action($body) {
    $message_uuid = $body->messageUuid;
    $database = new database;

    // Get the message info + domain name for file path
    $sql = "SELECT vm.voicemail_message_uuid, vm.message_base64, vm.caller_id_name,
                   vm.caller_id_number, vm.created_epoch, vm.message_length,
                   v.voicemail_id, d.domain_name
            FROM v_voicemail_messages vm
            JOIN v_voicemails v ON v.voicemail_uuid = vm.voicemail_uuid
            JOIN v_domains d ON d.domain_uuid = vm.domain_uuid
            WHERE vm.voicemail_message_uuid = :message_uuid";

    $row = $database->select($sql, array('message_uuid' => $message_uuid), 'row');

    if (empty($row)) {
        return array('success' => false, 'message' => 'Message not found');
    }

    // Try database first (message_base64), then fall back to file on disk
    $audio_base64 = null;
    $content_type = 'audio/wav';

    if (!empty($row['message_base64'])) {
        $audio_base64 = $row['message_base64'];
    } else {
        // Read from file: /var/lib/freeswitch/storage/voicemail/default/{domain}/{ext}/msg_{uuid}.wav
        $voicemail_dir = '/var/lib/freeswitch/storage/voicemail/default';
        $file_path = $voicemail_dir . '/' . $row['domain_name'] . '/' . $row['voicemail_id'] . '/msg_' . $message_uuid . '.wav';

        if (file_exists($file_path)) {
            $audio_data = file_get_contents($file_path);
            if ($audio_data !== false) {
                $audio_base64 = base64_encode($audio_data);
            }
        }

        // Try .mp3 if .wav not found
        if (empty($audio_base64)) {
            $file_path_mp3 = $voicemail_dir . '/' . $row['domain_name'] . '/' . $row['voicemail_id'] . '/msg_' . $message_uuid . '.mp3';
            if (file_exists($file_path_mp3)) {
                $audio_data = file_get_contents($file_path_mp3);
                if ($audio_data !== false) {
                    $audio_base64 = base64_encode($audio_data);
                    $content_type = 'audio/mpeg';
                }
            }
        }
    }

    if (empty($audio_base64)) {
        return array('success' => false, 'message' => 'No audio data available');
    }

    // Mark as read
    $sql = "UPDATE v_voicemail_messages SET message_status = 'saved', read_epoch = :epoch
            WHERE voicemail_message_uuid = :message_uuid AND (message_status IS NULL OR message_status = '')";
    $database->execute($sql, array(
        'message_uuid' => $message_uuid,
        'epoch' => time()
    ));

    return array(
        'success' => true,
        'messageUuid' => $row['voicemail_message_uuid'],
        'voicemailId' => $row['voicemail_id'],
        'callerIdName' => $row['caller_id_name'],
        'callerIdNumber' => $row['caller_id_number'],
        'createdEpoch' => (int)$row['created_epoch'],
        'messageLengthSeconds' => (int)$row['message_length'],
        'audioBase64' => $audio_base64,
        'audioContentType' => $content_type
    );
}
