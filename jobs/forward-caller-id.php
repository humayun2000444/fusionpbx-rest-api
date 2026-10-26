<?php
/**
 * Forwarded outside calls present the forwarding extension's own caller ID.
 *
 *   php forward-caller-id.php status|apply|rollback
 *
 * Why: RouteSphere (CheckAuthorizationStep) rejects every outbound call whose
 * caller number is not one of the sending partner's own DIDs, and the SBC
 * answers 480 within ~40 ms:
 *
 *   [CHECK_AUTH] REJECTED (strict DID, outbound): caller 8801581130123 is not
 *   an owned DID of partner 334 (didCount=9)
 *
 * A forward (all / busy / no answer / not registered) or a blind transfer of an
 * OUTSIDE call re-routes the caller's own channel, so it still carries the
 * outside caller's number. The outbound routes set
 * effective_caller_id_number=${outbound_caller_id_number}, which FusionPBX only
 * loads for calls a phone places, and the stock call-forward-all copies the
 * caller into it on purpose. So every such forward was rejected, on every
 * domain (found 2026-10-26, DID 09695123456 -> ext 95 -> 01912096921).
 *
 * Two global dialplans, both at order 45, mutually exclusive on ${user_exists}:
 *
 *   forward-caller-id           an inbound call that reaches an extension
 *                               remembers which extension (a set, no lookup)
 *   forward-caller-id-outbound  when the same call is later routed to a number
 *                               that is not an extension, it presents the DID
 *                               that was called, else that extension's outbound
 *                               caller ID; the name is the extension's outbound
 *                               caller ID name when it has one
 *
 * The called DID is ${caller_destination}, which the stock public caller-details
 * dialplan sets from the To user on every inbound call. RouteSphere delivers a
 * call to a partner with its own canonical form of that partner's DID, so it is
 * exactly what the strict-DID check accepts, and it needs no per-extension setup.
 *
 * The directory lookup happens only on the outbound pass, so inbound calls that
 * are never forwarded cost nothing extra (BTCL runs close to its CPU ceiling).
 *
 * 45 is after user_exists (10) and before the first route that bridges out
 * (international, 50, on 44 BTCL domains). Internal calls (call_direction
 * local) and calls a phone places never match, and an extension without an
 * outbound caller ID behaves exactly as before.
 *
 * The stock dialplans are not edited. Rollback deletes these two rows, and
 * FusionPBX upgrades only add missing stock rows by app_uuid, so they leave
 * these alone. The XML is generated from the detail rows by FusionPBX's own
 * dialplan class, the way the GUI's dialplan_edit.php does it, so the GUI shows
 * and saves them like any other dialplan.
 *
 * FUSIONPBX_ROOT overrides the FusionPBX directory (for running a copy of this
 * file from outside app/rest_api/jobs).
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$fusionpbx_root = getenv('FUSIONPBX_ROOT') ?: dirname(__DIR__, 3);
chdir($fusionpbx_root);
set_include_path($fusionpbx_root);
require_once $fusionpbx_root . '/resources/require.php';

const FCID_REMEMBER = '4de5339b-68a7-44f5-a705-f3bb6f94388a';
const FCID_OUTBOUND = '49404c6f-4fac-47db-a860-b9639b0ebab3';
const FCID_ORDER = 45;

function fcid_detail($uuid, $group, $order, $tag, $type, $data, $break = null, $inline = null) {
    return array(
        'domain_uuid' => null,
        'dialplan_uuid' => null, // filled in by fcid_dialplans()
        'dialplan_detail_uuid' => $uuid,
        'dialplan_detail_tag' => $tag,
        'dialplan_detail_type' => $type,
        'dialplan_detail_data' => $data,
        'dialplan_detail_break' => $break,
        'dialplan_detail_inline' => $inline,
        'dialplan_detail_group' => $group,
        'dialplan_detail_order' => $order,
        'dialplan_detail_enabled' => 'true',
    );
}

function fcid_dialplans() {
    $extension_cid = '${user_data ${forward_caller_id_extension}@${domain_name} var outbound_caller_id_';
    $dialplans = array(
        array(
            'dialplan_uuid' => FCID_REMEMBER,
            'app_uuid' => '8b7cc6b7-c8c3-4d08-b040-42547d9b8245',
            'dialplan_name' => 'forward-caller-id',
            'dialplan_description' => 'An outside call reaching an extension remembers which one, for forward-caller-id-outbound (rest_api jobs/forward-caller-id.php).',
            'dialplan_details' => array(
                fcid_detail('494c29ce-b107-4f58-b092-caa865a9ea6e', 0, 5, 'condition', '${user_exists}', '^true$'),
                fcid_detail('b7b82a0e-a742-43b5-a06f-82c19f646555', 0, 10, 'condition', '${call_direction}', '^inbound$'),
                fcid_detail('2eb22e80-c40b-4c0c-b988-70c9cefee70f', 0, 15, 'action', 'set', 'forward_caller_id_extension=${destination_number}', null, 'true'),
            ),
        ),
        array(
            'dialplan_uuid' => FCID_OUTBOUND,
            'app_uuid' => '2e885bce-0a34-4241-8c57-c29b0f83e524',
            'dialplan_name' => 'forward-caller-id-outbound',
            'dialplan_description' => 'A forwarded or transferred outside call presents the DID that was called (else the extension\'s caller ID); RouteSphere rejects any caller that is not an owned DID (rest_api jobs/forward-caller-id.php).',
            'dialplan_details' => array(
                fcid_detail('d3dad96d-50ab-42a9-9012-0e4f9e89d13b', 0, 5, 'condition', '${user_exists}', '^false$'),
                fcid_detail('a3124888-28dc-470e-beb0-c3106e15e42c', 0, 10, 'condition', '${call_direction}', '^inbound$'),
                fcid_detail('6a3d62d5-3fe1-4434-95b4-0c9644f419e5', 0, 15, 'condition', '${forward_caller_id_extension}', '^.+$'),
                // fallback: the extension's own outbound caller ID
                fcid_detail('d06b4f07-735f-4599-bed2-f556bba946b3', 0, 20, 'condition', $extension_cid . 'number}', '^(.+)$', 'never'),
                fcid_detail('0cf784ba-9b02-410e-b8d2-c2940fcb0008', 0, 25, 'action', 'set', 'outbound_caller_id_number=$1', null, 'true'),
                fcid_detail('f8af4be3-a1ca-4521-9cbb-a0bbde8c9d16', 0, 30, 'action', 'set', 'outbound_caller_id_name=$1', null, 'true'),
                // preferred: the DID that was called. 8+ digits because the global
                // caller-details fills an empty caller_destination with the
                // extension number, and that must never go out as a caller ID.
                fcid_detail('b053ddc6-bb38-42fd-93a3-7961e3cffda5', 1, 35, 'condition', '${caller_destination}', '^(\+?\d{8,})$', 'never'),
                fcid_detail('aae65761-5f7b-4f06-b4f2-8811f1ca5f42', 1, 40, 'action', 'set', 'outbound_caller_id_number=$1', null, 'true'),
                fcid_detail('cd4c9adb-5335-41ee-a457-7d781b458a91', 1, 45, 'action', 'set', 'outbound_caller_id_name=$1', null, 'true'),
                // the extension's outbound caller ID name, when it has one
                fcid_detail('e30b2c89-2d37-4a05-9685-b22b7355e792', 2, 50, 'condition', $extension_cid . 'name}', '^(.+)$', 'never'),
                fcid_detail('143cde73-9807-4679-a947-c47188b430eb', 2, 55, 'action', 'set', 'outbound_caller_id_name=$1', null, 'true'),
            ),
        ),
    );

    foreach ($dialplans as &$dialplan) {
        $dialplan['domain_uuid'] = null;
        $dialplan['hostname'] = null;
        $dialplan['dialplan_context'] = 'global';
        $dialplan['dialplan_number'] = null;
        $dialplan['dialplan_continue'] = 'true';
        $dialplan['dialplan_order'] = FCID_ORDER;
        $dialplan['dialplan_enabled'] = 'true';
        foreach ($dialplan['dialplan_details'] as &$detail) {
            $detail['dialplan_uuid'] = $dialplan['dialplan_uuid'];
        }
        unset($detail);

        // Same calls as the GUI's dialplan_edit.php.
        $generator = new dialplan;
        $generator->source = 'details';
        $generator->destination = 'array';
        $generator->uuid = $dialplan['dialplan_uuid'];
        $generator->prepare_details(array('dialplans' => array($dialplan)));
        $xml = $generator->xml();
        $dialplan['dialplan_xml'] = $xml[$dialplan['dialplan_uuid']] ?? '';
    }
    unset($dialplan);
    return $dialplans;
}

function fcid_rows($database) {
    $sql = "select d.dialplan_uuid, d.dialplan_name, d.domain_uuid, d.dialplan_context, d.dialplan_order, d.dialplan_enabled, d.dialplan_xml, ";
    $sql .= "(select count(*) from v_dialplan_details s where s.dialplan_uuid = d.dialplan_uuid) as details ";
    $sql .= "from v_dialplans d where d.dialplan_uuid in (:remember, :outbound) order by d.dialplan_name";
    return $database->select($sql, array('remember' => FCID_REMEMBER, 'outbound' => FCID_OUTBOUND), 'all') ?: array();
}

// Every domain context includes the global dialplans and caches its own copy;
// the cache never expires, so each one has to go.
function fcid_flush_cache($database) {
    $domains = $database->select("select domain_name from v_domains", null, 'all') ?: array();
    $cache = new cache;
    foreach ($domains as $domain) {
        $cache->delete("dialplan:" . $domain['domain_name']);
    }
    echo "dialplan cache cleared for " . count($domains) . " domains\n";
}

function fcid_status($database) {
    $rows = fcid_rows($database);
    if (empty($rows)) {
        echo "not applied\n";
        return;
    }
    foreach ($rows as $row) {
        echo "== " . $row['dialplan_name'] . " uuid=" . $row['dialplan_uuid'] . " domain=" . ($row['domain_uuid'] ?? 'NULL')
            . " context=" . $row['dialplan_context'] . " order=" . $row['dialplan_order'] . " enabled=" . $row['dialplan_enabled']
            . " details=" . $row['details'] . "\n" . $row['dialplan_xml'] . "\n";
    }
}

function fcid_delete($database) {
    $parameters = array('remember' => FCID_REMEMBER, 'outbound' => FCID_OUTBOUND);
    $database->execute("delete from v_dialplan_details where dialplan_uuid in (:remember, :outbound)", $parameters);
    $database->execute("delete from v_dialplans where dialplan_uuid in (:remember, :outbound)", $parameters);
}

function fcid_apply($database) {
    $dialplans = fcid_dialplans();
    foreach ($dialplans as $dialplan) {
        if (strpos($dialplan['dialplan_xml'], 'uuid="' . $dialplan['dialplan_uuid'] . '"') === false) {
            fwrite(STDERR, "ABORT: no XML generated for " . $dialplan['dialplan_name'] . "\n");
            exit(1);
        }
    }

    // A re-run replaces the rows rather than updating them, so a changed
    // detail list cannot leave stale details behind.
    fcid_delete($database);

    $array['dialplans'] = $dialplans;
    $database = new database;
    $database->app_name = 'dialplans';
    $database->app_uuid = '742714e5-8cdf-32fd-462c-cbe7e3d655db';
    $database->save($array);
    $message = $database->message;
    unset($array);

    // Check what landed, including that the rows stayed global.
    $database = new database;
    $rows = fcid_rows($database);
    $expected_details = array(FCID_REMEMBER => 3, FCID_OUTBOUND => 11);
    $ok = count($rows) == 2;
    foreach ($rows as $row) {
        $ok = $ok && $row['domain_uuid'] === null && $row['dialplan_context'] === 'global'
            && (int)$row['details'] === $expected_details[$row['dialplan_uuid']]
            && strpos($row['dialplan_xml'], 'forward_caller_id_extension') !== false
            && ($row['dialplan_uuid'] !== FCID_OUTBOUND || strpos($row['dialplan_xml'], '${caller_destination}') !== false);
    }
    if (!$ok) {
        fcid_delete($database);
        fwrite(STDERR, "ABORT: rows did not land as expected, removed again. save(): " . json_encode($message) . "\n");
        fcid_status($database);
        exit(1);
    }

    fcid_flush_cache($database);
    fcid_status($database);
}

$database = new database;
switch ($argv[1] ?? '') {
    case 'status':
        fcid_status($database);
        break;
    case 'apply':
        fcid_apply($database);
        break;
    case 'rollback':
        fcid_delete($database);
        fcid_flush_cache($database);
        fcid_status($database);
        break;
    default:
        fwrite(STDERR, "usage: php forward-caller-id.php status|apply|rollback\n");
        exit(2);
}
