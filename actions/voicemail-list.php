<?php

// List voicemails for a domain with message counts

$required_params = array('domainUuid');

function do_action($body) {
    $domain_uuid = $body->domainUuid;
    $database = new database;

    $sql = "SELECT v.voicemail_uuid, v.voicemail_id, v.voicemail_password,
                   v.voicemail_mail_to, v.voicemail_enabled, v.voicemail_description,
                   v.voicemail_attach_file, v.voicemail_local_after_email,
                   v.greeting_id, v.voicemail_transcription_enabled,
                   COALESCE(mc.msg_count, 0) as message_count,
                   COALESCE(mc.new_count, 0) as new_message_count,
                   e.extension, e.effective_caller_id_name
            FROM v_voicemails v
            LEFT JOIN (
                SELECT voicemail_uuid,
                       COUNT(*) as msg_count,
                       COUNT(*) FILTER (WHERE message_status IS NULL OR message_status = '') as new_count
                FROM v_voicemail_messages
                GROUP BY voicemail_uuid
            ) mc ON mc.voicemail_uuid = v.voicemail_uuid
            LEFT JOIN v_extensions e ON e.extension = v.voicemail_id AND e.domain_uuid = v.domain_uuid
            WHERE v.domain_uuid = :domain_uuid
            ORDER BY v.voicemail_id";

    $result = $database->select($sql, array('domain_uuid' => $domain_uuid), 'all');

    if (!is_array($result)) {
        $result = array();
    }

    $voicemails = array();
    foreach ($result as $row) {
        $voicemails[] = array(
            'voicemailUuid' => $row['voicemail_uuid'],
            'voicemailId' => $row['voicemail_id'],
            'extension' => $row['extension'],
            'callerIdName' => $row['effective_caller_id_name'],
            'password' => $row['voicemail_password'],
            'mailTo' => $row['voicemail_mail_to'],
            'enabled' => $row['voicemail_enabled'],
            'description' => $row['voicemail_description'],
            'attachFile' => $row['voicemail_attach_file'],
            'localAfterEmail' => $row['voicemail_local_after_email'],
            'greetingId' => $row['greeting_id'],
            'transcriptionEnabled' => $row['voicemail_transcription_enabled'],
            'messageCount' => (int)$row['message_count'],
            'newMessageCount' => (int)$row['new_message_count']
        );
    }

    return array(
        'success' => true,
        'total' => count($voicemails),
        'voicemails' => $voicemails
    );
}
