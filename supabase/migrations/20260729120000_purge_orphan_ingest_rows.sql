-- Pre-flight for 20260729120001 / 20260729120002: an unreachable row would
-- abort the FK's validation scan (SQLSTATE 23503) and take the whole db push
-- with it. These rows are exactly the PII #PRIV-1/DINT-1 describe: content
-- landed under a stream (or effects charged against a source) that no longer
-- exists, reachable by nothing and deletable by nothing. Deleting them IS
-- part of the fix, not merely a precondition for it.
--
-- Expected to match 0 rows today (the ingest fleet landed 2026-07-27 and prod
-- carries no customer data). Left in regardless: it is the only thing standing
-- between a stray orphan and a failed production migration.

DELETE FROM "ingest"."record_versions" rv
WHERE NOT EXISTS (
    SELECT 1 FROM "ingest"."streams" s WHERE s."id" = rv."stream_id"
);

DELETE FROM "ingest"."effects" e
WHERE e."source_id" IS NOT NULL
  AND NOT EXISTS (
    SELECT 1 FROM "ingest"."sources" s WHERE s."id" = e."source_id"
  );
