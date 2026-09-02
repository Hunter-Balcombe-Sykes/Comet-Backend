-- Corners: the 'rounded' rung (1rem) is retired (owner, 2026-09-02, batch 2
-- D.2) — square ('sharp') or curved ('default'), nothing softer. Rows that
-- picked it read as curved from now on. Data only; the column's check is
-- application-side (DesignKitValidationRules).
BEGIN;
UPDATE site.design_kits SET corners = 'default' WHERE corners = 'rounded';
COMMIT;
