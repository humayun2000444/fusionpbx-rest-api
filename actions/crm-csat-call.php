<?php
/*
 * crm-csat-call — ring one customer for the CRM's satisfaction survey.
 *
 * SEPARATE FROM SMART IVR, on purpose. The survey used to be placed through
 * the order-confirm engine, which meant a company had to switch Smart IVR
 * calling on to use CSAT, borrow Smart IVR's caller ID, and paste a personal
 * API token into the CSAT settings. This action needs none of that: the CRM
 * calls it with its own PBX key, and nothing here reads or writes any Smart
 * IVR setting.
 *
 * NOTHING IS SPOKEN BY A MACHINE. The question is the company's own recording
 * and so is the optional thank-you; there is no text-to-speech anywhere in
 * the call (crm_csat.lua).
 *
 * THE CALL LEAVES LIKE ANY OTHER. The number is matched against the domain's
 * own enabled outbound routes in order, and the first that matches decides the
 * gateway and the digits sent — the same Default route the rest of the
 * company's calls take — rather than the first gateway found.
 *
 * params:
 *   domainUuid             the company's domain (the CRM stamps it)
 *   phone                  the customer's number
 *   callerIdNumber         the company number the customer sees (required)
 *   callerIdName           optional
 *   recordingUuid          the question, one of the domain's recordings (required)
 *   thanksRecordingUuid    optional, played after a valid keypress
 *   scale                  3 or 5: the keys accepted
 *   token                  the survey's reference; comes back with the keypress
 *   answerUrl              where the keypress is reported (the CRM's
 *                          /portal/csat/voice); ?token=&digit= are appended
 *   dryRun                 true: check and resolve everything, ring nobody
 */

$required_params = array("phone");

const CRM_CSAT_SCRIPT = '/usr/share/freeswitch/scripts/crm_csat.lua';
const CRM_CSAT_OUTBOUND_APP_UUID = '8c914ec3-9fc0-8ab5-4cda-6c9288bdc9a3';

function crm_csat_recording($database, $domain_uuid, $domain_name, $uuid) {
    $row = $database->select(
        "SELECT recording_filename FROM v_recordings WHERE recording_uuid = :r AND domain_uuid = :d",
        array("r" => $uuid, "d" => $domain_uuid), "row");
    if (empty($row['recording_filename'])) {
        return array(null, "that recording is not one of this company's");
    }
    $dir_row = $database->select(
        "SELECT default_setting_value FROM v_default_settings WHERE default_setting_category = 'switch'
            AND default_setting_subcategory = 'recordings' AND default_setting_enabled = 'true' LIMIT 1",
        array(), "row");
    $dir = !empty($dir_row['default_setting_value'])
        ? rtrim($dir_row['default_setting_value'], '/') : '/var/lib/freeswitch/recordings';
    $path = $dir . '/' . $domain_name . '/' . $row['recording_filename'];
    // It travels inside originate {...}: a comma or a quote would end the value.
    if (preg_match("/[,'\"{}\\\\]/", $path)) {
        return array(null, "the recording's file name has a comma or a quote in it — rename it");
    }
    if (!file_exists($path)) {
        return array(null, "the recording's file is missing on the PBX");
    }
    return array($path, null);
}

/** The first enabled outbound route whose pattern takes this number -> [gateway, digits, route]. */
function crm_csat_route($database, $domain_uuid, $phone) {
    $routes = $database->select(
        "SELECT p.dialplan_name, p.dialplan_order,
                (SELECT x.dialplan_detail_data FROM v_dialplan_details x
                  WHERE x.dialplan_uuid = p.dialplan_uuid AND x.dialplan_detail_tag = 'condition'
                    AND x.dialplan_detail_type = 'destination_number'
                  ORDER BY x.dialplan_detail_order LIMIT 1) AS pattern,
                (SELECT x.dialplan_detail_data FROM v_dialplan_details x
                  WHERE x.dialplan_uuid = p.dialplan_uuid AND x.dialplan_detail_tag = 'action'
                    AND x.dialplan_detail_type = 'bridge' AND x.dialplan_detail_data LIKE 'sofia/gateway/%'
                  ORDER BY x.dialplan_detail_order LIMIT 1) AS bridge
           FROM v_dialplans p
          WHERE p.domain_uuid = :d AND p.app_uuid = :app AND p.dialplan_enabled = 'true'
          ORDER BY p.dialplan_order, p.dialplan_name",
        array("d" => $domain_uuid, "app" => CRM_CSAT_OUTBOUND_APP_UUID), "all");
    foreach ((array) $routes as $r) {
        if (empty($r['pattern']) || empty($r['bridge'])) {
            continue;
        }
        if (!preg_match('#^sofia/gateway/([0-9a-fA-F-]{36})/(.+)$#', $r['bridge'], $m)) {
            continue;
        }
        $re = '/' . str_replace('/', '\/', $r['pattern']) . '/';
        if (@preg_match($re, $phone) !== 1) {
            continue;
        }
        $digits = preg_replace($re, $m[2], $phone);
        if (!preg_match('/^\+?[0-9*#]{3,24}$/', (string) $digits)) {
            continue;
        }
        return array(strtolower($m[1]), $digits, $r['dialplan_name']);
    }
    return array(null, null, null);
}

function do_action($body) {
    global $domain_uuid;
    $db_domain_uuid = isset($body->domainUuid) ? $body->domainUuid : (isset($body->domain_uuid) ? $body->domain_uuid : $domain_uuid);
    $database = new database;
    $domain = $database->select("SELECT domain_name FROM v_domains WHERE domain_uuid = :d",
        array("d" => $db_domain_uuid), "row");
    if (!$domain) {
        return array("error" => "Domain not found", "code" => 404);
    }
    $domain_name = $domain['domain_name'];
    if (!file_exists(CRM_CSAT_SCRIPT)) {
        return array("error" => "crm_csat.lua is not installed on this PBX", "code" => 409);
    }

    $phone = preg_replace('/[\s\-().]/', '', (string) $body->phone);
    if (!preg_match('/^\+?[0-9]{5,20}$/', $phone)) {
        return array("error" => "the phone number is not a number", "code" => 400);
    }
    $cid = preg_replace('/[\s\-().]/', '', isset($body->callerIdNumber) ? (string) $body->callerIdNumber : '');
    if (!preg_match('/^\+?[0-9]{3,20}$/', $cid)) {
        return array("error" => "no caller ID — choose the number customers see in the satisfaction settings", "code" => 400);
    }
    $cid_name = isset($body->callerIdName) ? preg_replace("/[^A-Za-z0-9 ._-]/", "", (string) $body->callerIdName) : '';
    $cid_name = substr(trim($cid_name), 0, 40) ?: $cid;
    $scale = intval(isset($body->scale) ? $body->scale : 5);
    if ($scale !== 3 && $scale !== 5) {
        return array("error" => "scale must be 3 or 5", "code" => 400);
    }
    $token = isset($body->token) ? (string) $body->token : '';
    if (!preg_match('/^[A-Za-z0-9_-]{8,64}$/', $token)) {
        return array("error" => "token is not a survey reference", "code" => 400);
    }
    $answer_url = isset($body->answerUrl) ? trim((string) $body->answerUrl) : '';
    if (!preg_match('#^https?://[A-Za-z0-9.\-:\[\]]+(/[A-Za-z0-9._~\-/]*)?$#', $answer_url)) {
        return array("error" => "answerUrl is not a plain http(s) address", "code" => 400);
    }

    $rec = isset($body->recordingUuid) ? (string) $body->recordingUuid : '';
    if ($rec === '') {
        return array("error" => "no question recording — choose one in the satisfaction settings", "code" => 400);
    }
    list($question, $why) = crm_csat_recording($database, $db_domain_uuid, $domain_name, $rec);
    if (!$question) {
        return array("error" => "question recording: " . $why, "code" => 400);
    }
    $thanks = '';
    if (!empty($body->thanksRecordingUuid)) {
        list($thanks, $why) = crm_csat_recording($database, $db_domain_uuid, $domain_name, (string) $body->thanksRecordingUuid);
        if (!$thanks) {
            return array("error" => "thank-you recording: " . $why, "code" => 400);
        }
    }

    list($gateway, $digits, $route) = crm_csat_route($database, $db_domain_uuid, $phone);
    if (!$gateway) {
        return array("error" => "no outbound route of this company takes " . $phone, "code" => 400);
    }

    if (!empty($body->dryRun)) {
        // Everything checked and resolved, nobody rung.
        return array("success" => true, "dryRun" => true, "route" => $route,
                     "dial" => "sofia/gateway/$gateway/$digits",
                     "question" => basename($question), "thanks" => $thanks ? basename($thanks) : null);
    }

    $call_uuid = uuid();
    $vars = array(
        "origination_uuid=$call_uuid",
        "origination_caller_id_number=$cid",
        "origination_caller_id_name='$cid_name'",
        "effective_caller_id_number=$cid",
        "effective_caller_id_name='$cid_name'",
        // A real answer, not the carrier's ringback: the recording must start
        // when the customer picks up, not while their phone is still ringing.
        "ignore_early_media=true",
        "originate_timeout=40",
        "call_direction=outbound",
        "domain_uuid=$db_domain_uuid",
        "domain_name=$domain_name",
        "crm_csat_token=$token",
        "crm_csat_scale=$scale",
        "crm_csat_question='$question'",
        "crm_csat_thanks='$thanks'",
        "crm_csat_url=$answer_url",
    );
    $cmd = "originate {" . implode(",", $vars) . "}sofia/gateway/$gateway/$digits &lua(crm_csat.lua)";
    // In the background: originate waits for an answer, and the CRM should not.
    // ONE attempt only — a second path tried after an unclear reply would ring
    // the customer twice. bgapi's reply comes back as headers (an array), not
    // a body, so its Reply-Text is what says whether the job was accepted.
    if (class_exists('event_socket')) {
        $res = event_socket::async($cmd);
        $resp = is_array($res)
            ? (string) (isset($res['Reply-Text']) ? $res['Reply-Text'] : json_encode($res))
            : (string) $res;
    } else {
        $resp = (string) shell_exec("/usr/bin/fs_cli -x " . escapeshellarg("bgapi " . $cmd) . " 2>&1");
    }
    $ok = strpos($resp, '+OK') !== false || strpos($resp, 'Job-UUID') !== false;
    return array("success" => $ok, "callUuid" => $call_uuid, "route" => $route,
                 "error" => $ok ? null : ("the call was not placed: " . trim($resp)));
}
