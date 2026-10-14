-- view_call_recordings: use the partial recording index.
--
-- The view filtered on `record_name IS NOT NULL AND record_path IS NOT NULL`.
-- The matching partial index v_xml_cdr_recording_retention_idx is
-- `WHERE record_name IS NOT NULL AND record_name <> ''`. Postgres won't use
-- a partial index unless the query predicate IMPLIES the index predicate,
-- and the view said nothing about `record_name <> ''`. So every call to
-- /api/recordings/v1/list-by-domain planned a parallel seq scan over
-- v_xml_cdr (29 M rows on BTCL, cost 2.4 M).
--
-- For a tenant with recordings this happened to finish fast because the
-- top-25 by start_stamp were nearby in the heap. For a tenant with 0
-- recordings (BTCL innoversal after the 2026-10-14 bulk clear) the scan
-- walked the whole table confirming the empty answer and nginx timed out.
--
-- Fix: add `record_name <> ''` to the view predicate. In practice record_name
-- is either NULL or a filled filename (`select count(*) from v_xml_cdr where
-- record_name = ''` returned 0 on BTCL), so this changes nothing semantically.
-- With the predicate in place the planner uses v_xml_cdr_recording_retention_idx:
-- the innoversal query dropped from a 60-s seq scan to 0.07 ms.

CREATE OR REPLACE VIEW view_call_recordings AS
SELECT domain_uuid,
       xml_cdr_uuid           AS call_recording_uuid,
       caller_id_name,
       caller_id_number,
       caller_destination,
       destination_number,
       record_name            AS call_recording_name,
       record_path            AS call_recording_path,
       record_transcription   AS call_recording_transcription,
       duration               AS call_recording_length,
       start_stamp            AS call_recording_date,
       direction              AS call_direction,
       extension_uuid,
       exported_at
  FROM v_xml_cdr
 WHERE record_name IS NOT NULL AND record_name <> ''
   AND record_path IS NOT NULL
 ORDER BY start_stamp DESC;
