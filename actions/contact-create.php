<?php

$required_params = array();

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

    // Extract contact fields
    $contact_type = isset($body->contact_type) ? $body->contact_type :
                   (isset($body->contactType) ? $body->contactType : null);
    $contact_organization = isset($body->contact_organization) ? $body->contact_organization :
                           (isset($body->contactOrganization) ? $body->contactOrganization : null);
    $contact_name_prefix = isset($body->contact_name_prefix) ? $body->contact_name_prefix :
                          (isset($body->contactNamePrefix) ? $body->contactNamePrefix : null);
    $contact_name_given = isset($body->contact_name_given) ? $body->contact_name_given :
                         (isset($body->contactNameGiven) ? $body->contactNameGiven : null);
    $contact_name_middle = isset($body->contact_name_middle) ? $body->contact_name_middle :
                          (isset($body->contactNameMiddle) ? $body->contactNameMiddle : null);
    $contact_name_family = isset($body->contact_name_family) ? $body->contact_name_family :
                          (isset($body->contactNameFamily) ? $body->contactNameFamily : null);
    $contact_name_suffix = isset($body->contact_name_suffix) ? $body->contact_name_suffix :
                          (isset($body->contactNameSuffix) ? $body->contactNameSuffix : null);
    $contact_nickname = isset($body->contact_nickname) ? $body->contact_nickname :
                       (isset($body->contactNickname) ? $body->contactNickname : null);
    $contact_title = isset($body->contact_title) ? $body->contact_title :
                    (isset($body->contactTitle) ? $body->contactTitle : null);
    $contact_role = isset($body->contact_role) ? $body->contact_role :
                   (isset($body->contactRole) ? $body->contactRole : null);
    $contact_category = isset($body->contact_category) ? $body->contact_category :
                       (isset($body->contactCategory) ? $body->contactCategory : null);
    $contact_url = isset($body->contact_url) ? $body->contact_url :
                  (isset($body->contactUrl) ? $body->contactUrl : null);
    $contact_time_zone = isset($body->contact_time_zone) ? $body->contact_time_zone :
                        (isset($body->contactTimeZone) ? $body->contactTimeZone : null);
    $contact_note = isset($body->contact_note) ? $body->contact_note :
                   (isset($body->contactNote) ? $body->contactNote : null);

    // At least one identifying field is required
    if (empty($contact_name_given) && empty($contact_name_family) && empty($contact_organization)) {
        return array(
            "success" => false,
            "error" => "At least one of contact_name_given, contact_name_family, or contact_organization is required"
        );
    }

    $database = new database;

    // Generate UUID
    $contact_uuid = uuid();

    // Build insert query
    $columns = array("contact_uuid", "domain_uuid", "insert_date");
    $values = array(":contact_uuid", ":domain_uuid", "NOW()");
    $parameters = array(
        "contact_uuid" => $contact_uuid,
        "domain_uuid" => $db_domain_uuid
    );

    // Add optional fields
    $optional_fields = array(
        "contact_type" => $contact_type,
        "contact_organization" => $contact_organization,
        "contact_name_prefix" => $contact_name_prefix,
        "contact_name_given" => $contact_name_given,
        "contact_name_middle" => $contact_name_middle,
        "contact_name_family" => $contact_name_family,
        "contact_name_suffix" => $contact_name_suffix,
        "contact_nickname" => $contact_nickname,
        "contact_title" => $contact_title,
        "contact_role" => $contact_role,
        "contact_category" => $contact_category,
        "contact_url" => $contact_url,
        "contact_time_zone" => $contact_time_zone,
        "contact_note" => $contact_note
    );

    foreach ($optional_fields as $field => $value) {
        if (!empty($value)) {
            $columns[] = $field;
            $values[] = ":" . $field;
            $parameters[$field] = $value;
        }
    }

    $sql = "INSERT INTO v_contacts (" . implode(", ", $columns) . ") VALUES (" . implode(", ", $values) . ")";

    try {
        $database->execute($sql, $parameters);
    } catch (Exception $e) {
        return array(
            "success" => false,
            "error" => "Failed to create contact: " . $e->getMessage()
        );
    }

    // Add phones if provided
    $phones = isset($body->phones) ? $body->phones : array();
    if (!empty($phones)) {
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

    // Add emails if provided
    $emails = isset($body->emails) ? $body->emails : array();
    if (!empty($emails)) {
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
        "message" => "Contact created successfully",
        "contactUuid" => $contact_uuid
    );
}
