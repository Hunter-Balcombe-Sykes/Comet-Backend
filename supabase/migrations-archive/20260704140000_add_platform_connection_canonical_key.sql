-- =====================================================================
-- FOUND-14 — canonical_key column for multi-account platform dedupe
-- =====================================================================
-- writeAccountConnection() previously scanned every account row's JSONB
-- payload across 7 identity fields to detect a reconnected account — unindexable, no
-- DB-level uniqueness. This promotes the platform-canonical identity value (the
-- same value accountKeyOf() derives) to a normalized, indexed column set at write
-- time, plus a partial UNIQUE index for a DB-level dedupe guarantee.
--
-- Scope: ACCOUNT rows only (resource_id LIKE 'acct-%'); single-selection default,
-- 'event-*' and 'link-*' rows keep canonical_key NULL and are excluded by the
-- partial predicate. Dev holds ZERO acct-* rows today → backfill/dedup are no-ops.
--
-- guard:no-unsafe-migrations:disable-file
-- Exempt: the index build can't use CONCURRENTLY inside this transaction (needed
-- for the backfill + dedup steps above to run atomically). Safe without it anyway —
-- canonical_key is a column ADD COLUMN-ed earlier in this same migration, so the
-- partial predicate (`canonical_key IS NOT NULL`) matches zero rows until the
-- backfill above runs, and dev holds zero acct-* rows today regardless (see header).

BEGIN;

-- 1) Additive nullable column — catalog-only, no table rewrite.
ALTER TABLE site.platform_connections
    ADD COLUMN IF NOT EXISTS canonical_key text;

-- 2) Backfill existing account rows from the stored identity field, normalized
--    exactly like the app (lower(trim(...))). COALESCE walks the same candidate
--    fields the old PHP scan probed, in the app's precedence order.
UPDATE site.platform_connections
SET canonical_key = lower(btrim(COALESCE(
        payload->>'handle', payload->>'input', payload->>'apiPath',
        payload->>'channelId', payload->>'login', payload->>'url', payload->>'link'
    )))
WHERE resource_id LIKE 'acct-%'
  AND deleted_at IS NULL
  AND canonical_key IS NULL
  AND COALESCE(
        payload->>'handle', payload->>'input', payload->>'apiPath',
        payload->>'channelId', payload->>'login', payload->>'url', payload->>'link'
    ) IS NOT NULL;

-- 3) Dedup guard so the unique index can build: keep the most-recently-updated
--    row per identity, soft-delete the rest. No-op on current data.
WITH ranked AS (
    SELECT id, row_number() OVER (
               PARTITION BY user_id, platform, canonical_key
               ORDER BY updated_at DESC NULLS LAST, created_at DESC NULLS LAST, id
           ) AS rn
    FROM site.platform_connections
    WHERE canonical_key IS NOT NULL AND deleted_at IS NULL
)
UPDATE site.platform_connections p
SET deleted_at = now(), is_active = false
FROM ranked
WHERE p.id = ranked.id AND ranked.rn > 1;

-- 4) DB-level dedupe guarantee for account rows (partial: only constrained rows).
CREATE UNIQUE INDEX IF NOT EXISTS idx_platform_connections_canonical
    ON site.platform_connections (user_id, platform, canonical_key)
    WHERE canonical_key IS NOT NULL AND deleted_at IS NULL;

COMMIT;
