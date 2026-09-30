<?php

/**
 * time-condition-helper.php
 *
 * Shared by time-condition-create.php and time-condition-update.php. Both write
 * the same rows for the same payload, so the pieces that have to match the
 * FusionPBX GUI exactly live here once instead of being kept in step by hand.
 */

if (!function_exists('time_condition_resolve_recording')) {
	/**
	 * Resolve the audio ("Recordings") destination.
	 *
	 * The GUI stores it as the lua application with the data
	 * "streamfile.lua <recording filename>" - see the recordings entry in
	 * app/recordings/app_config.php, whose dialplan template is
	 * "lua:streamfile.lua ${recording_filename}". streamfile.lua takes the bare
	 * filename, strips any directory from it and looks it up under THIS
	 * domain's recordings directory, so a path assembled by the caller is both
	 * unnecessary and, if the base is wrong, silent: the prompt plays as
	 * nothing at all.
	 *
	 * A client may also send the uuid form the IVR endpoints already accept -
	 * "recording:<uuid>", on its own or after "streamfile.lua " - which is
	 * resolved here against this domain's own recordings.
	 */
	function time_condition_resolve_recording($database, $data, $domain_uuid) {
		$data = trim((string) $data);
		if ($data === '') { return $data; }

		$prefix = '';
		$value = $data;
		if (preg_match('/^(streamfile\.lua\s+)(.*)$/i', $data, $m)) {
			$prefix = 'streamfile.lua ';
			$value = trim($m[2]);
		}

		if (preg_match('/^recording:([0-9a-fA-F-]{36})$/', $value, $m)) {
			$rec = $database->select(
				"SELECT recording_filename FROM v_recordings "
				."WHERE recording_uuid = :recording_uuid AND domain_uuid = :domain_uuid",
				array("recording_uuid" => $m[1], "domain_uuid" => $domain_uuid), "row"
			);
			// An unknown uuid is left alone rather than blanked: a wrong prompt
			// is easier to notice and fix than an action with no data at all.
			if (empty($rec['recording_filename'])) { return $data; }
			$value = $rec['recording_filename'];
			if ($prefix === '') { $prefix = 'streamfile.lua '; }
		}

		if ($prefix !== '') { $value = basename($value); }

		return $prefix . $value;
	}
}

if (!function_exists('time_condition_normalize_action')) {
	/**
	 * Turn the {action, actionData} pair a client sends into the
	 * dialplan_detail_type / dialplan_detail_data pair the FusionPBX GUI would
	 * have written for the same choice.
	 *
	 * Returns array($type, $data).
	 */
	function time_condition_normalize_action($database, $action_type, $action_data, $domain_uuid, $domain_name) {
		$action_type = trim((string) $action_type);
		$action_data = trim((string) $action_data);
		if ($action_type === '') { $action_type = 'transfer'; }

		if ($action_type === 'transfer') {
			// A transfer without a dialplan and context is accepted by the
			// database and ignored by FreeSWITCH, so the context is appended
			// here for callers that send a bare extension.
			if ($action_data !== '' && strpos($action_data, 'XML') === false) {
				$action_data = $action_data . ' XML ' . $domain_name;
			}
			return array($action_type, $action_data);
		}

		if ($action_type === 'lua') {
			$action_data = time_condition_resolve_recording($database, $action_data, $domain_uuid);
		}

		// Everything else - hangup, playback, phrase, set, sleep, answer -
		// passes through untouched, exactly as the GUI stores it.
		return array($action_type, $action_data);
	}
}

if (!function_exists('time_condition_rebuild_xml')) {
	/**
	 * Regenerate v_dialplans.dialplan_xml from the detail rows and drop the
	 * cached dialplan for the domain - the same two steps
	 * app/time_conditions/time_condition_edit.php performs immediately after it
	 * saves the details.
	 *
	 * This is not cosmetic. FreeSWITCH is served the dialplan_xml column
	 * verbatim: the XML handler selects that one column
	 * (app/xml_handler/resources/scripts/dialplan/dialplan.lua) and never looks
	 * at v_dialplan_details. Writing only the detail rows therefore produces a
	 * time condition that this portal and the FusionPBX GUI both display
	 * correctly and that the switch has never heard of - the extension does not
	 * exist, the call goes nowhere, and no log anywhere says why.
	 *
	 * Returns a short status string for the API response.
	 */
	function time_condition_rebuild_xml($dialplan_uuid, $domain_name) {
		$dialplan_class = $_SERVER["DOCUMENT_ROOT"] . "/app/dialplans/resources/classes/dialplan.php";
		if (!file_exists($dialplan_class)) {
			return "dialplan class not found at " . $dialplan_class;
		}
		if (!class_exists('dialplan')) {
			require_once $dialplan_class;
		}

		$dialplans = new dialplan;
		$dialplans->source = "details";
		$dialplans->destination = "database";
		$dialplans->uuid = $dialplan_uuid;
		$dialplans->xml();

		// Clearing the cache without regenerating would only make FreeSWITCH
		// re-read the same stale column, so it belongs with the step above.
		if (class_exists('cache')) {
			$cache = new cache;
			$cache->delete("dialplan:" . $domain_name);
		}

		// Report what actually landed rather than assuming, so a caller can
		// tell "saved" from "saved and live".
		$check = new database;
		$row = $check->select(
			"SELECT dialplan_xml FROM v_dialplans WHERE dialplan_uuid = :dialplan_uuid",
			array("dialplan_uuid" => $dialplan_uuid), "row"
		);
		return empty($row['dialplan_xml']) ? "dialplan_xml is still empty" : "dialplan_xml regenerated";
	}
}
