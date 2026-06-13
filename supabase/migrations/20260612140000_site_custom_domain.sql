-- 20260612140000_site_custom_domain.sql
--
-- Custom domains (Cloudflare for SaaS). A user may connect their own domain
-- (e.g. bookwith.me) instead of <handle>.partna.au. Columns on site.sites:
--   * custom_domain            — the connected FQDN (lowercase), unique per site.
--   * custom_domain_status     — 'pending' (cert/DNS not yet validated), 'active'
--                                (cert issued + hostname active → live), 'error'.
--   * custom_domain_verified_at— when it first went active.
--   * custom_domain_cf_id      — Cloudflare custom-hostname id (for status polling
--                                and teardown).
--
-- Routing: SyncSubdomainToKvJob (the single SUBDOMAIN_KV writer) publishes a
-- `domain:<host>` KV entry → {type:'individual', handle} only while status is
-- 'active'; the router Worker resolves it to the handle.
--
-- A drop_custom_domain.sql exists in migrations-archive (the old brand model had
-- this and it was stripped) — this is the standalone-model reintroduction.

ALTER TABLE site.sites
    ADD COLUMN IF NOT EXISTS custom_domain text,
    ADD COLUMN IF NOT EXISTS custom_domain_status text,
    ADD COLUMN IF NOT EXISTS custom_domain_verified_at timestamptz,
    ADD COLUMN IF NOT EXISTS custom_domain_cf_id text;

-- One site per domain (case-insensitive). Partial so multiple NULLs are allowed.
CREATE UNIQUE INDEX IF NOT EXISTS sites_custom_domain_unique
    ON site.sites (lower(custom_domain)) WHERE custom_domain IS NOT NULL;

ALTER TABLE site.sites
    DROP CONSTRAINT IF EXISTS sites_custom_domain_status_check;

ALTER TABLE site.sites
    ADD CONSTRAINT sites_custom_domain_status_check
    CHECK (custom_domain_status IS NULL OR custom_domain_status IN ('pending', 'active', 'error'));
