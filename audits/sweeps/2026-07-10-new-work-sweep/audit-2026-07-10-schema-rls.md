# Schema / RLS / search_path Audit — 2026-07-10

**Branch:** audit-fix/analytics-master-2026-07-10
**Lens:** Schema / RLS / search_path: database-side correctness, constraint coverage, migration safety
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4.5`
**Source files audited:**
- `app/Models/Analytics/ItemView.php`
- `app/Models/Analytics/LinkClick.php`
- `app/Models/Analytics/SiteVisit.php`
- `app/Models/Core/Site/ContentSelection.php`
- `app/Models/Core/Site/IntegrationConnection.php`
- `app/Models/Core/Site/ShopBrand.php`
- `app/Models/Core/Site/ShopProduct.php`
- `app/Models/Core/Site/Site.php`
- `app/Models/Core/Site/SiteMedia.php`
- `app/Models/Core/Site/Workplace.php`
- `app/Models/Core/Staff/StaffAuditEntry.php`
- `app/Models/Core/User/User.php`
- `supabase/migrations/20260704160000_shop_brands_products.sql`
- `supabase/migrations/20260704170000_drop_menu_platform_checks.sql`
- `supabase/migrations/20260704180000_drop_users_about.sql`
- `supabase/migrations/20260705000000_migrate_retired_font_slugs.sql`
- `supabase/migrations/20260705120000_drop_dead_profile_features.sql`
- `supabase/migrations/20260705150200_create_content_selection.sql`
- `supabase/migrations/20260706000000_add_city_to_site_visits.sql`
- `supabase/migrations/20260707020000_site_visits_lat_lon.sql`
- `supabase/migrations/20260707030000_rename_skeleton_ids.sql`
- `supabase/migrations/20260707030000_shop_brand_modes.sql`
- `supabase/migrations/20260707080000_skeletons_sheet_thread.sql`
- `supabase/migrations/20260707120000_rename_skeleton_ids_bento_class.sql`
- `supabase/migrations/20260708120000_sites_shop_global_settings.sql`
- `supabase/migrations/20260708124853_staff_audit_log_ip_hash_and_get_reads.sql`
- `supabase/migrations/20260708140000_add_atlas_multipage_skeleton.sql`
- `supabase/migrations/20260708160000_remove_thread_sheet_skeletons.sql`
- `supabase/migrations/20260709041138_add_one_skeleton_id.sql`
- `supabase/migrations/20260709042716_create_content_popularity_scores.sql`
- `supabase/migrations/20260709042911_create_item_views.sql`
- `supabase/migrations/20260709064322_migrate_retired_font_slugs_one.sql`
- `supabase/migrations/20260710120000_add_section_views_duration_ms.sql`
- `supabase/migrations/CONVENTIONS.md`, `docs/migration-guidelines.md` (cross-referenced for convention compliance)

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 11 complete
- P3 Low: 0 of 2 complete

---

## P2 — Should fix

- [ ] **#SCHEMA-1** · P2 — `site.content_selection` and `site.workplaces` are tenant-data tables in `site.*` with RLS never enabled
    - **Where:** supabase/migrations/20260705150200_create_content_selection.sql (whole file); supabase/migrations/20260701150000_create_workplaces.sql (whole file)
    - **Affects:** Defense-in-depth for content-selection and workplace-card rows; both schemas (`site`) are exposed to PostgREST per `supabase/config.toml` (`schemas = ["public", "graphql_public", "core", "site", "analytics", "notifications"]`), so a future GRANT to `anon`/`authenticated` would expose these tables with zero RLS backstop
    - **Effort:** S (~0.5–1h) — two short migrations
    - **What to do:**
        - Add `ALTER TABLE site.content_selection ENABLE ROW LEVEL SECURITY; ... FORCE ROW LEVEL SECURITY;` plus an `app_backend` FOR ALL policy, matching the pattern the same author used one day earlier in `20260704160000_shop_brands_products.sql` (`shop_brands_app_backend_all` / `shop_products_app_backend_all`).
        - Same for `site.workplaces`.
        - Add a regression-guard test in `tests/Feature/Security/` (mirror `DesignKitsRlsTest.php`) so this can't silently regress again — there is currently no sweep test covering `site.*` RLS coverage generally (only `DesignKitsRlsTest` and `ModerationSchemaRlsTest` exist as targeted exemplars).
    - **Technical:** Both tables' migrations explicitly document RLS-off as a deliberate choice ("access is gated in the Laravel policy layer... not at the DB"), and today only `app_backend` (which carries `BYPASSRLS`) holds any grant, so there is no live exposure. But this is the exact category-1 gap the lens targets: `site`/`core`/`analytics`/`notifications` are all in the PostgREST-exposed schema list, and the very next migration in this window (`20260704160000_shop_brands_products.sql`, ~1 day before `content_selection`) shows the author using the full `ENABLE + FORCE + CREATE POLICY` pattern for two new tables in the same PR family — so the rigor exists in the codebase, it just wasn't applied consistently to `content_selection`/`workplaces`. Treat this as hardening: a future grant change (staff dashboard read, PostgREST direct client) would otherwise have zero RLS backstop.
    - **Plain English:** Two tables that store a user's chosen background content and their workplace/business card info have no database-level lock on who can read them — protection currently comes entirely from the app's permission checks, with nothing backing it up at the database layer. Right now that's fine because only the trusted backend connects to these tables, but if a future feature ever grants broader database access (e.g. a staff dashboard reading straight from Supabase), there'd be no safety net. Other tables built in the same week already have this safety net; these two don't.
    - **Evidence:**
        ```sql
        -- position is 1..15, unique per site. RLS is OFF to match the sibling 1:1
        -- app-managed table site.workplaces — access is gated in the Laravel policy
        -- layer (ContentSelectionPolicy), not at the DB. app_backend gets the standard
        -- CRUD grant. media_id ON DELETE CASCADE drops an entry if its upload is HARD
        -- deleted; soft-deletes are reconciled in the app (unselect != delete upload).
        CREATE TABLE IF NOT EXISTS site.content_selection (
        ```

- [ ] **#SCHEMA-2** · P2 — `analytics.content_popularity_scores` enables RLS but adds no `FORCE` and no policies
    - **Where:** supabase/migrations/20260709042716_create_content_popularity_scores.sql:24-26
    - **Affects:** Defense-in-depth for site popularity-ranking data (the actively-developed "trending content" scoring feature)
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `ALTER TABLE analytics.content_popularity_scores FORCE ROW LEVEL SECURITY;` and an `app_backend` `FOR ALL ... USING (true) WITH CHECK (true)` policy.
        - Mirror the pattern the same author already used 5 days earlier in the same migration family — `20260704160000_shop_brands_products.sql` (`ALTER TABLE site.shop_brands ENABLE ROW LEVEL SECURITY; ... FORCE ROW LEVEL SECURITY; CREATE POLICY shop_brands_app_backend_all ...`) — and the richer precedent in the baseline's `analytics.section_views` (owner-select + staff-select + service-role-all policies).
        - Add a regression test mirroring `DesignKitsRlsTest.php`.
    - **Technical:** `ENABLE ROW LEVEL SECURITY` with zero `CREATE POLICY` statements already default-denies non-bypassing roles, so there's no live gap today (only `app_backend`, which is `BYPASSRLS`, is granted). The finding is that this migration diverges from the codebase's own established rigor for exactly this shape of table: `analytics.section_views` (baseline) and `site.shop_brands`/`site.shop_products` (this same author, `20260704160000`, five days prior) both pair `ENABLE` with `FORCE` and explicit policies. `content_popularity_scores` and its sibling `item_views` (SCHEMA-3) dropped that second half of the pattern.
    - **Plain English:** This table stores which content is "trending" per site. It has a security switch turned on, but the second half of the setup — locking out the table's owner account and writing the actual access rules — was skipped, unlike a sibling table built five days earlier in the same batch of work. It's not exploitable today, but it's an inconsistency worth closing before more code starts relying on this table.
    - **Evidence:**
        ```sql
        ALTER TABLE analytics.content_popularity_scores ENABLE ROW LEVEL SECURITY;

        GRANT SELECT, INSERT, UPDATE, DELETE ON analytics.content_popularity_scores TO app_backend;
        ```

- [ ] **#SCHEMA-3** · P2 — `analytics.item_views` enables RLS but adds no `FORCE` and no policies
    - **Where:** supabase/migrations/20260709042911_create_item_views.sql:36-38
    - **Affects:** Same gap as SCHEMA-2; item-level impression events feeding the popularity scoring job
    - **Effort:** S (~0.5–1h)
    - **What to do:** Same as SCHEMA-2 — add `FORCE ROW LEVEL SECURITY` + `app_backend` policy + regression test.
    - **Technical:** Identical gap and identical precedent-deviation as SCHEMA-2 (same author, same day, same migration pair). Both tables should be fixed together since they share one root cause and one likely migration file.
    - **Plain English:** Same issue as the popularity-scores table — the alarm is installed but not armed, and no rules were written for it, unlike the sibling table built five days earlier.
    - **Evidence:**
        ```sql
        ALTER TABLE analytics.item_views ENABLE ROW LEVEL SECURITY;

        GRANT SELECT, INSERT, UPDATE, DELETE ON analytics.item_views TO app_backend;
        ```

- [ ] **#SCHEMA-4** · P2 — `analytics.content_popularity_scores.site_id` has no foreign key to `site.sites(id)`
    - **Where:** supabase/migrations/20260709042716_create_content_popularity_scores.sql:7-16
    - **Affects:** Referential integrity of popularity-rank rows; orphan scores survive a hard-deleted site with no cleanup path
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - `ALTER TABLE analytics.content_popularity_scores ADD CONSTRAINT content_popularity_scores_site_fk FOREIGN KEY (site_id) REFERENCES site.sites(id) ON DELETE CASCADE NOT VALID;` then `VALIDATE CONSTRAINT` in a separate statement per `CONVENTIONS.md §4`.
    - **Technical:** The `Site::belongsTo`-style relation the model implies assumes referential integrity, but the DDL has no `REFERENCES` clause. This is a real deviation, not a deliberate ingest-throughput tradeoff: the sibling analytics table created in the exact same baseline (`analytics.section_views`) and the same-week `analytics.site_sessions` (`20260610000000_analytics_v2_clicks_sessions.sql`) both declare `site_id uuid NOT NULL` **with** `REFERENCES site.sites(id) ON DELETE CASCADE` — establishing FK-backed `site_id` as the house pattern for exactly this table shape. `content_popularity_scores` and `item_views` (SCHEMA-5) both skip it.
    - **Plain English:** Every popularity-ranking row claims to belong to a specific website, but the database never checks that the website still exists. Every comparable table in this codebase does enforce that link — these two don't, so a permanently deleted website could leave orphaned ranking data behind with nothing to clean it up.
    - **Evidence:**
        ```sql
        CREATE TABLE IF NOT EXISTS analytics.content_popularity_scores (
            id           uuid PRIMARY KEY DEFAULT gen_random_uuid(),
            site_id      uuid NOT NULL,
            content_type text NOT NULL,   -- page|shop_product|menu_item|menu_category|service|block|gallery_item|engine_item
        ```

- [ ] **#SCHEMA-5** · P2 — `analytics.item_views.site_id` has no foreign key to `site.sites(id)`
    - **Where:** supabase/migrations/20260709042911_create_item_views.sql:9-26
    - **Affects:** Same referential-integrity gap as SCHEMA-4; item-impression rows
    - **Effort:** S (~0.5–1h)
    - **What to do:** Same fix as SCHEMA-4 — `NOT VALID` FK + `VALIDATE CONSTRAINT` split, `ON DELETE CASCADE`.
    - **Technical:** Same deviation from the `section_views`/`site_sessions` FK precedent described in SCHEMA-4. Bundle the two FK additions together — same migration shape, same root cause.
    - **Plain English:** Same issue as SCHEMA-4 for the impression-events table — no database check that the website an impression is attributed to actually still exists.
    - **Evidence:**
        ```sql
        CREATE TABLE IF NOT EXISTS analytics.item_views (
            id           uuid PRIMARY KEY DEFAULT gen_random_uuid(),
            user_id      uuid,             -- site owner (denormalized; populated by controller, nullable = fail-open)
            site_id      uuid NOT NULL,
            item_type    text NOT NULL,    -- shop_product|menu_item|menu_category|service|block|gallery_item|engine_item
        ```

- [ ] **#SCHEMA-6** · P2 — `site.shop_brands.selection_mode` and `.link_mode` are enum-like `text` columns with no CHECK constraint
    - **Where:** supabase/migrations/20260707030000_shop_brand_modes.sql:16-22
    - **Affects:** Data integrity of per-brand shop display configuration; a bad value from a direct write (reconcile job, operator fix, future migration) would silently corrupt the public shop payload
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - `CHECK (selection_mode IN ('manual', 'latest'))` and `CHECK (link_mode IN ('product', 'checkout'))`, each `NOT VALID` → `VALIDATE CONSTRAINT`.
        - `referral_query` is correctly left unconstrained (free text).
    - **Technical:** The migration's own comment states "Values validated in the request layer... no CHECK constraints, matching the SQLite-test-mirror convention" — but this directly contradicts the project's own documented Postgres-vs-SQLite drift risk (CLAUDE.md: *"Postgres CHECK constraints aren't enforced at all [in SQLite tests]... this bit the async Instagram connect twice"*) and the codebase's own established pattern for enum-like columns on `site.sites`: `Site::BOOKING_MODES` is explicitly described as mirroring a real `sites_booking_mode_check` DB CHECK constraint. `selection_mode`/`link_mode` (and `shop_link_mode` in SCHEMA-7) are the only enum-like site/shop columns in this window that opted out of the CHECK pattern the rest of the codebase uses (see also `content_selection.entry_type`, `20260705150200`, which does add a CHECK despite equally having Form Request validation).
    - **Plain English:** Two settings that control how a connected online store displays on a professional's page — "pick products manually vs. auto-track newest" and "link to product page vs. straight to checkout" — only have two valid values each, but the database will silently accept anything. The app's forms already check this, but if anyone ever writes to the database directly (a bug fix, a data migration), a typo could break how that store's items display, and nothing in the database would catch it.
    - **Evidence:**
        ```sql
        -- Values validated in the request layer (UpdateShopBrandRequest) — no CHECK
        -- constraints, matching the SQLite-test-mirror convention.

        ALTER TABLE site.shop_brands
            ADD COLUMN IF NOT EXISTS selection_mode text NOT NULL DEFAULT 'manual',
            ADD COLUMN IF NOT EXISTS link_mode text NOT NULL DEFAULT 'product',
            ADD COLUMN IF NOT EXISTS referral_query text NOT NULL DEFAULT '';
        ```

- [ ] **#SCHEMA-7** · P2 — `site.sites.shop_link_mode` is an enum-like `text` column with no CHECK constraint
    - **Where:** supabase/migrations/20260708120000_sites_shop_global_settings.sql:27-29
    - **Affects:** Same data-integrity gap as SCHEMA-6, at the global (all-stores) setting level
    - **Effort:** S (~0.5–1h)
    - **What to do:** `CHECK (shop_link_mode IN ('checkout', 'product'))`, `NOT VALID` → `VALIDATE`. `shop_auto_latest` (boolean) is correctly unconstrained.
    - **Technical:** Same root cause and same explicit "matching the SQLite-test-mirror convention" opt-out as SCHEMA-6 — worth fixing in the same session since it's the same table (`site.sites`) as the `booking_mode` CHECK that already exists there (`Site::BOOKING_MODES` docblock: *"mirrors the `sites_booking_mode_check` DB CHECK constraint"*). Two enum columns on the same table with two different constraint postures is the concrete inconsistency to close.
    - **Plain English:** The site-wide "how do product links behave" setting has the same gap as SCHEMA-6 — only two valid values, no database-level check. It sits on the very same table (`sites`) as another setting (`booking_mode`) that already has this protection, so the fix brings the table back to one consistent standard.
    - **Evidence:**
        ```sql
        -- Values validated in the request layer (shop settings request) — no CHECK
        -- constraint, matching the SQLite-test-mirror convention used by the sibling
        -- shop_brand_modes migration.

        ALTER TABLE site.sites
            ADD COLUMN IF NOT EXISTS shop_link_mode  text    NOT NULL DEFAULT 'checkout',
            ADD COLUMN IF NOT EXISTS shop_auto_latest boolean NOT NULL DEFAULT true;
        ```

- [ ] **#SCHEMA-8** · P2 — `analytics.item_views` has no DB-level dedup key; relies entirely on app-side Redis with a documented fail-open path
    - **Where:** supabase/migrations/20260709042911_create_item_views.sql (full table DDL); app/Services/Analytics/AnalyticsDedupGuard.php:17-18, 36-46
    - **Affects:** Popularity-score accuracy on Redis fault (crash, eviction under memory pressure, failover) — a re-delivered impression beacon would double-count
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a DB-level dedup key (e.g. `UNIQUE (site_id, item_type, item_id, session_id)` with a partial-uniqueness window, or an app-minted `idempotency_key uuid UNIQUE` with `ON CONFLICT DO NOTHING` on insert) so a Redis outage degrades to "no-op duplicate insert" instead of a duplicated scoring row.
        - This mirrors an existing gap on `analytics.section_views` (same `AnalyticsDedupGuard`, same fail-open design) — fixing `item_views` first is fine, but note the identical gap exists upstream if a future audit touches `section_views`.
    - **Technical:** `AnalyticsDedupGuard::claim()` is explicitly fail-open: *"any cache fault is swallowed and treated as novel, so a Redis blip degrades to a possible duplicate rather than a dropped beacon or a 500."* This is a deliberate, documented tradeoff (matches `section_views`'s identical pattern), and sustained faults do escalate to Nightwatch via `EscalatesRepeatedFaults`, bounding the blast radius to "a burst of duplicates during an outage window," not silent indefinite drift. That mitigation is why this stays P2 (hardening) rather than P1 — the risk is real but requires an actual Redis outage, which is both rare and already observable.
    - **Plain English:** To avoid counting the same page view twice, the system uses a short-term memory cache as a "have I seen this before?" check. If that cache has a hiccup — a crash, a memory squeeze, a failover — the code is deliberately built to let the event through rather than lose it, which means a duplicate could sneak into the popularity rankings. The team does get alerted if this cache stays broken for a while, so it wouldn't go unnoticed for long, but there's no permanent database-level backstop the way there could be.
    - **Evidence:**
        ```php
        // Fail-open: any cache fault is swallowed and treated as novel, so a Redis blip
        // degrades to a possible duplicate rather than a dropped beacon or a 500.
        class AnalyticsDedupGuard
        ```

- [ ] **#SCHEMA-9** · P2 — Three `site.sites.skeleton_id` CHECK-swap migrations drop the `BEGIN`/`COMMIT` wrapper every prior sibling migration used, leaving a brief unconstrained window
    - **Where:** supabase/migrations/20260708160000_remove_thread_sheet_skeletons.sql:5-12 (highest-risk instance — narrows the enum + remaps data); also supabase/migrations/20260708140000_add_atlas_multipage_skeleton.sql:11-16 and supabase/migrations/20260709041138_add_one_skeleton_id.sql:12-18 (both widen-only, lower risk, same missing wrapper)
    - **Affects:** `site.sites.skeleton_id` enum enforcement during the DDL window of a deploy; not a current runtime issue for existing rows
    - **Effort:** S (~0.5–1h) — process/lint fix for future migrations; the three already-applied files cannot be meaningfully "fixed" retroactively (per `docs/migration-guidelines.md`, editing an already-applied migration's SQL has no effect on environments that already ran it)
    - **What to do:**
        - For any *future* skeleton_id (or similar CHECK-narrowing) migration, wrap `DROP CONSTRAINT` + any remap `UPDATE` + `ADD CONSTRAINT ... NOT VALID` in a single `BEGIN`/`COMMIT`, exactly as `20260707030000_rename_skeleton_ids.sql`, `20260707080000_skeletons_sheet_thread.sql`, and `20260707120000_rename_skeleton_ids_bento_class.sql` all correctly do; keep `VALIDATE CONSTRAINT` outside the transaction as its own step.
        - Consider adding this to the `guard:no-unsafe-migrations` CI lint referenced in `CONVENTIONS.md` (it already catches the three most dangerous violations; "DROP CONSTRAINT not immediately followed by ADD CONSTRAINT in the same transaction" would be a natural fourth check).
    - **Technical:** `20260707030000`, `20260707080000`, and `20260707120000` all correctly wrap `DROP CONSTRAINT` + `ADD CONSTRAINT ... NOT VALID` in one `BEGIN`/`COMMIT` block (with `VALIDATE` run separately afterward) — this is the two-step pattern `CONVENTIONS.md §2` documents. `20260708140000_add_atlas_multipage_skeleton.sql`, `20260708160000_remove_thread_sheet_skeletons.sql`, and `20260709041138_add_one_skeleton_id.sql` (the next three skeleton_id migrations, all landing within the following 36 hours — see `d8cf5aa3 one(prebuild/p1): 'one' skeleton enum + SitepageId page taxonomy`) drop the transaction wrapper despite their own comments still citing "Two-step CHECK swap per CONVENTIONS.md §2 and the prior skeleton_id CHECK migrations" as the pattern being followed. Without `BEGIN`/`COMMIT`, `DROP CONSTRAINT` auto-commits as its own statement — if the following `ADD CONSTRAINT` (or, in the `remove_thread_sheet` case, the preceding remap `UPDATE`) fails or the migration is interrupted between statements, `site.sites.skeleton_id` is left with **no CHECK constraint at all** until the next migration run, and any concurrent write during that gap could persist an arbitrary string. `remove_thread_sheet_skeletons.sql` is the highest-risk instance because it also narrows the enum (removing `'sheet'`/`'thread'`) rather than only widening it, and its own comment confirms it was "Applied live to the dev DB... 2026-07-08."
    - **Plain English:** When this codebase changes the list of allowed website-layout types, the safe pattern is to bundle "remove the old rule" and "add the new rule" into one atomic step, so the database is never left with zero rules in between. Three migrations in a row — all citing that exact safe pattern in their own comments — accidentally skipped the bundling step. In practice the risk window is a fraction of a second during a rare manual deploy step, but it's a real gap between what the comments say and what the code does, and the pattern should be restored for the next migration that touches this column.
    - **Evidence:**
        ```sql
        -- Remove the thread + sheet skeletons (#78 — user directive, "dumb, don't want
        -- them"). Remap any site on them to deck (the redesign's directional anchor)
        -- first, then narrow the CHECK to the 5 kept skeletons (bento/dock/flick/deck/atlas).
        -- Applied live to the dev DB (glncumufgaqcmqhzwrxm) 2026-07-08.
        UPDATE site.sites SET skeleton_id = 'deck' WHERE skeleton_id IN ('thread', 'sheet');

        ALTER TABLE site.sites DROP CONSTRAINT IF EXISTS sites_skeleton_id_check;

        ALTER TABLE site.sites ADD CONSTRAINT sites_skeleton_id_check
            CHECK (skeleton_id IN ('bento', 'dock', 'flick', 'deck', 'atlas')) NOT VALID;
        ```

- [ ] **#SCHEMA-10** · P2 — `analytics.content_popularity_scores.content_type` has no CHECK constraint, and the table's own documented taxonomy is already stale
    - **Where:** supabase/migrations/20260709042716_create_content_popularity_scores.sql:10, 18-19; app/Console/Commands/ComputeContentPopularityScores.php:83-108
    - **Affects:** Data quality of the popularity leaderboard; a bad `content_type` string would corrupt ranking aggregation
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a CHECK constraint (`NOT VALID` → `VALIDATE`), but source the value list from the current code, not the migration's own comment — the comment is already out of date (see Technical).
        - Current valid set per `ComputeContentPopularityScores::CLICK_SECTION_TO_ITEM_TYPE` + the `'page'` grain: `'page', 'shop_product', 'menu_item', 'menu_category', 'service', 'block', 'gallery_item', 'engine_item', 'listen_item', 'watch_item', 'link_item'`.
        - Update the table/column comment in the same migration to match.
    - **Technical:** The table comment ("`content_type` enumerated in-code... page|shop_product|menu_item|menu_category|service|block|gallery_item|engine_item") lists 8 values, but `ComputeContentPopularityScores::CLICK_SECTION_TO_ITEM_TYPE` (as of commits `2c52169d`/`369f7149`, both landed 2026-07-10 — the same day as this audit) added three more item types — `listen_item`, `watch_item`, `link_item` — for the new ONE-skeleton listen/watch/custom-link scoring. Any CHECK constraint written against the migration's own stale comment (as DeepSeek's draft proposed) would immediately reject valid production writes. This is exactly the kind of drift `CLAUDE.md` warns about for constraint-bound columns — the code changed same-day and the DDL comment didn't follow.
    - **Plain English:** This column records what *kind* of content a popularity score belongs to. The database doesn't enforce a fixed list of valid kinds, and — worse — the comment in the database file that's supposed to document the valid list is already out of date: the app started scoring three new content kinds (music tracks, videos, custom links) the same day as this audit, and the database-side documentation wasn't updated to match. Any fix needs to use today's actual list, not the one written in the file.
    - **Evidence:**
        ```sql
        content_type text NOT NULL,   -- page|shop_product|menu_item|menu_category|service|block|gallery_item|engine_item
        ```
        ```php
        // ONE item scoring by link-out (2026-07-10): listen tracks, watch videos,
        // and custom links score from clicks in their own page's section.
        'listen' => 'listen_item',
        ...
        'custom' => 'link_item',
        'other' => 'link_item',
        ```

- [ ] **#SCHEMA-11** · P2 — `analytics.item_views.item_type` has no CHECK constraint, and the app-side allowlist already exceeds the table's documented taxonomy
    - **Where:** supabase/migrations/20260709042911_create_item_views.sql:13, 28-29; app/Http/Requests/Api/PublicSite/Analytics/ItemSeenRequest.php:33-44
    - **Affects:** Data quality of the impression-events table feeding popularity scoring; the currently-shipping "ONE" listen/watch/link item types
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a CHECK constraint sourced from `ItemSeenRequest::ITEM_TYPES` (the docblock itself calls this constant the source of truth, "kept in lockstep with `ComputeContentPopularityScores` + the DDL comment") — currently 10 values: `'shop_product', 'menu_item', 'menu_category', 'service', 'block', 'gallery_item', 'engine_item', 'listen_item', 'watch_item', 'link_item'`.
        - `NOT VALID` → `VALIDATE`. Update the table comment to match the current list in the same migration.
    - **Technical:** Same root cause as SCHEMA-10, on the sibling ingest table. `ItemSeenRequest::ITEM_TYPES` was widened from 7 to 10 values on 2026-07-10 (commit `2c52169d`/`369f7149`) specifically because the storefront now fires item-seen impressions for the new ONE-theme listen/watch/link items — its own docblock notes *"those three grains only ever accrue click score, never view score"* if not accepted. The table's DDL comment (`item_type per scored-item taxonomy`) predates that change by one day and lists only 7 values. Fix SCHEMA-10 and SCHEMA-11 together — same taxonomy, same drift, same day.
    - **Plain English:** Same issue as SCHEMA-10, but on the table that records individual item views rather than the scoreboard. The app already accepts three new item kinds as of today; the database's documentation of what's valid hasn't caught up. This is the most time-sensitive of the constraint gaps in this audit since it touches a feature that just shipped.
    - **Evidence:**
        ```sql
        item_type    text NOT NULL,    -- shop_product|menu_item|menu_category|service|block|gallery_item|engine_item
        ```
        ```php
        public const ITEM_TYPES = [
            'shop_product',
            'menu_item',
            'menu_category',
            'service',
            'block',
            'gallery_item',
            'engine_item',
            'listen_item',
            'watch_item',
            'link_item',
        ];
        ```

## P3 — Nice to have

- [ ] **#SCHEMA-12** · P3 — Duplicate migration timestamp `20260707030000` shared by two unrelated migrations
    - **Where:** supabase/migrations/20260707030000_rename_skeleton_ids.sql and supabase/migrations/20260707030000_shop_brand_modes.sql
    - **Affects:** Migration ordering determinism only; the two files touch disjoint tables (`site.sites` vs `site.shop_brands`) so lexical suffix ordering (`_rename...` < `_shop_brand...`) is stable and nothing depends on the order today
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Nothing needs re-timestamping retroactively (an edited/renamed already-applied file has no effect on environments that already ran it, and no schema-affecting reason to force a rename here — see `docs/migration-guidelines.md`).
        - Add a lint check (glob `supabase/migrations/*.sql`, assert no two files share a 14-digit timestamp prefix) to `guard:no-unsafe-migrations` or a new CI step so this can't recur.
    - **Technical:** The Supabase migration runner orders by filename; two files sharing a timestamp sort deterministically by suffix today, so this is non-breaking. It's flagged purely because nothing currently prevents a third same-timestamp file from landing with an unpredictable relative order to one of these two — a cheap, mechanical CI guard closes the gap permanently.
    - **Plain English:** Two unrelated database change files were accidentally given the identical sequence number. They happen to affect completely different tables, so nothing is broken today, but it's the kind of coincidence that a simple automated check should catch before a third file collides in a way that actually matters.
    - **Evidence:**
        ```sql
        -- File 1: supabase/migrations/20260707030000_rename_skeleton_ids.sql
        UPDATE site.sites
        SET skeleton_id = CASE skeleton_id
            WHEN 'skeleton-1' THEN 'bento'
        ```
        ```sql
        -- File 2: supabase/migrations/20260707030000_shop_brand_modes.sql
        ALTER TABLE site.shop_brands
            ADD COLUMN IF NOT EXISTS selection_mode text NOT NULL DEFAULT 'manual',
        ```

- [ ] **#SCHEMA-13** · P3 — Inline INSERT/UPDATE data backfill inside `20260704160000_shop_brands_products.sql`'s `BEGIN`/`COMMIT` block
    - **Where:** supabase/migrations/20260704160000_shop_brands_products.sql:57-103
    - **Affects:** Deployment safety pattern for `site.platform_connections` at the time this migration ran; already applied, so no forward-looking risk to the current dev/prod databases from this specific file
    - **Effort:** S (~0.5–1h) — documentation/lint value only; the file itself cannot be meaningfully "fixed" retroactively
    - **What to do:**
        - No action needed on this already-applied file — per `docs/migration-guidelines.md`, editing SQL of an already-applied migration doesn't re-run it on environments that already have it, so a rewrite wouldn't change any real database's history, and a truly fresh apply of this file scans an empty `platform_connections` table (no rows yet), so the lock-duration risk doesn't reproduce on a fresh baseline either.
        - Treat this as a pattern reminder: the next migration doing a real data reshape on a populated table should split DDL and backfill into two files per `CONVENTIONS.md §5`, exactly as this project's own `docs/migration-guidelines.md` "Full-table-scan data scrubs" section (written specifically to codify this rule) already prescribes.
    - **Technical:** The migration performs `CREATE TABLE` + RLS setup + a CTE-based `INSERT ... SELECT` (with a `CROSS JOIN LATERAL jsonb_each`) + a follow-up `UPDATE` on `site.platform_connections`, all inside one `BEGIN`/`COMMIT`. This is precisely the anti-pattern `CONVENTIONS.md §5` labels "BAD" ("migration holds `ACCESS EXCLUSIVE` while updating... rows"). At the pre-beta row counts this ran against, the practical impact was negligible, and — per `docs/migration-guidelines.md`'s "Editing already-applied migrations" section — there is no way to retroactively make this file's execution safer for the environments it already ran on. Kept at P3 as a documented, non-actionable pattern violation rather than a live risk.
    - **Plain English:** This one-time data-reshuffling script combined "create the new tables" and "copy the old data into them" into a single all-or-nothing step, which the project's own rulebook says to avoid on tables with real traffic. It already ran successfully at a very small scale, so there's nothing left to fix in this specific file — it's flagged so the same mistake isn't repeated on a bigger table next time.
    - **Evidence:**
        ```sql
        WITH ins_brands AS (
            INSERT INTO site.shop_brands
                (connection_id, brand_id, provider, url, source_url, name, currency,
                 favicon, logo, discount_code, fetch_mode, is_individual, position,
                 style_analysis, created_at, updated_at)
            SELECT pc.id,
                   b.key,
        ...
        UPDATE site.platform_connections
        SET payload = '{"storage":"relational"}'::jsonb
        WHERE platform = 'shop'
          AND deleted_at IS NULL
          AND (payload->>'storage') IS DISTINCT FROM 'relational';

        COMMIT;
        ```

## Suggested Bundled Sessions

None — every finding in this audit is a database schema/migration change (RLS policy, CHECK constraint, foreign key, or migration-transaction correctness), which the fix-flow policy always runs standalone with individual sign-off.

## Standalone — do NOT bundle

- **#SCHEMA-1 — content_selection + workplaces RLS off** · DB migration/schema change (RLS policy addition on two tables).
- **#SCHEMA-2 — content_popularity_scores RLS FORCE + policy** · DB migration/schema change.
- **#SCHEMA-3 — item_views RLS FORCE + policy** · DB migration/schema change.
- **#SCHEMA-4 — content_popularity_scores.site_id FK** · DB migration/schema change.
- **#SCHEMA-5 — item_views.site_id FK** · DB migration/schema change.
- **#SCHEMA-6 — shop_brands selection_mode/link_mode CHECK** · DB migration/schema change.
- **#SCHEMA-7 — sites.shop_link_mode CHECK** · DB migration/schema change.
- **#SCHEMA-8 — item_views dedup key** · DB migration/schema change.
- **#SCHEMA-9 — skeleton_id CHECK-swap transaction wrapper** · DB migration/schema change (and CI lint change).
- **#SCHEMA-10 — content_popularity_scores.content_type CHECK + stale taxonomy** · DB migration/schema change.
- **#SCHEMA-11 — item_views.item_type CHECK + stale taxonomy** · DB migration/schema change.
- **#SCHEMA-12 — duplicate migration timestamp** · DB migration/schema change (CI lint addition).
- **#SCHEMA-13 — shop_brands_products inline backfill** · DB migration/schema change (documentation-only, but still migration-pattern scope).
