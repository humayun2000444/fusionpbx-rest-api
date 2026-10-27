-- Call-center queue dialplans: callcenter <EXTENSION>@<domain>, not <NAME>@<domain>.
--
-- call-center-queue-create.php wrote the queue NAME into the dialplan's
-- callcenter action, while mod_callcenter loads FusionPBX queues as
-- <queue_extension>@<domain> (and FusionPBX's own queue page writes that).
-- Every queue created through the API was therefore unreachable — by its own
-- extension and from any IVR option or route sending calls to it ("Queue not
-- found", hangup). Found 2026-10-27: 1 queue on CCL, 6 on BTCL.
--
-- Rewrites the target (dialplan_xml and v_dialplan_details) wherever it is not
-- the queue's extension@domain. Idempotent. Clear the dialplan cache of the
-- affected domains afterwards (/var/cache/fusionpbx/dialplan.<domain>).
UPDATE v_dialplans p
   SET dialplan_xml = regexp_replace(p.dialplan_xml, 'application="callcenter" data="[^"]*"',
                                     'application="callcenter" data="' || q.queue_extension || '@' || d.domain_name || '"'),
       update_date = NOW()
  FROM v_call_center_queues q
  JOIN v_domains d ON d.domain_uuid = q.domain_uuid
 WHERE p.dialplan_uuid = q.dialplan_uuid
   AND coalesce(substring(p.dialplan_xml from 'application="callcenter" data="([^"]*)"'), '')
       <> q.queue_extension || '@' || d.domain_name;

UPDATE v_dialplan_details dd
   SET dialplan_detail_data = q.queue_extension || '@' || d.domain_name
  FROM v_call_center_queues q
  JOIN v_domains d ON d.domain_uuid = q.domain_uuid
 WHERE dd.dialplan_uuid = q.dialplan_uuid
   AND dd.dialplan_detail_tag = 'action' AND dd.dialplan_detail_type = 'callcenter'
   AND dd.dialplan_detail_data <> q.queue_extension || '@' || d.domain_name;
