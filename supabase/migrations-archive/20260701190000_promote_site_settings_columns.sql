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
-- Four separate windows, not one transaction (CONVENTIONS.md §5 / audit
-- MIG-4): a single BEGIN/COMMIT around ADD COLUMN + backfill UPDATE + ADD
-- CONSTRAINT + VALIDATE CONSTRAINT holds the ACCESS EXCLUSIVE taken by the
-- first ADD COLUMN across the full-table backfill scan AND the CHECK
-- validation scan — the repo's own §5 "BAD" example. Splitting means each
-- DDL window is metadata-only/near-instant, and the backfill + validation
-- each run under a weaker lock (or none) instead of one held for the whole
-- sequence.
-- =====================================================================

-- Window 1: add the 10 nullable columns. Metadata-only, near-instant.
-- Each window below commits independently, so a failure in a later window
-- (lock/statement timeout) leaves this window's COMMIT standing -- the next
-- `db push` replays the whole file from here. IF NOT EXISTS / DROP ... IF
-- EXISTS make every window safe to repeat, not just the first attempt.
BEGIN;

SET LOCAL lock_timeout      = '2s';
SET LOCAL statement_timeout = '10s';

ALTER TABLE site.sites
  ADD COLUMN IF NOT EXISTS hero_title                  text,
  ADD COLUMN IF NOT EXISTS hero_subtitle              text,
  ADD COLUMN IF NOT EXISTS primary_button_text        text,
  ADD COLUMN IF NOT EXISTS primary_button_url         text,
  ADD COLUMN IF NOT EXISTS bio_text                   text,
  ADD COLUMN IF NOT EXISTS show_branding              boolean,
  ADD COLUMN IF NOT EXISTS charlie_enabled            boolean,
  ADD COLUMN IF NOT EXISTS services_auto_sync_enabled boolean,
  ADD COLUMN IF NOT EXISTS booking_mode              text,
  ADD COLUMN IF NOT EXISTS manual_booking_url         text;

COMMIT;

-- Window 2: backfill from the existing JSONB — a bare statement (no
-- BEGIN/COMMIT) so it runs in its own implicit transaction and inherits no
-- lock from Window 1 (CONVENTIONS.md §5). `settings->>'key'` is NULL when
-- the key is absent, so unset keys leave the column NULL. Boolean keys are
-- JSON booleans ('true'/'false' as text via ->>), cast to boolean; NULL
-- casts to NULL.
--
-- `settings` is `jsonb DEFAULT '{}'::jsonb NOT NULL` (baseline migration,
-- site.sites), so `WHERE settings IS NOT NULL` was a tautology matching
-- every row — an unconditional full-table scan, not a filter. The `?|`
-- key-presence guard below is a real filter, and it also makes this
-- backfill correctly inert if it's ever re-run after
-- 20260701200000_strip_site_settings_jsonb_keys.sql has removed these keys
-- — re-running the old unguarded UPDATE at that point would NULL out all
-- ten columns.
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
WHERE settings ?| array['hero_title','hero_subtitle','primary_button_text',
                        'primary_button_url','bio_text','show_branding',
                        'charlie_enabled','services_auto_sync_enabled',
                        'booking_mode','manual_booking_url'];

-- Window 3: add the booking_mode CHECK in NOT VALID form (brief metadata
-- lock; skips the row scan).
BEGIN;

SET LOCAL lock_timeout      = '2s';
SET LOCAL statement_timeout = '10s';

ALTER TABLE site.sites DROP CONSTRAINT IF EXISTS sites_booking_mode_check;
ALTER TABLE site.sites
  ADD CONSTRAINT sites_booking_mode_check
  CHECK (booking_mode IS NULL OR booking_mode IN ('manual','none')) NOT VALID;

COMMIT;

-- Window 4: validate in its own transaction (SHARE UPDATE EXCLUSIVE —
-- doesn't block concurrent reads/writes). Fails if any backfilled row has a
-- booking_mode NOT IN ('manual','none') and NOT NULL — investigate the data
-- before forcing (do not widen the CHECK to hide bad data).
BEGIN;

SET LOCAL lock_timeout      = '2s';
SET LOCAL statement_timeout = '10s';

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
