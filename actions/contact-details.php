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

    // Get contact details
    $sql = "SELECT
                c.contact_uuid,
                c.domain_uuid,
                c.contact_parent_uuid,
                c.contact_type,
                c.contact_organization,
                c.contact_name_prefix,
                c.contact_name_given,
                c.contact_name_middle,
                c.contact_name_family,
                c.contact_name_suffix,
                c.contact_nickname,
                c.contact_title,
                c.contact_role,
                c.contact_category,
                c.contact_url,
                c.contact_time_zone,
                c.contact_note,
                c.insert_date,
                c.update_date
            FROM v_contacts c
            WHERE c.contact_uuid = :contact_uuid";

    $parameters = array("contact_uuid" => $contact_uuid);
    $contact = $database->select($sql, $parameters, 'row');

    if (empty($contact)) {
        return array(
            "success" => false,
            "error" => "Contact not found"
        );
    }

    // Get phones
    $phones_sql = "SELECT
                        contact_phone_uuid,
                        contact_uuid,
                        phone_label,
                        phone_type_voice,
                        phone_type_fax,
                        phone_type_video,
                        phone_type_text,
                        phone_speed_dial,
                        phone_country_code,
                        phone_number,
                        phone_extension,
                        phone_primary,
                        phone_description
                    FROM v_contact_phones
                    WHERE contact_uuid = :contact_uuid
                    ORDER BY phone_primary DESC";
    $phones = $database->select($phones_sql, $parameters, 'all');

    // Get emails
    $emails_sql = "SELECT
                        contact_email_uuid,
                        contact_uuid,
                        email_label,
                        email_address,
                        email_primary,
                        email_description
                    FROM v_contact_emails
                    WHERE contact_uuid = :contact_uuid
                    ORDER BY email_primary DESC";
    $emails = $database->select($emails_sql, $parameters, 'all');

    // Get addresses
    $addresses_sql = "SELECT
                        contact_address_uuid,
                        contact_uuid,
                        address_label,
                        address_type,
                        address_street,
                        address_extended,
                        address_community,
                        address_locality,
                        address_region,
                        address_postal_code,
                        address_country,
                        address_latitude,
                        address_longitude,
                        address_primary,
                        address_description
                    FROM v_contact_addresses
                    WHERE contact_uuid = :contact_uuid
                    ORDER BY address_primary DESC";
    $addresses = $database->select($addresses_sql, $parameters, 'all');

    // Get notes
    $notes_sql = "SELECT
                        contact_note_uuid,
                        contact_uuid,
                        contact_note,
                        last_mod_date,
                        last_mod_user
                    FROM v_contact_notes
                    WHERE contact_uuid = :contact_uuid
                    ORDER BY last_mod_date DESC";
    $notes = $database->select($notes_sql, $parameters, 'all');

    $contact['phones'] = !empty($phones) ? $phones : array();
    $contact['emails'] = !empty($emails) ? $emails : array();
    $contact['addresses'] = !empty($addresses) ? $addresses : array();
    $contact['notes'] = !empty($notes) ? $notes : array();

    return array(
        "success" => true,
        "contact" => $contact
    );
}
