<?php

$required_params = array();

function do_action($body) {
    global $domain_uuid, $domain_name;

    // Use domain_uuid from request if provided, otherwise use global
    $db_domain_uuid = isset($body->domain_uuid) ? $body->domain_uuid :
                     (isset($body->domainUuid) ? $body->domainUuid : $domain_uuid);

    // Optional params
    $device_uuid = isset($body->device_uuid) ? $body->device_uuid :
                  (isset($body->deviceUuid) ? $body->deviceUuid : null);
    $vendor = isset($body->vendor) ? strtolower($body->vendor) : null;
    $format = isset($body->format) ? strtolower($body->format) : null;

    $database = new database;

    // If deviceUuid provided, auto-detect vendor from v_devices
    if (!empty($device_uuid) && empty($format) && empty($vendor)) {
        $sql = "SELECT device_vendor FROM v_devices WHERE device_uuid = :device_uuid AND domain_uuid = :domain_uuid";
        $params = array("device_uuid" => $device_uuid, "domain_uuid" => $db_domain_uuid);
        $device_row = $database->select($sql, $params, "row");
        if ($device_row && !empty($device_row['device_vendor'])) {
            $vendor = strtolower($device_row['device_vendor']);
        }
    }

    // Determine output format from vendor or format param
    $output_format = determine_format($format, $vendor);

    // Query extensions
    $sql = "SELECT extension, effective_caller_id_name, description, enabled FROM v_extensions WHERE domain_uuid = :domain_uuid AND enabled = 'true' ORDER BY extension";
    $params = array("domain_uuid" => $db_domain_uuid);
    $extensions = $database->select($sql, $params, "all");
    if (!is_array($extensions)) {
        $extensions = array();
    }

    // Query contacts with phone numbers
    $sql = "SELECT c.contact_name_given, c.contact_name_family, c.contact_organization, p.phone_number, p.phone_type_voice, p.phone_label FROM v_contacts c LEFT JOIN v_contact_phones p ON c.contact_uuid = p.contact_uuid WHERE c.domain_uuid = :domain_uuid ORDER BY c.contact_name_given";
    $params = array("domain_uuid" => $db_domain_uuid);
    $contacts = $database->select($sql, $params, "all");
    if (!is_array($contacts)) {
        $contacts = array();
    }

    // Build unified entry arrays
    $ext_entries = array();
    foreach ($extensions as $ext) {
        $name = !empty($ext['effective_caller_id_name']) ? $ext['effective_caller_id_name'] : $ext['description'];
        if (empty($name)) {
            $name = 'Extension ' . $ext['extension'];
        }
        $ext_entries[] = array(
            "name" => $name,
            "number" => $ext['extension'],
            "group" => "Extensions"
        );
    }

    $contact_entries = array();
    foreach ($contacts as $ct) {
        // Skip contacts without a phone number
        if (empty($ct['phone_number'])) {
            continue;
        }
        $name_parts = array();
        if (!empty($ct['contact_name_given'])) {
            $name_parts[] = $ct['contact_name_given'];
        }
        if (!empty($ct['contact_name_family'])) {
            $name_parts[] = $ct['contact_name_family'];
        }
        $name = implode(' ', $name_parts);
        if (empty($name) && !empty($ct['contact_organization'])) {
            $name = $ct['contact_organization'];
        }
        if (empty($name)) {
            $name = $ct['phone_number'];
        }
        $contact_entries[] = array(
            "name" => $name,
            "number" => $ct['phone_number'],
            "group" => "Contacts"
        );
    }

    $all_entries = array_merge($ext_entries, $contact_entries);

    // Generate XML
    $xml = generate_phonebook_xml($output_format, $all_entries, $ext_entries, $contact_entries);

    return array(
        "success" => true,
        "format" => $output_format,
        "phonebookXml" => $xml,
        "entries" => count($all_entries),
        "extensions" => count($ext_entries),
        "contacts" => count($contact_entries)
    );
}

function determine_format($format, $vendor) {
    // If explicit format given, use it
    if (!empty($format)) {
        $known = array('grandstream', 'yealink', 'polycom', 'cisco', 'snom', 'dinstar', 'fanvil', 'generic');
        if (in_array($format, $known)) {
            return $format;
        }
    }

    // Map vendor name to format
    if (!empty($vendor)) {
        $vendor_map = array(
            'grandstream' => 'grandstream',
            'yealink'     => 'yealink',
            'polycom'     => 'polycom',
            'cisco'       => 'cisco',
            'snom'        => 'snom',
            'dinstar'     => 'dinstar',
            'fanvil'      => 'fanvil',
        );
        if (isset($vendor_map[$vendor])) {
            return $vendor_map[$vendor];
        }
    }

    return 'generic';
}

function xml_escape($str) {
    return htmlspecialchars($str, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

function generate_phonebook_xml($format, $all_entries, $ext_entries, $contact_entries) {
    switch ($format) {
        case 'grandstream':
            return generate_grandstream($ext_entries, $contact_entries);
        case 'yealink':
        case 'dinstar':
        case 'fanvil':
        case 'generic':
            return generate_yealink($all_entries);
        case 'polycom':
            return generate_polycom($all_entries);
        case 'cisco':
            return generate_cisco($all_entries);
        case 'snom':
            return generate_snom($all_entries);
        default:
            return generate_yealink($all_entries);
    }
}

function generate_grandstream($ext_entries, $contact_entries) {
    $xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    $xml .= '<AddressBook>' . "\n";

    // Group 0 = Extensions
    foreach ($ext_entries as $entry) {
        $xml .= '  <Contact>' . "\n";
        $xml .= '    <LastName>' . xml_escape($entry['name']) . '</LastName>' . "\n";
        $xml .= '    <FirstName></FirstName>' . "\n";
        $xml .= '    <Phone>' . "\n";
        $xml .= '      <phonenumber>' . xml_escape($entry['number']) . '</phonenumber>' . "\n";
        $xml .= '      <accountindex>0</accountindex>' . "\n";
        $xml .= '    </Phone>' . "\n";
        $xml .= '    <Group>0</Group>' . "\n";
        $xml .= '  </Contact>' . "\n";
    }

    // Group 1 = Contacts
    foreach ($contact_entries as $entry) {
        $xml .= '  <Contact>' . "\n";
        $xml .= '    <LastName>' . xml_escape($entry['name']) . '</LastName>' . "\n";
        $xml .= '    <FirstName></FirstName>' . "\n";
        $xml .= '    <Phone>' . "\n";
        $xml .= '      <phonenumber>' . xml_escape($entry['number']) . '</phonenumber>' . "\n";
        $xml .= '      <accountindex>0</accountindex>' . "\n";
        $xml .= '    </Phone>' . "\n";
        $xml .= '    <Group>1</Group>' . "\n";
        $xml .= '  </Contact>' . "\n";
    }

    $xml .= '</AddressBook>';
    return $xml;
}

function generate_yealink($entries) {
    $xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    $xml .= '<IPPhoneDirectory>' . "\n";

    foreach ($entries as $entry) {
        $xml .= '  <DirectoryEntry>' . "\n";
        $xml .= '    <Name>' . xml_escape($entry['name']) . '</Name>' . "\n";
        $xml .= '    <Telephone>' . xml_escape($entry['number']) . '</Telephone>' . "\n";
        $xml .= '  </DirectoryEntry>' . "\n";
    }

    $xml .= '</IPPhoneDirectory>';
    return $xml;
}

function generate_polycom($entries) {
    $xml  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
    $xml .= '<directory>' . "\n";
    $xml .= '  <item_list>' . "\n";

    $sd = 1;
    foreach ($entries as $entry) {
        $xml .= '    <item>' . "\n";
        $xml .= '      <fn>' . xml_escape($entry['name']) . '</fn>' . "\n";
        $xml .= '      <ct>' . xml_escape($entry['number']) . '</ct>' . "\n";
        $xml .= '      <sd>' . $sd . '</sd>' . "\n";
        $xml .= '    </item>' . "\n";
        $sd++;
    }

    $xml .= '  </item_list>' . "\n";
    $xml .= '</directory>';
    return $xml;
}

function generate_cisco($entries) {
    $xml  = '<CiscoIPPhoneDirectory>' . "\n";
    $xml .= '  <Title>Company Directory</Title>' . "\n";

    foreach ($entries as $entry) {
        $xml .= '  <DirectoryEntry>' . "\n";
        $xml .= '    <Name>' . xml_escape($entry['name']) . '</Name>' . "\n";
        $xml .= '    <Telephone>' . xml_escape($entry['number']) . '</Telephone>' . "\n";
        $xml .= '  </DirectoryEntry>' . "\n";
    }

    $xml .= '</CiscoIPPhoneDirectory>';
    return $xml;
}

function generate_snom($entries) {
    $xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    $xml .= '<tbook>' . "\n";

    foreach ($entries as $entry) {
        $xml .= '  <item context="default" type="none">' . "\n";
        $xml .= '    <name>' . xml_escape($entry['name']) . '</name>' . "\n";
        $xml .= '    <number>' . xml_escape($entry['number']) . '</number>' . "\n";
        $xml .= '  </item>' . "\n";
    }

    $xml .= '</tbook>';
    return $xml;
}
