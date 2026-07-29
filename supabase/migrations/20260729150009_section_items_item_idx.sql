CREATE INDEX CONCURRENTLY IF NOT EXISTS "idx_section_items_item"
    ON "site"."section_items" ("item_id");
