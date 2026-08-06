-- One auto-sync toggle grammar (platforms-as-sources, 2026-08-05).
--
-- The two site-level auto switches migrate into the connections' own sparse
-- display_settings under the one key every platform uses now
-- (auto_sync_latest; absent = ON, only explicit false is off):
--
--   sites.content_instagram_auto_enabled  → instagram connections
--   sites.shop_auto_latest                → shop (partna.storefront) connections
--
-- Only the OFF state is copied — ON is the sparse default, and the old
-- instagram semantics (null == off, flipped true on connect) mean null and
-- false both migrate as off WHERE a connection exists to carry the value.
--
-- ROLLBACK: ALTER TABLE site.sites ADD COLUMN content_instagram_auto_enabled boolean,
--           ADD COLUMN shop_auto_latest boolean NOT NULL DEFAULT true;
--           IRREVERSIBLE in practice: the per-connection values cannot be
--           split back into the exact site-column states they came from.

BEGIN;

SET LOCAL lock_timeout      = '2s';
SET LOCAL statement_timeout = '10s';

UPDATE "site"."platform_connections" pc
SET "display_settings" = COALESCE(pc."display_settings", '{}'::jsonb)
        || '{"auto_sync_latest": false}'::jsonb,
    "updated_at" = now()
FROM "site"."sites" s
WHERE s."user_id" = pc."user_id"
  AND pc."surface_key" LIKE 'instagram.%'
  AND pc."deleted_at" IS NULL
  AND COALESCE(s."content_instagram_auto_enabled", false) = false;

UPDATE "site"."platform_connections" pc
SET "display_settings" = COALESCE(pc."display_settings", '{}'::jsonb)
        || '{"auto_sync_latest": false}'::jsonb,
    "updated_at" = now()
FROM "site"."sites" s
WHERE s."user_id" = pc."user_id"
  AND pc."surface_key" = 'partna.storefront'
  AND pc."deleted_at" IS NULL
  AND s."shop_auto_latest" = false;

ALTER TABLE "site"."sites" DROP COLUMN IF EXISTS "content_instagram_auto_enabled";
ALTER TABLE "site"."sites" DROP COLUMN IF EXISTS "shop_auto_latest";

COMMIT;
