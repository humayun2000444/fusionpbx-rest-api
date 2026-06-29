<?php

// Delete voicemail message(s)

$required_params = array('messageUuids');

function do_action($body) {
    $message_uuids = $body->messageUuids;
    $database = new database;

    if (!is_array($message_uuids) || empty($message_uuids)) {
        return array('success' => false, 'message' => 'messageUuids must be a non-empty array');
    }

    $deleted = 0;
    foreach ($message_uuids as $uuid) {
        $sql = "DELETE FROM v_voicemail_messages WHERE voicemail_message_uuid = :uuid";
        $result = $database->execute($sql, array('uuid' => $uuid));
        if ($result !== false) {
            $deleted++;
        }
    }

    return array(
        'success' => true,
        'message' => $deleted . ' message(s) deleted',
        'deletedCount' => $deleted
    );
}
