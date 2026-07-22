-- #SCHEMA-9 / #SCHEMA-10 / #SCHEMA-11: FORCE ROW LEVEL SECURITY on four core.*
-- tables created in the 20260711000100–000300 batch, a few hours before the
-- 20260711160000_analytics_force_rls_parity.sql hardening sweep landed the same
-- day — they got ENABLE ROW LEVEL SECURITY but not FORCE.
--
-- SCOPE / HONEST NOTE: the practical security delta here is near-nil today. All
-- four tables are owned by `postgres` (a superuser, which bypasses RLS regardless
-- of FORCE) and the app connects as `app_backend` (BYPASSRLS). FORCE only ever
-- bites a NON-superuser table owner, which does not exist on these tables now.
-- This is consistency hygiene / forward-looking defence-in-depth (if a table is
-- ever re-owned to a non-superuser role), matching the precedent file above — not
-- a live exposure. No application code path changes (all models run as app_backend).
--
-- Each table already carries its staff-select + service_role policies (verified
-- against pg_policies on the live dev DB), so FORCE binds an existing policy set —
-- it never turns into a deny-all. core.early_access_signups holds PII (email,
-- consent IP hash, invite token hash), so it is the highest-value of the four even
-- though today's exposure is nil.
--
-- Metadata-only catalog operations (no row scan, no sustained locks). None of these
-- are hot-traffic tables (HOT_TABLES = site.design_kits/sites/blocks, core.users),
-- so no lock/statement timeout guard is required (CONVENTIONS.md §8).

BEGIN;

ALTER TABLE core.user_segments        FORCE ROW LEVEL SECURITY;
ALTER TABLE core.user_segment_members FORCE ROW LEVEL SECURITY;
ALTER TABLE core.feature_availability  FORCE ROW LEVEL SECURITY;
ALTER TABLE core.early_access_signups  FORCE ROW LEVEL SECURITY;

COMMIT;
