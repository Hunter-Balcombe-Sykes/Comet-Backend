-- Drops two dead columns confirmed to have zero remaining readers/writers.
--
-- site.site_media.product_gid: legacy Shopify product-link column from
-- before the standalone strip-down. Grepped app/ routes/ config/ database/
-- tests/ for `product_gid` -- only hit was the SiteMedia @property docblock
-- (already annotated "not referenced by any current application code") and
-- SQLite test fixture DDL, neither of which reads or writes it. No
-- $fillable/$casts/$hidden/$appends entry, no DataExportPayloadBuilder
-- allow-list reference, no raw SQL/view/RLS/trigger reference anywhere in
-- supabase/migrations/. Its partial index (site_media_product_gid_idx) is
-- dropped automatically along with the column.
--
-- notifications.email_subscriptions.qr_slug: unique-when-set column never
-- written or read by app code (per its own @property docblock). Grepped
-- app/ routes/ config/ database/ tests/ for `qr_slug` -- only hit was the
-- EmailSubscription @property docblock and SQLite test fixture DDL. Not in
-- $fillable/$casts, no export allow-list reference, no RLS/trigger
-- reference. Its unique constraint (email_subscriptions_qr_slug_key) is
-- dropped automatically along with the column.
--
-- Neither table is in the migration guard's HOT_TABLES list, so no
-- CONCURRENTLY handling is required for this drop.

BEGIN;
SET LOCAL lock_timeout = '2s';
SET LOCAL statement_timeout = '10s';

ALTER TABLE site.site_media DROP COLUMN IF EXISTS product_gid;
ALTER TABLE notifications.email_subscriptions DROP COLUMN IF EXISTS qr_slug;

COMMIT;
