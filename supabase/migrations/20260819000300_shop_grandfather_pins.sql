-- Shop goes opt-in (owner, 2026-08-17): the shop section shape moves from
-- kind_is (the whole catalogue publishes) to the pins + latest-per-source
-- default. Without this every connected store's live products would fall to
-- "newest only" the moment the shape changes. Grandfather: pin every product
-- that is visible today — kind product, not removed, backed by a live source
-- item, not already curated either way — into its site's shop section, in
-- today's recency order, so nothing disappears and the owner prunes from
-- there.
--
-- Idempotent (ON CONFLICT on the (section_id, item_id) unique) and scoped to
-- sites that already HAVE a shop section — a site with none has nothing
-- published to keep.
-- ROLLBACK: NONE — these are curation rows the owner can remove; deleting
-- them blindly would drop pins the owner made by hand after this ran.

BEGIN;

INSERT INTO "site"."section_items" ("section_id", "item_id", "state", "sort_key", "created_at")
SELECT
    s.id,
    i.id,
    'pinned',
    ROW_NUMBER() OVER (PARTITION BY s.id ORDER BY i.first_seen_at DESC, i.id),
    now()
FROM "site"."sections" s
JOIN "site"."sites" st ON st.id = s.site_id
JOIN "content"."items" i
    ON i.user_id = st.user_id
   AND i.kind = 'product'
   AND i.removed_at IS NULL
WHERE s.key = 'pool:shop'
  AND EXISTS (
      SELECT 1 FROM "content"."source_items" si
      WHERE si.item_id = i.id AND si.removed_at IS NULL
  )
  AND NOT EXISTS (
      SELECT 1 FROM "site"."section_items" existing
      WHERE existing.section_id = s.id AND existing.item_id = i.id
  )
ON CONFLICT ("section_id", "item_id") DO NOTHING;

COMMIT;
