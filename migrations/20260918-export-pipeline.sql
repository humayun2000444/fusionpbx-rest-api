-- Export pipeline, 2026-09-18. Applied to both clusters already; kept here so
-- the schema change is reproducible on a new box.
--
-- exported_at is what makes retention safe: a row is only eligible for deletion
-- once a puller has confirmed receiving it (see cdr-mark-exported). Retention
-- by AGE alone can delete a call nobody has a second copy of, which is the
-- thing customers rightly object to.
--
-- Nullable with no default, so on PostgreSQL 11+ this is a catalogue-only
-- change - no rewrite of the 8.6M-row table.
ALTER TABLE v_xml_cdr ADD COLUMN IF NOT EXISTS exported_at timestamptz;

-- Partial: only rows still awaiting export are indexed, so it stays small even
-- though the table does not.
CREATE INDEX CONCURRENTLY IF NOT EXISTS v_xml_cdr_unexported_idx
    ON v_xml_cdr (start_stamp, xml_cdr_uuid) WHERE exported_at IS NULL;

-- Same definition as before with exported_at appended; existing columns keep
-- their names, order and types so current consumers are unaffected.
CREATE OR REPLACE VIEW view_call_recordings AS
 SELECT domain_uuid,
    xml_cdr_uuid AS call_recording_uuid,
    caller_id_name,
    caller_id_number,
    caller_destination,
    destination_number,
    record_name AS call_recording_name,
    record_path AS call_recording_path,
    record_transcription AS call_recording_transcription,
    duration AS call_recording_length,
    start_stamp AS call_recording_date,
    direction AS call_direction,
    extension_uuid,
    exported_at
   FROM v_xml_cdr
  WHERE record_name IS NOT NULL AND record_path IS NOT NULL
  ORDER BY start_stamp DESC;
