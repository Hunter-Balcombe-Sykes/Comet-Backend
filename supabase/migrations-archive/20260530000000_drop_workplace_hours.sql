-- 20260530000000_drop_workplace_hours.sql
--
-- Workplace: drop the `hours` subkey from every site's
-- settings.google_business_profile JSONB blob.
--
-- The hours field was speculative (Google Places import shipped
-- time-only strings without day prefixes, so consumers had to
-- synthesise day labels by index). It's being removed from the
-- engine type + public API + skeleton-1 template. Existing rows
-- get cleaned here so the JSONB shape matches the new contract.
--
-- Idempotent — the `?` operator skips rows that don't have the
-- google_business_profile key at all.

BEGIN;

UPDATE site.sites
SET settings = jsonb_set(
    settings,
    '{google_business_profile}',
    (settings -> 'google_business_profile') - 'hours'
)
WHERE settings ? 'google_business_profile'
  AND (settings -> 'google_business_profile') ? 'hours';

COMMIT;
