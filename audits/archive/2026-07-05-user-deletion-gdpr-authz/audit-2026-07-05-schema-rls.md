# Schema / RLS / search_path Audit — 2026-07-05

**Branch:** development
**Lens:** Schema / RLS / search_path — database-side correctness, constraint coverage, migration safety (RLS coverage, FORCE, multi-schema `search_path` correctness, constraint/index hygiene, trigger correctness, migration safety, UUID/PK consistency)
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4.5`
**Source files audited:**
- `supabase/migrations/` (all 129 files, full baseline through `20260705120000_drop_dead_profile_features.sql`)
- `app/Models/` (spot-checked against schema for UUID/timestamp conventions)
- `app/Services/Analytics/`, `app/Jobs/Analytics/RecordAnalyticsEventJob.php`, `app/Http/Controllers/Api/PublicSite/AnalyticsController.php` (cross-check for analytics dedup claim)
- `docs/migration-guidelines.md`
- `tests/Feature/Security/PlatformAndMenuRlsTest.php`, `DesignKitsRlsTest.php`, `ModerationSchemaRlsTest.php`

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 2 complete
- P3 Low: 0 of 4 complete

---

## P2 — Should fix

- [ ] **#SCHEMA-1** · P2 — `site.workplaces` has no RLS at all, unlike every sibling table created around it
    - **Where:** `supabase/migrations/20260701150000_create_workplaces.sql`
    - **Affects:** `site.workplaces` (per-site physical address, phone, lat/long — one row per site, 1:1 with `site.sites`). No `ALTER TABLE ... ENABLE ROW LEVEL SECURITY` appears anywhere for this table across the migration history.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `ALTER TABLE site.workplaces ENABLE ROW LEVEL SECURITY;` + `ALTER TABLE site.workplaces FORCE ROW LEVEL SECURITY;` + an `app_backend FOR ALL` policy, following the exact pattern used for `site.shop_brands`/`site.shop_products` (created three days later in `20260704160000_shop_brands_products.sql`, which correctly includes both statements at creation time) and the sweep migration `20260702000000_rls_parity_platform_connections_menus.sql`.
        - Extend `tests/Feature/Security/PlatformAndMenuRlsTest.php`'s `rls_tables` dataset with a `workplaces` entry — that test is already the house pattern for exactly this regression class (introspects `pg_class.relrowsecurity`/`relforcerowsecurity` + `pg_policies`).
    - **Technical:** Every other `site.*` tenant table created after 2026-06-02 (`site.design_kits`, `site.design_kit_contributions`, `site.shop_brands`, `site.shop_products`, the five menu tables) enables and forces RLS at creation. `site.workplaces` was created on 2026-07-01 — squarely inside that window — and a follow-up column migration (`20260701220001_workplace_previous_website_analysis.sql`) even notes "table grants/RLS unchanged," confirming the gap wasn't an oversight caught later. `app_backend` (the sole app-facing role) has `BYPASSRLS`, and neither `anon` nor `authenticated` currently holds table-level grants on `site.*` (only `USAGE` on the schema — confirmed by grepping the baseline's grant section), so this isn't exploitable via PostgREST today. It is, however, the one `site.*` table since the design-kits hardening pass that doesn't match the established defence-in-depth convention.
    - **Plain English:** Every table holding one person's private data got a lock installed on it — except this one, which stores someone's business address and phone number. Nobody can walk through that door today because the building's front desk (the database's permission system) doesn't hand out a key to anyone but the app itself. But if that ever changes — a new integration, a database browsing tool, anything that talks to the database directly — this is the one room with no lock at all.
    - **Evidence:**
        ```sql
        CREATE TABLE IF NOT EXISTS site.workplaces (
            site_id          uuid PRIMARY KEY REFERENCES site.sites (id) ON DELETE CASCADE,
            name             text,
            address          text,
            address_line1    text,
            city             text,
            state            text,
            postcode         text,
            country          text,
            latitude         double precision,
            longitude        double precision,
            phone            text,
            website          text,
            previous_website text,
            category         text,
            description      text,
            created_at       timestamptz,
            updated_at       timestamptz
        );
        ```
    - `no ALTER TABLE ... ENABLE ROW LEVEL SECURITY statement exists for this table anywhere in supabase/migrations/`

- [ ] **#SCHEMA-2** · P2 — ~19 baseline tenant tables plus `analytics.site_sessions` have RLS enabled but lack FORCE ROW LEVEL SECURITY
    - **Where:** `supabase/migrations/20260526000000_baseline_standalone_user.sql` (§10, RLS section); `supabase/migrations/20260610000000_analytics_v2_clicks_sessions.sql`
    - **Affects:** `core.users`, `site.customers`, `core.waitlist_signups`, `core.user_confirmation_preferences`, `audit.user_deletion_audit`, `audit.data_export_audit`, `audit.staff_audit_log`, `audit.auth_factor_events`, `audit.handle_change_log`, `core.feature_flags`, `core.feature_flag_overrides`, `site.sites`, `site.blocks`, `site.site_media`, `site.media_variants`, `site.site_subdomain_aliases`, `core.user_handle_aliases`, `site.services`, `site.service_categories`, `site.enquiries`, all six `notifications.*` tables, five of six `analytics.*` tables from the baseline, plus `analytics.site_sessions` (added later). (`site.themes` — listed in the original draft — was dropped entirely by `20260527070000_skeleton_system_cleanup.sql` and no longer exists; removed from this list.)
    - **Effort:** L (~1–2d)
    - **What to do:**
        - Apply `ALTER TABLE <table> FORCE ROW LEVEL SECURITY;` to every table above, in one dedicated migration (metadata-only, no row scan — safe as a single transaction, same reasoning documented in `20260702000000_rls_parity_platform_connections_menus.sql`).
        - Extend `tests/Feature/Security/PlatformAndMenuRlsTest.php`'s dataset (or add a sibling test) to cover the full tenant-table list so future tables can't silently reintroduce the gap — the existing dataset only guards `platform_connections` + the 5 menu tables today.
    - **Technical:** Without `FORCE ROW LEVEL SECURITY`, the table owner role is exempt from RLS policy evaluation entirely (a core Postgres behavior, independent of whether the owner is a superuser). Every table hardened since `20260602000000_design_kits_rls.sql` (design kits, moderation schema, feedback, platform_connections, the menu subsystem, shop_brands/shop_products, supabase_email_events) includes FORCE; the ~19 original baseline tables and `analytics.site_sessions` (added on 2026-06-10, after the FORCE convention was established) never received the same treatment. As with SCHEMA-1, `app_backend` carries `BYPASSRLS` and `anon`/`authenticated` lack table-level grants on these schemas today, so this is defence-in-depth against a future PostgREST/Supabase-client exposure, not a live bypass.
    - **Plain English:** Every one of these tables has a lock installed (a rule saying "you can only see your own data"), but there's a master key that skips the lock entirely — and it turns out most of the original tables from launch day never got the newer safety catch that makes even the master key respect the lock. Newer tables added since June all have the safety catch; these ~20 older ones don't. It doesn't matter today because nothing currently uses that master key from outside the app, but it's the kind of gap that becomes a real problem the moment something changes.
    - **Evidence:**
        ```sql
        -- Baseline §10 — representative sample, ENABLE with no FORCE:
        ALTER TABLE core.users ENABLE ROW LEVEL SECURITY;
        ALTER TABLE site.sites ENABLE ROW LEVEL SECURITY;
        ALTER TABLE site.enquiries ENABLE ROW LEVEL SECURITY;
        ALTER TABLE notifications.notifications ENABLE ROW LEVEL SECURITY;
        ALTER TABLE analytics.site_visits ENABLE ROW LEVEL SECURITY;

        -- 20260610000000_analytics_v2_clicks_sessions.sql — same gap, added after the FORCE convention existed:
        ALTER TABLE analytics.site_sessions ENABLE ROW LEVEL SECURITY;

        -- Contrast — core.partna_staff (baseline) is the one table that DOES get FORCE, at creation:
        ALTER TABLE core.partna_staff OWNER TO postgres;
        ALTER TABLE core.partna_staff FORCE ROW LEVEL SECURITY;
        ```

## P3 — Nice to have

- [ ] **#SCHEMA-3** · P3 — Five menu-subsystem tables lack a `gen_random_uuid()` default on their UUID primary keys
    - **Where:** `supabase/migrations/20260617130000_create_menus.sql` (`site.menus`); `supabase/migrations/20260619050000_menu_relational_redesign.sql` (`site.menu_categories`, `site.menu_items`); `supabase/migrations/20260701140000_menu_platform_links.sql` (`site.menu_platform_links`); `supabase/migrations/20260701140100_menu_item_platforms_table.sql` (`site.menu_item_platforms`)
    - **Affects:** Any non-Eloquent write path (admin cleanup, reconciliation job, Supabase Studio direct edit) — the app writes exclusively through Eloquent's `HasUuids` trait today, so the production write path is unaffected.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `ALTER TABLE <table> ALTER COLUMN id SET DEFAULT gen_random_uuid();` on all five tables, mirroring the identical fix already applied to `site.platform_connections` (`20260609000000_harden_platform_connections.sql`) and `site.site_subdomain_aliases` (`20260624010000_schema_hardening_constraints.sql`).
    - **Technical:** All five tables declare `id uuid PRIMARY KEY` with no `DEFAULT`. Tellingly, these tables' own backfill/rollback SQL (in the same migration files, and in `20260701140000`/`20260701140100`) has to manually call `gen_random_uuid()` in every `INSERT` precisely because there's no DB-side default — direct proof of the gap. The fix is a one-line, metadata-only `ALTER COLUMN ... SET DEFAULT`, identical in shape to two fixes already shipped for sibling tables.
    - **Plain English:** Every other table's ID gets auto-generated by the database if you forget to supply one. These five tables don't have that safety net — a script or one-off cleanup query that inserts a row without an ID will fail. It's a one-line fix that's already been applied to two similar tables; these five were just missed.
    - **Evidence:**
        ```sql
        CREATE TABLE IF NOT EXISTS site.menus (
            id              uuid PRIMARY KEY,
            user_id         uuid NOT NULL REFERENCES core.users (id) ON DELETE CASCADE,
            ...
        );

        CREATE TABLE IF NOT EXISTS site.menu_categories (
            id              uuid PRIMARY KEY,
            menu_id         uuid NOT NULL REFERENCES site.menus (id) ON DELETE CASCADE,
            ...
        );

        CREATE TABLE IF NOT EXISTS site.menu_items (
            id              uuid PRIMARY KEY,
            menu_id         uuid NOT NULL REFERENCES site.menus (id) ON DELETE CASCADE,
            ...
        );

        CREATE TABLE IF NOT EXISTS site.menu_platform_links (
            id         uuid PRIMARY KEY,
            menu_id    uuid NOT NULL REFERENCES site.menus (id) ON DELETE CASCADE,
            ...
        );

        CREATE TABLE IF NOT EXISTS site.menu_item_platforms (
            id             uuid PRIMARY KEY,
            menu_item_id   uuid NOT NULL REFERENCES site.menu_items (id) ON DELETE CASCADE,
            ...
        );
        ```

- [ ] **#SCHEMA-4** · P3 — `site.workplaces` and `site.shop_brands`/`site.shop_products` lack `updated_at` triggers for non-Eloquent writes
    - **Where:** `supabase/migrations/20260701150000_create_workplaces.sql`; `supabase/migrations/20260704160000_shop_brands_products.sql`
    - **Affects:** Timestamp accuracy on raw-SQL writes (admin cleanup, reconciliation jobs, direct Supabase Studio edits). The app writes through Eloquent, which stamps `updated_at` in PHP, so the current production path is unaffected.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `CREATE OR REPLACE TRIGGER set_timestamp_<table> BEFORE UPDATE ON <table> FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();` on `site.workplaces`, `site.shop_brands`, and `site.shop_products`, mirroring the pattern already applied to `site.platform_connections` in `20260624010000_schema_hardening_constraints.sql`.
    - **Technical:** The shared `public.set_updated_at()` trigger function is the established repo pattern for keeping `updated_at` current on non-Eloquent writes — `20260624010000` added it to `platform_connections` specifically because "raw updates (sync jobs, admin SQL) leave the column stale." None of `site.workplaces`, `site.shop_brands`, or `site.shop_products` — all created after that fix — carry the trigger, despite `shop_brands`/`shop_products` correctly picking up the RLS+FORCE convention from the same era.
    - **Plain English:** Most tables have a backup "last changed" clock that ticks even when a row is updated directly in the database rather than through the app. These three tables only have the app's clock, not the backup one. If a background job or admin tool ever updates them directly, the "last modified" time will silently freeze at the old value.
    - **Evidence:**
        ```sql
        -- 20260624010000 — the pattern already applied to platform_connections:
        CREATE OR REPLACE TRIGGER set_timestamp_platform_connections
            BEFORE UPDATE ON site.platform_connections
            FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();
        ```
        ```sql
        -- 20260701150000_create_workplaces.sql and 20260704160000_shop_brands_products.sql
        -- both create updated_at columns with no corresponding BEFORE UPDATE trigger.
        CREATE TABLE IF NOT EXISTS site.workplaces (
            site_id          uuid PRIMARY KEY REFERENCES site.sites (id) ON DELETE CASCADE,
            ...
            created_at       timestamptz,
            updated_at       timestamptz
        );
        ```

- [ ] **#SCHEMA-5** · P3 — `DROP COLUMN` used directly on two recent migrations instead of a rename-then-drop deploy cycle
    - **Where:** `supabase/migrations/20260704180000_drop_users_about.sql`; `supabase/migrations/20260705120000_drop_dead_profile_features.sql`
    - **Affects:** Rollback capability only — if a deploy needs rolling back after either migration lands, the previous code version referencing the dropped columns would fail. Both migrations already document deploy-ordering discipline ("Apply to dev Supabase ONLY AFTER the [...] code has deployed"), which mitigates the *forward* risk; the residual gap is a rollback landing after the drop.
    - **Effort:** S (~0.5–1h) — process guidance only; the columns are already dropped.
    - **What to do:**
        - For future irreversible column/table drops on tables that could plausibly need a rollback, add a short note to `docs/migration-guidelines.md` recommending a rename-to-`_deprecated` intermediate step for cases where rollback safety matters more than migration simplicity — the existing doc already covers the two adjacent lock-safety patterns (full-table-scan scrubs, `NOT VALID`/`VALIDATE` CHECK splits) but not this one.
        - No action needed on the two already-applied migrations — both are pre-pilot with the deploy-ordering safeguard already documented inline.
    - **Technical:** `DROP COLUMN` is immediate and irreversible; both migrations' own rollback comments acknowledge "data is unrecoverable." Both already apply the correct *forward* discipline (drop only after the code that stops referencing the column is live), which is the more important half of the safety story. The gap is narrower than the original framing suggested: it's rollback-after-drop, not any-rollback, and it's a documentation gap (`docs/migration-guidelines.md` doesn't yet cover this specific pattern), not a bug in the two shipped migrations.
    - **Plain English:** Dropping a database column is permanent — there's no undo button. These two migrations were careful to wait until the app stopped using the column before dropping it, which handles the main risk. The one edge case left is: if something goes wrong *after* the drop and the team needs to roll the app back to an older version, that older version would still expect the column to exist. Worth a line in the migration playbook for next time, but not an urgent fix.
    - **Evidence:**
        ```sql
        BEGIN;
        ALTER TABLE core.users DROP COLUMN IF EXISTS about;
        COMMIT;
        ```
        ```sql
        ALTER TABLE site.sites
            DROP COLUMN IF EXISTS hero_title,
            DROP COLUMN IF EXISTS hero_subtitle,
            DROP COLUMN IF EXISTS primary_button_text,
            DROP COLUMN IF EXISTS primary_button_url,
            DROP COLUMN IF EXISTS bio_text;

        ALTER TABLE core.users DROP COLUMN IF EXISTS bio;
        ```

- [ ] **#SCHEMA-6** · P3 — Five recent migrations embed data backfills inline instead of extracting them to a post-deploy step
    - **Where:** `supabase/migrations/20260704160000_shop_brands_products.sql`; `supabase/migrations/20260701140100_menu_item_platforms_table.sql`; `supabase/migrations/20260701140000_menu_platform_links.sql`; `supabase/migrations/20260701150100_create_user_credentials_experience.sql`; `supabase/migrations/20260701150000_create_workplaces.sql`
    - **Affects:** Future migrations on populated production tables — an inline `INSERT ... SELECT` or `UPDATE` that scans every row holds locks for the scan duration. `docs/migration-guidelines.md` already documents this exact anti-pattern (its "Full-table-scan data scrubs" section, general guidance, not tied to these five files) — the residual gap is that these five migrations predate/don't follow it, not that the pattern is undocumented.
    - **Effort:** S (~0.5–1h) — the backfills already ran pre-beta; this is a documentation cross-reference, not a code change.
    - **What to do:**
        - Add a line to `docs/migration-guidelines.md`'s existing "Full-table-scan data scrubs" section pointing at these five migrations as historical examples of the anti-pattern, so future contributors have a concrete before/after to compare against — the section's current example (`site.sites SET settings = settings - 'design'`) is a single-column UPDATE; these five are the more common “promote a JSONB blob to child tables” shape.
        - No action needed on the migrations themselves — one processed real dev data (41 legacy-shape menu items per its own comment), all are already applied, and the target tables of two of them (`core.user_credentials`, `core.user_experience`) have since been dropped entirely (`20260705120000_drop_dead_profile_features.sql`).
    - **Technical:** `docs/migration-guidelines.md` already establishes the canonical fix (extract to a post-deploy artisan command or chunked job) for this exact shape of problem, citing the `design_kits` backfill as the model. These five migrations (2026-07-01 through 2026-07-04) don't follow it — each embeds an `INSERT ... SELECT` or `UPDATE` scanning the full source table inside the migration transaction. At current row counts this is instant; the risk is purely that the pattern gets copy-pasted onto a hot table at real scale.
    - **Plain English:** Moving data around while restructuring a database table is like renovating a room while people are still using it — it's faster to do the heavy lifting as a background task that can pause and resume, rather than locking everyone out until it's done. The team already wrote this rule down for one case; these five migrations were written before (or without following) that rule. None of them affected real users — one even discovered the "zero rows" assumption was wrong for dev data — but it's worth pointing future migrations at these as the pattern to avoid.
    - **Evidence:**
        ```sql
        WITH ins_brands AS (
            INSERT INTO site.shop_brands
                (connection_id, brand_id, provider, url, source_url, name, currency,
                 favicon, logo, discount_code, fetch_mode, is_individual, position,
                 style_analysis, created_at, updated_at)
            SELECT pc.id,
                   b.key,
                   COALESCE(b.value->>'provider', 'shopify'),
                   b.value->>'url',
                   b.value->>'sourceUrl',
                   b.value->>'name',
                   b.value->>'currency',
                   b.value->>'favicon',
                   b.value->>'logo',
                   COALESCE(b.value->>'discountCode', ''),
                   b.value->>'fetchMode',
                   COALESCE((b.value->>'individual')::boolean, b.key = 'individual'),
                   (b.ord - 1)::int,
                   b.value->'styleAnalysis',
                   now(), now()
            FROM site.platform_connections pc
            CROSS JOIN LATERAL jsonb_each(pc.payload) WITH ORDINALITY AS b(key, value, ord)
            WHERE pc.platform = 'shop'
              AND pc.deleted_at IS NULL
              AND jsonb_typeof(pc.payload) = 'object'
              AND (pc.payload->>'storage') IS DISTINCT FROM 'relational'
              AND jsonb_typeof(b.value) = 'object'
            ON CONFLICT (connection_id, brand_id) DO NOTHING
            RETURNING id AS brand_row_id, connection_id, brand_id
        )
        ```

## Suggested Bundled Sessions

- **Bundle 1 — Migration guideline documentation:** #SCHEMA-5, #SCHEMA-6
    - **Why grouped:** Both are pure `docs/migration-guidelines.md` additions with no schema change — the DROP COLUMN rename-cycle pattern and the inline-backfill anti-pattern's historical examples are natural neighbors in the same doc.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

## Standalone — do NOT bundle

- **#SCHEMA-1 — `site.workplaces` missing RLS entirely** · DB schema change (new migration adding RLS/FORCE/policy + test dataset extension).
- **#SCHEMA-2 — ~20 tables missing FORCE ROW LEVEL SECURITY** · DB schema change touching ~20 tables, L-effort — plan + sign-off before a sweep migration this broad.
- **#SCHEMA-3 — 5 menu tables missing `gen_random_uuid()` default** · DB schema change (ALTER COLUMN SET DEFAULT across 5 tables).
- **#SCHEMA-4 — 3 tables missing `updated_at` triggers** · DB schema change (new triggers on 3 tables).
