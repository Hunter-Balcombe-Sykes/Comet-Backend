-- Drops the flat `address` display-address column from site.workplaces.
-- Decision (docs/superpowers/plans/2026-07-23-signup-testing-repairs.md item 1):
-- the structured fields (address_line1/city/state/postcode/country) are the
-- only source of truth going forward; a single-line display string is
-- computed on the fly by consumers, never stored/edited independently.
-- Existing drifted data (address populated, structured fields blank on some
-- rows) is accepted as-is — no backfill, per owner direction (test data only).
BEGIN;
SET LOCAL lock_timeout = '2s';
SET LOCAL statement_timeout = '10s';

ALTER TABLE site.workplaces DROP COLUMN IF EXISTS address;

COMMIT;
