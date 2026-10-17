-- Rollback of 20261017-identifier-whitespace-guard.sql: removes the triggers and
-- the function. Values the guard already cleaned stay clean, and
-- tb_identifier_guard_log is kept as the record of what it changed.

BEGIN;
SET LOCAL lock_timeout = '5s';

DO $do$
DECLARE
    r record;
BEGIN
    FOR r IN
        SELECT c.relname
          FROM pg_trigger tg JOIN pg_class c ON c.oid = tg.tgrelid
         WHERE tg.tgname = 'tb_identifier_guard'
    LOOP
        EXECUTE format('DROP TRIGGER tb_identifier_guard ON %I', r.relname);
    END LOOP;
END
$do$;

DROP FUNCTION IF EXISTS tb_identifier_guard();

COMMIT;
