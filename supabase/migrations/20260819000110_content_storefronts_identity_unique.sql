-- 20260819000110_content_storefronts_identity_unique.sql
--
-- Store identity, enforced by the database rather than by upsertStore()'s
-- read-then-write. One concern, one statement: CREATE INDEX CONCURRENTLY
-- cannot share a migration file with anything else (CLAUDE.md — the CLI sends a
-- multi-statement file as one libpq pipeline, and CONCURRENTLY is rejected
-- inside a pipeline with SQLSTATE 25001), and it cannot run inside a
-- transaction either, so this file has no BEGIN/COMMIT.
--
-- PARTIAL on external_ref IS NOT NULL, deliberately. The column is nullable
-- (20260813100001 added it to existing rows), and in Postgres two NULLs are
-- distinct under a unique index — so a plain index would silently permit
-- unlimited (user_id, provider, NULL) rows while LOOKING like it enforced
-- identity. A row with no external_ref has no identity to enforce: it cannot be
-- looked up by collectionIdFor(), and both ShopContentReader::brandMap() and
-- ShopConnections::stores() skip it rather than collide it onto ''. Saying
-- that in the predicate is honest; leaving it implicit is not.
--
-- Dev pre-flight 2026-08-17: 0 rows with a null external_ref and 0 duplicate
-- identities, so this builds clean. CONCURRENTLY regardless — content.storefronts
-- is on the live public read path.
--
-- ROLLBACK: DROP INDEX IF EXISTS content.storefronts_user_provider_ref_uq;

CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS storefronts_user_provider_ref_uq
    ON content.storefronts (user_id, provider, external_ref)
    WHERE external_ref IS NOT NULL;
