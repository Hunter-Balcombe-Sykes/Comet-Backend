-- =====================================================================
-- FOUND-16 Phase 1 — promote 10 site.sites.settings sub-keys to columns
-- =====================================================================
-- Adds typed, nullable columns for the 10 named settings sub-keys, backfills
-- them from the existing JSONB, and constrains booking_mode. The JSONB keys are
-- NOT stripped here (dual-write): the two public-read views keep passing the
-- full settings blob through, so this migration cannot cause silent NULL loss.
-- The strip + view re-inject ship atomically in the Phase 2 migration.
--
-- Pre-beta: zero rows, so the backfill is a no-op now; it is written correct
-- for prod-shape parity.
--
-- All statements are transaction-safe (ADD COLUMN, UPDATE, ADD CONSTRAINT
-- NOT VALID, VALIDATE CONSTRAINT), so the whole sequence is wrapped so a
-- mid-migration failure rolls back to the pre-migration schema.
-- =====================================================================
BEGIN;

ALTER TABLE site.sites
  ADD COLUMN hero_title                  text,
  ADD COLUMN hero_subtitle              text,
  ADD COLUMN primary_button_text        text,
  ADD COLUMN primary_button_url         text,
  ADD COLUMN bio_text                   text,
  ADD COLUMN show_branding              boolean,
  ADD COLUMN charlie_enabled            boolean,
  ADD COLUMN services_auto_sync_enabled boolean,
  ADD COLUMN booking_mode              text,
  ADD COLUMN manual_booking_url         text;

-- Backfill from the existing JSONB. `settings->>'key'` is NULL when the key is
-- absent, so unset keys leave the column NULL. Boolean keys are JSON booleans
-- ('true'/'false' as text via ->>), cast to boolean; NULL casts to NULL.
UPDATE site.sites SET
  hero_title                 = settings->>'hero_title',
  hero_subtitle              = settings->>'hero_subtitle',
  primary_button_text        = settings->>'primary_button_text',
  primary_button_url         = settings->>'primary_button_url',
  bio_text                   = settings->>'bio_text',
  show_branding              = (settings->>'show_branding')::boolean,
  charlie_enabled            = (settings->>'charlie_enabled')::boolean,
  services_auto_sync_enabled = (settings->>'services_auto_sync_enabled')::boolean,
  booking_mode               = settings->>'booking_mode',
  manual_booking_url         = settings->>'manual_booking_url'
WHERE settings IS NOT NULL;

-- booking_mode enum guard. NOT VALID → VALIDATE keeps the ADD lock brief and
-- validates existing rows separately. VALIDATE fails if any backfilled row has
-- a booking_mode NOT IN ('manual','none') and NOT NULL — investigate the data
-- before forcing (do not widen the CHECK to hide bad data).
ALTER TABLE site.sites
  ADD CONSTRAINT sites_booking_mode_check
  CHECK (booking_mode IS NULL OR booking_mode IN ('manual','none')) NOT VALID;

ALTER TABLE site.sites VALIDATE CONSTRAINT sites_booking_mode_check;

COMMIT;

-- ROLLBACK:
-- BEGIN;
-- ALTER TABLE site.sites DROP CONSTRAINT IF EXISTS sites_booking_mode_check;
-- ALTER TABLE site.sites
--   DROP COLUMN IF EXISTS hero_title,
--   DROP COLUMN IF EXISTS hero_subtitle,
--   DROP COLUMN IF EXISTS primary_button_text,
--   DROP COLUMN IF EXISTS primary_button_url,
--   DROP COLUMN IF EXISTS bio_text,
--   DROP COLUMN IF EXISTS show_branding,
--   DROP COLUMN IF EXISTS charlie_enabled,
--   DROP COLUMN IF EXISTS services_auto_sync_enabled,
--   DROP COLUMN IF EXISTS booking_mode,
--   DROP COLUMN IF EXISTS manual_booking_url;
-- COMMIT;
-- (settings JSONB is untouched by Phase 1, so no re-injection is needed on rollback.)
