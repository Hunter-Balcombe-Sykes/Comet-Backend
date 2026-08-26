-- Give collection-facet rows an ORIGIN: which source item contributed them.
--
-- Without it, replaceCollections() can only scope its delete to
-- (item_id, source_id) — and there is exactly ONE manual content.sources row
-- per user, so two hand-added items bound to one item are indistinguishable and
-- each save wipes the other's rows. See
-- docs/superpowers/specs/2026-08-26-facet-origin-scope-design.md §2.
--
-- NULLABLE and NULL-by-default on purpose: NULL means "unscoped, replaced
-- exactly as today", which is what makes the backfill below unable to corrupt
-- anything and what keeps the flag-off path byte-for-byte.
--
-- collection_items is deliberately NOT included — its PK is
-- (collection_id, item_id), so membership is per-item by design and there is no
-- per-coord set to preserve. f_action is not included either: it has no writer
-- anywhere in app/.
--
-- The four (item_id, source_item_id) indexes that serve the scoped delete are
-- NOT here: each gets its own +1..+4 file so it can be CONCURRENTLY. A
-- CONCURRENTLY statement cannot share a file with anything else — the CLI sends
-- a multi-statement file as ONE libpq pipeline and CONCURRENTLY fails there with
-- SQLSTATE 25001 (CONVENTIONS.md §1, guard-no-unsafe-migrations check 6). They
-- lead with item_id, which is populated, so the guard rightly refuses the plain
-- form: a composite index still scans the whole table.
--
-- The four indexes are reversed by their own +1..+4 files.
-- ROLLBACK:
--   ALTER TABLE "content"."item_media"    DROP COLUMN IF EXISTS "source_item_id";
--   ALTER TABLE "content"."offers"        DROP COLUMN IF EXISTS "source_item_id";
--   ALTER TABLE "content"."item_tags"     DROP COLUMN IF EXISTS "source_item_id";
--   ALTER TABLE "content"."item_variants" DROP COLUMN IF EXISTS "source_item_id";
-- The backfill needs no inverse: dropping the columns takes it with them.

ALTER TABLE "content"."item_media"
    ADD COLUMN IF NOT EXISTS "source_item_id" uuid NULL
    REFERENCES "content"."source_items" ("id") ON DELETE CASCADE;

ALTER TABLE "content"."offers"
    ADD COLUMN IF NOT EXISTS "source_item_id" uuid NULL
    REFERENCES "content"."source_items" ("id") ON DELETE CASCADE;

ALTER TABLE "content"."item_tags"
    ADD COLUMN IF NOT EXISTS "source_item_id" uuid NULL
    REFERENCES "content"."source_items" ("id") ON DELETE CASCADE;

ALTER TABLE "content"."item_variants"
    ADD COLUMN IF NOT EXISTS "source_item_id" uuid NULL
    REFERENCES "content"."source_items" ("id") ON DELETE CASCADE;

-- Backfill ONLY where it is unambiguous: the item has exactly one LIVE source
-- item on that source. Anything ambiguous stays NULL and behaves as it does
-- today, healing on its next write. This is why the backfill cannot corrupt
-- data and needs no down-migration beyond dropping the columns.
UPDATE "content"."item_media" m
SET "source_item_id" = si."id"
FROM "content"."source_items" si
WHERE si."item_id" = m."item_id"
  AND si."source_id" = m."source_id"
  AND si."removed_at" IS NULL
  AND m."source_item_id" IS NULL
  AND NOT EXISTS (
      SELECT 1 FROM "content"."source_items" si2
      WHERE si2."item_id" = m."item_id"
        AND si2."source_id" = m."source_id"
        AND si2."removed_at" IS NULL
        AND si2."id" <> si."id"
  );

UPDATE "content"."offers" o
SET "source_item_id" = si."id"
FROM "content"."source_items" si
WHERE si."item_id" = o."item_id"
  AND si."source_id" = o."source_id"
  AND si."removed_at" IS NULL
  AND o."source_item_id" IS NULL
  AND NOT EXISTS (
      SELECT 1 FROM "content"."source_items" si2
      WHERE si2."item_id" = o."item_id"
        AND si2."source_id" = o."source_id"
        AND si2."removed_at" IS NULL
        AND si2."id" <> si."id"
  );

UPDATE "content"."item_tags" t
SET "source_item_id" = si."id"
FROM "content"."source_items" si
WHERE si."item_id" = t."item_id"
  AND si."source_id" = t."source_id"
  AND si."removed_at" IS NULL
  AND t."source_item_id" IS NULL
  AND NOT EXISTS (
      SELECT 1 FROM "content"."source_items" si2
      WHERE si2."item_id" = t."item_id"
        AND si2."source_id" = t."source_id"
        AND si2."removed_at" IS NULL
        AND si2."id" <> si."id"
  );

UPDATE "content"."item_variants" v
SET "source_item_id" = si."id"
FROM "content"."source_items" si
WHERE si."item_id" = v."item_id"
  AND si."source_id" = v."source_id"
  AND si."removed_at" IS NULL
  AND v."source_item_id" IS NULL
  AND NOT EXISTS (
      SELECT 1 FROM "content"."source_items" si2
      WHERE si2."item_id" = v."item_id"
        AND si2."source_id" = v."source_id"
        AND si2."removed_at" IS NULL
        AND si2."id" <> si."id"
  );
