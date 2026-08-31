-- The scope of a review is a fact about its INGESTION, and until now nothing
-- recorded it (BLOCKER, 2026-09-01). PoolResolver's person-scope has one tier
-- that publishes a review carrying no name evidence at all — no staff
-- attribution, no mention in the prose — purely because the vendor feed behind
-- it was already narrowed to one team member. That gate read
-- ingest.sources.selection_ref, the source's CURRENT selection, while the
-- reviews themselves carried no marker saying what they arrived under. So a
-- Fresha connection that harvested vision-hair-studio-melbourne-tzo6gxk0
-- STOREWIDE and was later narrowed to employee 5035183 retroactively
-- re-labelled the whole salon's corpus as that employee's and published all of
-- it on their page, permanently: no later run could tell those rows apart.
--
-- content.source_items is the row an ingestion writes, keyed (source_id,
-- coord), so the selection in force at write time belongs on it.
-- ProjectionWriter::upsertSourceItem() stamps it, and only a run that actually
-- FETCHED may restate it — `ingest:project` re-derives from the landed log
-- without fetching and leaves it alone.
--
-- DELIBERATELY NOT BACKFILLED. Every existing row stays NULL, and NULL is read
-- as "we do not know", which the gate treats as NOT employee-scoped. Copying
-- ingest.sources.selection_ref into these rows is precisely the retroactive
-- re-labelling this column exists to end; the honest cost is that reviews
-- genuinely landed under an employee selection stay suppressed until their
-- source is harvested again, and suppression is the safe direction.
--
-- ROLLBACK: ALTER TABLE "content"."source_items" DROP COLUMN IF EXISTS "ingest_selection_ref";

BEGIN;
SET LOCAL lock_timeout      = '2s';
SET LOCAL statement_timeout = '10s';

ALTER TABLE "content"."source_items"
    ADD COLUMN IF NOT EXISTS "ingest_selection_ref" text NULL;

COMMIT;
