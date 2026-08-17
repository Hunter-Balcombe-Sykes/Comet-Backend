-- 20260819000100_content_storefronts_user_id.sql
--
-- Denormalise the owner onto content.storefronts so store identity
-- (user_id, provider, external_ref) is enforceable in ONE table.
--
-- Why this is needed at all: 20260813100001's own comment states the position —
-- true identity is (content.collections.user_id, provider, external_ref), but
-- user_id lives one table over and Postgres has no cross-table unique index.
-- Slice 5b §8 deferred the fix to slice 7; slice 7 never picked it up. With the
-- legacy site.shop_brands gone, ShopContentWriter::upsertStore() is the SOLE
-- writer and its application-level lookup is the only thing standing between a
-- concurrent scheduled sync and a duplicated store — the same fault that minted
-- 18 collections for 9 stores during slice 5a.
--
-- The unique index itself is 20260819000110, in its own file: it is built
-- CONCURRENTLY and CLAUDE.md's one-CONCURRENTLY-per-file rule applies.
--
-- Pre-flight against dev (glncumufgaqcmqhzwrxm), 2026-08-17, before writing this:
--   storefronts_total          15
--   null_external_ref           0
--   orphaned_no_collection      0
--   collection_without_owner    0
--   duplicate_identities        0
-- So the UPDATE fills every row, SET NOT NULL cannot fail on a leftover null,
-- and 20260819000110's index cannot fail on a pre-existing duplicate.
--
-- ROLLBACK: DROP INDEX IF EXISTS content.storefronts_user_provider_ref_uq;
--           ALTER TABLE content.storefronts DROP COLUMN IF EXISTS user_id;
BEGIN;

ALTER TABLE content.storefronts
    ADD COLUMN IF NOT EXISTS user_id uuid REFERENCES core.users(id) ON DELETE CASCADE;

UPDATE content.storefronts s
   SET user_id = c.user_id
  FROM content.collections c
 WHERE c.id = s.collection_id
   AND s.user_id IS NULL;

ALTER TABLE content.storefronts ALTER COLUMN user_id SET NOT NULL;

COMMIT;
