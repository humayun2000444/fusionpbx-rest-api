<?php

$required_params = array();

function do_action($body) {
    global $domain_uuid;

    // Accept extension_uuid OR extension@domain format
    $extension_uuid = isset($body->extension_uuid) ? $body->extension_uuid :
                     (isset($body->extensionUuid) ? $body->extensionUuid : null);

    $extension_at_domain = isset($body->extension_at_domain) ? $body->extension_at_domain :
                          (isset($body->extensionAtDomain) ? $body->extensionAtDomain : null);

    // Also accept separate extension and domain
    $extension = isset($body->extension) ? $body->extension : null;
    $domain_name = isset($body->domain_name) ? $body->domain_name :
                  (isset($body->domainName) ? $body->domainName : null);

    $database = new database;
    $user_uuid = null;
    $db_domain_uuid = null;

    // Method 1: By extension_uuid
    if (!empty($extension_uuid)) {
        // Get user_uuid from extension_users
        $sql = "SELECT eu.user_uuid, e.domain_uuid
                FROM v_extension_users eu
                JOIN v_extensions e ON e.extension_uuid = eu.extension_uuid
                WHERE eu.extension_uuid = :extension_uuid
                LIMIT 1";
        $result = $database->select($sql, array("extension_uuid" => $extension_uuid), 'row');

        if (!empty($result)) {
            $user_uuid = $result['user_uuid'];
            $db_domain_uuid = $result['domain_uuid'];
        }
    }
    // Method 2: By extension@domain format
    elseif (!empty($extension_at_domain)) {
        $parts = explode('@', $extension_at_domain);
        if (count($parts) == 2) {
            $extension = $parts[0];
            $domain_name = $parts[1];
        }
    }

    // Method 3: By separate extension and domain_name
    if (empty($user_uuid) && !empty($extension) && !empty($domain_name)) {
        // First get domain_uuid from domain_name
        $domain_sql = "SELECT domain_uuid FROM v_domains WHERE domain_name = :domain_name LIMIT 1";
        $domain_result = $database->select($domain_sql, array("domain_name" => $domain_name), 'row');

        if (!empty($domain_result)) {
            $db_domain_uuid = $domain_result['domain_uuid'];

            // Now get extension_uuid and user_uuid
            $sql = "SELECT eu.user_uuid, e.extension_uuid
                    FROM v_extensions e
                    LEFT JOIN v_extension_users eu ON eu.extension_uuid = e.extension_uuid
                    WHERE e.extension = :extension
                    AND e.domain_uuid = :domain_uuid
                    LIMIT 1";
            $result = $database->select($sql, array(
                "extension" => $extension,
                "domain_uuid" => $db_domain_uuid
            ), 'row');

            if (!empty($result)) {
                $user_uuid = $result['user_uuid'];
                $extension_uuid = $result['extension_uuid'];
            }
        }
    }

    // Validate we have what we need
    if (empty($extension_uuid) && empty($extension_at_domain) && (empty($extension) || empty($domain_name))) {
        return array(
            "success" => false,
            "error" => "Either extension_uuid, extension_at_domain (format: 1001@domain.com), or both extension and domain_name are required"
        );
    }

    if (empty($user_uuid)) {
        // No user linked to this extension, return empty contacts
        return array(
            "success" => true,
            "contacts" => array(),
            "count" => 0,
            "message" => "No user linked to this extension or extension not found"
        );
    }

    // Get contacts linked to this user
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
            JOIN v_contact_users cu ON cu.contact_uuid = c.contact_uuid
            WHERE cu.user_uuid = :user_uuid
            ORDER BY c.contact_name_given, c.contact_name_family, c.contact_organization";

    $contacts = $database->select($sql, array("user_uuid" => $user_uuid), 'all');

    if (empty($contacts)) {
        return array(
            "success" => true,
            "contacts" => array(),
            "count" => 0,
            "extensionUuid" => $extension_uuid,
            "userUuid" => $user_uuid
        );
    }

    // Get phones and emails for contacts
    $contact_uuids = array_column($contacts, 'contact_uuid');
    $placeholders = array();
    $params = array();
    foreach ($contact_uuids as $i => $uuid) {
        $placeholders[] = ":uuid_$i";
        $params["uuid_$i"] = $uuid;
    }

    // Get phones
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
    $phones = $database->select($phones_sql, $params, 'all');

    // Get emails
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
    $emails = $database->select($emails_sql, $params, 'all');

    // Group by contact_uuid
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

    // Add to contacts
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
        "count" => count($contacts),
        "extensionUuid" => $extension_uuid,
        "userUuid" => $user_uuid
    );
}
