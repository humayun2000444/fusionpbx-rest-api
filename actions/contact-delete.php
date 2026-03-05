<?php

$required_params = array("contact_uuid");

function do_action($body) {
    global $domain_uuid;

    $contact_uuid = isset($body->contact_uuid) ? $body->contact_uuid :
                   (isset($body->contactUuid) ? $body->contactUuid : null);

    if (empty($contact_uuid)) {
        return array(
            "success" => false,
            "error" => "contact_uuid is required"
        );
    }

    $database = new database;

    // Verify contact exists
    $check_sql = "SELECT contact_uuid FROM v_contacts WHERE contact_uuid = :contact_uuid";
    $existing = $database->select($check_sql, array("contact_uuid" => $contact_uuid), 'row');

    if (empty($existing)) {
        return array(
            "success" => false,
            "error" => "Contact not found"
        );
    }

    // Delete related records first (foreign key constraints)
    $related_tables = array(
        "v_contact_phones",
        "v_contact_emails",
        "v_contact_addresses",
        "v_contact_notes",
        "v_contact_urls",
        "v_contact_relations",
        "v_contact_times",
        "v_contact_settings",
        "v_contact_groups",
        "v_contact_users",
        "v_contact_attachments"
    );

    try {
        foreach ($related_tables as $table) {
            $delete_sql = "DELETE FROM $table WHERE contact_uuid = :contact_uuid";
            $database->execute($delete_sql, array("contact_uuid" => $contact_uuid));
        }

        // Delete the contact
        $sql = "DELETE FROM v_contacts WHERE contact_uuid = :contact_uuid";
        $database->execute($sql, array("contact_uuid" => $contact_uuid));
    } catch (Exception $e) {
        return array(
            "success" => false,
            "error" => "Failed to delete contact: " . $e->getMessage()
        );
    }

    // Verify deletion
    $verify_sql = "SELECT contact_uuid FROM v_contacts WHERE contact_uuid = :contact_uuid";
    $verify = $database->select($verify_sql, array("contact_uuid" => $contact_uuid), 'row');

    if (!empty($verify)) {
        return array(
            "success" => false,
            "error" => "Contact deletion failed"
        );
    }

    return array(
        "success" => true,
        "message" => "Contact deleted successfully",
        "contactUuid" => $contact_uuid
    );
}
