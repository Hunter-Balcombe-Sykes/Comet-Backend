-- guard:no-unsafe-migrations:disable-file — new empty schema (zero rows), from-zero apply
-- only. FK validation is instant on empty tables, no live-traffic risk. Same rationale as
-- the collapsed baseline (20260726000000_baseline_pilot.sql).

-- V5 Platform System — new schema for the unified platform/content-pool architecture.
-- All tables are empty (no data migration). Isolated in the `v5` schema — no foreign keys
-- to existing schemas, zero risk to running app. Kill: DROP SCHEMA v5 CASCADE.

BEGIN;
SET LOCAL lock_timeout      = '2s';
SET LOCAL statement_timeout = '10s';

-- ---------------------------------------------------------------------------
-- Schema
-- ---------------------------------------------------------------------------
CREATE SCHEMA IF NOT EXISTS v5;
COMMENT ON SCHEMA v5 IS 'V5 platform/content-pool system — isolated from core/site/analytics';

-- ---------------------------------------------------------------------------
-- 1. Platform definitions (global catalog — shared across all users)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS v5.platform_definitions (
    id              uuid DEFAULT gen_random_uuid() NOT NULL PRIMARY KEY,
    name            text NOT NULL,
    logo            text,
    url             text,
    user_type       text,          -- channel, account, etc.
    platform_colour text,
    url_format      text,          -- template, e.g. http://platform.com/<handle>/
    is_source       boolean DEFAULT false NOT NULL,
    is_url_source   boolean DEFAULT false NOT NULL,
    identifier_name_type text,     -- handle, accountID, username, businessName, custom
    scrape_method_id    uuid,      -- FK to v5.scrape_methods (added after table creation)
    created_at      timestamp with time zone DEFAULT now() NOT NULL,
    updated_at      timestamp with time zone DEFAULT now() NOT NULL,
    deleted_at      timestamp with time zone
);
ALTER TABLE v5.platform_definitions OWNER TO postgres;

-- ---------------------------------------------------------------------------
-- 2. Platform categories (category definitions with inheritable defaults)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS v5.platform_categories (
    id                  uuid DEFAULT gen_random_uuid() NOT NULL PRIMARY KEY,
    name                text NOT NULL UNIQUE,  -- social media, video, streaming, music, podcast, booking, ordering, shopping, reservations, events, shops, other
    default_refresh_interval interval,         -- default for platforms in this category
    default_source_method    text,             -- API, apify, other
    created_at          timestamp with time zone DEFAULT now() NOT NULL,
    updated_at          timestamp with time zone DEFAULT now() NOT NULL
);
ALTER TABLE v5.platform_categories OWNER TO postgres;

-- ---------------------------------------------------------------------------
-- 3. Platform ↔ Category junction (many-to-many)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS v5.platform_category (
    platform_definition_id uuid NOT NULL REFERENCES v5.platform_definitions(id) ON DELETE CASCADE,
    platform_category_id   uuid NOT NULL REFERENCES v5.platform_categories(id) ON DELETE CASCADE,
    PRIMARY KEY (platform_definition_id, platform_category_id)
);
ALTER TABLE v5.platform_category OWNER TO postgres;

-- ---------------------------------------------------------------------------
-- 4. User platforms (a user's connected platform instances)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS v5.user_platforms (
    id                  uuid DEFAULT gen_random_uuid() NOT NULL PRIMARY KEY,
    user_id             uuid NOT NULL,          -- FK to core.users (no RI — isolated)
    platform_definition_id uuid NOT NULL REFERENCES v5.platform_definitions(id) ON DELETE RESTRICT,
    identifier_value    text,                   -- the user's handle/URL/etc for this platform
    identifier_name_type text,                  -- overrides platform default if set
    is_enabled          boolean DEFAULT true NOT NULL,
    refresh_interval    interval,               -- overrides category default if set
    source_method       text,                   -- overrides category default if set
    created_at          timestamp with time zone DEFAULT now() NOT NULL,
    updated_at          timestamp with time zone DEFAULT now() NOT NULL,
    deleted_at          timestamp with time zone
);
ALTER TABLE v5.user_platforms OWNER TO postgres;

-- ---------------------------------------------------------------------------
-- 5. Platform source item config (what a platform sources into content pools)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS v5.platform_source_configs (
    id                  uuid DEFAULT gen_random_uuid() NOT NULL PRIMARY KEY,
    user_platform_id    uuid NOT NULL REFERENCES v5.user_platforms(id) ON DELETE CASCADE,
    item_type           text NOT NULL,          -- media, video, embed, song, podcast, service, menu item, product, event, review, business info, rating, link
    destination_pool    text,                   -- which content pool this feeds into (or NULL for specific place)
    format              text,                   -- file(pdf,vid,img,other), text, boolean, url
    created_at          timestamp with time zone DEFAULT now() NOT NULL,
    updated_at          timestamp with time zone DEFAULT now() NOT NULL
);
ALTER TABLE v5.platform_source_configs OWNER TO postgres;

-- ---------------------------------------------------------------------------
-- 6. Platform source rules (inheritable: base → category → platform)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS v5.platform_source_rules (
    id                      uuid DEFAULT gen_random_uuid() NOT NULL PRIMARY KEY,
    source_config_id        uuid REFERENCES v5.platform_source_configs(id) ON DELETE CASCADE,
    platform_definition_id  uuid REFERENCES v5.platform_definitions(id) ON DELETE CASCADE,
    platform_category_id    uuid REFERENCES v5.platform_categories(id) ON DELETE CASCADE,
    rule_name               text NOT NULL,      -- release_sync, full_sync, auto_sync
    default_value           text,               -- 'true', 'false', or a select value
    is_enabled              boolean DEFAULT true NOT NULL,
    is_applicable           boolean DEFAULT true NOT NULL,  -- false = N/A for this platform
    -- Exactly one scope FK must be set (enforced by app logic)
    CONSTRAINT source_rules_scope_check CHECK (
        (source_config_id IS NOT NULL AND platform_definition_id IS NULL AND platform_category_id IS NULL) OR
        (source_config_id IS NULL AND platform_definition_id IS NOT NULL AND platform_category_id IS NULL) OR
        (source_config_id IS NULL AND platform_definition_id IS NULL AND platform_category_id IS NOT NULL)
    ),
    created_at              timestamp with time zone DEFAULT now() NOT NULL,
    updated_at              timestamp with time zone DEFAULT now() NOT NULL
);
ALTER TABLE v5.platform_source_rules OWNER TO postgres;

-- ---------------------------------------------------------------------------
-- 7. Scrape / fetch methods (base templates with platform overrides)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS v5.scrape_methods (
    id              uuid DEFAULT gen_random_uuid() NOT NULL PRIMARY KEY,
    name            text NOT NULL,
    base_template   text NOT NULL,               -- API base, Apify base, Custom scraper base, Fully custom
    base_config     jsonb DEFAULT '{}' NOT NULL, -- auth pattern, pagination, rate limiting, error handling, response parsing
    platform_overrides jsonb DEFAULT '{}' NOT NULL, -- endpoint URLs, headers, auth credentials, field mappings
    created_at      timestamp with time zone DEFAULT now() NOT NULL,
    updated_at      timestamp with time zone DEFAULT now() NOT NULL
);
ALTER TABLE v5.scrape_methods OWNER TO postgres;

-- FK for platform_definitions.scrape_method_id (added here to avoid dependency ordering)
ALTER TABLE v5.platform_definitions
    ADD CONSTRAINT fk_platform_defs_scrape_method
    FOREIGN KEY (scrape_method_id) REFERENCES v5.scrape_methods(id) ON DELETE SET NULL;

-- ---------------------------------------------------------------------------
-- 8. Content pools
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS v5.content_pools (
    id              uuid DEFAULT gen_random_uuid() NOT NULL PRIMARY KEY,
    name            text NOT NULL UNIQUE,        -- watch, music, podcasts, services, menu, products, events, links, media
    allowed_types   text[] NOT NULL DEFAULT '{}', -- e.g. {'video','embed'} for watch
    created_at      timestamp with time zone DEFAULT now() NOT NULL,
    updated_at      timestamp with time zone DEFAULT now() NOT NULL
);
ALTER TABLE v5.content_pools OWNER TO postgres;

-- ---------------------------------------------------------------------------
-- 9. Items (content pool items, mergeable across platform sources)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS v5.items (
    id              uuid DEFAULT gen_random_uuid() NOT NULL PRIMARY KEY,
    user_id         uuid NOT NULL,               -- FK to core.users (no RI — isolated)
    identifier      text NOT NULL,               -- the merge key (e.g. song ISRC, product SKU)
    name            text,
    item_type       text NOT NULL,               -- video, track, podcast episode, embed, service, menu item, product, event, link, media
    is_selected     boolean DEFAULT false NOT NULL,
    created_at      timestamp with time zone DEFAULT now() NOT NULL,
    updated_at      timestamp with time zone DEFAULT now() NOT NULL,
    deleted_at      timestamp with time zone
);
ALTER TABLE v5.items OWNER TO postgres;

-- ---------------------------------------------------------------------------
-- 10. Item ↔ Pool junction
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS v5.item_pool (
    item_id         uuid NOT NULL REFERENCES v5.items(id) ON DELETE CASCADE,
    content_pool_id uuid NOT NULL REFERENCES v5.content_pools(id) ON DELETE CASCADE,
    PRIMARY KEY (item_id, content_pool_id)
);
ALTER TABLE v5.item_pool OWNER TO postgres;

-- ---------------------------------------------------------------------------
-- 11. Item ↔ Platform (which platform sources feed into this item)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS v5.item_sources (
    id              uuid DEFAULT gen_random_uuid() NOT NULL PRIMARY KEY,
    item_id         uuid NOT NULL REFERENCES v5.items(id) ON DELETE CASCADE,
    user_platform_id uuid NOT NULL REFERENCES v5.user_platforms(id) ON DELETE CASCADE,
    is_enabled      boolean DEFAULT true NOT NULL,
    created_at      timestamp with time zone DEFAULT now() NOT NULL,
    UNIQUE (item_id, user_platform_id)
);
ALTER TABLE v5.item_sources OWNER TO postgres;

-- ---------------------------------------------------------------------------
-- 12. Item values (per-source values within an item — allows multi-source merge)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS v5.item_values (
    id              uuid DEFAULT gen_random_uuid() NOT NULL PRIMARY KEY,
    item_id         uuid NOT NULL REFERENCES v5.items(id) ON DELETE CASCADE,
    item_source_id  uuid REFERENCES v5.item_sources(id) ON DELETE SET NULL,  -- NULL = manual entry
    field_name      text NOT NULL,               -- e.g. album_name, duration, price
    value           text,
    format          text,                        -- file(pdf,vid,img,other), text, boolean, url
    is_manually_set boolean DEFAULT false NOT NULL, -- blocks source override if true
    created_at      timestamp with time zone DEFAULT now() NOT NULL,
    updated_at      timestamp with time zone DEFAULT now() NOT NULL,
    UNIQUE (item_id, item_source_id, field_name)
);
ALTER TABLE v5.item_values OWNER TO postgres;

-- ---------------------------------------------------------------------------
-- 13. Item URL templates (per item type, per platform)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS v5.item_url_templates (
    id                      uuid DEFAULT gen_random_uuid() NOT NULL PRIMARY KEY,
    template                text NOT NULL,       -- e.g. <platform>.com/<handle>/<itemid>
    platform_definition_id  uuid NOT NULL REFERENCES v5.platform_definitions(id) ON DELETE CASCADE,
    item_type               text NOT NULL,       -- the item type this template is for
    is_platform_syncable    boolean DEFAULT false NOT NULL,  -- auto-connect platform + auto-select item
    platform_identifier     text,                -- the placeholder key, e.g. <handle>
    source_method           text,                -- sourcing method for this template
    created_at              timestamp with time zone DEFAULT now() NOT NULL,
    updated_at              timestamp with time zone DEFAULT now() NOT NULL
);
ALTER TABLE v5.item_url_templates OWNER TO postgres;

-- ---------------------------------------------------------------------------
-- 14. User column sources (platform → single column bindings)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS v5.user_column_sources (
    id                  uuid DEFAULT gen_random_uuid() NOT NULL PRIMARY KEY,
    user_platform_id    uuid NOT NULL REFERENCES v5.user_platforms(id) ON DELETE CASCADE,
    target_column       text NOT NULL,           -- the user column this feeds into
    sync_platform_side  boolean DEFAULT true NOT NULL,   -- platform-side gate
    sync_enabled_column_side boolean DEFAULT true NOT NULL, -- column-side gate
    created_at          timestamp with time zone DEFAULT now() NOT NULL,
    updated_at          timestamp with time zone DEFAULT now() NOT NULL,
    UNIQUE (user_platform_id, target_column)
);
ALTER TABLE v5.user_column_sources OWNER TO postgres;

-- ---------------------------------------------------------------------------
-- 15. Temp scrape platforms (one-time, never saved to user)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS v5.temp_scrapes (
    id              uuid DEFAULT gen_random_uuid() NOT NULL PRIMARY KEY,
    user_id         uuid NOT NULL,               -- FK to core.users (no RI — isolated)
    scrape_type     text NOT NULL,               -- linkinbio, previous_website
    source_url      text NOT NULL,
    scraped_urls    jsonb DEFAULT '[]' NOT NULL,  -- URLs extracted from the scrape
    processed_at    timestamp with time zone,
    created_at      timestamp with time zone DEFAULT now() NOT NULL
);
ALTER TABLE v5.temp_scrapes OWNER TO postgres;

-- ---------------------------------------------------------------------------
-- 16. Platform URL routing (for platforms with is_url_source = true)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS v5.platform_url_sources (
    id                  uuid DEFAULT gen_random_uuid() NOT NULL PRIMARY KEY,
    user_platform_id    uuid NOT NULL REFERENCES v5.user_platforms(id) ON DELETE CASCADE,
    last_routed_at      timestamp with time zone,
    created_at          timestamp with time zone DEFAULT now() NOT NULL,
    updated_at          timestamp with time zone DEFAULT now() NOT NULL
);
ALTER TABLE v5.platform_url_sources OWNER TO postgres;

-- ---------------------------------------------------------------------------
-- Seed content pools (the 9 standard pools from the conceptual outline)
-- ---------------------------------------------------------------------------
INSERT INTO v5.content_pools (name, allowed_types) VALUES
    ('watch',      '{video,embed}'),
    ('music',      '{track,embed}'),
    ('podcasts',   '{episode,embed}'),
    ('services',   '{}'),
    ('menu',       '{}'),
    ('products',   '{}'),
    ('events',     '{}'),
    ('links',      '{}'),
    ('media',      '{}'),
    ('reviews',    '{}')
ON CONFLICT (name) DO NOTHING;

-- ---------------------------------------------------------------------------
-- Seed platform categories
-- ---------------------------------------------------------------------------
INSERT INTO v5.platform_categories (name) VALUES
    ('social media'),
    ('video'),
    ('streaming'),
    ('music'),
    ('podcast'),
    ('booking'),
    ('ordering'),
    ('reservations'),
    ('events'),
    ('ecommerce'),
    ('education'),
    ('business'),
    ('other')
ON CONFLICT (name) DO NOTHING;

COMMIT;
