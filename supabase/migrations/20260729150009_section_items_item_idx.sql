-- ROLLBACK: DROP INDEX CONCURRENTLY IF EXISTS site.idx_section_items_item;
--           in its own one-statement file, no BEGIN/COMMIT (CONVENTIONS.md §1).
CREATE INDEX CONCURRENTLY IF NOT EXISTS "idx_section_items_item"
    ON "site"."section_items" ("item_id");
