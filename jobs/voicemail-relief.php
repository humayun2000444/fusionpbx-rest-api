<?php
/**
 * Take the voicemail load off a saturated box: index the message table and
 * turn off one mailbox that nobody listens to.
 *
 *   php voicemail-relief.php status   [<domain> <mailbox>]
 *   php voicemail-relief.php apply    <domain> <mailbox>
 *   php voicemail-relief.php rollback <domain> <mailbox>
 *
 * Why (BTCL, 2026-10-27): v_voicemail_messages had only its primary key, so
 * every message count -- FreeSWITCH's MWI (mwi.lua, mwi_subscribe.lua,
 * message_count.lua), the voicemail app after each recording, the GUI --
 * read the whole table. One mailbox held almost all of it:
 * pbx-innoversal-345.alaapcloud.gov.bd 10001, an Asterisk dialler registered
 * as one extension, 217,776 messages, all unread, 49,766 in the last 7 days,
 * 30 GB. Those counts took 0.4-0.8 s each, about 150 a minute, on a box whose
 * CPU was already 97-99% busy.
 *
 * apply
 *   1. CREATE INDEX CONCURRENTLY on (voicemail_uuid, message_status), the
 *      columns every count filters on. It does not block calls or new
 *      messages while it builds; an invalid leftover is dropped and apply stops.
 *   2. Turns the mailbox off the way the GUI's Voicemails list toggle does
 *      (voicemail::voicemail_toggle): an MWI update for the mailbox, then
 *      save() of voicemail_enabled = 'false' under the voicemail app. With the
 *      mailbox off, app/voicemail/index.lua finds no enabled mailbox, so an
 *      unanswered call is no longer answered and recorded, and mwi.lua /
 *      mwi_subscribe.lua skip it.
 *
 * The existing messages and their files are left alone; whether to keep them
 * is the customer's call. rollback turns the mailbox back on the same way and
 * drops the index (only the one this job names).
 *
 * Run as www-data: the FusionPBX database user owns the table, and nothing
 * else on disk is touched. FUSIONPBX_ROOT overrides the FusionPBX directory
 * (for running a copy of this file from outside app/rest_api/jobs).
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$fusionpbx_root = getenv('FUSIONPBX_ROOT') ?: dirname(__DIR__, 3);
chdir($fusionpbx_root);
set_include_path($fusionpbx_root);
require_once $fusionpbx_root . '/resources/require.php';

const VMR_INDEX = 'v_voicemail_messages_voicemail_status_idx';
const VMR_APP_UUID = 'b523c2d2-64cd-46f1-9520-ca4b4098e044';

function vmr_index($database) {
    $sql = "select i.indisvalid, pg_size_pretty(pg_relation_size(c.oid)) as size ";
    $sql .= "from pg_class c join pg_index i on i.indexrelid = c.oid where c.relname = :name";
    return $database->select($sql, array('name' => VMR_INDEX), 'row') ?: null;
}

function vmr_mailbox($database, $domain_name, $mailbox) {
    $sql = "select v.voicemail_uuid, v.voicemail_id, v.voicemail_enabled, v.domain_uuid, d.domain_name ";
    $sql .= "from v_voicemails v join v_domains d on d.domain_uuid = v.domain_uuid ";
    $sql .= "where d.domain_name = :domain_name and v.voicemail_id = :voicemail_id";
    $row = $database->select($sql, array('domain_name' => $domain_name, 'voicemail_id' => $mailbox), 'row');
    if (empty($row)) {
        fwrite(STDERR, "ABORT: no mailbox $mailbox in $domain_name\n");
        exit(1);
    }
    return $row;
}

function vmr_status($database, $box = null) {
    $index = vmr_index($database);
    echo "index " . VMR_INDEX . ": " . ($index === null ? 'missing'
        : (filter_var($index['indisvalid'], FILTER_VALIDATE_BOOLEAN) ? 'valid' : 'INVALID') . ', ' . $index['size']) . "\n";
    if ($box === null) {
        return;
    }
    $sql = "select count(*) as total, count(*) filter (where message_status is null or message_status = '') as unread ";
    $sql .= "from v_voicemail_messages where voicemail_uuid = :uuid";
    $count = $database->select($sql, array('uuid' => $box['voicemail_uuid']), 'row');
    echo "mailbox {$box['voicemail_id']}@{$box['domain_name']}: enabled={$box['voicemail_enabled']}, "
        . "{$count['total']} messages ({$count['unread']} unread)\n";
    // the count FreeSWITCH runs for every other mailbox on the box
    $sql = "explain select count(*) from v_voicemail_messages where voicemail_uuid = :uuid ";
    $sql .= "and (message_status is null or message_status = '')";
    $other = $database->select("select voicemail_uuid from v_voicemails where voicemail_uuid <> :uuid limit 1",
        array('uuid' => $box['voicemail_uuid']), 'column');
    if ($other) {
        $plan = array_column($database->select($sql, array('uuid' => $other), 'all') ?: array(), 'QUERY PLAN');
        $scan = preg_grep('/Scan/', $plan);
        echo "message count for any other mailbox: " . trim((string)reset($scan)) . "\n";
    }
}

function vmr_create_index($database) {
    $index = vmr_index($database);
    if ($index !== null && !filter_var($index['indisvalid'], FILTER_VALIDATE_BOOLEAN)) {
        // a failed CONCURRENTLY build leaves an invalid index behind
        $database->execute("drop index concurrently if exists " . VMR_INDEX);
        $index = null;
    }
    if ($index === null) {
        $started = microtime(true);
        $database->execute("create index concurrently if not exists " . VMR_INDEX
            . " on v_voicemail_messages (voicemail_uuid, message_status)");
        $index = vmr_index($database);
        if ($index === null || !filter_var($index['indisvalid'], FILTER_VALIDATE_BOOLEAN)) {
            $database->execute("drop index concurrently if exists " . VMR_INDEX);
            fwrite(STDERR, "ABORT: index build failed, nothing left behind: " . json_encode($database->message) . "\n");
            exit(1);
        }
        printf("index built in %.1f s\n", microtime(true) - $started);
    }
}

// voicemail::voicemail_toggle() for one mailbox, without the GUI's token and
// session: MWI for the mailbox first, then save() under the voicemail app.
function vmr_set_enabled($box, $enabled) {
    event_socket::api("luarun app.lua voicemail mwi " . $box['voicemail_id'] . "@" . $box['domain_name']);

    $array['voicemails'][0]['voicemail_uuid'] = $box['voicemail_uuid'];
    $array['voicemails'][0]['voicemail_enabled'] = $enabled;
    $database = new database;
    $database->app_name = 'voicemail';
    $database->app_uuid = VMR_APP_UUID;
    $database->save($array);
    $message = $database->message;

    // save() reports an array either way; the row is what counts
    $box = vmr_mailbox(new database, $box['domain_name'], $box['voicemail_id']);
    if ($box['voicemail_enabled'] !== $enabled) {
        fwrite(STDERR, "ABORT: voicemail_enabled is still '{$box['voicemail_enabled']}'. save(): " . json_encode($message) . "\n");
        exit(1);
    }
    return $box;
}

$database = new database;
$mode = $argv[1] ?? '';
$box = isset($argv[2], $argv[3]) ? vmr_mailbox($database, $argv[2], $argv[3]) : null;
switch ($mode) {
    case 'status':
        vmr_status($database, $box);
        break;
    case 'apply':
        if ($box === null) {
            fwrite(STDERR, "usage: php voicemail-relief.php apply <domain> <mailbox>\n");
            exit(2);
        }
        vmr_create_index($database);
        if ($box['voicemail_enabled'] === 'false') {
            echo "mailbox already off\n";
        } else {
            $box = vmr_set_enabled($box, 'false');
        }
        vmr_status(new database, $box);
        break;
    case 'rollback':
        if ($box === null) {
            fwrite(STDERR, "usage: php voicemail-relief.php rollback <domain> <mailbox>\n");
            exit(2);
        }
        if ($box['voicemail_enabled'] !== 'true') {
            $box = vmr_set_enabled($box, 'true');
        }
        $database->execute("drop index concurrently if exists " . VMR_INDEX);
        vmr_status(new database, $box);
        break;
    default:
        fwrite(STDERR, "usage: php voicemail-relief.php status [<domain> <mailbox>] | apply <domain> <mailbox> | rollback <domain> <mailbox>\n");
        exit(2);
}
