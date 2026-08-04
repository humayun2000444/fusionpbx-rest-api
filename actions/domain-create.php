<?php

// Create a domain, doing exactly what the FusionPBX GUI does in
// core/domains/domain_edit.php (action == "add"): insert the domain, import the
// default dialplans, then create the recordings and voicemail directories.
//
// Until this existed the only way to create a domain was TelcoREST's JPA path
// (/api/v1/domains/create), which writes v_domains directly and then asks this
// API to add the dialplans over HTTP. That call ran inside an uncommitted
// transaction, so the domain was invisible to it and every domain came up with
// none of its ~34 default dialplans - no user_record, so per-extension call
// recording silently did nothing. Doing both steps in one place removes that
// whole class of failure.
//
// Parameters are accepted in snake_case or camelCase:
//   domain_name  | domainName         (required)
//   domain_description | description  (optional)
//   domain_enabled | enabled          (optional, default true - the GUI always
//                                      creates enabled; pass false deliberately)
//   time_zone      | timeZone         (optional, e.g. "Asia/Dhaka" - omit to
//                                      inherit the global domain/time_zone default)

$required_params = array();

// Mirrors the dialplan block of domain_edit.php. Guarded because
// domain-init-dialplans.php performs the same import and the two files must be
// able to coexist in one request without redeclaring.
if (!function_exists('domain_create_import_dialplans')) {
	function domain_create_import_dialplans($domain_uuid, $domain_name) {
		$dialplan_class = $_SERVER["DOCUMENT_ROOT"] . "/app/dialplans/resources/classes/dialplan.php";
		if (!file_exists($dialplan_class)) {
			return array('imported' => false, 'reason' => 'dialplan class not found at ' . $dialplan_class);
		}
		if (!class_exists('dialplan')) {
			require_once $dialplan_class;
		}

		$domains = array(array('domain_uuid' => $domain_uuid, 'domain_name' => $domain_name));

		$dialplan = new dialplan;
		$dialplan->import($domains);

		// Fill in dialplan_xml for any row the import left empty, exactly as the GUI does.
		$dialplans = new dialplan;
		$dialplans->source = "details";
		$dialplans->destination = "database";
		$dialplans->context = $domain_name;
		$dialplans->is_empty = "dialplan_xml";
		$dialplans->xml();

		return array('imported' => true);
	}
}

function do_action($body) {

	// Accept both spellings rather than forcing one on callers.
	$domain_name = null;
	if (isset($body->domain_name)) { $domain_name = $body->domain_name; }
	elseif (isset($body->domainName)) { $domain_name = $body->domainName; }

	if (empty($domain_name) || !is_string($domain_name)) {
		return array('code' => 400, 'success' => false, 'error' => 'domain_name is required');
	}

	// The GUI lower-cases the name; domain lookups elsewhere assume that.
	$domain_name = strtolower(trim($domain_name));

	// A malformed name yields a domain FreeSWITCH can never route to, and it is
	// awkward to remove afterwards, so reject it up front.
	if (!preg_match('/^[a-z0-9]([a-z0-9\-\.]*[a-z0-9])?$/', $domain_name) || strlen($domain_name) > 255) {
		return array('code' => 400, 'success' => false, 'error' => 'invalid domain_name: ' . $domain_name);
	}

	$domain_description = '';
	if (isset($body->domain_description)) { $domain_description = $body->domain_description; }
	elseif (isset($body->description)) { $domain_description = $body->description; }

	// The GUI hard-codes enabled on create; allow an explicit override.
	$domain_enabled = 'true';
	if (isset($body->domain_enabled)) { $domain_enabled = filter_var($body->domain_enabled, FILTER_VALIDATE_BOOLEAN) ? 'true' : 'false'; }
	elseif (isset($body->enabled)) { $domain_enabled = filter_var($body->enabled, FILTER_VALIDATE_BOOLEAN) ? 'true' : 'false'; }

	$database = new database;

	// Case-insensitive duplicate check, same query the GUI uses.
	$sql = "SELECT count(*) FROM v_domains WHERE lower(domain_name) = :domain_name";
	$num_rows = $database->select($sql, array('domain_name' => $domain_name), 'column');
	if ($num_rows > 0) {
		return array('code' => 409, 'success' => false, 'error' => 'domain already exists: ' . $domain_name);
	}

	$domain_uuid = uuid();

	$array['domains'][0]['domain_uuid'] = $domain_uuid;
	$array['domains'][0]['domain_name'] = $domain_name;
	$array['domains'][0]['domain_enabled'] = $domain_enabled;
	$array['domains'][0]['domain_description'] = $domain_description;

	// save() empties the array it is given and the dialplan import still needs it.
	$domain_array = $array;

	// $database->save() gates on permission_exists('domain_add'), and the dialplan
	// import below gates on the dialplan permissions. There is no logged-in user
	// over REST, so both would be refused with "Forbidden, does not have
	// 'domain_add'". permissions::new() is a singleton, so granting here is what
	// the later permission_exists() calls see. Note this is invisible from the
	// command line: permissions::exists() returns true outright when
	// defined('STDIN'), so a CLI test of this action passes either way.
	$granted = array('domain_add', 'domain_edit', 'dialplan_add', 'dialplan_edit');
	$permission = permissions::new();
	foreach ($granted as $p) {
		$permission->add($p, 'temp');
	}

	$database->app_name = 'domains';
	$database->app_uuid = '8b91605b-f6d2-42e6-a56d-5d1ded01bb44';
	$save_result = $database->save($array);
	unset($array);

	// save() leaves its own state behind; reusing the object for the verify read
	// returns nothing even when the insert succeeded. recording-create.php takes
	// a fresh object for the same reason.
	$database = new database;

	// Confirm the row landed before doing any of the follow-up work, so a failed
	// insert cannot be reported as a success.
	$sql = "SELECT domain_uuid FROM v_domains WHERE domain_uuid = :domain_uuid";
	$check = $database->select($sql, array('domain_uuid' => $domain_uuid), 'column');
	if (empty($check)) {
		foreach ($granted as $p) { $permission->delete($p, 'temp'); }
		return array(
			'code' => 500,
			'success' => false,
			'error' => 'failed to create domain ' . $domain_name,
			'save_result' => $save_result
		);
	}

	// Default dialplans - this is the step whose absence broke call recording.
	$import = domain_create_import_dialplans($domain_uuid, $domain_name);

	// Optional per-domain time zone. Without one the domain inherits the global
	// default setting (domain/time_zone); that default is what decides how the
	// FusionPBX UI renders CDR timestamps, so a wrong value shows every call at
	// the wrong wall-clock time even though start_stamp is stored correctly in UTC.
	$time_zone = null;
	if (isset($body->time_zone)) { $time_zone = trim($body->time_zone); }
	elseif (isset($body->timeZone)) { $time_zone = trim($body->timeZone); }

	$time_zone_set = null;
	if (!empty($time_zone)) {
		// Reject a bad identifier rather than storing it: PHP would fall back to
		// UTC at render time and the mistake would only surface as times being
		// silently hours out.
		if (!in_array($time_zone, timezone_identifiers_list(), true)) {
			$time_zone_error = 'invalid time_zone: ' . $time_zone;
		}
		else {
			$ts = array();
			$ts['domain_settings'][0]['domain_setting_uuid'] = uuid();
			$ts['domain_settings'][0]['domain_uuid'] = $domain_uuid;
			$ts['domain_settings'][0]['app_uuid'] = '2c2453c0-1bea-4475-9f44-4d969650de09';
			$ts['domain_settings'][0]['domain_setting_category'] = 'domain';
			$ts['domain_settings'][0]['domain_setting_subcategory'] = 'time_zone';
			$ts['domain_settings'][0]['domain_setting_name'] = 'name';
			$ts['domain_settings'][0]['domain_setting_value'] = $time_zone;
			$ts['domain_settings'][0]['domain_setting_enabled'] = 'true';

			$permission->add('domain_setting_add', 'temp');
			$db_ts = new database;
			$db_ts->app_name = 'domain_settings';
			$db_ts->app_uuid = '2c2453c0-1bea-4475-9f44-4d969650de09';
			$db_ts->save($ts);
			$permission->delete('domain_setting_add', 'temp');

			$verify = new database;
			$time_zone_set = $verify->select(
				"SELECT domain_setting_value FROM v_domain_settings
				 WHERE domain_uuid = :domain_uuid AND domain_setting_subcategory = 'time_zone'",
				array('domain_uuid' => $domain_uuid), 'column');
			if (empty($time_zone_set)) {
				$time_zone_error = 'time_zone could not be saved';
			}
		}
	}

	// Hand the elevated permissions back before returning.
	foreach ($granted as $p) { $permission->delete($p, 'temp'); }

	$sql = "SELECT count(*) FROM v_dialplans WHERE domain_uuid = :domain_uuid";
	$dialplan_count = (int) $database->select($sql, array('domain_uuid' => $domain_uuid), 'column');

	// Recordings and voicemail directories, as the GUI creates them. Resolved
	// from settings rather than $_SESSION, which does not exist over REST.
	$settings = new settings(array('domain_uuid' => $domain_uuid));
	$directories = array();

	$recordings_dir = $settings->get('switch', 'recordings', '/var/lib/freeswitch/recordings');
	if (!empty($recordings_dir) && !file_exists($recordings_dir . '/' . $domain_name)) {
		if (@mkdir($recordings_dir . '/' . $domain_name, 0770, true)) {
			$directories[] = $recordings_dir . '/' . $domain_name;
		}
	}

	$voicemail_dir = $settings->get('switch', 'voicemail', '/var/lib/freeswitch/storage/voicemail');
	if (!empty($voicemail_dir) && !file_exists($voicemail_dir . '/default/' . $domain_name)) {
		if (@mkdir($voicemail_dir . '/default/' . $domain_name, 0770, true)) {
			$directories[] = $voicemail_dir . '/default/' . $domain_name;
		}
	}

	// Make the new domain live without waiting for a cache expiry.
	if (class_exists('cache')) {
		$cache = new cache;
		$cache->delete("dialplan:" . $domain_name);
		$cache->delete("directory:" . $domain_name);
	}

	$reloaded = false;
	if (class_exists('event_socket')) {
		$esl = event_socket::create();
		if ($esl) {
			event_socket::api('reloadacl');
			event_socket::api('reloadxml');
			$reloaded = true;
		}
	}
	if (!$reloaded) {
		$reloaded = (shell_exec("/usr/bin/fs_cli -x 'reloadxml' 2>&1") !== null);
	}

	$response = array(
		'code' => 201,
		'success' => true,
		'message' => 'Domain created successfully',
		'domain_uuid' => $domain_uuid,
		'domain_name' => $domain_name,
		'domain_enabled' => $domain_enabled,
		'domain_description' => $domain_description,
		'dialplan_count' => $dialplan_count,
		'time_zone' => $time_zone_set,        // null = inherits the global default
		'directories_created' => $directories,
		'reloaded' => $reloaded
	);

	// The domain is usable without a time zone (it inherits the default), so this
	// is a warning rather than a failure - but say it, because the symptom
	// otherwise is just "the clock is wrong" months later.
	if (!empty($time_zone_error)) {
		$response['warning'] = $time_zone_error . ' - domain created and inherits the default time zone';
	}

	// A domain with no dialplans is the exact failure this action exists to
	// prevent, so surface it instead of returning a clean success.
	if (empty($import['imported']) || $dialplan_count == 0) {
		$response['success'] = false;
		$response['code'] = 500;
		$response['error'] = 'domain created but default dialplans were not imported'
			. (isset($import['reason']) ? ' (' . $import['reason'] . ')' : '')
			. ' - call recording and other defaults will not work until this is resolved';
	}

	return $response;
}
