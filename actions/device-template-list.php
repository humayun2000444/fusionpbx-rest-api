<?php
$required_params = array();

function do_action($body) {
    global $domain_uuid, $domain_name;

    // Check multiple possible template locations (FusionPBX uses different paths)
    $possible_dirs = array(
        '/usr/share/fusionpbx/templates/provision/',
        '/etc/fusionpbx/resources/templates/provision/',
        '/var/www/fusionpbx/resources/templates/provision/',
        '/var/www/fusionpbx/app/provision/resources/templates/provision/',
        '/usr/local/share/fusionpbx/templates/provision/',
        '/usr/local/etc/fusionpbx/resources/templates/provision/',
    );

    $template_dir = null;
    foreach ($possible_dirs as $dir) {
        if (is_dir($dir)) {
            $template_dir = $dir;
            break;
        }
    }

    if (empty($template_dir)) {
        return array(
            "success" => true,
            "installed" => array(),
            "available" => array(),
            "templateDir" => null,
            "message" => "No template directory found"
        );
    }

    // Vendor display labels
    $vendor_labels = array(
        'aastra' => 'Aastra', 'acrobits' => 'Acrobits', 'algo' => 'Algo',
        'atcom' => 'ATCOM', 'avaya' => 'Avaya', 'cisco' => 'Cisco',
        'digium' => 'Digium', 'escene' => 'Escene', 'fanvil' => 'Fanvil',
        'flyingvoice' => 'FlyingVoice', 'grandstream' => 'Grandstream',
        'groundwire' => 'Groundwire', 'htek' => 'Htek', 'linksys' => 'Linksys',
        'linphone' => 'Linphone', 'mitel' => 'Mitel', 'obihai' => 'Obihai',
        'panasonic' => 'Panasonic', 'poly' => 'Poly', 'polycom' => 'Polycom',
        'sangoma' => 'Sangoma', 'sipnetic' => 'Sipnetic', 'snom' => 'Snom',
        'spectralink' => 'Spectralink', 'swissvoice' => 'SwissVoice',
        'telekonnectors' => 'Telekonnectors', 'vtech' => 'VTech',
        'yealink' => 'Yealink', 'yeastar' => 'Yeastar', 'zoiper' => 'Zoiper',
    );

    // Scan template directory for all vendors and their models
    $available = array();
    $entries = scandir($template_dir);
    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..' || !is_dir($template_dir . $entry)) continue;

        // Get models (subdirectories of vendor)
        $models = array();
        $file_count = 0;
        $vendor_path = $template_dir . $entry . '/';
        $sub_entries = scandir($vendor_path);
        foreach ($sub_entries as $sub) {
            if ($sub === '.' || $sub === '..') continue;
            if (is_dir($vendor_path . $sub)) {
                $models[] = strtoupper($sub);
            }
            if (is_file($vendor_path . $sub)) {
                $file_count++;
            }
        }

        // Count total files recursively
        $total_files = 0;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($vendor_path, RecursiveDirectoryIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if ($file->isFile()) $total_files++;
        }

        $label = isset($vendor_labels[$entry]) ? $vendor_labels[$entry] : ucfirst($entry);

        $available[] = array(
            "vendor" => $entry,
            "label" => $label,
            "models" => $models,
            "modelCount" => count($models),
            "fileCount" => $total_files,
            "installed" => true, // All found in directory are installed
        );
    }

    // Sort by label
    usort($available, function($a, $b) {
        return strcasecmp($a['label'], $b['label']);
    });

    return array(
        "success" => true,
        "templateDir" => $template_dir,
        "vendorCount" => count($available),
        "available" => $available,
    );
}
