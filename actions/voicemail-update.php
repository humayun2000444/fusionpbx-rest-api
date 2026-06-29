<?php

// Update voicemail settings

$required_params = array('voicemailUuid');

function do_action($body) {
    $voicemail_uuid = $body->voicemailUuid;
    $database = new database;

    // Verify voicemail exists
    $sql = "SELECT voicemail_uuid, voicemail_id FROM v_voicemails WHERE voicemail_uuid = :uuid";
    $vm = $database->select($sql, array('uuid' => $voicemail_uuid), 'row');
    if (empty($vm)) {
        return array('success' => false, 'message' => 'Voicemail not found');
    }

    // Build update fields
    $updates = array();
    $params = array('uuid' => $voicemail_uuid);

    if (isset($body->password)) {
        $updates[] = "voicemail_password = :password";
        $params['password'] = $body->password;
    }
    if (isset($body->mailTo)) {
        $updates[] = "voicemail_mail_to = :mail_to";
        $params['mail_to'] = $body->mailTo;
    }
    if (isset($body->enabled)) {
        $updates[] = "voicemail_enabled = :enabled";
        $params['enabled'] = $body->enabled;
    }
    if (isset($body->attachFile)) {
        $updates[] = "voicemail_attach_file = :attach_file";
        $params['attach_file'] = $body->attachFile;
    }
    if (isset($body->localAfterEmail)) {
        $updates[] = "voicemail_local_after_email = :local_after_email";
        $params['local_after_email'] = $body->localAfterEmail;
    }
    if (isset($body->greetingId)) {
        $updates[] = "greeting_id = :greeting_id";
        $params['greeting_id'] = $body->greetingId;
    }
    if (isset($body->transcriptionEnabled)) {
        $updates[] = "voicemail_transcription_enabled = :transcription";
        $params['transcription'] = $body->transcriptionEnabled;
    }
    if (isset($body->description)) {
        $updates[] = "voicemail_description = :description";
        $params['description'] = $body->description;
    }

    if (empty($updates)) {
        return array('success' => false, 'message' => 'No fields to update');
    }

    $sql = "UPDATE v_voicemails SET " . implode(', ', $updates) . " WHERE voicemail_uuid = :uuid";
    $database->execute($sql, $params);

    return array(
        'success' => true,
        'message' => 'Voicemail ' . $vm['voicemail_id'] . ' updated',
        'voicemailId' => $vm['voicemail_id']
    );
}
