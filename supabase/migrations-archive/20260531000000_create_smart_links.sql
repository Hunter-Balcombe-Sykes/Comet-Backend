-- 20260531000000_create_smart_links.sql
--
-- Smart Links: URL-fetched rich link cards on a professional's sitepage.
-- Two families:
--   - commerce: brand / collection / product / event (rehosted images,
--     discount + referral mechanics)
--   - content:  music / podcast episode / video (hotlinked source-CDN images)
--
-- Modelled on site.services (distinct sitepage entity → own table, own
-- resolver method in the public payload, own observer for cache-bust).
-- Snapshot columns are filled by the backend SmartLinkResolver; metadata
-- holds type-specific extras (price, stock, date, location, show, channel).
--
-- Indexes are created non-concurrently in the same file as CREATE TABLE:
-- the table is empty at index time so there is no lock contention (the
-- migration guard exempts same-file CREATE TABLE + CREATE INDEX). FKs and
-- CHECKs are inline in CREATE TABLE (not ADD CONSTRAINT) — also lock-free
-- on an empty table.

BEGIN;

CREATE TABLE IF NOT EXISTS site.smart_links (
    id                    uuid PRIMARY KEY,
    user_id               uuid NOT NULL REFERENCES core.users (id) ON DELETE CASCADE,
    site_id               uuid NOT NULL REFERENCES site.sites (id) ON DELETE CASCADE,

    family                text NOT NULL CHECK (family IN ('commerce', 'content')),
    type                  text NOT NULL,
    platform              text NOT NULL,

    -- URL model (spec §4)
    canonical_url         text NOT NULL,
    tracking_query        text,
    discount_code         text,
    discount_kind         text CHECK (discount_kind IN ('percent', 'fixed')),
    discount_value        numeric,

    -- snapshot (filled by resolver; our CDN for commerce, source CDN for content)
    title                 text,
    image_url             text,
    favicon_url           text,
    brand_name            text,
    brand_logo_url        text,
    metadata              jsonb NOT NULL DEFAULT '{}'::jsonb,
    image_sources         jsonb NOT NULL DEFAULT '{}'::jsonb,

    -- ordering + per-row visibility
    sort_order            integer NOT NULL DEFAULT 0,
    is_active             boolean NOT NULL DEFAULT true,

    -- refresh bookkeeping
    last_refreshed_at     timestamptz,
    last_refresh_status   text CHECK (last_refresh_status IN ('ok', 'unavailable', 'error')),
    consecutive_failures  integer NOT NULL DEFAULT 0,

    created_at            timestamptz,
    updated_at            timestamptz,
    deleted_at            timestamptz
);

-- Dashboard + sitepage read order; cron staleness scan.
CREATE INDEX IF NOT EXISTS idx_smart_links_site_family_sort
    ON site.smart_links (site_id, family, sort_order)
    WHERE deleted_at IS NULL;

CREATE INDEX IF NOT EXISTS idx_smart_links_last_refreshed
    ON site.smart_links (last_refreshed_at)
    WHERE deleted_at IS NULL;

COMMIT;
