<?php
/**
 * Inserts one finished video call into v_xml_cdr, shaped like the row
 * FreeSWITCH's importer writes for a local extension-to-extension call, so the
 * CDR pages, reports and exports treat it like any other call.
 *
 * How to tell one apart: last_app = 'videocall' and sip_call_id starts with
 * 'janus-videocall-'. There is no FreeSWITCH XML/JSON behind it, so json and
 * xml stay empty, as they already are for every row on CCL.
 */

require_once __DIR__ . '/Config.php';

final class CdrWriter
{
    private $pdo;
    private $dsnInfo;
    private $log;

    /** $pdo is for the tests, which run the insert inside a rolled-back transaction. */
    public function __construct($fusionpbxConfig, callable $log, ?PDO $pdo = null)
    {
        $this->dsnInfo = Config::fusionpbxDatabase($fusionpbxConfig);
        $this->log = $log;
        $this->pdo = $pdo;
    }

    /** @return bool true = written or deliberately skipped; false = try again later */
    public function __invoke(array $cdr)
    {
        list($callerExt, $domain) = VideoCallCdr::splitUser($cdr['caller']);
        list($calleeExt, $calleeDomain) = VideoCallCdr::splitUser($cdr['callee']);
        if ($domain === '' || $calleeDomain !== $domain) {
            // Tenants are separate organisations: never file a call under a
            // domain the other party does not belong to.
            $this->say("skip {$cdr['caller']} -> {$cdr['callee']}: not one domain");
            return true;
        }

        try {
            $pdo = $this->pdo();
            $d = $pdo->prepare("SELECT domain_uuid FROM v_domains WHERE lower(domain_name) = :d AND domain_enabled::text IN ('true','t')");
            $d->execute([':d' => $domain]);
            $domainUuid = $d->fetchColumn();
            if (!$domainUuid) {
                $this->say("skip {$cdr['caller']}: domain $domain not found or disabled");
                return true;
            }

            $x = $pdo->prepare('SELECT extension_uuid, effective_caller_id_name FROM v_extensions
                                 WHERE domain_uuid = :d AND (extension = :e OR number_alias = :e) LIMIT 1');
            $x->execute([':d' => $domainUuid, ':e' => $callerExt]);
            $ext = $x->fetch(PDO::FETCH_ASSOC);
            if (!$ext) {
                $this->say("skip {$cdr['caller']}: extension $callerExt not in $domain");
                return true;
            }
            $x->execute([':d' => $domainUuid, ':e' => $calleeExt]);
            $callee = $x->fetch(PDO::FETCH_ASSOC);
            if (!$callee) {
                $this->say("skip {$cdr['caller']} -> {$cdr['callee']}: extension $calleeExt not in $domain");
                return true;
            }

            $start = intdiv($cdr['start_us'], 1000000);
            $end = intdiv($cdr['end_us'], 1000000);
            $answered = $cdr['answer_us'] !== null;
            $answer = $answered ? intdiv($cdr['answer_us'], 1000000) : 0;
            $audio = $cdr['audio_codec'] ? strtoupper($cdr['audio_codec']) : null;
            $video = $cdr['video_codec'] ? strtoupper($cdr['video_codec']) : null;

            $row = [
                ':uuid' => $cdr['xml_cdr_uuid'],
                ':domain_uuid' => $domainUuid,
                ':domain_name' => $domain,
                // FusionPBX files an extension-to-extension call under the
                // extension that was CALLED - every 1234 -> 1235 audio call on
                // tb.com belongs to 1235 - and the softphone's call history
                // lists rows by extension_uuid. Filed under the caller, a video
                // call never showed up as incoming for the callee.
                ':extension_uuid' => $callee['extension_uuid'],
                ':sip_call_id' => $cdr['sip_call_id'],
                ':caller_id_name' => trim((string) $ext['effective_caller_id_name']) !== '' ? $ext['effective_caller_id_name'] : $callerExt,
                ':caller_id_number' => $callerExt,
                ':destination_number' => $calleeExt,
                ':start_epoch' => $start,
                ':answer_epoch' => $answer,
                ':end_epoch' => $end,
                // FusionPBX stores talk time in duration too (xml_cdr.php:
                // duration = billsec, mduration = billmsec) - true for every
                // FreeSWITCH row on CCL. Ring time goes in waitsec.
                ':duration' => $answered ? $end - $answer : 0,
                ':mduration' => $answered ? intdiv($cdr['end_us'] - $cdr['answer_us'], 1000) : 0,
                ':billsec' => $answered ? $end - $answer : 0,
                ':billmsec' => $answered ? intdiv($cdr['end_us'] - $cdr['answer_us'], 1000) : 0,
                ':waitsec' => $answered ? $answer - $start : null,
                ':codec' => $audio,
                ':rate' => $audio === 'OPUS' ? '48000' : null,
                ':last_arg' => trim('video ' . ($video ?: '')),
                ':missed_call' => $cdr['missed_call'] ? 'true' : 'false',
                ':status' => $cdr['status'],
                ':hangup_cause' => $cdr['hangup_cause'],
                ':q850' => $cdr['hangup_cause_q850'],
                ':disposition' => $cdr['sip_hangup_disposition'],
            ];

            $sql = "INSERT INTO v_xml_cdr (
                        xml_cdr_uuid, domain_uuid, domain_name, extension_uuid, sip_call_id, accountcode,
                        direction, leg, caller_id_name, caller_id_number, caller_destination, source_number,
                        destination_number, start_epoch, start_stamp, answer_epoch, answer_stamp,
                        end_epoch, end_stamp, duration, mduration, billsec, billmsec, waitsec,
                        hold_accum_seconds, read_codec, read_rate, write_codec, write_rate,
                        last_app, last_arg, voicemail_message, missed_call, status,
                        hangup_cause, hangup_cause_q850, sip_hangup_disposition, insert_date)
                    VALUES (
                        :uuid, :domain_uuid, :domain_name, :extension_uuid, :sip_call_id, :domain_name,
                        'local', 'a', :caller_id_name, :caller_id_number, :destination_number, :caller_id_number,
                        :destination_number, :start_epoch, to_timestamp(:start_epoch), :answer_epoch,
                        CASE WHEN :answer_epoch > 0 THEN to_timestamp(:answer_epoch) END,
                        :end_epoch, to_timestamp(:end_epoch), :duration, :mduration, :billsec, :billmsec, :waitsec,
                        0, :codec, :rate, :codec, :rate,
                        'videocall', :last_arg, false, :missed_call::boolean, :status,
                        :hangup_cause, :q850, :disposition, now())
                    ON CONFLICT (xml_cdr_uuid) DO NOTHING";
            $pdo->prepare($sql)->execute($row);
            return true;
        } catch (PDOException $e) {
            // Database down or restarting: keep the call and try again on the
            // next event instead of losing it.
            $this->say("database error, will retry {$cdr['xml_cdr_uuid']}: " . $e->getMessage());
            $this->pdo = null;
            return false;
        }
    }

    private function pdo()
    {
        if ($this->pdo === null) {
            $c = $this->dsnInfo;
            $this->pdo = new PDO("pgsql:host={$c['host']};port={$c['port']};dbname={$c['name']}", $c['username'], $c['password'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_EMULATE_PREPARES => true,
                PDO::ATTR_TIMEOUT => 5,
            ]);
        }
        return $this->pdo;
    }

    private function say($line)
    {
        call_user_func($this->log, $line);
    }
}
