-- Drop the per-platform CHECK on site.platform_connections.
--
-- The CHECK was application-level config masquerading as schema — six migrations
-- of appended platform strings (the last: 20260622120000_allow_events_custom_platform).
-- The PlatformRegistry is now the single source of truth for what a platform is,
-- so the registry (app-level) is the gate and the CHECK is redundant churn. Adding
-- platform #37 is now one descriptor in PlatformRegistryServiceProvider — zero
-- migration.
--
-- Why this is safe without a DB-level replacement (pre-customer blast radius):
--   * Writes use app-controlled platform constants / route defaults; the only
--     user-influenced value (RefreshController's {platform}) is gated on the
--     registry's refreshable set.
--   * GenericPlatformController::descriptor() 404s on any platform not in the registry.
--   * tests/Feature/Platforms/Registry/RegistryCoverageTest.php asserts the registry
--     key set == the platforms the app stores — the job the CHECK used to do.
--   * SQLite (CI) never enforced this CHECK, so the suite is unaffected; verify the
--     drop against the live dev Postgres (CLAUDE.md SQLite-vs-Postgres caveat).
--
-- guard:no-unsafe-migrations:disable-file
-- Exempt: DROP CONSTRAINT takes a brief ACCESS EXCLUSIVE lock on a table holding a
-- handful of pre-beta rows — harmless. No data is read or rewritten.

ALTER TABLE site.platform_connections
    DROP CONSTRAINT IF EXISTS platform_connections_platform_check;
