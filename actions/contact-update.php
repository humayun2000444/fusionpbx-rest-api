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
    $check_sql = "SELECT contact_uuid, domain_uuid FROM v_contacts WHERE contact_uuid = :contact_uuid";
    $existing = $database->select($check_sql, array("contact_uuid" => $contact_uuid), 'row');

    if (empty($existing)) {
        return array(
            "success" => false,
            "error" => "Contact not found"
        );
    }

    $db_domain_uuid = $existing['domain_uuid'];

    // Build update query dynamically
    $updates = array();
    $parameters = array("contact_uuid" => $contact_uuid);

    // Map of field names (both snake_case and camelCase)
    $field_mappings = array(
        "contact_type" => array("contact_type", "contactType"),
        "contact_organization" => array("contact_organization", "contactOrganization"),
        "contact_name_prefix" => array("contact_name_prefix", "contactNamePrefix"),
        "contact_name_given" => array("contact_name_given", "contactNameGiven"),
        "contact_name_middle" => array("contact_name_middle", "contactNameMiddle"),
        "contact_name_family" => array("contact_name_family", "contactNameFamily"),
        "contact_name_suffix" => array("contact_name_suffix", "contactNameSuffix"),
        "contact_nickname" => array("contact_nickname", "contactNickname"),
        "contact_title" => array("contact_title", "contactTitle"),
        "contact_role" => array("contact_role", "contactRole"),
        "contact_category" => array("contact_category", "contactCategory"),
        "contact_url" => array("contact_url", "contactUrl"),
        "contact_time_zone" => array("contact_time_zone", "contactTimeZone"),
        "contact_note" => array("contact_note", "contactNote")
    );

    foreach ($field_mappings as $db_field => $body_fields) {
        foreach ($body_fields as $body_field) {
            if (isset($body->$body_field)) {
                $updates[] = "$db_field = :$db_field";
                $parameters[$db_field] = $body->$body_field;
                break;
            }
        }
    }

    // Always update the update_date
    $updates[] = "update_date = NOW()";

    if (count($updates) <= 1) {
        return array(
            "success" => false,
            "error" => "No fields to update"
        );
    }

    $sql = "UPDATE v_contacts SET " . implode(", ", $updates) . " WHERE contact_uuid = :contact_uuid";

    try {
        $database->execute($sql, $parameters);
    } catch (Exception $e) {
        return array(
            "success" => false,
            "error" => "Failed to update contact: " . $e->getMessage()
        );
    }

    // Handle phones update if provided
    $phones = isset($body->phones) ? $body->phones : null;
    if ($phones !== null) {
        // Delete existing phones
        $database->execute(
            "DELETE FROM v_contact_phones WHERE contact_uuid = :contact_uuid",
            array("contact_uuid" => $contact_uuid)
        );

        // Insert new phones
        foreach ($phones as $phone) {
            $phone_uuid = uuid();
            $phone_sql = "INSERT INTO v_contact_phones (
                            contact_phone_uuid, domain_uuid, contact_uuid,
                            phone_label, phone_number, phone_extension,
                            phone_primary, phone_description, insert_date
                          ) VALUES (
                            :phone_uuid, :domain_uuid, :contact_uuid,
                            :phone_label, :phone_number, :phone_extension,
                            :phone_primary, :phone_description, NOW()
                          )";
            $phone_params = array(
                "phone_uuid" => $phone_uuid,
                "domain_uuid" => $db_domain_uuid,
                "contact_uuid" => $contact_uuid,
                "phone_label" => isset($phone->phone_label) ? $phone->phone_label : (isset($phone->phoneLabel) ? $phone->phoneLabel : null),
                "phone_number" => isset($phone->phone_number) ? $phone->phone_number : (isset($phone->phoneNumber) ? $phone->phoneNumber : null),
                "phone_extension" => isset($phone->phone_extension) ? $phone->phone_extension : (isset($phone->phoneExtension) ? $phone->phoneExtension : null),
                "phone_primary" => isset($phone->phone_primary) ? ($phone->phone_primary ? 1 : 0) : (isset($phone->phonePrimary) ? ($phone->phonePrimary ? 1 : 0) : 0),
                "phone_description" => isset($phone->phone_description) ? $phone->phone_description : (isset($phone->phoneDescription) ? $phone->phoneDescription : null)
            );
            $database->execute($phone_sql, $phone_params);
        }
    }

    // Handle emails update if provided
    $emails = isset($body->emails) ? $body->emails : null;
    if ($emails !== null) {
        // Delete existing emails
        $database->execute(
            "DELETE FROM v_contact_emails WHERE contact_uuid = :contact_uuid",
            array("contact_uuid" => $contact_uuid)
        );

        // Insert new emails
        foreach ($emails as $email) {
            $email_uuid = uuid();
            $email_sql = "INSERT INTO v_contact_emails (
                            contact_email_uuid, domain_uuid, contact_uuid,
                            email_label, email_address, email_primary,
                            email_description, insert_date
                          ) VALUES (
                            :email_uuid, :domain_uuid, :contact_uuid,
                            :email_label, :email_address, :email_primary,
                            :email_description, NOW()
                          )";
            $email_params = array(
                "email_uuid" => $email_uuid,
                "domain_uuid" => $db_domain_uuid,
                "contact_uuid" => $contact_uuid,
                "email_label" => isset($email->email_label) ? $email->email_label : (isset($email->emailLabel) ? $email->emailLabel : null),
                "email_address" => isset($email->email_address) ? $email->email_address : (isset($email->emailAddress) ? $email->emailAddress : null),
                "email_primary" => isset($email->email_primary) ? ($email->email_primary ? 1 : 0) : (isset($email->emailPrimary) ? ($email->emailPrimary ? 1 : 0) : 0),
                "email_description" => isset($email->email_description) ? $email->email_description : (isset($email->emailDescription) ? $email->emailDescription : null)
            );
            $database->execute($email_sql, $email_params);
        }
    }

    return array(
        "success" => true,
        "message" => "Contact updated successfully",
        "contactUuid" => $contact_uuid
    );
}
