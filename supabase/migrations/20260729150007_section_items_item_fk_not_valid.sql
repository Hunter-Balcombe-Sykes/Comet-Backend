-- DINT-4. site.section_items.item_id pointed at content.items with nothing
-- enforcing it (20260727150000_sections_and_documents.sql:73), while the
-- section_id on the line above carried a proper FK. The docblock reason in
-- app/Models/Core/Site/SectionItem.php:22 ("no FK: content lives in another
-- schema") is not a real constraint -- Postgres supports cross-schema FKs, and
-- this codebase already has one (content.sources.connection_id ->
-- site.platform_connections, 20260727140000_content_schema.sql:23). Update that
-- docblock in the same PR.
--
-- ON DELETE CASCADE, not SET NULL: item_id is NOT NULL, and a pin/exclude that
-- names a deleted item is meaningless -- there is nothing for it to mean. Not
-- RESTRICT: that would make a future GC job (DINT-10) fail rather than clean up.
--
-- Both merge paths already satisfy this by construction:
--   - ItemMerger::foldInto() repoints section_items onto the survivor
--     (app/Services/Content/ItemMerger.php:258-265) BEFORE the discarded item
--     is deleted at ItemMerger.php:76.
--   - ProjectionWriter::mergeInto() refuses to delete a discarded item that
--     carries curation (app/Ingest/Projection/ProjectionWriter.php:513-518).
-- The residual risk this closes is any OTHER hard delete of content.items.
--
-- ROLLBACK: ALTER TABLE site.section_items DROP CONSTRAINT IF EXISTS section_items_item_id_fk;

BEGIN;
SET LOCAL lock_timeout      = '5s';
SET LOCAL statement_timeout = '30s';

ALTER TABLE "site"."section_items"
    ADD CONSTRAINT "section_items_item_id_fk"
    FOREIGN KEY ("item_id") REFERENCES "content"."items" ("id") ON DELETE CASCADE
    NOT VALID;

COMMIT;
