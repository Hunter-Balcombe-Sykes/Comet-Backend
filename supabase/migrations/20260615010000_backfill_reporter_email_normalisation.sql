-- 20260615010000_backfill_reporter_email_normalisation.sql
--
-- SEM-1 (data side) — normalise existing moderation.case_signals.reporter_email
-- to lowercase + trimmed, matching the lowercased lookup the GDPR export uses.
-- The write-side normalisation now lives in ContentReportService and the export
-- query is already case-insensitive (so correctness no longer depends on this);
-- this backfill makes the stored column canonical so future exact-match queries
-- and dedup stay correct.
--
-- Plain UPDATE in a transaction (no CONCURRENTLY / NOT VALID needed): the row
-- count is bounded by the number of content reports, which is tiny pre-beta.
-- Only touches rows that actually differ.

BEGIN;

UPDATE moderation.case_signals
SET reporter_email = lower(btrim(reporter_email))
WHERE reporter_email IS NOT NULL
  AND reporter_email IS DISTINCT FROM lower(btrim(reporter_email));

COMMIT;
