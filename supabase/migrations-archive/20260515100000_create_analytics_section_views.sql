-- Phase A.0 — analytics.section_views
--
-- Per-session per-section visibility events. Fired by Partna-Hydrogen's IntersectionObserver
-- when a storefront section enters the viewport. The session+section pair is deduped at write
-- time (5 min window) so scroll-back doesn't inflate counts.
--
-- Shape mirrors analytics.link_clicks (FK to site.blocks + UTM + identity fields) plus a
-- section_key text column so sections that don't correspond 1:1 to a Block (header, footer,
-- bio) can still be tracked under a stable key.

BEGIN;

CREATE TABLE IF NOT EXISTS analytics.section_views (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    professional_id uuid NOT NULL REFERENCES core.professionals(id) ON DELETE CASCADE,
    site_id uuid NOT NULL REFERENCES site.sites(id) ON DELETE CASCADE,
    block_id uuid REFERENCES site.blocks(id) ON DELETE SET NULL,
    section_key text NOT NULL,
    occurred_at timestamptz NOT NULL DEFAULT now(),
    session_id uuid,
    visitor_id uuid,
    ip_hash text,
    user_agent text,
    referrer text,
    utm_source text,
    utm_medium text,
    utm_campaign text,
    country_code text,
    device_type text,
    created_at timestamptz NOT NULL DEFAULT now()
);

ALTER TABLE analytics.section_views OWNER TO postgres;

CREATE INDEX IF NOT EXISTS section_views_professional_occurred_idx
    ON analytics.section_views (professional_id, occurred_at DESC);

CREATE INDEX IF NOT EXISTS section_views_site_section_occurred_idx
    ON analytics.section_views (site_id, section_key, occurred_at DESC);

CREATE INDEX IF NOT EXISTS section_views_session_section_idx
    ON analytics.section_views (session_id, section_key);

-- RLS: same model as cart_events — service_role only. Read path goes through the authenticated
-- Laravel API, not direct Supabase client access.
ALTER TABLE analytics.section_views ENABLE ROW LEVEL SECURITY;

CREATE POLICY "section_views_service_role_all"
    ON analytics.section_views
    FOR ALL
    TO service_role
    USING (true)
    WITH CHECK (true);

COMMIT;
