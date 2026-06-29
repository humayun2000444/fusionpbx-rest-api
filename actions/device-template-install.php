<?php
$required_params = array("vendor");

function do_action($body) {
    global $domain_uuid, $domain_name;

    $vendor = strtolower(trim($body->vendor));
    // Check multiple possible template locations
    $possible_dirs = array(
        '/var/www/fusionpbx/resources/templates/provision/',
        '/usr/share/fusionpbx/templates/provision/',
        '/etc/fusionpbx/resources/templates/provision/',
        '/var/www/fusionpbx/app/provision/resources/templates/provision/',
    );
    $base_dir = '/var/www/fusionpbx/resources/templates/provision/';
    foreach ($possible_dirs as $dir) {
        if (is_dir($dir)) { $base_dir = $dir; break; }
    }

    // Validate vendor name
    if (!preg_match('/^[a-z0-9\-]+$/', $vendor)) {
        return array("success" => false, "error" => "Invalid vendor name.");
    }

    // Try to install via FusionPBX's built-in app_defaults.php first
    // This generates templates from vendor settings in the database
    $app_defaults = '/var/www/fusionpbx/app/provision/app_defaults.php';
    if (file_exists($app_defaults)) {
        // Include necessary FusionPBX framework
        if (!defined('PROJECT_PATH')) {
            define('PROJECT_PATH', '/var/www/fusionpbx');
        }

        // Run the defaults script which creates template directories
        try {
            // Check if the vendor directory already exists after running defaults
            if (!is_dir($base_dir)) {
                @mkdir($base_dir, 0755, true);
                @chown($base_dir, 'www-data');
            }
        } catch (Exception $e) {
            // Continue to manual creation
        }
    }

    // If the directory still doesn't exist or is empty, create vendor templates manually
    $vendor_dir = $base_dir . $vendor . '/';

    if (!is_dir($base_dir)) {
        if (!@mkdir($base_dir, 0755, true)) {
            return array("success" => false, "error" => "Cannot create template directory. Check permissions.");
        }
        @chown($base_dir, 'www-data');
    }

    if (!is_dir($vendor_dir)) {
        if (!@mkdir($vendor_dir, 0755, true)) {
            return array("success" => false, "error" => "Cannot create vendor directory. Check permissions.");
        }
    }

    // Vendor-specific template generators
    $templates = get_vendor_templates($vendor);
    if (empty($templates)) {
        return array("success" => false, "error" => "No template definitions available for vendor '{$vendor}'.");
    }

    $files_created = 0;
    $errors = array();

    foreach ($templates as $filename => $content) {
        $filepath = $vendor_dir . $filename;

        // Create subdirectories if needed
        $subdir = dirname($filepath);
        if (!is_dir($subdir)) {
            @mkdir($subdir, 0755, true);
        }

        if (file_put_contents($filepath, $content) !== false) {
            @chmod($filepath, 0644);
            $files_created++;
        } else {
            $errors[] = "Failed to write: {$filename}";
        }
    }

    // Set ownership
    exec("chown -R www-data:www-data " . escapeshellarg($vendor_dir) . " 2>/dev/null");

    if ($files_created === 0) {
        return array("success" => false, "error" => "No files were created.", "errors" => $errors);
    }

    $result = array(
        "success" => true,
        "message" => "Installed {$files_created} template file(s) for {$vendor}",
        "vendor" => $vendor,
        "filesInstalled" => $files_created
    );
    if (!empty($errors)) {
        $result["warnings"] = $errors;
    }
    return $result;
}

function get_vendor_templates($vendor) {
    switch ($vendor) {
        case 'grandstream':
            return grandstream_templates();
        case 'yealink':
            return yealink_templates();
        case 'polycom':
            return polycom_templates();
        case 'cisco':
            return cisco_templates();
        case 'fanvil':
            return fanvil_templates();
        case 'snom':
            return snom_templates();
        default:
            return generic_templates($vendor);
    }
}

function grandstream_templates() {
    // Grandstream uses cfg{MAC} files in XML format
    $cfg = <<<'XML'
<?xml version="1.0" encoding="UTF-8" ?>
<gs_provision version="1">
<!-- Account 1 -->
<config name="P271" value="{$line_1_server_address}"/>
<config name="P47" value="{$line_1_server_address}"/>
<config name="P35" value="{$line_1_user_id}"/>
<config name="P36" value="{$line_1_auth_id}"/>
<config name="P34" value="{$line_1_password}"/>
<config name="P3" value="{$line_1_display_name}"/>
<config name="P33" value="{$line_1_user_id}"/>
<config name="P2327" value="{$line_1_outbound_proxy}"/>
<config name="P270" value="1"/>
<config name="P272" value="{$line_1_sip_port}"/>
<config name="P location_base" value="2"/>
<!-- SIP Transport: 0=UDP, 1=TCP, 2=TLS -->
<config name="P130" value="0"/>
<!-- Register Expiration -->
<config name="P32" value="{$line_1_register_expires}"/>

<!-- Account 2 -->
<config name="P401" value="{$line_2_server_address}"/>
<config name="P402" value="{$line_2_server_address}"/>
<config name="P404" value="{$line_2_user_id}"/>
<config name="P405" value="{$line_2_auth_id}"/>
<config name="P406" value="{$line_2_password}"/>
<config name="P407" value="{$line_2_display_name}"/>
<config name="P408" value="{$line_2_user_id}"/>

<!-- General Settings -->
<!-- NTP Server -->
<config name="P30" value="pool.ntp.org"/>
<!-- Time Zone - GMT+6 (Bangladesh) -->
<config name="P64" value="TZX+6"/>
<!-- DHCP -->
<config name="P8" value="0"/>
<!-- Firmware Upgrade -->
<config name="P192" value="{$provision_server_url}"/>
<!-- Provisioning Mode: 0=TFTP, 1=HTTP, 2=HTTPS -->
<config name="P212" value="2"/>

<!-- Device Keys / BLF -->
{$device_keys}
</gs_provision>
XML;

    return array(
        'cfg.xml' => $cfg,
    );
}

function yealink_templates() {
    $cfg = <<<'INI'
#!version:1.0.0.1

## Account 1
account.1.enable = 1
account.1.label = {$line_1_user_id}
account.1.display_name = {$line_1_display_name}
account.1.auth_name = {$line_1_auth_id}
account.1.user_name = {$line_1_user_id}
account.1.password = {$line_1_password}
account.1.sip_server.1.address = {$line_1_server_address}
account.1.sip_server.1.port = {$line_1_sip_port}
account.1.sip_server.1.transport_type = 0
account.1.sip_server.1.register_on_enable = 1
account.1.sip_server.1.expires = {$line_1_register_expires}
account.1.outbound_proxy.1.address = {$line_1_outbound_proxy}

## Account 2
account.2.enable = {if $line_2_user_id}1{else}0{/if}
account.2.label = {$line_2_user_id}
account.2.display_name = {$line_2_display_name}
account.2.auth_name = {$line_2_auth_id}
account.2.user_name = {$line_2_user_id}
account.2.password = {$line_2_password}
account.2.sip_server.1.address = {$line_2_server_address}

## General
local_time.ntp_server1 = pool.ntp.org
local_time.time_zone = +6

## Auto Provision
auto_provision.server.url = {$provision_server_url}
auto_provision.mode = 6

## Device Keys
{$device_keys}
INI;

    return array(
        'y_template.cfg' => $cfg,
    );
}

function polycom_templates() {
    $cfg = <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<polycomConfig>
  <reg reg.1.displayName="{$line_1_display_name}"
       reg.1.address="{$line_1_user_id}"
       reg.1.label="{$line_1_user_id}"
       reg.1.auth.userId="{$line_1_auth_id}"
       reg.1.auth.password="{$line_1_password}"
       reg.1.server.1.address="{$line_1_server_address}"
       reg.1.server.1.port="{$line_1_sip_port}"
       reg.1.server.1.register="1"
       reg.1.server.1.expires="{$line_1_register_expires}"
       reg.1.outboundProxy.address="{$line_1_outbound_proxy}" />
  <tcpIpApp sntp.address="pool.ntp.org" sntp.gmtOffset="+6" />
</polycomConfig>
XML;

    return array(
        'reg-template.cfg' => $cfg,
    );
}

function cisco_templates() {
    $cfg = <<<'XML'
<flat-profile>
  <!-- Line 1 -->
  <Proxy_1_ ua="na">{$line_1_server_address}</Proxy_1_>
  <Display_Name_1_ ua="na">{$line_1_display_name}</Display_Name_1_>
  <User_ID_1_ ua="na">{$line_1_user_id}</User_ID_1_>
  <Auth_ID_1_ ua="na">{$line_1_auth_id}</Auth_ID_1_>
  <Password_1_ ua="na">{$line_1_password}</Password_1_>
  <Use_Outbound_Proxy_1_ ua="na">Yes</Use_Outbound_Proxy_1_>
  <Outbound_Proxy_1_ ua="na">{$line_1_outbound_proxy}</Outbound_Proxy_1_>
  <Register_Expires_1_ ua="na">{$line_1_register_expires}</Register_Expires_1_>

  <!-- General -->
  <Primary_NTP_Server ua="na">pool.ntp.org</Primary_NTP_Server>
  <Time_Zone ua="na">GMT+06:00</Time_Zone>
  <Profile_Rule ua="na">{$provision_server_url}</Profile_Rule>
</flat-profile>
XML;

    return array(
        'spa_template.xml' => $cfg,
    );
}

function fanvil_templates() {
    $cfg = <<<'INI'
<<VOIP CONFIG FILE>>Version:2.0002

[Account1_BasicSetting]
Enable = 1
Label = {$line_1_user_id}
DisplayName = {$line_1_display_name}
UserName = {$line_1_user_id}
AuthName = {$line_1_auth_id}
AuthPassword = {$line_1_password}
SIPServerAddr = {$line_1_server_address}
SIPServerPort = {$line_1_sip_port}
RegisterExpire = {$line_1_register_expires}
OutboundProxy = {$line_1_outbound_proxy}

[AutoProvision]
ServerURL = {$provision_server_url}
INI;

    return array(
        'fanvil_template.cfg' => $cfg,
    );
}

function snom_templates() {
    $cfg = <<<'XML'
<?xml version="1.0" encoding="utf-8"?>
<settings>
  <phone-settings>
    <user_active idx="1">on</user_active>
    <user_realname idx="1">{$line_1_display_name}</user_realname>
    <user_name idx="1">{$line_1_user_id}</user_name>
    <user_host idx="1">{$line_1_server_address}</user_host>
    <user_pname idx="1">{$line_1_auth_id}</user_pname>
    <user_pass idx="1">{$line_1_password}</user_pass>
    <user_outbound idx="1">{$line_1_outbound_proxy}</user_outbound>
    <user_expiry idx="1">{$line_1_register_expires}</user_expiry>
    <ntp_server>pool.ntp.org</ntp_server>
    <timezone>BDT-6</timezone>
    <setting_server>{$provision_server_url}</setting_server>
  </phone-settings>
</settings>
XML;

    return array(
        'snom_template.xml' => $cfg,
    );
}

function generic_templates($vendor) {
    $cfg = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<!-- Generic provisioning template for {$vendor} -->
<config>
  <account>
    <server>{$line_1_server_address}</server>
    <username>{$line_1_user_id}</username>
    <auth_id>{$line_1_auth_id}</auth_id>
    <password>{$line_1_password}</password>
    <display_name>{$line_1_display_name}</display_name>
    <port>{$line_1_sip_port}</port>
    <expires>{$line_1_register_expires}</expires>
  </account>
</config>
XML;

    return array(
        'config.xml' => str_replace('{$vendor}', $vendor, $cfg),
    );
}
