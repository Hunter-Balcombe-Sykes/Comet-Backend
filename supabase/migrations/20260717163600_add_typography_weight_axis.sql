-- typography_weight: overall weight register selection (light/regular/bold).
-- Allowed values enforced app-side (DesignKitValidationRules), matching the
-- 2026-07-15 aesthetic axes (typography_tracking / theme_contrast /
-- weight_heading); renderer default = regular (null emits no shift).
-- Applied to the live dev DB 2026-07-17 via MCP (same statement).
BEGIN;

SET LOCAL lock_timeout      = '2s';
SET LOCAL statement_timeout = '10s';

ALTER TABLE site.design_kits
  ADD COLUMN IF NOT EXISTS typography_weight TEXT NULL;

COMMIT;
