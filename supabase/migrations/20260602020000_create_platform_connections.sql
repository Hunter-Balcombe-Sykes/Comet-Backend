-- 20260602020000_create_platform_connections.sql
--
-- Per-user platform integration connections — the pilot promotion of the
-- platform feature from single-tenant test-mode (one global cache blob per
-- platform in routes/api/platforms.php) to durable per-user storage. This is
-- the promotion documented in FreshaController: "persist via a
-- platform_connections table per user".
--
-- DELIBERATELY SEPARATE from site.smart_links (product decision): platforms is
-- an additive, self-contained feature — it does not combine with, override, or
-- depend on smart_links or any other system. The two share conventions (uuid
-- PK, jsonb payload, refresh bookkeeping columns, soft deletes) for house
-- consistency, but are independent tables, models, observers, and read paths.
--
-- One row = one connected resource for one user (a Shopify store, an Apple
-- artist, an Instagram username, a Fresha salon, ...). The user-curated
-- selection AND the last fetched upstream snapshot both live in `payload`
-- (shape varies per platform — mirrors the blob each controller caches today).
--
-- Indexes are non-concurrent in the same file as CREATE TABLE: the table is
-- empty at index time so there is no lock contention (the migration guard
-- exempts same-file CREATE TABLE + CREATE INDEX). FK + CHECK are inline in
-- CREATE TABLE (not ADD CONSTRAINT) — also lock-free on an empty table.

BEGIN;

CREATE TABLE IF NOT EXISTS site.platform_connections (
    id                    uuid PRIMARY KEY,
    user_id               uuid NOT NULL REFERENCES core.users (id) ON DELETE CASCADE,

    platform              text NOT NULL CHECK (platform IN (
                              'shopify', 'eventbrite', 'apple-music', 'apple-podcast',
                              'youtube', 'instagram', 'fresha', 'tiktok', 'facebook'
                          )),

    -- Opaque per-platform resource key: brand id, organiser slug, artist id,
    -- channel id, username, store slug. Unique per (user, platform).
    resource_id           text NOT NULL,

    -- User-curated selection + last fetched upstream snapshot. Shape varies
    -- per platform (mirrors the blob each controller caches today).
    payload               jsonb NOT NULL DEFAULT '{}'::jsonb,

    -- Ordering across a user's connections of one platform (e.g. Shopify
    -- multi-brand) + per-row visibility.
    sort_order            integer NOT NULL DEFAULT 0,
    is_active             boolean NOT NULL DEFAULT true,

    -- Refresh bookkeeping (mirrors site.smart_links).
    last_visited_at       timestamptz,
    last_refreshed_at     timestamptz,
    last_refresh_status   text CHECK (last_refresh_status IN ('ok', 'unavailable', 'error')),
    last_refresh_error    text,
    consecutive_failures  integer NOT NULL DEFAULT 0,

    created_at            timestamptz,
    updated_at            timestamptz,
    deleted_at            timestamptz
);

-- One active connection per (user, platform, resource).
CREATE UNIQUE INDEX IF NOT EXISTS idx_platform_connections_unique_active
    ON site.platform_connections (user_id, platform, resource_id)
    WHERE deleted_at IS NULL;

-- Dashboard + public sitepage read order (all of a user's connections for a platform).
CREATE INDEX IF NOT EXISTS idx_platform_connections_user_platform_sort
    ON site.platform_connections (user_id, platform, sort_order)
    WHERE deleted_at IS NULL;

-- Background refresh staleness scan (cron picks the oldest first).
CREATE INDEX IF NOT EXISTS idx_platform_connections_last_refreshed
    ON site.platform_connections (last_refreshed_at)
    WHERE deleted_at IS NULL AND is_active;

COMMIT;
