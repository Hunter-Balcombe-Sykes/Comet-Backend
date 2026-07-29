-- SCHEMA-1 / DINT-5: content.source_items.kind is the INGRESS for every
-- projected record (ProjectionWriter::upsertSourceItem, app/Ingest/Projection/
-- ProjectionWriter.php:211,229) yet carried no domain, while content.items.kind
-- -- one hop downstream -- has enforced the same 14 values since
-- 20260727140000_content_schema.sql:43-46. The effect of the gap is that a
-- projector-authoring bug is caught on the SECOND write, not the first.
--
-- The domain is an EXACT MIRROR of content.items.kind, deliberately: the value
-- is copied verbatim into items.kind by createItem(), so a superset here would
-- only move the failure back to where it already is.
--
-- Verified against real data before writing (2026-07-29): dev carries 348
-- source_items rows, 0 outside this domain (release 202, episode 82, video 54,
-- event 6, channel 3, article 1). Prod does not have content.* yet
-- (to_regclass IS NULL) -- this lands on an empty table at the cutover.
-- All 19 Projector::kind() implementations return in-domain literals.
--
-- ROLLBACK: ALTER TABLE content.source_items DROP CONSTRAINT IF EXISTS source_items_kind_check;

BEGIN;
SET LOCAL lock_timeout      = '5s';
SET LOCAL statement_timeout = '30s';

ALTER TABLE "content"."source_items"
    ADD CONSTRAINT "source_items_kind_check" CHECK ("kind" IN (
        'video', 'track', 'release', 'episode', 'channel', 'service', 'menu_item',
        'product', 'event', 'link', 'media', 'review', 'document', 'article'
    )) NOT VALID;

COMMIT;
