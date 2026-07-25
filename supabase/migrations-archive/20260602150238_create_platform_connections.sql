-- =====================================================================
-- site.platform_connections — external platform link records
-- =====================================================================
-- Adopted into the repo to reconcile migration history: this migration was
-- applied directly to the development database on 2026-06-02 (version
-- 20260602150238) but had no file in git. Reconstructed verbatim from the
-- live dev definition so repo↔dev histories match and fresh rebuilds produce
-- the same schema. Already applied on dev — `supabase db push` skips it by
-- version match (it will not re-run).

CREATE TABLE IF NOT EXISTS site.platform_connections (
    id                    uuid PRIMARY KEY,
    user_id               uuid NOT NULL REFERENCES core.users (id) ON DELETE CASCADE,
    platform              text NOT NULL CHECK (platform IN (
                              'shopify', 'eventbrite', 'apple-music', 'apple-podcast',
                              'youtube', 'instagram', 'fresha', 'tiktok', 'facebook'
                          )),
    resource_id           text NOT NULL,
    payload               jsonb NOT NULL DEFAULT '{}'::jsonb,
    sort_order            integer NOT NULL DEFAULT 0,
    is_active             boolean NOT NULL DEFAULT true,
    last_visited_at       timestamptz,
    last_refreshed_at     timestamptz,
    last_refresh_status   text CHECK (last_refresh_status IN ('ok', 'unavailable', 'error')),
    last_refresh_error    text,
    consecutive_failures  integer NOT NULL DEFAULT 0,
    created_at            timestamptz,
    updated_at            timestamptz,
    deleted_at            timestamptz
);

CREATE UNIQUE INDEX IF NOT EXISTS idx_platform_connections_unique_active
    ON site.platform_connections (user_id, platform, resource_id)
    WHERE deleted_at IS NULL;

CREATE INDEX IF NOT EXISTS idx_platform_connections_user_platform_sort
    ON site.platform_connections (user_id, platform, sort_order)
    WHERE deleted_at IS NULL;

CREATE INDEX IF NOT EXISTS idx_platform_connections_last_refreshed
    ON site.platform_connections (last_refreshed_at)
    WHERE deleted_at IS NULL AND is_active;
