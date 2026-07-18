# Schema / RLS / search_path Audit — 2026-07-08

**Branch:** audit-fix/middleware-2026-07-06
**Lens:** Schema / RLS / search_path — database-side correctness, constraint coverage, migration safety (RLS coverage, `search_path` pinning, constraint/index hygiene, trigger correctness, migration operational safety, soft-delete/retention, UUID/PK consistency, function definitions, JSONB design, append-only discipline)
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4.5`
**Source files audited:**
- supabase/migrations/20260526000000_baseline_standalone_user.sql
- supabase/migrations/20260527010000_reorganize_schemas.sql
- supabase/migrations/20260527030000_rename_professional_to_user.sql
- supabase/migrations/20260527070000_skeleton_system_cleanup.sql
- supabase/migrations/20260528000000_create_moderation_schema.sql
- supabase/migrations/20260602000000_design_kits_rls.sql
- supabase/migrations/20260606030000_moderation_schema_rls.sql
- supabase/migrations/20260606040000_pin_function_search_paths.sql
- supabase/migrations/20260607000000_restrict_app_backend_append_only_grants.sql
- supabase/migrations/20260609000000_harden_platform_connections.sql
- supabase/migrations/20260617130000_create_menus.sql
- supabase/migrations/20260619050000_menu_relational_redesign.sql
- supabase/migrations/20260624010000_schema_hardening_constraints.sql
- supabase/migrations/20260701130000_design_kit_contributions.sql
- supabase/migrations/20260701140000_menu_platform_links.sql
- supabase/migrations/20260701140100_menu_item_platforms_table.sql
- supabase/migrations/20260701150000_create_workplaces.sql
- supabase/migrations/20260701150100_create_user_credentials_experience.sql
- supabase/migrations/20260702000000_rls_parity_platform_connections_menus.sql
- supabase/migrations/20260704160000_shop_brands_products.sql
- supabase/migrations/20260705120000_drop_dead_profile_features.sql
- supabase/migrations/20260705150200_create_content_selection.sql
- tests/Feature/Security/PlatformAndMenuRlsTest.php
- tests/Feature/Security/DesignKitsRlsTest.php
- app/Models/Core/Site/Menu.php

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 5 complete
- P3 Low: 0 of 1 complete

---

## P2 — Should fix

- [ ] **#SCHEMA-1** · P2 — ~33 baseline tenant-data tables have `ENABLE ROW LEVEL SECURITY` but no `FORCE ROW LEVEL SECURITY`
    - **Where:** supabase/migrations/20260526000000_baseline_standalone_user.sql:1667, 1690, 1699, 1709, 1720, 1736, 1757, 1775, 1794, 1812, 1821, 1835, 1859, 1884, 1907, 1935, 1949, 1981, 2003, 2020, 2040, 2060, 2071, 2082, 2102, 2116, 2130, 2141, 2154, 2170, 2190, 2206
    - **Affects:** Every tenant/PII table created in the standalone baseline (`core.users`, `core.customers`, `site.sites`, `site.blocks`, `site.site_media`, `site.enquiries`, `notifications.email_subscriptions`, `analytics.site_visits`, etc.) — a session connected as the table owner (`postgres`, e.g. via Supabase Studio's SQL editor or a leaked owner credential) can read/write every row of every one of these tables without any policy being consulted.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add `ALTER TABLE <schema>.<table> FORCE ROW LEVEL SECURITY;` for every baseline table that has `ENABLE` but not `FORCE` (verified: `core.users`, `core.customers`, `core.waitlist_signups`, `core.user_confirmation_preferences`, `core.feature_flags`, `core.feature_flag_overrides`, `audit.user_deletion_audit`, `audit.data_export_audit`, `audit.staff_audit_log`, `audit.auth_factor_events`, `audit.handle_change_log`, `core.user_handle_aliases`, `site.sites`, `site.blocks`, `site.site_media`, `site.media_variants`, `site.site_subdomain_aliases`, `site.services`, `site.service_categories`, `site.enquiries`, all six `notifications.*` tables, all six `analytics.*` tables). `core.partna_staff` already has FORCE (baseline line 398) and is NOT part of this gap; `site.themes` was later `DROP TABLE ... CASCADE`'d (`20260527070000_skeleton_system_cleanup.sql`) and no longer exists — exclude both from the fix list.
        - Add a regression test mirroring `tests/Feature/Security/PlatformAndMenuRlsTest.php` / `DesignKitsRlsTest.php` that sweeps all baseline tenant tables and asserts `relforcerowsecurity` is true.
    - **Technical:** PostgreSQL's `ENABLE ROW LEVEL SECURITY` does not apply to the table owner or a session with the `BYPASSRLS` attribute unless `FORCE ROW LEVEL SECURITY` is also set. The house pattern established for every table hardened since — `site.design_kits` (20260602000000), `moderation.*` (20260606030000), `site.platform_connections` + all five `site.menu_*` tables (20260702000000, confirmed by `PlatformAndMenuRlsTest.php`), `site.shop_brands`/`site.shop_products` (20260704160000), `core.supabase_email_events` (20260625000000) — is `ENABLE` + `FORCE` together. The baseline's ~33 tables only ever received `ENABLE`. `app_backend` carries `BYPASSRLS` so the application code path is unaffected; the exposure is specifically a direct-DB-access path (Studio SQL editor, a leaked `postgres` credential, a future PostgREST schema exposure) bypassing every RLS policy including the anon/authenticated policies already defined on `site.sites`, `site.blocks`, etc.
    - **Plain English:** Every apartment in the original building has a keypad lock installed, but the building manager's master key still opens every door without entering a code. The newer apartments (design kits, moderation, menus, shops) already require even the master key to punch the code. The ~33 original apartments still let the master key waltz right in — a real problem only if someone gets hold of that master key, but a gap worth closing before pilot since it's a one-line fix repeated 33 times.
    - **Evidence:**
        ```sql
        ALTER TABLE core.users ENABLE ROW LEVEL SECURITY;
        ALTER TABLE site.sites ENABLE ROW LEVEL SECURITY;
        ALTER TABLE analytics.site_visits ENABLE ROW LEVEL SECURITY;
        ALTER TABLE notifications.notifications ENABLE ROW LEVEL SECURITY;
        -- (no companion FORCE ROW LEVEL SECURITY anywhere in the file for these)

        -- Contrast — core.partna_staff DOES have it (line 398, same file):
        ALTER TABLE core.partna_staff FORCE ROW LEVEL SECURITY;
        ```

- [ ] **#SCHEMA-2** · P2 — `site.workplaces` has no RLS at all (1:1 tenant table, pattern breach vs. `site.design_kits`)
    - **Where:** supabase/migrations/20260701150000_create_workplaces.sql:13-31
    - **Affects:** Every site's workplace card data (name, address, phone, `previous_website_analysis`, etc.) — no `ENABLE`, no `FORCE`, no policies exist for this table at all.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `ALTER TABLE site.workplaces ENABLE ROW LEVEL SECURITY; ALTER TABLE site.workplaces FORCE ROW LEVEL SECURITY;` plus an `app_backend` FOR ALL policy, mirroring `site.design_kits` (20260602000000) — a table with the identical 1:1-with-`site.sites` shape.
        - Add a regression test entry to `tests/Feature/Security/PlatformAndMenuRlsTest.php` (or a sibling file) covering `site.workplaces`.
    - **Technical:** `site.workplaces` is a 1:1 tenant table (PK = `site_id`, FK to `site.sites`) created 2026-07-01 with zero RLS statements in the migration. Its direct analogue, `site.design_kits`, received full `ENABLE` + `FORCE` + per-role policies in `20260602000000_design_kits_rls.sql` a month earlier. Notably, `20260702000000_rls_parity_platform_connections_menus.sql` closed this exact class of gap for `site.platform_connections` and all five `site.menu_*` tables the very next day after `workplaces` shipped, explicitly citing "a forward-looking exposure: if a future migration adds a blanket site-schema grant or exposes these tables via PostgREST" as the rationale — the same rationale applies unchanged to `workplaces`, which that migration did not touch. `app_backend` has `BYPASSRLS` so the app path is unaffected today.
    - **Plain English:** This is a second 1:1 annex off the main house (workplaces, just like design_kits) but nobody installed a door lock on it. A sibling annex (menus) had its lock installed the very next day, but workplaces was skipped. Nothing bad happens today because the master key isn't used, but the annex should get the same lock as every other room before more foot traffic runs through the building.
    - **Evidence:**
        ```sql
        -- 20260701150000_create_workplaces.sql — no RLS anywhere in the file:
        CREATE TABLE IF NOT EXISTS site.workplaces (
            site_id          uuid PRIMARY KEY REFERENCES site.sites (id) ON DELETE CASCADE,
            name             text,
            address          text,
            ...
            created_at       timestamptz,
            updated_at       timestamptz
        );
        -- (No ALTER TABLE ... ENABLE ROW LEVEL SECURITY follows.)
        ```

- [ ] **#SCHEMA-3** · P2 — `site.content_selection` has no RLS at all (self-propagated from SCHEMA-2, created AFTER the menu RLS-parity fix)
    - **Where:** supabase/migrations/20260705150200_create_content_selection.sql:13-40
    - **Affects:** Every site's ordered content-selection picks (up to 15 entries referencing `site.site_media` rows or external refs) — same exposure profile as `site.workplaces`.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `ALTER TABLE site.content_selection ENABLE ROW LEVEL SECURITY; ALTER TABLE site.content_selection FORCE ROW LEVEL SECURITY;` plus an `app_backend` FOR ALL policy, same pattern as `site.design_kits` / (post-fix) `site.workplaces`.
        - Update the migration comment — it currently cites `site.workplaces` as precedent for skipping RLS; that precedent should be corrected, not extended.
    - **Technical:** Created 2026-07-05 with the explicit comment "RLS is OFF to match the sibling 1:1 app-managed table site.workplaces." This migration landed *three days after* `20260702000000_rls_parity_platform_connections_menus.sql` closed the identical gap for `site.platform_connections` and all five menu tables — meaning the established, current-as-of-that-week convention was "every `site.*` table gets `ENABLE` + `FORCE`," yet `content_selection` cited the one remaining (unfixed) exception as its justification, propagating the drift forward instead of catching it. `GRANT SELECT, INSERT, UPDATE, DELETE ON site.content_selection TO app_backend` is present, but no role-scoped RLS policy exists.
    - **Plain English:** The builder saw one annex still had no lock and used that as the excuse to skip a lock on a brand-new annex too — three days after the crew had just gone around fixing that exact gap on every other room in the building. The note ("we check IDs at the front desk instead") describes how every other 1:1 room already works, lock included. The fix is the same lock every other room gets.
    - **Evidence:**
        ```sql
        -- 20260705150200_create_content_selection.sql:
        -- position is 1..15, unique per site. RLS is OFF to match the sibling 1:1
        -- app-managed table site.workplaces — access is gated in the Laravel policy
        -- layer (ContentSelectionPolicy), not at the DB. app_backend gets the standard
        -- CRUD grant.
        CREATE TABLE IF NOT EXISTS site.content_selection (
            id           uuid PRIMARY KEY DEFAULT gen_random_uuid(),
            site_id      uuid NOT NULL REFERENCES site.sites (id) ON DELETE CASCADE,
            ...
        );
        GRANT SELECT, INSERT, UPDATE, DELETE ON site.content_selection TO app_backend;
        -- No ALTER TABLE ... ENABLE ROW LEVEL SECURITY anywhere in the file.
        ```

- [ ] **#SCHEMA-4** · P2 — Five menu-subsystem tables lack DB-side `gen_random_uuid()` defaults on their UUID primary keys
    - **Where:** supabase/migrations/20260617130000_create_menus.sql:17, supabase/migrations/20260619050000_menu_relational_redesign.sql:45,57, supabase/migrations/20260701140000_menu_platform_links.sql:17, supabase/migrations/20260701140100_menu_item_platforms_table.sql:14
    - **Affects:** `site.menus`, `site.menu_categories`, `site.menu_items`, `site.menu_platform_links`, `site.menu_item_platforms` — a raw SQL insert, admin fix query, or future reconcile job that omits `id` fails with "null value in column id violates not-null constraint."
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - `ALTER TABLE site.menus ALTER COLUMN id SET DEFAULT gen_random_uuid();` and the same for `menu_categories`, `menu_items`, `menu_platform_links`, `menu_item_platforms`.
        - Mirror the exact pattern already applied to `site.platform_connections` in `20260609000000_harden_platform_connections.sql` (`CONS-29`: "raw SQL / admin inserts that bypass Eloquent").
    - **Technical:** All five menu tables declare `id uuid PRIMARY KEY` with no `DEFAULT`. Every model (`Menu`, `MenuCategory`, `MenuItem`, `MenuPlatformLink`, `MenuItemPlatform`, confirmed via `Read`) uses Eloquent's `HasUuids` trait, so the normal Laravel write path is unaffected — the gap only bites a raw SQL path that doesn't currently exist. The fix is catalog-only (`SET DEFAULT`), touches no row data, and directly follows the precedent set for `site.platform_connections` (`ALTER COLUMN id SET DEFAULT gen_random_uuid()`) one week earlier in the same subsystem's hardening pass.
    - **Plain English:** Every other new table in the system pre-fills its ID column automatically, like a form that fills in the reference number for you. These five menu tables are missing that pre-fill. The website code fills it in today, so nothing breaks in normal use — but a future manual database fix or cleanup job that forgets to generate the ID would get rejected. Adding the pre-fill now costs nothing and prevents that future headache.
    - **Evidence:**
        ```sql
        -- 20260617130000_create_menus.sql:
        CREATE TABLE IF NOT EXISTS site.menus (
            id              uuid PRIMARY KEY,
            user_id         uuid NOT NULL REFERENCES core.users (id) ON DELETE CASCADE,
            ...
        );

        -- 20260619050000_menu_relational_redesign.sql:
        CREATE TABLE IF NOT EXISTS site.menu_categories (
            id              uuid PRIMARY KEY,
            ...
        );
        CREATE TABLE IF NOT EXISTS site.menu_items (
            id              uuid PRIMARY KEY,
            ...
        );

        -- Contrast — the house pattern applied to a sibling table one week earlier
        -- (20260609000000_harden_platform_connections.sql):
        ALTER TABLE site.platform_connections
            ALTER COLUMN id         SET DEFAULT gen_random_uuid(),
            ALTER COLUMN created_at SET DEFAULT now(),
            ALTER COLUMN updated_at SET DEFAULT now();
        ```

- [ ] **#SCHEMA-5** · P2 — Three append-only audit tables have privilege revocation but no `BEFORE UPDATE/DELETE` rejection trigger (belt without suspenders)
    - **Where:** supabase/migrations/20260526000000_baseline_standalone_user.sql:633-658, 2326-2328; supabase/migrations/20260527010000_reorganize_schemas.sql:41,43 (schema-wide grant restriction); supabase/migrations/20260528000000_create_moderation_schema.sql:159-176
    - **Affects:** `audit.auth_factor_events`, `audit.user_deletion_audit`, `audit.moderation_events` — a compromised `app_backend` credential with re-granted privileges, or a superuser session, could UPDATE/DELETE rows in these append-only audit logs with nothing at the DB layer to stop it.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Create a shared rejection trigger function (reusing the pattern of `core.reject_staff_audit_log_mutation`) and bind it `BEFORE UPDATE OR DELETE` on all three tables.
        - Mirror the belt-and-suspenders pattern already applied to `audit.handle_change_log` and `audit.staff_audit_log` (both have trigger AND privilege revocation, per the baseline).
    - **Technical:** The baseline established a two-layer append-only defence on `core.staff_audit_log` and `core.handle_change_log`: (a) a `BEFORE UPDATE OR DELETE` trigger raising an exception, and (b) `REVOKE UPDATE, DELETE ... FROM app_backend`. `core.auth_factor_events` (now `audit.auth_factor_events` after the `20260527010000_reorganize_schemas.sql` schema move) received only the privilege revocation, no trigger. `audit.user_deletion_audit` (renamed from `core.professional_deletion_audit` per `20260527030000_rename_professional_to_user.sql`) and `audit.moderation_events` (created in `20260528000000_create_moderation_schema.sql`, after the reorg) rely solely on the schema-wide `REVOKE UPDATE, DELETE ON ALL TABLES IN SCHEMA audit FROM app_backend` + `ALTER DEFAULT PRIVILEGES IN SCHEMA audit GRANT SELECT, INSERT ON TABLES` (confirmed in `20260527010000_reorganize_schemas.sql:156,158`), which does correctly cover them at the privilege layer — but there is no trigger fallback if that privilege grant is ever accidentally widened (as happened incrementally for other tables in `20260607000000_restrict_app_backend_append_only_grants.sql`, which had to re-tighten grants after they'd drifted).
    - **Plain English:** Two of the audit log cabinets have both a lock on the drawer AND a steel bar preventing the drawer from being pulled open even if the lock is picked. Three newer cabinets have only the lock. If someone ever loosens the lock by accident, those three cabinets become writable with nothing else stopping it. Adding the steel bar is a five-minute job that prevents a future accident from becoming a lost-audit-trail incident.
    - **Evidence:**
        ```sql
        -- Baseline: staff_audit_log gets BOTH layers (house exemplar):
        CREATE TRIGGER staff_audit_log_reject_mutation
            BEFORE UPDATE OR DELETE ON core.staff_audit_log
            FOR EACH ROW EXECUTE FUNCTION core.reject_staff_audit_log_mutation();
        REVOKE UPDATE, DELETE ON core.staff_audit_log FROM app_backend;
        GRANT SELECT, INSERT ON core.staff_audit_log TO app_backend;

        -- Baseline: auth_factor_events gets ONLY layer 2 (no trigger anywhere):
        REVOKE UPDATE, DELETE ON core.auth_factor_events FROM app_backend;
        GRANT SELECT, INSERT ON core.auth_factor_events TO app_backend;
        ```

## P3 — Nice to have

- [ ] **#SCHEMA-6** · P3 — Seven post-baseline tables with `created_at`/`updated_at` columns lack `BEFORE UPDATE` triggers to auto-refresh `updated_at`
    - **Where:** supabase/migrations/20260701150000_create_workplaces.sql:29-30, supabase/migrations/20260705150200_create_content_selection.sql:28-29, supabase/migrations/20260617130000_create_menus.sql:28-29, supabase/migrations/20260619050000_menu_relational_redesign.sql:50-51,80-81, supabase/migrations/20260701140000_menu_platform_links.sql:23-24, supabase/migrations/20260701140100_menu_item_platforms_table.sql:21-22
    - **Affects:** `site.workplaces`, `site.content_selection`, `site.menus`, `site.menu_categories`, `site.menu_items`, `site.menu_platform_links`, `site.menu_item_platforms` — a raw SQL update or future reconcile job that bypasses Eloquent leaves `updated_at` stale, confusing monitoring/cache-invalidation logic that trusts the column.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - For each of the seven tables, add `CREATE TRIGGER set_timestamp_<table> BEFORE UPDATE ON <schema>.<table> FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();`
        - Mirror the pattern applied to `site.design_kits` (`20260603000003_design_kit_timestamps.sql`) and `site.platform_connections` (`20260624010000_schema_hardening_constraints.sql`, DINT-10).
    - **Technical:** Nearly every table in the codebase carries a `BEFORE UPDATE ... EXECUTE FUNCTION public.set_updated_at()` trigger as defence-in-depth so any write path (Eloquent, raw SQL, queue job, admin query) keeps `updated_at` truthful. All seven tables here have `created_at`/`updated_at` columns and Eloquent models that manage timestamps in PHP (confirmed for `Menu`), so the current write path is unaffected — this is the same gap that was closed for `site.design_kits` (because "writeDesignKit() uses raw DB::table() queries") and `site.platform_connections` (DINT-10) after the fact. No new function is needed — `public.set_updated_at()` already exists and is pinned per `20260606040000_pin_function_search_paths.sql`.
    - **Plain English:** Every table in the system has a small automatic stamp that records "last updated at," no matter who or what changed the row. Seven newer tables rely on the application code to remember that stamp — which it does today. If a future manual fix or background job ever writes to these tables directly, the stamp would freeze and quietly mislead anyone reading it later. This is a cheap, one-line-per-table insurance policy, not an active problem today.
    - **Evidence:**
        ```sql
        -- House exemplar (20260603000003_design_kit_timestamps.sql pattern):
        CREATE TRIGGER set_timestamp_design_kits
            BEFORE UPDATE ON site.design_kits
            FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();

        -- 20260701150000_create_workplaces.sql — has the columns, no trigger:
        CREATE TABLE IF NOT EXISTS site.workplaces (
            ...
            created_at       timestamptz,
            updated_at       timestamptz
        );
        -- (No set_timestamp_workplaces trigger anywhere in the file or any follow-up.)
        ```

## Suggested Bundled Sessions

None — every finding in this audit is a `supabase/migrations/` schema change, which the fix-flow's blocker gate requires to run standalone with individual plan + sign-off.

## Standalone — do NOT bundle

- **#SCHEMA-1 — Baseline tables missing FORCE ROW LEVEL SECURITY** · DB migration/schema change (ALTER TABLE across ~33 tables).
- **#SCHEMA-2 — `site.workplaces` missing RLS entirely** · DB migration/schema change (new RLS policies).
- **#SCHEMA-3 — `site.content_selection` missing RLS entirely** · DB migration/schema change (new RLS policies); companion to #SCHEMA-2, run back-to-back but with separate sign-off.
- **#SCHEMA-4 — Menu tables missing `gen_random_uuid()` defaults** · DB migration/schema change (ALTER COLUMN across 5 tables).
- **#SCHEMA-5 — Audit tables missing append-only rejection trigger** · DB migration/schema change touching the `audit` schema's tamper-resistance guarantees.
- **#SCHEMA-6 — Missing `updated_at` triggers on 7 tables** · DB migration/schema change (CREATE TRIGGER across 7 tables).
