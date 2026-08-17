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
-- writer and its application-level lookup was the only thing standing between a
-- concurrent scheduled sync and a duplicated store — the same fault that minted
-- 18 collections for 9 stores during slice 5a.
--
-- The unique index itself is 20260819000110, in its own file: it is built
-- CONCURRENTLY and CLAUDE.md's one-CONCURRENTLY-per-file rule applies.
--
-- ⚠️ HISTORY — dev ran an EARLIER, UNSAFE FORM of this file.
-- As first applied to dev (glncumufgaqcmqhzwrxm) this migration used a bare
-- `ALTER COLUMN user_id SET NOT NULL` and an inline column REFERENCES. The
-- pre-push guard rejected both (CONVENTIONS.md §3 and §4) and the file was
-- rewritten to the safe pattern below. Dev is NOT re-run — the version is
-- already recorded in supabase_migrations.schema_migrations, whose `statements`
-- column preserves what actually executed there — and dev already holds exactly
-- the end state this file produces: user_id NOT NULL, FK present and validated,
-- 0 null owners across 15 rows. What follows is the form PRODUCTION and any
-- from-zero apply will use, and it reaches the identical end state.
--
-- Pre-flight against dev before the original apply: 15 storefronts, 0 null
-- external_ref, 0 orphans, 0 collections without an owner, 0 duplicate
-- (user_id, provider, external_ref) identities.
--
-- ROLLBACK: ALTER TABLE content.storefronts DROP CONSTRAINT IF EXISTS storefronts_user_id_fkey;
--           ALTER TABLE content.storefronts ALTER COLUMN user_id DROP NOT NULL;
--           ALTER TABLE content.storefronts DROP COLUMN IF EXISTS user_id;

-- Step 1 — add the column, nullable, with NO inline FK. An inline column
-- REFERENCES validates every existing row under ACCESS EXCLUSIVE exactly as a
-- bare ADD CONSTRAINT would (CONVENTIONS.md §4).
BEGIN;

ALTER TABLE content.storefronts
    ADD COLUMN IF NOT EXISTS user_id uuid;

COMMIT;

-- Step 2 — the foreign key, NOT VALID: only new rows are checked at write time,
-- so the catalog write releases its lock immediately.
BEGIN;

ALTER TABLE content.storefronts
    ADD CONSTRAINT storefronts_user_id_fkey
    FOREIGN KEY (user_id) REFERENCES core.users(id) ON DELETE CASCADE
    NOT VALID;

COMMIT;

-- Step 3 — backfill, deliberately OUTSIDE a transaction (CONVENTIONS.md §5).
-- Every storefront reaches its owner through its parent collection; that is the
-- relationship this column denormalises.
UPDATE content.storefronts s
   SET user_id = c.user_id
  FROM content.collections c
 WHERE c.id = s.collection_id
   AND s.user_id IS NULL;

-- Step 4 — the NOT NULL guarantee as a NOT VALID check, so the scan does not
-- run under ACCESS EXCLUSIVE.
BEGIN;

ALTER TABLE content.storefronts
    ADD CONSTRAINT chk_storefronts_user_id_not_null CHECK (user_id IS NOT NULL) NOT VALID;

COMMIT;

-- Step 5 — validate both, each in its OWN transaction. VALIDATE takes only
-- SHARE UPDATE EXCLUSIVE, which allows concurrent reads and writes; bundling it
-- with its ADD would hold the heavier lock through the whole scan and defeat the
-- split (guard Check 8).
BEGIN;

ALTER TABLE content.storefronts VALIDATE CONSTRAINT storefronts_user_id_fkey;

COMMIT;

BEGIN;

ALTER TABLE content.storefronts VALIDATE CONSTRAINT chk_storefronts_user_id_not_null;

COMMIT;

-- Step 6 — promote to a real NOT NULL. Near-instant: Postgres skips the row
-- scan because the validated CHECK already proves no NULLs. The helper check
-- has done its job and goes with it.
BEGIN;

ALTER TABLE content.storefronts ALTER COLUMN user_id SET NOT NULL;
ALTER TABLE content.storefronts DROP CONSTRAINT chk_storefronts_user_id_not_null;

COMMIT;
