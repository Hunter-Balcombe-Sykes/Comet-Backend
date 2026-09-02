-- Follow-up to 20260902170000, which narrowed site_media_pool_check to
-- content|design|documents but left the COLUMN DEFAULT pointing at 'gallery'.
--
-- That combination is self-contradictory: any INSERT that omits `pool` takes
-- the default and is then rejected by the CHECK. Eloquent never hits it —
-- SiteMedia::$attributes has supplied 'pool' => POOL_CONTENT since Item 5 —
-- but raw SQL does, and CI's applied-schema lane caught it immediately
-- (tests/Schema/SiteMediaScanStateTest inserts without a pool and got
-- SQLSTATE 23514).
--
-- 'content' is the right default for the same reason the model's is: it is
-- now the only pool a general-purpose media INSERT could mean. 'design' and
-- 'documents' are singleton/purpose lanes whose writers always name the pool.
--
-- Catalog-only change: SET DEFAULT rewrites no rows and takes no scan.
--
-- ROLLBACK: ALTER COLUMN pool SET DEFAULT 'gallery' — but only alongside a
--           rollback of 20260902170000, or the default is illegal again.

SET lock_timeout      = '2s';
SET statement_timeout = '30s';

ALTER TABLE "site"."site_media" ALTER COLUMN "pool" SET DEFAULT 'content';
