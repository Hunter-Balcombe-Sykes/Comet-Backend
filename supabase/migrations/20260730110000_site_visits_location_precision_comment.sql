-- PRIV-1: visitor lat/long are rounded to 2 decimal places (~1.1km) at the
-- app-side write boundary (PostgresEventWriter::visitRow(), config
-- 'partna.analytics.location_precision_decimals') before landing in these
-- columns -- enough for city/region-level analytics rollups, not enough
-- precision to locate a person. DetectsClientInfo::parseCoordinate() already
-- rounds to 4dp at ingest; this is a second, coarser cut applied at
-- persistence time so the guarantee holds regardless of ingest path.
-- Comment-only -- no data/shape change.
--
-- to revert:
--   COMMENT ON COLUMN "analytics"."site_visits"."latitude" IS NULL;
--   COMMENT ON COLUMN "analytics"."site_visits"."longitude" IS NULL;

COMMENT ON COLUMN "analytics"."site_visits"."latitude" IS
    'Visitor latitude, rounded to 2dp (~1.1km) at the app-side write boundary '
    '(PostgresEventWriter::visitRow(), PRIV-1) -- not precise enough to locate a person.';

COMMENT ON COLUMN "analytics"."site_visits"."longitude" IS
    'Visitor longitude, rounded to 2dp (~1.1km) at the app-side write boundary '
    '(PostgresEventWriter::visitRow(), PRIV-1) -- not precise enough to locate a person.';
