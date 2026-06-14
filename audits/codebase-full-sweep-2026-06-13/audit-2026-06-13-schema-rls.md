# Schema / RLS / search_path Audit — 2026-06-13

**Branch:** development
**Lens:** Schema / RLS / search_path: database-side correctness, constraint coverage, migration safety
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-5`
**Source files audited:**
- `supabase/migrations/` (all 84 files)
- `app/Models/` (all model files)

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 1 complete
- P2 Medium: 0 of 5 complete
- P3 Low: 0 of 2 complete

---

## P1 — Fix before pilot launch

- [ ] **#SCHEMA-1** · P1 — `site.smart_links` has no Row Level Security
    - **Where:** `supabase/migrations/20260531000000_create_smart_links.sql` (entire file)
    - **Affects:** Any Supabase client or PostgREST path with access to the `site` schema can read or write all users' smart-link rows — URLs, tracking queries, discount codes, and snapshot metadata — without restriction.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a new migration with `ALTER TABLE site.smart_links ENABLE ROW LEVEL SECURITY;` and `ALTER TABLE site.smart_links FORCE ROW LEVEL SECURITY;`.
        - Add owner SELECT / INSERT / UPDATE / DELETE policies keyed on `user_id` (via `core.users.auth_user_id = auth.uid()`), a staff-all policy, and an anon SELECT policy for published sites — mirroring `20260602000000_design_kits_rls.sql` exactly.
        - Add a companion regression-guard test in `tests/Feature/Security/` (mirroring `DesignKitsRlsTest.php`).
    - **Technical:** `site.smart_links` is the only tenant-data table in `site.*` with no RLS at all. Every sibling table — `site.sites`, `site.blocks`, `site.site_media`, `site.services`, `site.enquiries`, `site.design_kits`, `site.platform_connections` — has at minimum `ENABLE ROW LEVEL SECURITY`. `smart_links` was created in `20260531000000` and never received its hardening follow-up. `app_backend` carries `BYPASSRLS` so the Laravel application path is unaffected today. The risk materialises if `site` is ever added to `api.schemas` in the Supabase dashboard (enabling PostgREST access), or if a support engineer runs a raw query via Supabase Studio with an `authenticated`-role session. The house exemplar fix is `20260602000000_design_kits_rls.sql` (ENABLE + FORCE + authenticated owner/staff policies + anon published-site SELECT).
    - **Plain English:** Every room in the database has a lock on its door — except the smart-links room. Today the only way in is through the guarded front desk (the Laravel app), so visitors never see the unlocked door. But if we ever open a side entrance — like the built-in Supabase database explorer or a direct database connection — anyone with a key to the building can walk in and read or edit every user's smart-link data. Installing the same lock the other rooms already have closes this gap permanently.
    - **Evidence:**
        ```sql
        -- 20260531000000_create_smart_links.sql (full file)
        CREATE TABLE IF NOT EXISTS site.smart_links (
            id                    uuid PRIMARY KEY,
            user_id               uuid NOT NULL REFERENCES core.users (id) ON DELETE CASCADE,
            site_id               uuid NOT NULL REFERENCES site.sites (id) ON DELETE CASCADE,
            family                text NOT NULL CHECK (family IN ('commerce', 'content')),
            type                  text NOT NULL,
            platform              text NOT NULL,
            canonical_url         text NOT NULL,
            -- ... all other columns ...
            deleted_at            timestamptz
        );
        -- No ALTER TABLE site.smart_links ENABLE ROW LEVEL SECURITY anywhere in any migration.
        
        -- Contrast: the design_kits house exemplar (20260602000000_design_kits_rls.sql):
        ALTER TABLE site.design_kits ENABLE ROW LEVEL SECURITY;
        ALTER TABLE site.design_kits FORCE ROW LEVEL SECURITY;
        CREATE POLICY design_kits_select_authenticated ON site.design_kits ...
        ```

---

## P2 — Should fix

- [ ] **#SCHEMA-2** · P2 — `site.site_subdomain_aliases.id` lacks `DEFAULT gen_random_uuid()`
    - **Where:** `supabase/migrations/20260526000000_baseline_standalone_user.sql` (site.site_subdomain_aliases DDL, line 864)
    - **Affects:** A raw SQL `INSERT` into `site.site_subdomain_aliases` without an explicit UUID — from an admin query, a migration backfill, or a reconciliation script — fails with a NOT NULL violation instead of auto-generating an ID.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `ALTER TABLE site.site_subdomain_aliases ALTER COLUMN id SET DEFAULT gen_random_uuid();` in a new hardening migration. Batch with SCHEMA-3 below since both are metadata-only catalog operations.
    - **Technical:** The baseline DDL declares `id uuid NOT NULL` with no `DEFAULT`. The model uses `HasUuids` (PHP-side UUID generation), so Eloquent inserts are covered. The `created_at` and `updated_at` columns do have `DEFAULT now()` and a `BEFORE UPDATE` trigger (`set_timestamp_site_subdomain_aliases`), so timestamps are fine — only `id` is the outlier. The canonical standard established in `20260609000000_harden_platform_connections.sql` is that every UUID PK column carries `DEFAULT gen_random_uuid()` as defence-in-depth for admin queries and migration backfills. The sibling table `site.professional_handle_aliases` correctly has `id uuid DEFAULT gen_random_uuid() NOT NULL` in the same baseline file — confirming this was a one-off omission.
    - **Plain English:** Every row in the database needs a unique ID. The app automatically generates one before inserting. But if someone runs a database command directly — say, a support fix or a migration script — the table requires you to provide the ID manually. Forget to provide it and the insert fails. The fix adds an auto-generate rule at the database level so every insert method works without thinking about it.
    - **Evidence:**
        ```sql
        -- Baseline: site.site_subdomain_aliases — id has no DEFAULT
        CREATE TABLE IF NOT EXISTS site.site_subdomain_aliases (
            id uuid NOT NULL,   -- ← no DEFAULT gen_random_uuid()
            site_id uuid NOT NULL,
            subdomain varchar(63) NOT NULL,
            created_at timestamptz DEFAULT now() NOT NULL,  -- ← timestamps fine
            updated_at timestamptz DEFAULT now() NOT NULL,
            ...
        );
        
        -- Sibling table in the same file — correct pattern:
        CREATE TABLE IF NOT EXISTS site.professional_handle_aliases (
            id uuid DEFAULT gen_random_uuid() NOT NULL,  -- ← has default
            ...
        );
        
        -- The hardening precedent (20260609000000):
        ALTER TABLE site.platform_connections
            ALTER COLUMN id SET DEFAULT gen_random_uuid();
        ```

- [ ] **#SCHEMA-3** · P2 — `site.smart_links` missing `id` and timestamp DB-side defaults and `updated_at` trigger
    - **Where:** `supabase/migrations/20260531000000_create_smart_links.sql` (lines 23, 56–58)
    - **Affects:** Raw SQL inserts (admin queries, migration backfills, batch reconciliation jobs) silently store NULL timestamps; the `updated_at` column never auto-advances on row mutations outside Eloquent, breaking any staleness or cache-invalidation logic that reads it.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - `ALTER TABLE site.smart_links ALTER COLUMN id SET DEFAULT gen_random_uuid();`
        - `ALTER TABLE site.smart_links ALTER COLUMN created_at SET DEFAULT now();`
        - `ALTER TABLE site.smart_links ALTER COLUMN updated_at SET DEFAULT now();`
        - `CREATE OR REPLACE TRIGGER set_timestamp_smart_links BEFORE UPDATE ON site.smart_links FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();` (mirroring every other mutable `site.*` table).
        - Batch all four into one migration with the SCHEMA-2 fix above.
    - **Technical:** `site.platform_connections` had the identical gap and received a dedicated hardening migration (`20260609000000_harden_platform_connections.sql`) adding `SET DEFAULT gen_random_uuid()` for `id` and `SET DEFAULT now()` for timestamps. `smart_links` was created with the same omission and never received the follow-up. Additionally, every mutable `site.*` table — `site.blocks`, `site.site_media`, `site.services`, `site.enquiries`, `site.design_kits` — has a `BEFORE UPDATE` trigger calling `public.set_updated_at()`. The `SmartLink` model's `SoftDeletes` and `HasUuids` cover the Eloquent path; the gap is on direct-SQL paths and any tooling that issues raw `UPDATE` statements (including some Eloquent methods that bypass the model lifecycle, like `SmartLink::query()->update([...])`).
    - **Plain English:** The smart-links table was built without the standard auto-fill rules every other table has. If a row is inserted or updated through a database tool rather than the app, it won't get a valid ID, won't record when it was created, and won't record when it was last changed. The "last changed" field is especially important — other parts of the system check it to decide when to refresh a page. The fix adds the same auto-fill rules the rest of the system already uses.
    - **Evidence:**
        ```sql
        -- smart_links DDL (20260531000000) — missing defaults and trigger:
        id          uuid PRIMARY KEY,              -- no DEFAULT gen_random_uuid()
        created_at  timestamptz,                   -- no DEFAULT now()
        updated_at  timestamptz,                   -- no DEFAULT now(), no BEFORE UPDATE trigger
        
        -- platform_connections AFTER hardening (20260609000000):
        ALTER TABLE site.platform_connections
            ALTER COLUMN id         SET DEFAULT gen_random_uuid(),
            ALTER COLUMN created_at SET DEFAULT now(),
            ALTER COLUMN updated_at SET DEFAULT now();
        
        -- Every other mutable site.* table has this trigger (baseline):
        CREATE OR REPLACE TRIGGER set_timestamp_services
            BEFORE UPDATE ON site.services FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();
        -- (analogous triggers for blocks, site_media, enquiries, design_kits)
        ```

- [ ] **#SCHEMA-4** · P2 — `site.smart_links` has no deduplication key — duplicate canonical URLs can be inserted per site
    - **Where:** `supabase/migrations/20260531000000_create_smart_links.sql` (entire file)
    - **Affects:** A network blip during smart-link creation, a Horizon job retry, or a user double-clicking "Add link" can insert two rows for the same `canonical_url` on the same `site_id`. Both rows survive in the database and the sitepage renders two identical link cards.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a partial unique index: `CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS smart_links_site_url_uq ON site.smart_links (site_id, canonical_url) WHERE deleted_at IS NULL;` in a separate migration file (outside a transaction block, per the CONCURRENTLY convention in `20260610000001`).
        - Update the creation controller/service to handle the resulting `UniqueConstraintViolationException` as a 409 or idempotent return of the existing row.
    - **Technical:** `site.platform_connections` has `idx_platform_connections_unique_active (user_id, platform, resource_id) WHERE deleted_at IS NULL` establishing the one-active-connection-per-platform-resource invariant. `notifications.notifications` has `notifications_dedupe_key_per_pro_uq`. `smart_links` has no analogous dedup constraint. The `canonical_url` is the natural business key for a smart link within a site — the `UrlNormalizer` service normalises URLs before storage precisely to enable this comparison. Without a DB-level constraint, the only defence is application-layer idempotency, which fails under concurrent retries.
    - **Plain English:** If you try to add the same Spotify link to your page twice — whether by double-clicking or because a background job retried after a network blip — the database will happily store two identical copies. Both show up on your site. The fix is a database rule that says "one copy of each URL per page, period" — just like every other uniqueness rule in the system.
    - **Evidence:**
        ```sql
        -- smart_links (20260531000000): no UNIQUE constraint on (site_id, canonical_url)
        CREATE INDEX IF NOT EXISTS idx_smart_links_site_family_sort
            ON site.smart_links (site_id, family, sort_order) WHERE deleted_at IS NULL;
        CREATE INDEX IF NOT EXISTS idx_smart_links_last_refreshed
            ON site.smart_links (last_refreshed_at)          WHERE deleted_at IS NULL;
        -- No (site_id, canonical_url) unique index.
        
        -- platform_connections dedup precedent (20260602150238):
        CREATE UNIQUE INDEX idx_platform_connections_unique_active
            ON site.platform_connections (user_id, platform, resource_id)
            WHERE deleted_at IS NULL;
        ```

- [ ] **#SCHEMA-5** · P2 — `site.smart_links.type` and `site.smart_links.platform` have no CHECK constraints
    - **Where:** `supabase/migrations/20260531000000_create_smart_links.sql` (lines 28–29)
    - **Affects:** Any insert or update path can write arbitrary strings into `type` and `platform`. A typo, a future code change, or a raw SQL query can store an unrecognised value that the `SmartLinkTypeRegistry`'s `match` expressions cannot handle — silently producing blank or broken link cards on the sitepage.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add CHECK constraints enumerating the values produced by `SmartLinkTypeRegistry`. As of the current code: `type IN ('commerce.brand', 'commerce.collection', 'commerce.product', 'commerce.event', 'content.music.track', 'content.music.album', 'content.podcast.episode', 'content.video')` and `platform IN ('brand', 'collection', 'product', 'event', 'spotify', 'apple_music', 'bandcamp', 'apple_podcasts', 'youtube', 'vimeo')`.
        - Use `NOT VALID` + `VALIDATE` split to match the established pattern (see `20260528010000_alter_sites_moderation_state.sql`).
        - Add a migration comment linking the CHECK list to `SmartLinkTypeRegistry::COMMERCE_SELECTIONS` and `CONTENT_PLATFORMS` so the lists stay in sync when new platforms are added.
    - **Technical:** The sibling `family` column already has `CHECK (family IN ('commerce', 'content'))` in the same DDL. `last_refresh_status` has `CHECK (last_refresh_status IN ('ok', 'unavailable', 'error'))`. The omission on `type` and `platform` is inconsistent. `site.blocks` has `blocks_block_type_check` enumerating 16 block types; `site.platform_connections` has a `platform_check` constraint. The `SmartLinkTypeRegistry::resolveType` method is the only writer of `type` — its output is a closed set from the same PHP constant lists that would be mirrored in the CHECK. Adding platforms requires a new enum member in the registry AND a migration adding it to the CHECK — which is the correct moment to consider schema impact.
    - **Plain English:** The table already enforces that a smart link must be either "commerce" or "content" — it checks that at the database level. But the more specific sub-type (like "commerce.product" or "content.video") and the source platform (like "spotify" or "youtube") have no such check. It's like a form that validates the category dropdown but not the sub-category — you could accidentally store a value the app has no idea how to render, and the page would break silently. The fix adds the same allowed-values check the other typed columns already have.
    - **Evidence:**
        ```sql
        -- 20260531000000_create_smart_links.sql:
        family   text NOT NULL CHECK (family IN ('commerce', 'content')),  -- ✓ has CHECK
        type     text NOT NULL,   -- ✗ no CHECK
        platform text NOT NULL,   -- ✗ no CHECK
        
        -- SmartLinkTypeRegistry.php — the closed set of valid values:
        // public const COMMERCE_SELECTIONS = ['brand', 'collection', 'product', 'event'];
        // public const CONTENT_PLATFORMS = ['spotify', 'apple_music', 'bandcamp', 'apple_podcasts', 'youtube', 'vimeo'];
        // type values produced: 'commerce.{selection}', 'content.music.{track|album}',
        //   'content.podcast.episode', 'content.video'
        ```

- [ ] **#SCHEMA-6** · P2 — Baseline tenant-data tables have RLS `ENABLE` but not `FORCE` — owner role bypasses policies
    - **Where:** `supabase/migrations/20260526000000_baseline_standalone_user.sql` (RLS section, lines ~1667–2229); also `20260602150238_create_platform_connections.sql` and `20260610000000_analytics_v2_clicks_sessions.sql`
    - **Affects:** A direct connection to PostgreSQL as the `postgres` (owner) role — via Supabase Studio, a misconfigured direct connection, or a support tool — bypasses all RLS policies on 20+ core tenant-data tables: `core.users`, `site.sites`, `site.blocks`, `site.site_media`, `site.services`, `site.enquiries`, `site.site_subdomain_aliases`, `notifications.*`, `analytics.*`, `site.platform_connections`, `analytics.site_sessions`, and more. Any row of any tenant is readable and writable.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add a batch hardening migration applying `ALTER TABLE <table> FORCE ROW LEVEL SECURITY;` to every tenant-data table that currently only has `ENABLE`. Tables confirmed present: all `core.*`, `site.*`, `notifications.*`, `analytics.*` tables listed in the baseline RLS section, plus `site.platform_connections` (from `20260602150238`) and `analytics.site_sessions` (from `20260610000000`).
        - Follow the pattern established by `20260526210002_feedback_hardening.sql` (core.feedback), `20260602000000_design_kits_rls.sql` (site.design_kits), and `20260606030000_moderation_schema_rls.sql` (all moderation tables).
        - Update the RLS regression-guard test (analogous to `DesignKitsRlsTest.php`) to assert `FORCE` on every tenant-data table, not just design_kits.
    - **Technical:** PostgreSQL's default behaviour exempts the table owner from RLS policy evaluation. `FORCE ROW LEVEL SECURITY` removes that exemption — the owner must satisfy a policy just like any other role. In the baseline, only `core.partna_staff` has `FORCE` applied (line 398). Every table added after the baseline — `core.feedback`, `site.design_kits`, all five `moderation.*` tables — received both `ENABLE` and `FORCE`, establishing a clear new standard. The approximately 25 tables from the original baseline are the outliers. `app_backend` carries `BYPASSRLS` separately and is unaffected; this hardening closes the gap for the `postgres` owner role and any future role that might be granted owner-level access via provisioning mistakes. This is defence-in-depth, not a day-one exploit.
    - **Plain English:** There's a rule that says "check everyone's ID at the door." Most of the newer rooms check everyone — including the room owner. But the original rooms from the first build still let the owner walk through without showing ID. If a support engineer logs into the database directly as the admin account (which they sometimes do for maintenance), they see every user's data without any access controls applying. The fix is to retrofit the "check everyone including the owner" rule across the board.
    - **Evidence:**
        ```sql
        -- Baseline (20260526000000) — ENABLE without FORCE on 20+ tables:
        ALTER TABLE core.users ENABLE ROW LEVEL SECURITY;
        -- (no FORCE)
        ALTER TABLE site.sites ENABLE ROW LEVEL SECURITY;
        -- (no FORCE)
        -- ... repeated for core.customers, site.blocks, site.site_media,
        -- site.services, site.enquiries, notifications.notifications,
        -- analytics.site_visits, analytics.link_clicks, and ~15 more.
        
        -- The one baseline exception (FORCE applied):
        ALTER TABLE core.partna_staff FORCE ROW LEVEL SECURITY;
        
        -- The established new pattern (20260602000000, 20260606030000):
        ALTER TABLE site.design_kits ENABLE ROW LEVEL SECURITY;
        ALTER TABLE site.design_kits FORCE ROW LEVEL SECURITY;  -- ← what's missing above
        
        -- Also missing FORCE in post-baseline migrations:
        -- 20260602150238: ALTER TABLE site.platform_connections ENABLE ROW LEVEL SECURITY;
        -- 20260610000000: ALTER TABLE analytics.site_sessions ENABLE ROW LEVEL SECURITY;
        ```

---

## P3 — Nice to have

- [ ] **#SCHEMA-7** · P3 — Two trigger functions use `SET search_path TO 'pg_catalog'` instead of the canonical `SET search_path = ''`
    - **Where:** `supabase/migrations/20260526000000_baseline_standalone_user.sql` (lines 85–119 for `core.prevent_staff_escalation`, lines 148–187 for `core.enforce_site_gallery_max6`)
    - **Affects:** No exploitable security gap — both functions only reference `NEW`/`OLD` and builtins. The inconsistency prevents the `FunctionSearchPathTest.php` regression guard from covering these two functions (the test only covers the 12 pinned in `20260606040000`), leaving the Supabase security advisor free to re-fire on them.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - `ALTER FUNCTION core.prevent_staff_escalation() SET search_path = '';`
        - `ALTER FUNCTION core.enforce_site_gallery_max6() SET search_path = '';`
        - Add `['core', 'prevent_staff_escalation']` and `['core', 'enforce_site_gallery_max6']` to the `$searchPathFunctions` dataset in `tests/Feature/Security/FunctionSearchPathTest.php`.
        - Note: `core.set_default_theme_for_site` — the third `'pg_catalog'`-pinned function from the baseline — was already dropped by `20260527070001_drop_orphaned_theme_trigger.sql`; no action needed for it.
    - **Technical:** `20260606040000_pin_function_search_paths.sql` pinned 12 functions to `SET search_path = ''` (empty string — forces every unqualified reference to fail, making it maximally auditable via `SELECT proname FROM pg_proc WHERE proconfig IS NOT NULL AND array_to_string(proconfig, ',') LIKE '%search_path%'`). The two baseline functions use the older `SET search_path TO 'pg_catalog'` convention, which is functionally safe for their specific bodies but is a different string. The `FunctionSearchPathTest.php` test asserts `proconfig` contains `search_path` — it would pass for `'pg_catalog'`-pinned functions too — but since these two are not in the test's dataset at all, they are entirely unguarded against regression. Unifying on `= ''` means one canonical string to grep and one test to maintain.
    - **Plain English:** We have a rule for how database functions find their tools: "always point directly to the exact toolbox." We updated 12 functions to follow the newest version of this rule. Two older functions follow an earlier wording that achieves the same thing but reads differently. There's also no automated check watching those two — so if someone accidentally changes them back, nobody will notice. The fix updates the wording to match and adds them to the automated watchlist.
    - **Evidence:**
        ```sql
        -- Canonical pattern (20260606040000_pin_function_search_paths.sql):
        ALTER FUNCTION core.trg_user_handle_alias_check() SET search_path = '';
        
        -- Non-canonical survivors in baseline:
        CREATE OR REPLACE FUNCTION core.prevent_staff_escalation()
        RETURNS trigger LANGUAGE plpgsql
        SET search_path TO 'pg_catalog'   -- ← should be SET search_path = ''
        AS $$ ... $$;
        
        CREATE OR REPLACE FUNCTION core.enforce_site_gallery_max6()
        RETURNS trigger LANGUAGE plpgsql
        SET search_path TO 'pg_catalog'   -- ← should be SET search_path = ''
        AS $$ ... $$;
        ```

- [ ] **#SCHEMA-8** · P3 — Two recent migrations add CHECK constraints inline without `NOT VALID` + `VALIDATE` split
    - **Where:** `supabase/migrations/20260612120000_account_type_partna_business.sql` (line 33); `supabase/migrations/20260612140000_site_custom_domain.sql` (line 33)
    - **Affects:** No current risk — both tables are pre-beta with few rows. This is a pattern-hygiene finding: the codebase's established template (`NOT VALID` + `VALIDATE` in a second transaction) was skipped in two consecutive migrations. Copy-paste from these files into a future hot-table migration would hold `ACCESS EXCLUSIVE` for the full row scan rather than the lighter `SHARE UPDATE EXCLUSIVE` the split provides.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - For forward-correctness, rewrite both CHECK additions to use `ADD CONSTRAINT ... NOT VALID;` committed, then `VALIDATE CONSTRAINT ...;` in a separate transaction — matching the canonical pattern in `20260528010000_alter_sites_moderation_state.sql`. Since these have already applied to dev (and prod will receive them before it's live), a corrective migration replacing the inline adds is cleaner than an in-flight edit.
        - Add a one-line comment to each `ADD CONSTRAINT` explaining why the split is used, so future authors copy the right pattern.
    - **Technical:** `20260528010000_alter_sites_moderation_state.sql` is the house exemplar for this pattern — it adds `NOT VALID` in one transaction and `VALIDATE CONSTRAINT` in a second, with a comment citing `CONVENTIONS.md §2`. Both `20260612120000` and `20260612140000` skip this without explanation. For `account_type`, the preceding `UPDATE ... WHERE account_type = 'individual'` ensures all rows pass the new CHECK before it is added, so the scan is instant in practice. For `custom_domain_status`, the column is nullable and all existing rows carry `NULL` (satisfying the `IS NULL OR` branch), so the scan is also instant. The risk is entirely forward-looking: a developer copying either migration as a template into a populated table will inadvertently hold a long `ACCESS EXCLUSIVE` lock.
    - **Plain English:** When we add a rule that says "this column must contain one of these values," the database checks every existing row to confirm. There's a two-step way to do this check that lets other writers keep working while it runs, and a one-step way that blocks everyone. The codebase has an established two-step pattern. Two recent migrations used the one-step shortcut — safe today because the tables are nearly empty, but if someone copies this shortcut into a busy table later, it could cause a brief service interruption. The fix is to keep the two-step pattern consistent so future work inherits the right template.
    - **Evidence:**
        ```sql
        -- 20260612120000 — inline CHECK (no NOT VALID):
        ALTER TABLE core.users
            ADD CONSTRAINT users_account_type_check
            CHECK (account_type IN ('partna', 'business'));  -- no NOT VALID
        
        -- 20260612140000 — inline CHECK on site.sites (no NOT VALID):
        ALTER TABLE site.sites
            ADD CONSTRAINT sites_custom_domain_status_check
            CHECK (custom_domain_status IS NULL OR custom_domain_status IN ('pending', 'active', 'error'));  -- no NOT VALID
        
        -- Canonical pattern (20260528010000_alter_sites_moderation_state.sql):
        ALTER TABLE site.sites
            ADD CONSTRAINT sites_moderation_state_check
            CHECK (moderation_state IN ('active', 'warned', 'hidden')) NOT VALID;
        COMMIT;
        BEGIN;
        ALTER TABLE site.sites VALIDATE CONSTRAINT sites_moderation_state_check;
        COMMIT;
        ```

