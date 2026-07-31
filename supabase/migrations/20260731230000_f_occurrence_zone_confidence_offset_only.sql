-- Nightwatch #370 — content.f_occurrence's zone_confidence CHECK admits only
-- ('explicit','inferred','assumed'), but SchemaOrgEventProjector writes a
-- fourth value, 'offset_only'. The projector shipped in 978449f9 (eventbrite +
-- humanitix connectors) AFTER the content schema (20260727140000) and invented
-- the value without widening the constraint.
--
-- Blast radius is a whole ingest run, not one facet: the QueryException from
-- ProjectionWriter::upsertSingletonFacet propagates through writeFacets →
-- RunExecutor::execute and kills RunSourceJob, after source_items,
-- identity_keys, items, item_anchors, item_media, offers and f_link have
-- already been written. Every schema.org event carrying a start_date hits it.
--
-- WIDEN, DO NOT REMAP. 'offset_only' is a genuinely distinct state and the
-- projector's docblock is right about it: the vendor emits an ISO string with
-- the event's local UTC offset ("2026-06-20T09:00:00+10:00"), so the UTC
-- instant is exact, but the IANA zone is unknowable from an offset alone. That
-- is neither 'inferred' (we derived a zone from something) nor 'assumed' (we
-- picked a zone with no evidence) — in both of those we HAVE a zone. Here
-- `timezone` stays NULL by design. Collapsing the value into either would tell
-- content:retime a null zone was a guessed zone.
--
-- The new predicate is strictly a SUPERSET of the old one, so every existing
-- row already passes and the VALIDATE in 20260731230001 is a formality.
-- Verified against dev (glncumufgaqcmqhzwrxm) on 2026-07-31 before writing:
--   select zone_confidence, count(*) from content.f_occurrence group by 1;
-- returned zero rows — the table is empty precisely because the constraint has
-- been aborting the run before any occurrence lands. The split is kept anyway,
-- per CONVENTIONS.md §2, because the table will not stay empty once ingest
-- succeeds.
--
-- Re-adds under the constraint's existing auto-generated name
-- (f_occurrence_zone_confidence_check, from the inline CHECK in
-- 20260727140000) so a from-zero apply reaches the same catalog state as an
-- incremental one.
--
-- ROLLBACK: ALTER TABLE "content"."f_occurrence"
--               DROP CONSTRAINT IF EXISTS "f_occurrence_zone_confidence_check";
--           (Per CONVENTIONS.md §10, the reverse of ADD CONSTRAINT ... NOT
--           VALID is the DROP alone. Deliberately NOT re-adding the narrow
--           predicate here: §10 forbids reproducing a vocabulary list inside a
--           note, because ConstraintVocabularyLockstepTest regexes the FIRST
--           such list in the raw file and a note would shadow the real one.
--           Restoring the pre-migration constraint means restating the
--           predicate from 20260727140000 — and it CANNOT be restored while
--           any row carries the value this migration admitted: those rows
--           would have to be DELETEd first, destroying every schema.org event
--           occurrence, after which RunSourceJob resumes dying on the next
--           Eventbrite/Humanitix run. Roll forward instead.)

BEGIN;
SET LOCAL lock_timeout      = '5s';
SET LOCAL statement_timeout = '60s';

ALTER TABLE "content"."f_occurrence"
    DROP CONSTRAINT IF EXISTS "f_occurrence_zone_confidence_check";

ALTER TABLE "content"."f_occurrence"
    ADD CONSTRAINT "f_occurrence_zone_confidence_check"
    CHECK ("zone_confidence" IS NULL
           OR "zone_confidence" IN ('explicit', 'inferred', 'assumed', 'offset_only'))
    NOT VALID;

COMMIT;
