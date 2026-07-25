-- =====================================================================
-- core.users — sector / industry (account-global, Google-sourceable)
-- =====================================================================
-- A user-facing industry classification the user picks from a curated set
-- (see App\Services\Profile\SectorTaxonomy), and which Google Business can
-- fill or overwrite via the same category the design-preset factor already
-- maps. sector_source records provenance so the account-type precedence rule
-- holds without a second lookup:
--   business accounts → Google overwrites a differing manual value
--   partna accounts   → Google only fills an empty value
--
-- Both NULLABLE and additive; core.users has no sector column today.

BEGIN;
ALTER TABLE core.users
    ADD COLUMN IF NOT EXISTS sector text,
    ADD COLUMN IF NOT EXISTS sector_source text;
COMMIT;

-- ROLLBACK:
-- ALTER TABLE core.users
--     DROP COLUMN IF EXISTS sector,
--     DROP COLUMN IF EXISTS sector_source;
