<?php

$required_params = array("domain_uuid");

function do_action($body) {
    global $domain_uuid;

    // Use domain_uuid from request if provided, otherwise use global
    $db_domain_uuid = isset($body->domain_uuid) ? $body->domain_uuid :
                     (isset($body->domainUuid) ? $body->domainUuid : $domain_uuid);

    if (empty($db_domain_uuid)) {
        return array(
            "success" => false,
            "error" => "domain_uuid is required"
        );
    }

    $database = new database;

    // Get all contacts for the domain
    $sql = "SELECT
                c.contact_uuid,
                c.domain_uuid,
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
            WHERE c.domain_uuid = :domain_uuid
            ORDER BY c.contact_name_given, c.contact_name_family, c.contact_organization";

    $parameters = array("domain_uuid" => $db_domain_uuid);
    $contacts = $database->select($sql, $parameters, 'all');

    if (empty($contacts)) {
        return array(
            "success" => true,
            "contacts" => array(),
            "count" => 0
        );
    }

    // Get phones for all contacts
    $contact_uuids = array_column($contacts, 'contact_uuid');
    $placeholders = array();
    $phone_params = array();
    foreach ($contact_uuids as $i => $uuid) {
        $placeholders[] = ":uuid_$i";
        $phone_params["uuid_$i"] = $uuid;
    }

    $phones_sql = "SELECT
                        contact_phone_uuid,
                        contact_uuid,
                        phone_label,
                        phone_number,
                        phone_extension,
                        phone_primary,
                        phone_description
                    FROM v_contact_phones
                    WHERE contact_uuid IN (" . implode(",", $placeholders) . ")
                    ORDER BY phone_primary DESC";
    $phones = $database->select($phones_sql, $phone_params, 'all');

    // Get emails for all contacts
    $emails_sql = "SELECT
                        contact_email_uuid,
                        contact_uuid,
                        email_label,
                        email_address,
                        email_primary,
                        email_description
                    FROM v_contact_emails
                    WHERE contact_uuid IN (" . implode(",", $placeholders) . ")
                    ORDER BY email_primary DESC";
    $emails = $database->select($emails_sql, $phone_params, 'all');

    // Group phones and emails by contact_uuid
    $phones_by_contact = array();
    $emails_by_contact = array();

    if (!empty($phones)) {
        foreach ($phones as $phone) {
            $phones_by_contact[$phone['contact_uuid']][] = $phone;
        }
    }

    if (!empty($emails)) {
        foreach ($emails as $email) {
            $emails_by_contact[$email['contact_uuid']][] = $email;
        }
    }

    // Add phones and emails to each contact
    foreach ($contacts as &$contact) {
        $contact['phones'] = isset($phones_by_contact[$contact['contact_uuid']])
                            ? $phones_by_contact[$contact['contact_uuid']]
                            : array();
        $contact['emails'] = isset($emails_by_contact[$contact['contact_uuid']])
                            ? $emails_by_contact[$contact['contact_uuid']]
                            : array();
    }

    return array(
        "success" => true,
        "contacts" => $contacts,
        "count" => count($contacts)
    );
}
