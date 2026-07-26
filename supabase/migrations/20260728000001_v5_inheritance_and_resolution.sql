-- guard:no-unsafe-migrations:disable-file — new empty tables, from-zero apply only.
-- FK validation is instant on empty tables, no live-traffic risk.

-- V5 Schema additions: inheritance support + content pool features
-- Companion to 20260727000000_create_v5_schema.sql

BEGIN;
SET LOCAL lock_timeout      = '2s';
SET LOCAL statement_timeout = '10s';

-- ---------------------------------------------------------------------------
-- Add inheritance columns to platform_categories
-- Gap 30-31: is_source and is_url_source should be inheritable
-- ---------------------------------------------------------------------------
ALTER TABLE v5.platform_categories
    ADD COLUMN IF NOT EXISTS default_is_source boolean DEFAULT false NOT NULL,
    ADD COLUMN IF NOT EXISTS default_is_url_source boolean DEFAULT false NOT NULL,
    ADD COLUMN IF NOT EXISTS icon text;

-- ---------------------------------------------------------------------------
-- Add sort_order to items for reordering within pools
-- Gap 22: Links pool needs reordering
-- ---------------------------------------------------------------------------
ALTER TABLE v5.items
    ADD COLUMN IF NOT EXISTS sort_order integer DEFAULT 0 NOT NULL;

-- ---------------------------------------------------------------------------
-- Add profile/display columns to platform_definitions
-- ---------------------------------------------------------------------------
ALTER TABLE v5.platform_definitions
    ADD COLUMN IF NOT EXISTS profile_pic_url text,
    ADD COLUMN IF NOT EXISTS follower_count bigint,
    ADD COLUMN IF NOT EXISTS follower_label text DEFAULT 'followers',
    ADD COLUMN IF NOT EXISTS display_name text,
    ADD COLUMN IF NOT EXISTS bio text;

-- ---------------------------------------------------------------------------
-- Set category-level defaults for source categories
-- ---------------------------------------------------------------------------
UPDATE v5.platform_categories SET default_is_source = true WHERE name IN (
    'video', 'streaming', 'music', 'podcast', 'booking', 'ordering', 'events', 'ecommerce'
);

UPDATE v5.platform_categories SET default_is_url_source = true WHERE name IN (
    'social media', 'video', 'streaming', 'music', 'podcast'
);

-- ---------------------------------------------------------------------------
-- Add conflict resolution audit log
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS v5.resolution_audit (
    id                  uuid DEFAULT gen_random_uuid() NOT NULL PRIMARY KEY,
    item_id             uuid NOT NULL REFERENCES v5.items(id) ON DELETE CASCADE,
    field_name          text NOT NULL,
    winning_value       text,
    winning_source_id   uuid REFERENCES v5.item_sources(id) ON DELETE SET NULL,
    rule_applied        text NOT NULL,
    losers_json         jsonb DEFAULT '[]' NOT NULL,
    created_at          timestamp with time zone DEFAULT now() NOT NULL
);
ALTER TABLE v5.resolution_audit OWNER TO postgres;

-- ---------------------------------------------------------------------------
-- User-configurable per-field conflict resolution overrides
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS v5.conflict_resolution_configs (
    id              uuid DEFAULT gen_random_uuid() NOT NULL PRIMARY KEY,
    user_id         uuid NOT NULL,
    item_type       text,
    field_name      text NOT NULL,
    rule            text NOT NULL,
    created_at      timestamp with time zone DEFAULT now() NOT NULL,
    updated_at      timestamp with time zone DEFAULT now() NOT NULL,
    UNIQUE (user_id, item_type, field_name)
);
ALTER TABLE v5.conflict_resolution_configs OWNER TO postgres;

-- ---------------------------------------------------------------------------
-- Add resolution columns to existing tables
-- ---------------------------------------------------------------------------
ALTER TABLE v5.item_values
    ADD COLUMN IF NOT EXISTS is_resolved boolean DEFAULT false NOT NULL;

ALTER TABLE v5.items
    ADD COLUMN IF NOT EXISTS resolved_values jsonb DEFAULT '{}' NOT NULL;

COMMIT;
