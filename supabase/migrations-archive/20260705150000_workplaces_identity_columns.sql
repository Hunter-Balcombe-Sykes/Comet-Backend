-- =====================================================================
-- Extend site.workplaces — centralized business/workplace identity
-- =====================================================================
-- The workplace card gains the three identity fields it was missing so the
-- "Brand Info" / "Workplace Info" dashboard page and the Google Business
-- precedence sync can own them centrally instead of leaving them inside the
-- integration payload:
--   opening_hours  jsonb  — structured per-day hours. Shape:
--                           {"mon":[{"open":"0900","close":"1700"}], ... ,
--                            "exceptions":[]}. Derived from Google
--                           regularOpeningHours.periods on sync, or set manually.
--   contact_email  text   — public contact email. Manual-only: Google Places
--                           never returns an email, so sync never writes it.
--   field_sources  jsonb  — per-field provenance, e.g.
--                           {"name":{"source":"google-business","at":"<iso>"}}.
--                           Drives the dashboard "Synced from Google" badge and
--                           leaves room for future per-platform precedence rules.
--
-- All additive + NULLABLE (field_sources defaults to an empty object). The
-- table is pre-beta / near-empty, and every ADD COLUMN here is metadata-only
-- (no constant-default rewrite), so this is lock-light per migration conventions.

BEGIN;
ALTER TABLE site.workplaces
    ADD COLUMN IF NOT EXISTS opening_hours jsonb,
    ADD COLUMN IF NOT EXISTS contact_email text,
    ADD COLUMN IF NOT EXISTS field_sources jsonb NOT NULL DEFAULT '{}'::jsonb;
COMMIT;

-- ROLLBACK:
-- ALTER TABLE site.workplaces
--     DROP COLUMN IF EXISTS opening_hours,
--     DROP COLUMN IF EXISTS contact_email,
--     DROP COLUMN IF EXISTS field_sources;
