-- SCHEMA-3, WITH A CORRECTION TO THE FINDING. The column comment at
-- 20260727130000_ingest_schema.sql:184 documents FIVE values
-- (delete_guard | shape | drift | stranded | schema). The comment is STALE:
-- app/Ingest/Runtime/RunExecutor.php:180 writes kind = 'projection' when a
-- projection throws after a successful landing (pinned by
-- tests/Feature/Ingest/RunExecutorProjectionTest.php:101).
--
-- Applying the comment's 5-value domain would have been actively harmful:
-- every one of dev's 13 ingest.anomalies rows is kind='projection', so
-- VALIDATE CONSTRAINT would abort with SQLSTATE 23514 and take the db push
-- with it; and in prod the next projection failure would 500 on the anomaly
-- INSERT instead of recording it -- the triage queue failing exactly when it
-- is needed. The domain below is the union of the documented set and the
-- values the code actually writes.
--
-- Written today:  delete_guard (app/Ingest/Landing/Lander.php:416)
--                 shape        (app/Ingest/Runtime/RunExecutor.php:128)
--                 stranded     (app/Ingest/Runtime/SourceScheduler.php:209)
--                 projection   (app/Ingest/Runtime/RunExecutor.php:180)
-- Reserved, unwritten: drift, schema (kept -- they are the delete-guard and
-- schema-drift escalations the file's own header contemplates).
--
-- The stale comment cannot be fixed in place (20260727130000 is already
-- applied to dev), so it is superseded by the COMMENT ON COLUMN below --
-- which is also what a future reader will see in \d+ ingest.anomalies.
--
-- ROLLBACK: ALTER TABLE ingest.anomalies DROP CONSTRAINT IF EXISTS anomalies_kind_check;

BEGIN;
SET LOCAL lock_timeout      = '5s';
SET LOCAL statement_timeout = '30s';

ALTER TABLE "ingest"."anomalies"
    ADD CONSTRAINT "anomalies_kind_check" CHECK ("kind" IN (
        'delete_guard', 'shape', 'drift', 'stranded', 'schema', 'projection'
    )) NOT VALID;

COMMENT ON COLUMN "ingest"."anomalies"."kind" IS
    'Closed domain, enforced by anomalies_kind_check: delete_guard | shape | drift | stranded | schema | projection. '
    'Supersedes the stale 5-value comment in 20260727130000_ingest_schema.sql:184, which omitted the '
    '''projection'' value RunExecutor.php:180 has always written. Adding a value requires widening the CHECK.';

COMMIT;
