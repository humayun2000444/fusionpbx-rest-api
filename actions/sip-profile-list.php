<?php
/**
 * sip-profile-list: every SIP profile (Advanced > SIP Profiles) with its address, port, context and live state.
 */
require_once __DIR__ . '/sip-profile-helper.php';

$required_params = array();

function do_action($body) {
    $database = new database;
    $rows = $database->select('SELECT * FROM v_sip_profiles ORDER BY sip_profile_name', array(), 'all');
    $states = sip_profile_states();
    $profiles = array();
    foreach (is_array($rows) ? $rows : array() as $row) {
        $profiles[] = sip_profile_view($database, $row, false, $states);
    }
    return $profiles;
}
