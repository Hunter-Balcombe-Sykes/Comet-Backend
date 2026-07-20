-- SCHEMA-5 + SCHEMA-102 — site.sites.shop_link_mode CHECK constraint.
--
-- Both findings are the same gap: shop_link_mode is the GLOBAL switch stamped
-- onto every connected store's linkMode at read time (PublicIntegrationConnectionResource)
-- — the largest blast radius of any finding in this batch — yet it was stored
-- as free text with no DB guardrail. App-side source of truth:
-- Site::SHOP_LINK_MODES = ['checkout', 'product'] (app/Models/Core/Site/Site.php).
--
-- The 20260708120000_sites_shop_global_settings.sql header cites "no CHECK,
-- matching the SQLite-test-mirror convention" as the reason to skip a
-- constraint here — but that rationale doesn't hold: Site::BOOKING_MODES, a
-- structurally identical two-value enum on this SAME table, already carries a
-- real DB CHECK (sites_booking_mode_check, 20260701190000). This migration
-- brings shop_link_mode to parity with its sibling column.
--
-- site.sites is a HOT_TABLE (guard-no-unsafe-migrations.php Check 5) — every
-- authenticated + public-payload request touches this table, so both windows
-- carry a lock_timeout so a stuck migration fails fast instead of queuing
-- behind live traffic.
--
-- Dev census (glncumufgaqcmqhzwrxm, 2026-07-20): 20/20 site.sites rows have
-- shop_link_mode = 'checkout' — VALIDATE is a clean pass, no orphan values.

-- Window 1: add the CHECK in NOT VALID form (catalog-only write, lock released
-- at COMMIT, before the validation scan starts — CONVENTIONS.md §2).
BEGIN;

SET LOCAL lock_timeout      = '2s';
SET LOCAL statement_timeout = '10s';

ALTER TABLE site.sites
    ADD CONSTRAINT sites_shop_link_mode_check
    CHECK (shop_link_mode IN ('checkout', 'product')) NOT VALID;

COMMIT;

-- Window 2: validate in its own transaction (SHARE UPDATE EXCLUSIVE — allows
-- concurrent reads/writes on site.sites throughout).
BEGIN;

SET LOCAL lock_timeout      = '2s';
SET LOCAL statement_timeout = '10s';

ALTER TABLE site.sites VALIDATE CONSTRAINT sites_shop_link_mode_check;

COMMIT;

-- ROLLBACK:
-- BEGIN;
-- ALTER TABLE site.sites DROP CONSTRAINT IF EXISTS sites_shop_link_mode_check;
-- COMMIT;
