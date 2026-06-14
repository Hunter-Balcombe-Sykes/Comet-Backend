
<!-- ═══ LENS: schema-rls | CHUNK: schema ═══ -->

- [ ] **#SCHEMA-1** · P1 — `site.smart_links` has no Row Level Security at all
    - **Where:** supabase/migrations/20260531000000_create_smart_links.sql:entire file
    - **Affects:** Any Supabase client or PostgREST path that gains access to the `site` schema — all smart link rows (URLs, discount codes, metadata) become readable/writable by any authenticated JWT.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `ALTER TABLE site.smart_links ENABLE ROW LEVEL SECURITY;` and `ALTER TABLE site.smart_links FORCE ROW LEVEL SECURITY;`.
        - Add owner-SELECT / owner-INSERT / owner-UPDATE / owner-DELETE policies gated on `user_id` (mirroring `site.services`), plus a staff-all policy.
        - Add a companion regression test in `tests/Feature/Security/` (mirroring `DesignKitsRlsTest` and `ModerationSchemaRlsTest`).
    - **Technical:** Every other tenant-data table in `site.*` has RLS enabled + policies (sites, blocks, site_media, services, enquiries, design_kits, platform_connections). `smart_links` is the lone exception — it was created after the baseline with no RLS section. `app_backend` has BYPASSRLS so the application path is unaffected today, but if `site` is ever added to `api.schemas` or direct Supabase access is granted, smart links leak without warning. The house exemplar pattern (`site.design_kits` in `20260602000000_design_kits_rls.sql`) applies: ENABLE + FORCE + authenticated owner/staff policies + anon published-site SELECT.
    - **Plain English:** Every door in the building has a lock except one — the smart links room. Right now the only way in is through the guarded front desk (the Laravel app), so nobody notices. But if we ever open a side entrance (direct database access), that unlocked room is wide open. The fix is to install the same lock every other room already has.
    - **Evidence:**
        ```sql
        CREATE TABLE IF NOT EXISTS site.smart_links (
            id                    uuid PRIMARY KEY,
            user_id               uuid NOT NULL REFERENCES core.users (id) ON DELETE CASCADE,
            site_id               uuid NOT NULL REFERENCES site.sites (id) ON DELETE CASCADE,
            -- ... columns ...
        );
        -- No ALTER TABLE ... ENABLE ROW LEVEL SECURITY anywhere in the file.
        ```
    - `[DRAFT, confidence: 0.95]`

- [ ] **#SCHEMA-2** · P2 — Many baseline tenant-data tables have RLS enabled but not FORCED
    - **Where:** supabase/migrations/20260526000000_baseline_standalone_user.sql:RLS section (lines ~1800–2400)
    - **Affects:** Any direct connection as the table owner (postgres role) can bypass RLS on `core.users`, `site.sites`, `site.blocks`, `site.site_media`, `site.services`, `notifications.*`, `analytics.*`, and others — silently reading or mutating any tenant's data.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add `ALTER TABLE <table> FORCE ROW LEVEL SECURITY;` for every tenant-data table that currently only has `ENABLE ROW LEVEL SECURITY`.
        - Batch into a single hardening migration following the established pattern from `site.design_kits` (20260602000000), `core.feedback` (20260526210002), and `moderation.*` (20260606030000).
        - Update the RLS coverage test to assert FORCE on every tenant table.
    - **Technical:** PostgreSQL's default RLS behaviour exempts the table owner from policy checks. `FORCE ROW LEVEL SECURITY` closes that loophole — the owner must also satisfy a policy. The baseline only applied FORCE to `core.partna_staff`; every subsequent table (design_kits, feedback, moderation.*) added both ENABLE and FORCE. The gap is on the 20+ tables that only got ENABLE: a compromised postgres-role session or a misconfigured direct connection could bypass all policies. `app_backend` carries BYPASSRLS separately, so the application path is unaffected — this is defence-in-depth against owner-role access.
    - **Plain English:** Think of RLS as a bouncer at the club door checking IDs. `ENABLE ROW LEVEL SECURITY` stations the bouncer — but the club owner can still walk right past without showing ID. `FORCE ROW LEVEL SECURITY` makes the owner show ID too. Most of our tables have the bouncer but let the owner waltz through. The newer tables don't — they check everyone. We should retrofit the check-everyone rule across the board.
    - **Evidence:**
        ```sql
        ALTER TABLE core.users ENABLE ROW LEVEL SECURITY;
        -- No FORCE ROW LEVEL SECURITY follows.
        
        ALTER TABLE site.sites ENABLE ROW LEVEL SECURITY;
        -- No FORCE ROW LEVEL SECURITY follows.
        
        -- ... repeated for ~20 tables.
        
        -- Contrast with the house exemplar:
        ALTER TABLE site.design_kits ENABLE ROW LEVEL SECURITY;
        ALTER TABLE site.design_kits FORCE ROW LEVEL SECURITY;  -- present
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **#SCHEMA-3** · P2 — `site.smart_links.type` and `site.smart_links.platform` are TEXT columns with no CHECK constraint
    - **Where:** supabase/migrations/20260531000000_create_smart_links.sql:14-16
    - **Affects:** Any insert path (app, raw SQL, future API) can store arbitrary strings in `type` and `platform`, bypassing the namespaced type taxonomy the model and resolver expect.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add CHECK constraints on `type` and `platform` enumerating the known values (e.g. `type IN ('commerce.brand', 'commerce.collection', 'commerce.product', 'commerce.event', 'content.music', 'content.podcast_episode', 'content.video')`).
        - Use the `NOT VALID` + `VALIDATE` split pattern from CONVENTIONS.md §2 so the migration is lock-light even if rows exist.
        - Keep the CHECK lists in sync with the `SmartLinkResolver` type registry via a documentation comment.
    - **Technical:** The sibling model `site.blocks` has `blocks_block_type_check` enumerating 16 block types, and `site.platform_connections` has a CHECK on platform. `smart_links` has neither — `type` is documented as "namespaced" and `platform` mirrors the connection platforms, but nothing enforces it at the DB layer. A typo or future code change can insert rows the resolver can't interpret, causing silent 500s or blank cards on the sitepage. The `family` column already has a CHECK (`IN ('commerce', 'content')`) — `type` and `platform` should match that standard.
    - **Plain English:** The smart links table has two columns that are supposed to hold specific, known values — like "commerce.product" or "spotify." But there's no gatekeeper at the database level checking those values. It's like having a form field labeled "Country" with no dropdown — someone could type "Middle Earth" and the system would happily store it, then break when it tries to render the page. The fix is to add a database-level allowed-values list, just like every other typed column in the system.
    - **Evidence:**
        ```sql
        family   text NOT NULL CHECK (family IN ('commerce', 'content')),  -- has CHECK
        type     text NOT NULL,                                            -- no CHECK
        platform text NOT NULL,                                            -- no CHECK
        ```
    - `[DRAFT, confidence: 0.80]`

- [ ] **#SCHEMA-4** · P2 — `site.smart_links` has no deduplication key — duplicate URLs can be inserted for the same site
    - **Where:** supabase/migrations/20260531000000_create_smart_links.sql:entire file
    - **Affects:** Users who retry a failed smart-link creation, or queue workers that re-deliver events, can create duplicate smart-link rows for the same canonical URL on the same site — the sitepage renders duplicates.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a partial UNIQUE index: `CREATE UNIQUE INDEX CONCURRENTLY smart_links_site_url_uq ON site.smart_links (site_id, canonical_url) WHERE deleted_at IS NULL;`.
        - Ensure the app layer uses `firstOrCreate` or equivalent so duplicates are idempotent.
    - **Technical:** Every other entity where "one per (tenant, key)" makes sense has a partial unique index — `site.platform_connections` has `idx_platform_connections_unique_active (user_id, platform, resource_id) WHERE deleted_at IS NULL`, `notifications.notifications` has `notifications_dedupe_key_per_pro_uq`. `smart_links` has none. A network blip during creation, a Horizon retry, or a user double-clicking "Add link" can insert two rows for the same URL, and the sitepage resolver will render both. The canonical URL is the natural business key for a smart link within a site.
    - **Plain English:** If you try to add the same Spotify link twice to your page, the system should say "you already have that one" — not create a duplicate. Right now it has no way to spot duplicates, so a double-click or a network hiccup creates two copies of the same link card. The fix is a database rule that says "one URL per site, period."
    - **Evidence:**
        ```sql
        CREATE TABLE IF NOT EXISTS site.smart_links (
            -- ... columns ...
            canonical_url  text NOT NULL,
            -- ... more columns ...
        );
        -- No UNIQUE index on (site_id, canonical_url).
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **#SCHEMA-5** · P2 — `site.smart_links` columns lack DB-side defaults for `id`, `created_at`, and `updated_at`
    - **Where:** supabase/migrations/20260531000000_create_smart_links.sql:11,43-44
    - **Affects:** Raw SQL inserts, reconciliation jobs, and any future path that bypasses Eloquent's trait-based UUID/timestamp generation will insert NULLs or fail.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `ALTER TABLE site.smart_links ALTER COLUMN id SET DEFAULT gen_random_uuid();`
        - Add `ALTER TABLE site.smart_links ALTER COLUMN created_at SET DEFAULT now();`
        - Add `ALTER TABLE site.smart_links ALTER COLUMN updated_at SET DEFAULT now();`
        - Add a `BEFORE UPDATE` trigger calling `public.set_updated_at()` (mirroring every other site-schema table).
        - Apply the same hardening to `site.site_subdomain_aliases.id` which also lacks `DEFAULT gen_random_uuid()`.
    - **Technical:** `site.platform_connections` had the exact same gaps and was hardened in `20260609000000_harden_platform_connections.sql` — that migration added `SET DEFAULT gen_random_uuid()` for `id` and `SET DEFAULT now()` for timestamps. `smart_links` was created with the same pattern and never received the hardening follow-up. Additionally, every mutable site-schema table (blocks, site_media, services, enquiries, design_kits) has a `BEFORE UPDATE` trigger that auto-sets `updated_at` via `public.set_updated_at()`. `smart_links` has neither the trigger nor the column defaults. The `SmartLink` Eloquent model uses `HasUuids` and Laravel's automatic timestamp management, so the PHP path is covered — but raw SQL, queued batch operations, and DB-level tooling are not.
    - **Plain English:** The smart links table was built without the standard safety nets every other table has. If someone inserts a row directly (through a database tool, a migration, or a future background job), the ID won't auto-generate and the timestamps won't auto-fill. It's like having a front door that only works with your key fob — the manual keyhole isn't drilled yet. The fix adds the missing auto-fill rules so any entry method works.
    - **Evidence:**
        ```sql
        -- smart_links (missing defaults)
        id          uuid PRIMARY KEY,              -- no DEFAULT gen_random_uuid()
        created_at  timestamptz,                   -- no DEFAULT now()
        updated_at  timestamptz,                   -- no DEFAULT now(), no trigger
        
        -- platform_connections AFTER hardening (20260609000000)
        ALTER TABLE site.platform_connections
            ALTER COLUMN id         SET DEFAULT gen_random_uuid(),
            ALTER COLUMN created_at SET DEFAULT now(),
            ALTER COLUMN updated_at SET DEFAULT now();
        ```
    - `[DRAFT, confidence: 0.90]`

- [ ] **#SCHEMA-6** · P2 — `site.site_subdomain_aliases.id` lacks `DEFAULT gen_random_uuid()`
    - **Where:** supabase/migrations/20260526000000_baseline_standalone_user.sql (site.site_subdomain_aliases DDL); app/Models/Core/Site/SiteSubdomainAlias.php (uses HasUuids)
    - **Affects:** Raw SQL inserts into the subdomain-alias table — the `id` column is `uuid NOT NULL` with no DB-side default, so a direct INSERT without an explicit UUID fails.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - `ALTER TABLE site.site_subdomain_aliases ALTER COLUMN id SET DEFAULT gen_random_uuid();`
        - Batch this with the smart_links hardening in SCHEMA-5.
    - **Technical:** The column is defined as `id uuid NOT NULL` (no `DEFAULT gen_random_uuid()`). The Eloquent model uses the `HasUuids` trait which generates UUIDs in PHP before insert, so the application path is covered. But the baseline established `DEFAULT gen_random_uuid()` as the standard for every UUID PK — `site.sites`, `site.blocks`, `site.site_media`, `site.services`, and virtually every other domain table have it. `site_subdomain_aliases` and `smart_links` are the two outliers. A DB-level default is defence-in-depth for admin queries, migrations, and reconciliation scripts.
    - **Plain English:** Every ID card printer in the building auto-generates a unique number — except for two machines that require you to bring your own number. If someone forgets to bring one, the machine jams. The fix is to install the same auto-numbering feature those two machines are missing.
    - **Evidence:**
        ```sql
        CREATE TABLE IF NOT EXISTS site.site_subdomain_aliases (
            id uuid NOT NULL,   -- no DEFAULT gen_random_uuid()
            site_id uuid NOT NULL,
            -- ...
        );
        ```
    - `[DRAFT, confidence: 0.90]`

- [ ] **#SCHEMA-7** · P3 — Three trigger functions use `SET search_path TO 'pg_catalog'` instead of the canonical `SET search_path = ''`
    - **Where:** supabase/migrations/20260526000000_baseline_standalone_user.sql (function bodies for `core.prevent_staff_escalation`, `core.enforce_site_gallery_max6`, `core.set_default_theme_for_site`)
    - **Affects:** Minor inconsistency — these functions resolve unqualified builtins through `pg_catalog` explicitly rather than relying on the empty-string convention. No security gap (both are safe), but the codebase now carries two different patterns.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Run `ALTER FUNCTION core.prevent_staff_escalation() SET search_path = '';` and `ALTER FUNCTION core.enforce_site_gallery_max6() SET search_path = '';` to match the 12 functions pinned in `20260606040000_pin_function_search_paths.sql`.
        - `core.set_default_theme_for_site` was already dropped by `20260527070001`; confirm it's gone in all environments.
        - Update `tests/Feature/Security/FunctionSearchPathTest.php` to assert empty-string pinning on all trigger/helper functions.
    - **Technical:** The `20260606040000` migration pinned 12 functions to `search_path = ''` (the most secure choice: forces every object reference to be schema-qualified). These three functions were defined in the baseline with `SET search_path TO 'pg_catalog'` — equally secure for their specific bodies (they only reference `NEW`/`OLD`, builtins, and already-qualified table names), but it's a different convention. `set_default_theme_for_site` was already dropped; the other two remain. Unifying on `''` makes the security posture auditable in one query (`SELECT proname FROM pg_proc WHERE proconfig @> '{"search_path="}'` won't catch `pg_catalog`-pinned functions).
    - **Plain English:** We have a standard rule for how functions find their tools: "always point exactly to the toolbox you need." Twelve functions follow the latest version of the rule. Three older ones follow an earlier draft that's equally safe but worded differently. We should update the older ones to match so there's one consistent rule to audit against.
    - **Evidence:**
        ```sql
        -- Canonical pattern (from 20260606040000):
        ALTER FUNCTION core.trg_user_handle_alias_check() SET search_path = '';
        
        -- Non-canonical survivors:
        CREATE OR REPLACE FUNCTION core.prevent_staff_escalation()
        RETURNS trigger LANGUAGE plpgsql
        SET search_path TO 'pg_catalog'  -- should be SET search_path = ''
        AS $$ ... $$;
        
        CREATE OR REPLACE FUNCTION core.enforce_site_gallery_max6()
        RETURNS trigger LANGUAGE plpgsql
        SET search_path TO 'pg_catalog'  -- should be SET search_path = ''
        AS $$ ... $$;
        ```
    - `[DRAFT, confidence: 0.75]`

- [ ] **#SCHEMA-8** · P3 — `core.users.account_type` CHECK constraint was rebuilt inline instead of using `NOT VALID` + `VALIDATE`
    - **Where:** supabase/migrations/20260612120000_account_type_partna_business.sql:33-35
    - **Affects:** If this migration runs against a table with non-trivial row count (future environments), the inline CHECK takes `ACCESS EXCLUSIVE` for the full row scan rather than the lighter `SHARE UPDATE EXCLUSIVE` the split pattern provides.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Rewrite the final `ADD CONSTRAINT` to use `NOT VALID`, then `VALIDATE` in a separate transaction (CONVENTIONS.md §2).
        - This is a forward-looking fix — pre-beta the lock is harmless, but establishing the correct pattern prevents copy-paste drift into hot-table migrations later.
    - **Technical:** The migration drops the old CHECK, backfills row data, then adds the new CHECK inline — meaning PostgreSQL scans every row under `ACCESS EXCLUSIVE` before the constraint is accepted. For a pre-beta table with hundreds of rows this is instant. But the established pattern in this codebase (used in `20260526010000`, `20260528010000`, `20260530000100`, and every platform-connections CHECK modification from `20260610200000` onward) is: `ADD CONSTRAINT ... NOT VALID` (catalog-only, no row scan), then `VALIDATE CONSTRAINT` in a separate transaction (scans rows under `SHARE UPDATE EXCLUSIVE`, which allows concurrent writes). The migration comment notes the guard exemption for pre-beta but doesn't explain why the pattern was skipped.
    - **Plain English:** When we change the allowed values on a column, the database needs to check every existing row to make sure nobody has a now-invalid value. There's a two-step way to do this that keeps the door open for other writers during the check, and a one-step way that locks everyone out. We used the lock-everyone-out way because the room is nearly empty. That's fine today, but we should still use the keep-the-door-open pattern so nobody copies the shortcut into a crowded room later.
    - **Evidence:**
        ```sql
        -- This migration:
        ALTER TABLE core.users
            ADD CONSTRAINT users_account_type_check CHECK (account_type IN ('partna', 'business'));
        -- No NOT VALID, no separate VALIDATE transaction.
        
        -- Canonical pattern (e.g., 20260528010000):
        ALTER TABLE site.sites
            ADD CONSTRAINT sites_moderation_state_check
            CHECK (moderation_state IN ('active', 'warned', 'hidden')) NOT VALID;
        COMMIT;
        BEGIN;
        ALTER TABLE site.sites VALIDATE CONSTRAINT sites_moderation_state_check;
        COMMIT;
        ```
    - `[DRAFT, confidence: 0.70]`
