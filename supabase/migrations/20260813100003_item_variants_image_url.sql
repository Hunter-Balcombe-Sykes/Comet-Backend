-- Slice 5a Task 8 fix round 2, D1. content.item_variants names a choice
-- (label) and carries the provider's id for it (sku) — but not the image that
-- choice shows. Every shop scraper emits one per variant
-- (ShopifyScraper's variants[].image), and the public sitepage swaps the
-- product photo when a shopper picks a colour or style off it (#84). Without
-- this column the content.* round trip drops it: the variant survives, its
-- picture does not, and the swap has nothing to swap to.
--
-- The spec's §3.2 field mapping simply never accounted for it — the gap only
-- became visible when the public payload started being served from content.*
-- (fix round 1, C2) rather than from the legacy site.shop_products blob.
--
-- image_url, not media: deliberately a plain column rather than a row in
-- content.item_media. That table is keyed by (item_id, role, position) and
-- models the ITEM's gallery — it has no variant dimension, so a per-variant
-- asset has nowhere to attach without inventing one. The variant image is
-- also not independently addressable content (no fingerprint, no dedupe, no
-- storage path of its own); it is one attribute of the choice, which is what
-- this table already exists to describe. Widening item_media instead would
-- reshape a table every connector writes, for one platform's need.
--
-- No CONCURRENTLY: a nullable ADD COLUMN with no DEFAULT and no index is a
-- catalog-only metadata change on modern Postgres (11+) — it does not rewrite
-- the table or scan existing rows, so the ACCESS EXCLUSIVE lock is held for
-- the catalog update alone, not for the length of a scan. That is what makes
-- it safe here, NOT the row count: content.item_variants is a real, populated
-- table and this slice has been writing to it since Task 2. A future change
-- to it that DOES require a scan or rewrite (a NOT NULL, a non-null DEFAULT,
-- a type change) needs its own safe-migration treatment and must not inherit
-- this justification.
--
-- NOT APPLIED by this task — no supabase db push, no db link, no MCP database
-- call was run. The coordinator applies it to the shared dev database (two
-- other sessions use it). Until it lands there, ProjectionWriter writing this
-- column will error on dev; the SQLite stand-in and the Postgres-lane
-- provisioning already carry it so the suites run either way.
--
-- ROLLBACK: ALTER TABLE content.item_variants DROP COLUMN IF EXISTS image_url;

BEGIN;

ALTER TABLE content.item_variants
    ADD COLUMN IF NOT EXISTS image_url text NULL;

COMMENT ON COLUMN content.item_variants.image_url IS
    'The image this specific variant shows (ShopifyScraper''s variants[].image, etc.) — the picture the sitepage swaps to when a shopper selects this choice. NULL when the source published none, which is common and not an error. Not content.item_media: that table models the ITEM gallery and has no variant dimension.';

COMMIT;
