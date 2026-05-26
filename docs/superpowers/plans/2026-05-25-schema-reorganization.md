# Schema Reorganization Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Reorganize Postgres schemas so the same domain object doesn't live in two schemas, and audit-trail tables share a schema with append-only grants.

**Architecture:** Write ONE new additive migration (`supabase/migrations/20260527000000_reorganize_schemas.sql`) that creates the `audit` schema and runs `ALTER TABLE … SET SCHEMA …` for 8 tables. Then update Eloquent `$table` properties, raw SQL references, Pest's schema-ATTACH list, and docs. Standard `supabase db push` deploys it — no `db reset` required.

**Why additive migration, not editing the baseline:** Supabase's migration runner tracks applied versions in `supabase_migrations.schema_migrations` and refuses to re-apply files it's already seen. The hosted dev environment already has the baseline + 7 post-baseline migrations applied; editing the baseline would silently do nothing on push and require a destructive `db reset --linked`. The additive approach uses the same workflow as every other migration in this repo.

**Tech Stack:** Laravel 12, PostgreSQL via Supabase CLI, Pest 4 with SQLite in-memory tests (schemas simulated via `ATTACH DATABASE`).

**The 8 moves:**

| # | Table | From → To |
|---|-------|-----------|
| 1 | `handle_change_log` | core → audit |
| 2 | `staff_audit_log` | core → audit |
| 3 | `data_export_audit` | core → audit |
| 4 | `professional_deletion_audit` | core → audit |
| 5 | `auth_factor_events` | core → audit |
| 6 | `customers` | core → site |
| 7 | `themes` | site → core |
| 8 | `professional_handle_aliases` | site → core |

---

## Pre-flight: branch

- [ ] **Step 1: Pull latest, branch off `development`**

```bash
git fetch && git pull origin development
git checkout -b chore/schema-reorganization
```

---

## Task 1: Write the additive migration

**File to create:** `supabase/migrations/20260527000000_reorganize_schemas.sql`

The migration runs in one transaction (Supabase's default). All 8 `ALTER TABLE SET SCHEMA` and all function `CREATE OR REPLACE` happen atomically.

**Why `CREATE OR REPLACE FUNCTION` is needed alongside SET SCHEMA:** PostgreSQL views resolve table refs to OIDs at parse time and keep working after a schema move. Plpgsql functions defer name resolution until each call, so their bodies still text-reference the old schema and would fail at runtime. The 4 functions identified must be recreated with updated bodies.

- [ ] **Step 1.1: Create the migration file with the full content below**

```sql
-- ==========================================================================
-- Schema reorganization (2026-05-25)
--
-- Consolidates audit trails into a dedicated 'audit' schema with append-only
-- grants, moves CRM data (customers) to 'site' alongside enquiries, and moves
-- platform catalog/identity tables (themes, handle aliases) into 'core'.
--
-- See docs/superpowers/plans/2026-05-25-schema-reorganization.md
-- ==========================================================================

BEGIN;

-- --------------------------------------------------------------------------
-- 1. Create audit schema
-- --------------------------------------------------------------------------
-- audit is intentionally NOT granted to anon — audit data must never be
-- publicly reachable. service_role + app_backend only.
CREATE SCHEMA IF NOT EXISTS audit;
ALTER SCHEMA audit OWNER TO postgres;

GRANT USAGE ON SCHEMA audit TO service_role;

DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'app_backend') THEN
        EXECUTE 'GRANT USAGE ON SCHEMA audit TO app_backend';
    END IF;
END;
$$;

-- --------------------------------------------------------------------------
-- 2. Move tables
-- --------------------------------------------------------------------------
-- SET SCHEMA preserves table data, indexes, constraints, triggers, RLS
-- policies, and existing role grants. Cross-table FKs (from other tables)
-- continue to work because PG tracks them by OID, not name.

-- Audit lane (5 tables core -> audit)
ALTER TABLE core.handle_change_log            SET SCHEMA audit;
ALTER TABLE core.staff_audit_log              SET SCHEMA audit;
ALTER TABLE core.data_export_audit            SET SCHEMA audit;
ALTER TABLE core.professional_deletion_audit  SET SCHEMA audit;
ALTER TABLE core.auth_factor_events           SET SCHEMA audit;

-- CRM (customers belongs with site.enquiries)
ALTER TABLE core.customers SET SCHEMA site;

-- Platform catalog (themes has no professional_id or site_id)
ALTER TABLE site.themes SET SCHEMA core;

-- User-level identity (handle is on core.users, not on site)
ALTER TABLE site.professional_handle_aliases SET SCHEMA core;

-- --------------------------------------------------------------------------
-- 3. Refresh plpgsql functions that text-reference moved tables
-- --------------------------------------------------------------------------
-- Plpgsql resolves table names at call time, so these bodies still point at
-- the OLD schema even after SET SCHEMA. CREATE OR REPLACE updates them.

-- Used by site.sites BEFORE INSERT trigger: pick the default theme.
CREATE OR REPLACE FUNCTION core.set_default_theme_for_site()
RETURNS trigger
LANGUAGE plpgsql
SET search_path TO 'pg_catalog'
AS $$
begin
  if new.theme_id is null then
    select id
    into new.theme_id
    from core.themes
    order by is_default desc, created_at
    limit 1;

    if new.theme_id is null then
      raise exception 'Cannot create site: no themes exist in core.themes';
    end if;
  end if;

  return new;
end;
$$;

-- AFTER UPDATE on core.users handle: write old handle into the alias table.
CREATE OR REPLACE FUNCTION core.trg_professional_handle_change()
RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    v_reclaim_days int := 14;
    v_redirect_days int := 90;
BEGIN
    INSERT INTO core.professional_handle_aliases
        (professional_id, handle, reclaim_until, expires_at)
    VALUES
        (NEW.id,
         OLD.handle,
         now() + (v_reclaim_days || ' days')::interval,
         now() + (v_redirect_days || ' days')::interval)
    ON CONFLICT DO NOTHING;

    RETURN NEW;
END;
$$;

-- BEFORE UPDATE on core.users handle: reject renames into a still-active alias.
CREATE OR REPLACE FUNCTION core.trg_professional_handle_alias_check()
RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    v_blocking_pro uuid;
BEGIN
    IF NEW.handle IS NOT DISTINCT FROM OLD.handle THEN
        RETURN NEW;
    END IF;

    SELECT professional_id INTO v_blocking_pro
      FROM core.professional_handle_aliases
     WHERE LOWER(handle) = LOWER(NEW.handle)
       AND professional_id <> NEW.id
       AND (expires_at IS NULL OR expires_at > now())
     LIMIT 1;

    IF v_blocking_pro IS NOT NULL THEN
        RAISE EXCEPTION 'Handle % is reserved as a redirect for another professional', NEW.handle
            USING ERRCODE = '23505';
    END IF;

    RETURN NEW;
END;
$$;

-- Cosmetic: error messages reference the new schema names.
CREATE OR REPLACE FUNCTION core.trg_handle_change_log_append_only()
RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    RAISE EXCEPTION 'audit.handle_change_log is append-only' USING ERRCODE = '42501';
END;
$$;

CREATE OR REPLACE FUNCTION core.reject_staff_audit_log_mutation()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    RAISE EXCEPTION 'audit.staff_audit_log is append-only (OPS-2). UPDATE and DELETE are not permitted.';
END;
$$;

-- --------------------------------------------------------------------------
-- 4. Enforce append-only on audit at the GRANT level
-- --------------------------------------------------------------------------
-- The baseline grants core (SELECT,INSERT,UPDATE,DELETE) to app_backend on
-- ALL TABLES. SET SCHEMA preserves those grants, so audit tables still have
-- full CRUD. Revoke UPDATE/DELETE to enforce append-only at the role level
-- (defense in depth alongside the existing reject-mutation triggers).
DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'app_backend') THEN
        EXECUTE 'REVOKE UPDATE, DELETE ON ALL TABLES IN SCHEMA audit FROM app_backend';
        -- Future audit tables: default to SELECT + INSERT only.
        EXECUTE 'ALTER DEFAULT PRIVILEGES IN SCHEMA audit GRANT SELECT, INSERT ON TABLES TO app_backend';
    END IF;
END;
$$;

-- service_role: also restricted to append-only on audit.
REVOKE UPDATE, DELETE ON ALL TABLES IN SCHEMA audit FROM service_role;
ALTER DEFAULT PRIVILEGES IN SCHEMA audit GRANT SELECT, INSERT ON TABLES TO service_role;

COMMIT;
```

- [ ] **Step 1.2: Commit the migration**

```bash
git add supabase/migrations/20260527000000_reorganize_schemas.sql
git commit -m "feat(schema): add audit schema and reorganize 8 tables"
```

---

## Task 2: Update Eloquent model `$table` properties

7 model files. (No model for `auth_factor_events` — it uses `AuthFactorEventRepository::TABLE` constant; handled in Task 3.)

- [ ] **Step 2.1: Update `HandleChangeLog`**

In `app/Models/Core/HandleChangeLog.php:22`, change:

```php
protected $table = 'core.handle_change_log';
```

to:

```php
protected $table = 'audit.handle_change_log';
```

- [ ] **Step 2.2: Update `StaffAuditEntry`**

In `app/Models/Core/Staff/StaffAuditEntry.php:19`, change `'core.staff_audit_log'` to `'audit.staff_audit_log'`.

- [ ] **Step 2.3: Update `DataExportAudit`**

In `app/Models/Core/Gdpr/DataExportAudit.php:33`, change `'core.data_export_audit'` to `'audit.data_export_audit'`.

- [ ] **Step 2.4: Update `ProfessionalDeletionAuditEntry`**

In `app/Models/Core/Professional/ProfessionalDeletionAuditEntry.php:17`, change `'core.professional_deletion_audit'` to `'audit.professional_deletion_audit'`.

- [ ] **Step 2.5: Update `Customer`**

In `app/Models/Core/Professional/Customer.php:15`, change `'core.customers'` to `'site.customers'`.

- [ ] **Step 2.6: Update `Theme`**

In `app/Models/Core/Site/Theme.php:15`, change `'site.themes'` to `'core.themes'`.

- [ ] **Step 2.7: Update `ProfessionalHandleAlias`**

In `app/Models/Core/Site/ProfessionalHandleAlias.php:19`, change `'site.professional_handle_aliases'` to `'core.professional_handle_aliases'`.

- [ ] **Step 2.8: Verify and commit**

```bash
grep -rnE "protected \\\$table = '(core\.customers|core\.handle_change_log|core\.staff_audit_log|core\.data_export_audit|core\.professional_deletion_audit|core\.auth_factor_events|site\.themes|site\.professional_handle_aliases)'" app/Models/
```

Expected: no output.

```bash
git add app/Models/
git commit -m "chore(models): update \$table to new schemas"
```

(Note: model PHP namespaces are deliberately NOT renamed. `App\Models\Core\Professional\Customer` keeps its path even though the table moved to `site` — that's a code-organization concern, separate from this DB cleanup.)

---

## Task 3: Update raw SQL in `app/` (services, jobs, commands, repositories)

These are exact lines from the inventory. Use `sed -i ''` (macOS — note the empty backup-suffix arg).

- [ ] **Step 3.1: `DataExportPayloadBuilder` (5 distinct lines)**

```bash
F="app/Services/Professional/DataExport/DataExportPayloadBuilder.php"
sed -i '' \
  -e "s/'core\.professional_deletion_audit'/'audit.professional_deletion_audit'/g" \
  -e "s/'core\.customers'/'site.customers'/g" \
  -e "s/'core\.data_export_audit'/'audit.data_export_audit'/g" \
  -e "s/'core\.handle_change_log'/'audit.handle_change_log'/g" \
  -e "s/'site\.professional_handle_aliases'/'core.professional_handle_aliases'/g" \
  "$F"
```

- [ ] **Step 3.2: `AccountDeletionService`**

```bash
sed -i '' "s/'core\.professional_deletion_audit'/'audit.professional_deletion_audit'/g" \
  "app/Services/Professional/AccountDeletionService.php"
```

- [ ] **Step 3.3: `ProfessionalCacheService`**

```bash
sed -i '' "s/'core\.customers'/'site.customers'/g" \
  "app/Services/Cache/ProfessionalCacheService.php"
```

- [ ] **Step 3.4: `AuthFactorEventRepository`**

```bash
sed -i '' "s/'core\.auth_factor_events'/'audit.auth_factor_events'/g" \
  "app/Services/Auth/AuthFactorEventRepository.php"
```

- [ ] **Step 3.5: Handle-alias console commands and the KV sync job**

```bash
sed -i '' "s/'site\.professional_handle_aliases'/'core.professional_handle_aliases'/g" \
  "app/Console/Commands/NotifyHandleAliasExpiry.php" \
  "app/Console/Commands/PruneExpiredHandleAliases.php" \
  "app/Jobs/Cloudflare/SyncSubdomainToKvJob.php"
```

- [ ] **Step 3.6: Sweep — fail if anything stale remains in `app/`**

```bash
grep -rnE "'(core\.customers|core\.handle_change_log|core\.staff_audit_log|core\.data_export_audit|core\.professional_deletion_audit|core\.auth_factor_events|site\.themes|site\.professional_handle_aliases)'" \
  app/
```

Expected: no output. If anything remains, investigate before continuing — the inventory may have missed a file.

- [ ] **Step 3.7: Commit**

```bash
git add app/
git commit -m "chore(app): update raw SQL refs to new schemas"
```

---

## Task 4: Update Pest schema-ATTACH list and raw SQL in `tests/`

**Critical:** Pest tests run against SQLite in-memory with `ATTACH DATABASE ':memory:' AS <schema>` to simulate Postgres schemas. The current list at `tests/Pest.php:192` is hardcoded as `['core', 'site', 'commerce', 'notifications', 'analytics', 'billing', 'retail', 'brand']` — it must include `audit` or every `CREATE TABLE audit.foo` in test helpers will fail with "unknown database audit".

- [ ] **Step 4.1: Add `audit` to the Pest ATTACH list**

In `tests/Pest.php:192`, change:

```php
    foreach (['core', 'site', 'commerce', 'notifications', 'analytics', 'billing', 'retail', 'brand'] as $schema) {
```

to:

```php
    foreach (['core', 'site', 'audit', 'commerce', 'notifications', 'analytics', 'billing', 'retail', 'brand'] as $schema) {
```

(Keep the dead `commerce/billing/retail/brand` entries in place — cleaning them up is out of scope and would balloon the diff.)

- [ ] **Step 4.2: Update quoted table-name strings across tests/**

```bash
find tests -type f -name '*.php' -print0 | xargs -0 sed -i '' \
  -e "s/'core\.handle_change_log'/'audit.handle_change_log'/g" \
  -e "s/'core\.staff_audit_log'/'audit.staff_audit_log'/g" \
  -e "s/'core\.data_export_audit'/'audit.data_export_audit'/g" \
  -e "s/'core\.professional_deletion_audit'/'audit.professional_deletion_audit'/g" \
  -e "s/'core\.auth_factor_events'/'audit.auth_factor_events'/g" \
  -e "s/'core\.customers'/'site.customers'/g" \
  -e "s/'site\.themes'/'core.themes'/g" \
  -e "s/'site\.professional_handle_aliases'/'core.professional_handle_aliases'/g"
```

- [ ] **Step 4.3: Update unquoted table names in test CREATE TABLE helpers**

Test helpers in `Pest.php` use heredocs like `CREATE TABLE IF NOT EXISTS core.customers (...)`. The unquoted pass catches those:

```bash
find tests -type f -name '*.php' -print0 | xargs -0 sed -i '' \
  -e 's/core\.handle_change_log/audit.handle_change_log/g' \
  -e 's/core\.staff_audit_log/audit.staff_audit_log/g' \
  -e 's/core\.data_export_audit/audit.data_export_audit/g' \
  -e 's/core\.professional_deletion_audit/audit.professional_deletion_audit/g' \
  -e 's/core\.auth_factor_events/audit.auth_factor_events/g' \
  -e 's/core\.customers/site.customers/g' \
  -e 's/site\.themes/core.themes/g' \
  -e 's/site\.professional_handle_aliases/core.professional_handle_aliases/g'
```

- [ ] **Step 4.4: Sweep — verify zero stale references in tests/**

```bash
grep -rnE "(core\.customers|core\.handle_change_log|core\.staff_audit_log|core\.data_export_audit|core\.professional_deletion_audit|core\.auth_factor_events|site\.themes|site\.professional_handle_aliases)" \
  tests/
```

Expected: no output.

- [ ] **Step 4.5: Commit**

```bash
git add tests/
git commit -m "chore(tests): add audit schema to ATTACH list, update SQL refs"
```

---

## Task 5: Run the test suite and fix any fallout

- [ ] **Step 5.1: Run Pest**

```bash
composer test
```

Expected: all tests pass.

Likely failure modes if anything fails:
- **"no such table: audit.X"** — `attachTestSchemas()` wasn't called before the CREATE TABLE, OR Step 4.1 wasn't saved. Verify the ATTACH list.
- **"no such table: core.customers"** in a test — Step 4.2/4.3 missed a string. Re-run the sweep grep at 4.4.
- **A model factory** referencing the old schema — grep for `'(core|site)\.` in `database/factories/` (the inventory didn't surface any but worth checking).

- [ ] **Step 5.2: Run Pint**

```bash
php artisan pint
```

Expected: no changes. Sed shouldn't have moved braces.

- [ ] **Step 5.3: Commit any fallout fixes**

```bash
git add -A
git commit -m "fix: address test fallout from schema move"
```

---

## Task 6: Apply migration to local Supabase and verify

- [ ] **Step 6.1: Verify the local stack is running**

```bash
supabase status
```

If not running, start it:

```bash
supabase start
```

- [ ] **Step 6.2: Apply the new migration**

The local DB already has the baseline + 7 post-baseline migrations applied. Supabase will detect `20260527000000_reorganize_schemas.sql` as new and apply only that one.

```bash
supabase migration up
```

Expected: "Applying 20260527000000_reorganize_schemas.sql … Success."

If the local migration history is out of sync, `supabase db reset` rebuilds from scratch — safe locally.

- [ ] **Step 6.3: Verify the migration moved tables correctly**

```bash
supabase db remote psql -- -c "
SELECT table_schema, table_name
FROM information_schema.tables
WHERE table_schema IN ('core', 'site', 'audit')
  AND table_name IN ('customers', 'themes', 'professional_handle_aliases',
                     'handle_change_log', 'staff_audit_log', 'data_export_audit',
                     'professional_deletion_audit', 'auth_factor_events')
ORDER BY table_schema, table_name;
"
```

Expected output (8 rows):

| table_schema | table_name |
|---|---|
| audit | auth_factor_events |
| audit | data_export_audit |
| audit | handle_change_log |
| audit | professional_deletion_audit |
| audit | staff_audit_log |
| core | professional_handle_aliases |
| core | themes |
| site | customers |

If any row shows the OLD schema, the ALTER TABLE didn't take effect.

- [ ] **Step 6.4: Verify grants are append-only on audit**

```bash
supabase db remote psql -- -c "
SELECT table_schema, table_name, privilege_type
FROM information_schema.role_table_grants
WHERE grantee = 'app_backend'
  AND table_schema = 'audit'
ORDER BY table_name, privilege_type;
"
```

Expected: each of the 5 audit tables has exactly `SELECT` and `INSERT` privileges — no `UPDATE` or `DELETE`.

- [ ] **Step 6.5: Smoke-test via Tinker**

```bash
php artisan tinker
```

In the REPL:

```php
\App\Models\Core\Professional\Customer::query()->count();
// Expected: integer (0 or more), no exception.

\App\Models\Core\Site\Theme::query()->count();
// Expected: positive integer (seeded themes), no exception.

\App\Models\Core\Site\ProfessionalHandleAlias::query()->count();
// Expected: integer, no exception.

\App\Models\Core\Gdpr\DataExportAudit::query()->count();
// Expected: integer, no exception.
```

Any `SQLSTATE[42P01] relation "core.customers" does not exist` means a `$table` wasn't updated — go back to Task 2.

---

## Task 7: Update documentation

- [ ] **Step 7.1: Update `CLAUDE.md`**

In the "Architecture Rules" / "Database — Supabase Only" section, the line currently reads:

> PostgreSQL schemas: `public` (Laravel infrastructure), `core` (users, sites, services, customers, blocks, media, themes, staff), `site` (site-level tables), `notifications`, `analytics`. No `brand`, `commerce`, or `billing` schemas.

Update to:

> PostgreSQL schemas: `public` (Laravel infrastructure), `core` (users, themes, staff, feature flags, handle aliases, platform config), `site` (sites, blocks, services, media, customers, enquiries, subdomain aliases), `notifications`, `analytics`, `audit` (append-only compliance trails — `app_backend` has SELECT/INSERT only). No `brand`, `commerce`, or `billing` schemas.

- [ ] **Step 7.2: Update `AI_CONTEXT.md`**

```bash
grep -nE "core\.customers|site\.themes|core\.themes|site\.customers|core\.handle_change_log" AI_CONTEXT.md
```

Update each line found to the new schema. Per the inventory, lines 115 and 123 had relevant content.

- [ ] **Step 7.3: Update `docs/handle-redirects.md`**

```bash
grep -nE "core\.handle_change_log|site\.professional_handle_aliases" docs/handle-redirects.md
```

Lines 84 and 109 per inventory — update to `audit.handle_change_log` and `core.professional_handle_aliases`.

- [ ] **Step 7.4: Update the stale comment in `20260526210001_create_feedback_table.sql`**

The previous migration has a comment at line 108 referencing `core.customers`. Update for accuracy (it's just documentation in the migration file):

```bash
sed -i '' 's|mirrors core\.customers|mirrors site.customers|g' \
  "supabase/migrations/20260526210001_create_feedback_table.sql"
```

(Do NOT touch the SQL in that file — just the comment.)

- [ ] **Step 7.5: Commit doc updates**

```bash
git add CLAUDE.md AI_CONTEXT.md docs/handle-redirects.md \
  supabase/migrations/20260526210001_create_feedback_table.sql
git commit -m "docs: reflect new schema layout"
```

(Note: archived migration files at `supabase/migrations-archive/` and superseded plan docs at `docs/superpowers/plans/` are historical — do NOT update them.)

---

## Task 8: Push to hosted dev Supabase

Per CLAUDE.md push semantics. The user runs the interactive `link` step via `!`.

- [ ] **Step 8.1: User links to dev (interactive — runs in user's shell)**

```
! supabase link --project-ref glncumufgaqcmqhzwrxm
```

- [ ] **Step 8.2: Dry-run the push**

```bash
supabase db push --dry-run
```

Expected output: shows one migration to apply (`20260527000000_reorganize_schemas.sql`).

If the dry-run shows zero migrations or unexpected ones, stop and investigate before applying.

- [ ] **Step 8.3: Apply to hosted dev**

```bash
supabase db push
```

Expected: "Applied 1 migration."

- [ ] **Step 8.4: Verify on hosted dev**

Run the same verification query from Step 6.3 against the hosted dev DB (via Supabase SQL editor in the dashboard, or `psql` with the connection string). Confirm all 8 tables landed in their new schemas.

- [ ] **Step 8.5: Check dev app logs**

```bash
cloud env:logs partna development --tail 100
```

Expected: no `relation "core.customers" does not exist` (or any other moved-table) errors. If they appear, an app reference was missed — back to Task 3.

- [ ] **Step 8.6: Manually exercise a few flows in the dev app**

Pre-beta, no automated browser tests for this. Hit:
- The site bootstrap endpoint (uses themes) — `GET https://dev-api.partna.au/.../bootstrap`
- A customer-list endpoint (uses customers) — `GET https://dev-api.partna.au/.../customers`
- A handle change (exercises the handle alias triggers)

Watch `cloud env:logs partna development --live` while doing it.

---

## Task 9: Open PR

- [ ] **Step 9.1: Push branch**

```bash
git push -u origin chore/schema-reorganization
```

- [ ] **Step 9.2: Open PR to development**

```bash
gh pr create --base development --title "chore(schema): reorganize Postgres schemas" --body "$(cat <<'EOF'
## Summary
- New `audit` schema with append-only grants (`app_backend` has SELECT/INSERT only)
- 5 audit-trail tables moved from `core` to `audit` (handle_change_log, staff_audit_log, data_export_audit, professional_deletion_audit, auth_factor_events)
- `customers` moved from `core` to `site` (sits with related `enquiries`)
- `themes` moved from `site` to `core` (platform-wide catalog)
- `professional_handle_aliases` moved from `site` to `core` (user-level identity)
- One additive migration; 4 plpgsql trigger functions recreated to reference the new schema paths
- Pest's SQLite schema-ATTACH list extended with `audit`

## Test plan
- [x] `composer test` passes locally
- [x] `supabase migration up` applies cleanly on local
- [x] Verification queries (Steps 6.3 + 6.4) show all 8 tables in new schemas with correct grants
- [x] Tinker smoke tests pass for moved models
- [ ] Hosted dev push applied and verified (Steps 8.3–8.6)
- [ ] No relation-not-found errors in dev logs for 15 minutes after push

## Rollback
If something breaks post-merge on dev, write a single inverse migration (8 `ALTER TABLE SET SCHEMA` back, drop `audit` schema if empty, restore the 4 functions to baseline bodies). Pre-beta, low blast radius.

🤖 Generated with [Claude Code](https://claude.com/claude-code)
EOF
)"
```

---

## Out of scope (deliberate)

- **Production push.** This plan stops at hosted dev. Promoting to prod (`edplucmvkcnokyygxqsb`) is a separate authorization — the same migration applies, but it needs Josh's explicit sign-off and a brief confirmation that dev has been stable.
- **Editing the baseline migration.** Left untouched. Anyone reading the baseline alone won't see current state — they need to read the post-baseline chain. That's the normal Laravel/Rails pattern. A future "rebase baseline" exercise can fold this in.
- **Model PHP namespace moves** (e.g., `App\Models\Core\Professional\Customer` → `App\Models\Site\Customer`). The schemas are clean now; PHP namespaces are a separate code-organization question.
- **Cleaning up the dead `commerce`/`billing`/`retail`/`brand` entries in `Pest.php`'s ATTACH list.** Unrelated to this work.
- **Data migration.** Pre-beta, no rows to migrate.

## Risks and mitigations

| Risk | Mitigation |
|------|------------|
| A plpgsql function not in the inventory references a moved table | The migration is wrapped in `BEGIN; … COMMIT;` — if any DDL fails, the whole thing rolls back. Manual sweep of the baseline grep for `(core|site)\.<moved-table>` (already done in this plan's preflight) found exactly 4 functions. |
| A sed pass misses a string in a heredoc or odd quote style | Sweep grep in Steps 3.6 and 4.4. If they pass, no stale strings remain. |
| The hosted dev migration history is somehow ahead of the local | `supabase db push --dry-run` (Step 8.2) reveals this before the destructive push step. |
| App talks to dev DB before migration applied | Brief 5-second window during push. Pre-beta with no real traffic, acceptable. Cache layer is bypassed for these tables. |
| The `data_export_audit_email_sent_at` migration (timestamp 20260526200003) tries to `ALTER TABLE core.data_export_audit` on a fresh DB AFTER our new migration runs | Not possible — our migration has timestamp `20260527000000`, which sorts AFTER `20260526200003`. On a fresh DB, the column is added to `core.data_export_audit` first, then the table is moved to `audit`. The column travels with the table. |
