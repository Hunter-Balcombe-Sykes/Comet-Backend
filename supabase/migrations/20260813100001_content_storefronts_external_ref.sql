-- Task 4 fix round 1 (CRITICAL finding): ShopContentWriter::upsertStore()
-- keyed its idempotency lookup on content.collections.label, a mutable
-- display name (site.shop_brands.name, editable in-place). A rename between
-- backfill/sync runs missed the lookup and minted a second content.collections
-- + content.storefronts pair, orphaning the first — with its referral_query
-- and discount_code (affiliate revenue) frozen and unreachable, while both
-- rows stay linked to the same product items. Task 6's syncStore() calls
-- upsertStore() every scheduled cycle, so this was not an edge case.
--
-- The real identity is site.shop_brands.brand_id — the provider's own store
-- id (half of shop_brands_connection_id_brand_id_key, so stable across a
-- rename by construction) — but nothing on content.storefronts could hold it
-- until now. external_ref carries it.
--
-- No CONCURRENTLY: content.storefronts is a brand-new table (this slice, not
-- yet applied to prod — CLAUDE.md: "prod carries no customer data yet") with
-- effectively zero rows anywhere it has been applied, so there is no lock
-- contention to avoid. The index below is on a column ADD COLUMN-ed in this
-- SAME file — CONCURRENTLY cannot run inside the transaction wrapping the
-- ADD COLUMN anyway (guard-no-unsafe-migrations.php Check 1 exempts exactly
-- this shape: an index on a column added in the same migration is always
-- built over an empty column).
--
-- ROLLBACK: ALTER TABLE content.storefronts DROP COLUMN IF EXISTS external_ref;

BEGIN;

ALTER TABLE content.storefronts
    ADD COLUMN IF NOT EXISTS external_ref text NULL;

COMMENT ON COLUMN content.storefronts.external_ref IS
    'Provider-scoped store id (site.shop_brands.brand_id — e.g. a Shopify shop domain, or the reserved ''individual'' bucket), NOT the content.collections uuid. Carried so upsertStore()''s idempotency lookup survives a store rename (site.shop_brands.name is a mutable display label and must never be the key). Nullable/no default: rows from before this column existed have none until the next backfill/sync run re-upserts them.';

-- Lookup-performance index for upsertStore()'s WHERE clause, not a uniqueness
-- guard. True identity is (content.collections.user_id, provider,
-- external_ref) — user_id lives on collections, one table over, and Postgres
-- has no cross-table unique index; enforcing that triple at the DB layer
-- would mean denormalising user_id onto storefronts too (a bigger schema
-- change than this fix's scope: identity CORRECTNESS of the upsert key, not
-- hardening the pre-existing concurrent-double-run race, which the OLD
-- label-keyed lookup carried just as much as this one does and is unchanged
-- here). upsertStore() is the sole writer of this table and already scopes
-- every read through the (user_id, kind, provider, external_ref) join before
-- writing, so the application-level lookup is the actual enforcement.
CREATE INDEX IF NOT EXISTS idx_content_storefronts_external_ref
    ON content.storefronts (external_ref)
    WHERE external_ref IS NOT NULL;

COMMIT;
