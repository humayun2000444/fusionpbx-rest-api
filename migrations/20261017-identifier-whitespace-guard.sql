-- Identifier whitespace guard: a domain name or a dialplan context can never
-- hold whitespace, whichever code path writes it.
--
-- 2026-10-17, BTCL pbx.rio.1400 (Rio Travels): every outbound call ended in
-- send_refuse with last_app `eval not_secure`, while inbound calls worked.
-- An admin had renamed the domain in the FusionPBX GUI (pbx-rio-1400 ->
-- pbx.rio.1400) and pasted the new name with a leading TAB.
-- core/domains/domain_edit.php does not trim the posted name. database::save()
-- does, so the v_domains row came out clean and the domain list looked right,
-- but the rename cascade is raw `replace(column, old, new)` UPDATEs and wrote
-- the TAB into all 45 dialplan_context rows (conf-xfer@<TAB>... included) and
-- into the extensions' user_context and accountcode. FreeSWITCH's XML parser
-- turns a TAB inside an attribute into a space and the context lookup is an
-- exact match, so the tenant's own routes never loaded - only the global
-- dialplans ran, and the call fell off the end of them.
--
-- Three layers now stop this class of bug:
--   1. patches/domain-edit-strip-whitespace.patch - the GUI strips the name.
--   2. rest.php strips identifier fields at the API boundary.
--   3. this trigger, on every table that stores a domain name or a context.
--      It also covers TelcoREST, which writes these tables directly over JPA,
--      and hand-written SQL.
--
-- Contexts and domain names lose every whitespace character, including the
-- invisible ones a paste brings (NBSP, zero-width space, BOM). accountcode is
-- free text, so it is only trimmed at the ends. Each value the guard changes
-- is recorded in tb_identifier_guard_log; failing to log never blocks a write.
--
-- The loop below also sends rows that already hold whitespace through the
-- trigger. If that changes a dialplan_context, delete the domain's cache file
-- /var/cache/fusionpbx/dialplan.<domain> (as www-data) - FreeSWITCH is served
-- that file, and reloadxml does not refresh it.
--
-- Rollback: 20261017-identifier-whitespace-guard-rollback.sql

BEGIN;
SET LOCAL lock_timeout = '5s';
SET LOCAL client_min_messages = warning;

CREATE TABLE IF NOT EXISTS tb_identifier_guard_log (
    id          bigserial   PRIMARY KEY,
    logged_at   timestamptz NOT NULL DEFAULT now(),
    table_name  text        NOT NULL,
    row_uuid    text,
    column_name text        NOT NULL,
    old_value   text,
    new_value   text
);

-- TG_ARGV[0] is the row's primary-key column (for the log); the rest are the
-- columns to clean. A column written as 'name:ends' is trimmed at the ends only.
CREATE OR REPLACE FUNCTION tb_identifier_guard() RETURNS trigger
LANGUAGE plpgsql AS $fn$
DECLARE
    ws    constant text := '[[:space:] ​-‍⁠﻿]';
    rec   jsonb := to_jsonb(NEW);
    fix   jsonb := '{}';
    spec  text;
    col   text;
    old_v text;
    new_v text;
BEGIN
    FOREACH spec IN ARRAY TG_ARGV[1:TG_NARGS - 1] LOOP
        col   := split_part(spec, ':', 1);
        old_v := rec ->> col;
        CONTINUE WHEN old_v IS NULL;
        IF split_part(spec, ':', 2) = 'ends' THEN
            new_v := regexp_replace(old_v, '^' || ws || '+|' || ws || '+$', '', 'g');
        ELSE
            new_v := regexp_replace(old_v, ws || '+', '', 'g');
        END IF;
        CONTINUE WHEN new_v = old_v;
        fix := fix || jsonb_build_object(col, new_v);
        BEGIN
            INSERT INTO tb_identifier_guard_log (table_name, row_uuid, column_name, old_value, new_value)
            VALUES (TG_TABLE_NAME, rec ->> TG_ARGV[0], col, old_v, new_v);
        EXCEPTION WHEN OTHERS THEN
            NULL;
        END;
    END LOOP;
    IF fix <> '{}' THEN
        NEW := jsonb_populate_record(NEW, fix);
    END IF;
    RETURN NEW;
END
$fn$;

DO $do$
DECLARE
    ws   constant text := '[[:space:] ​-‍⁠﻿]';
    t    record;
    cols text[];
BEGIN
    FOR t IN
        SELECT * FROM (VALUES
            ('v_domains',             'domain_uuid',             ARRAY['domain_name']),
            ('v_dialplans',           'dialplan_uuid',           ARRAY['dialplan_context']),
            ('v_extensions',          'extension_uuid',          ARRAY['user_context', 'dial_domain', 'accountcode:ends']),
            ('v_destinations',        'destination_uuid',        ARRAY['destination_context']),
            ('v_ring_groups',         'ring_group_uuid',         ARRAY['ring_group_context']),
            ('v_ivr_menus',           'ivr_menu_uuid',           ARRAY['ivr_menu_context']),
            ('v_call_center_queues',  'call_center_queue_uuid',  ARRAY['queue_context']),
            ('v_call_flows',          'call_flow_uuid',          ARRAY['call_flow_context']),
            ('v_conferences',         'conference_uuid',         ARRAY['conference_context']),
            ('v_gateways',            'gateway_uuid',            ARRAY['context', 'from_domain', 'realm']),
            ('v_sip_profile_domains', 'sip_profile_domain_uuid', ARRAY['sip_profile_domain_name']),
            ('v_fax',                 'fax_uuid',                ARRAY['accountcode:ends']),
            ('v_pin_numbers',         'pin_number_uuid',         ARRAY['accountcode:ends'])
        ) AS spec(tbl, pk, specs)
    LOOP
        CONTINUE WHEN to_regclass(t.tbl) IS NULL;
        SELECT array_agg(split_part(s, ':', 1)) INTO cols FROM unnest(t.specs) AS s;

        EXECUTE format('DROP TRIGGER IF EXISTS tb_identifier_guard ON %I', t.tbl);
        EXECUTE format('CREATE TRIGGER tb_identifier_guard BEFORE INSERT OR UPDATE OF %s ON %I '
                       'FOR EACH ROW EXECUTE FUNCTION tb_identifier_guard(%L, %s)',
                       (SELECT string_agg(quote_ident(c), ', ') FROM unnest(cols) AS c),
                       t.tbl, t.pk,
                       (SELECT string_agg(quote_literal(s), ', ') FROM unnest(t.specs) AS s));

        EXECUTE format('UPDATE %I SET %s WHERE %s', t.tbl,
                       (SELECT string_agg(format('%1$I = %1$I', c), ', ') FROM unnest(cols) AS c),
                       (SELECT string_agg(format('%I ~ %L', c, ws), ' OR ') FROM unnest(cols) AS c));
    END LOOP;
END
$do$;

SELECT c.relname AS guarded_table
  FROM pg_trigger tg JOIN pg_class c ON c.oid = tg.tgrelid
 WHERE tg.tgname = 'tb_identifier_guard'
 ORDER BY 1;

SELECT table_name, row_uuid, column_name, old_value, new_value
  FROM tb_identifier_guard_log
 WHERE logged_at >= now() - interval '1 minute';

COMMIT;
