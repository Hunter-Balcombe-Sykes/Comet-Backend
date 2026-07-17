# Schema / RLS / search_path Audit — 2026-07-11

**Branch:** HEAD
**Lens:** Schema / RLS / search_path: database-side correctness, constraint coverage, migration safety
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4.5`
**Source files audited:**
- `supabase/migrations/20260701150000_create_workplaces.sql`
- `supabase/migrations/20260704160000_shop_brands_products.sql`
- `supabase/migrations/20260705150000_workplaces_identity_columns.sql`
- `supabase/migrations/20260705150200_create_content_selection.sql`
- `supabase/migrations/20260707030000_shop_brand_modes.sql`
- `supabase/migrations/20260708120000_sites_shop_global_settings.sql`
- `supabase/migrations/20260709042716_create_content_popularity_scores.sql`
- `supabase/migrations/20260709042911_create_item_views.sql`
- `supabase/migrations/20260710140000_rls_policies_new_tables.sql`
- `supabase/migrations/20260711000000_staff_account_type.sql`
- `supabase/migrations/20260711000100_user_segments.sql`
- `supabase/migrations/20260711000200_feature_availability.sql`
- `supabase/migrations/20260711000300_early_access_signups.sql`
- `supabase/migrations/20260711153000_feedback_type_area_target.sql`
- `supabase/migrations/20260711160000_analytics_force_rls_parity.sql`
- `app/Models/Analytics/ItemView.php`
- `app/Models/Core/Segments/UserSegmentMember.php`
- `app/Models/Core/Site/ShopBrand.php`
- `app/Models/Core/Site/Site.php`
- `app/Console/Commands/PurgeRawAnalyticsEvents.php`
- `scripts/guard-no-unsafe-migrations.php`

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 5 complete
- P3 Low: 0 of 8 complete

---

## P2 — Should fix

- [ ] **#SCHEMA-1** · P2 — `analytics.item_views.item_type` has a documented 7-value taxonomy but no `CHECK` constraint
    - **Where:** supabase/migrations/20260709042911_create_item_views.sql:13
    - **Affects:** Data integrity for popularity scoring — a mistyped `item_type` from the item-seen telemetry endpoint silently pollutes `analytics:compute-popularity` input.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - `ALTER TABLE analytics.item_views ADD CONSTRAINT item_views_item_type_check CHECK (item_type IN ('shop_product','menu_item','menu_category','service','block','gallery_item','engine_item')) NOT VALID;` then `VALIDATE CONSTRAINT` in a follow-up migration (`scripts/guard-no-unsafe-migrations.php` Check 3 requires the split).
    - **Technical:** The column comment documents the exact taxonomy inline but nothing enforces it at the DB layer. The canonical pattern for exactly this shape is `site.site_media.pool CHECK (pool IN (...))`, added `NOT VALID` then validated. `core.users.account_type` (`20260711000000_staff_account_type.sql`) follows the identical two-step dance for the same reason. The guard script (`scripts/guard-no-unsafe-migrations.php`, Check 3) requires `NOT VALID` + a separate `VALIDATE CONSTRAINT` migration for any new `CHECK` — that's a real but small cost, not a reason to skip the constraint entirely.
    - **Plain English:** The analytics pipeline records "what kind of thing was viewed" — a product, a menu item, a gallery image, etc. There are exactly seven valid kinds, documented right in the code, but the database doesn't enforce that list. A typo from the frontend (like `'shopProduct'` instead of `'shop_product'`) would write bad data forever into the popularity scores. A one-line rule closes that gap.
    - **Evidence:**
        ```sql
        item_type    text NOT NULL,    -- shop_product|menu_item|menu_category|service|block|gallery_item|engine_item
        ```

- [ ] **#SCHEMA-2** · P2 — `analytics.content_popularity_scores.content_type` has a documented 8-value taxonomy but no `CHECK` constraint
    - **Where:** supabase/migrations/20260709042716_create_content_popularity_scores.sql:10
    - **Affects:** The read-side popularity table the public payload builder (`IndividualProfilePayloadBuilder`) and `RankedActionsComputer` read directly — a bad `content_type` from a buggy upsert in `analytics:compute-popularity` surfaces wrong ranks in the sitepage payload.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - `ALTER TABLE analytics.content_popularity_scores ADD CONSTRAINT content_popularity_scores_content_type_check CHECK (content_type IN ('page','shop_product','menu_item','menu_category','service','block','gallery_item','engine_item')) NOT VALID;` then `VALIDATE CONSTRAINT`.
    - **Technical:** Same taxonomy as `item_views.item_type` plus `'page'`. Unlike `item_views`, this table has no purge job coverage (see `#SCHEMA-3` below) so a bad row here is effectively permanent, making the constraint slightly higher-value than its `item_views` sibling. Same canonical `NOT VALID` + `VALIDATE` pattern applies.
    - **Plain English:** The popularity leaderboard table — "which products/menu items/images are most popular" — has a column for "what kind of content this score is for." There are eight valid kinds, but the database doesn't enforce that list. One bug in the nightly scoring job and the leaderboard could return nonsense that's read directly by the live sitepage.
    - **Evidence:**
        ```sql
        content_type text NOT NULL,   -- page|shop_product|menu_item|menu_category|service|block|gallery_item|engine_item
        ```

- [ ] **#SCHEMA-3** · P2 — `analytics.item_views` and `analytics.content_popularity_scores` have no foreign key on `site_id` (or `user_id`), unlike their sibling `analytics.section_views` — orphan rows never clean up after site deletion
    - **Where:** supabase/migrations/20260709042911_create_item_views.sql:10-12; supabase/migrations/20260709042716_create_content_popularity_scores.sql:9
    - **Affects:** `AccountDeletionService::forceDelete()` relies on `ON DELETE CASCADE` FK chains to clean up user/site-linked rows (per its own comments: "user_id-linked rows are cascade-deleted by forceDelete via FK ON DELETE CASCADE"). Neither table has that FK, so a force-deleted site/user leaves permanently dangling `site_id`/`user_id` values. `analytics.item_views` self-heals via the 90-day `partna:analytics:purge-raw-events` retention purge; `analytics.content_popularity_scores` is **not** in that command's table list at all, so its orphan rows persist forever.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add `site_id uuid REFERENCES site.sites(id) ON DELETE CASCADE` (and `user_id uuid REFERENCES core.users(id) ON DELETE CASCADE` on `item_views`) via `ADD CONSTRAINT ... FOREIGN KEY ... NOT VALID` + `VALIDATE CONSTRAINT` (guard script Check 2).
        - Either add `analytics.content_popularity_scores` to `PurgeRawAnalyticsEvents::TABLES` keyed on `computed_at`, or rely purely on the new `ON DELETE CASCADE` for cleanup (cascade is sufficient once the FK exists).
    - **Technical:** The direct sibling table `analytics.section_views` (same author, same file family, explicitly named as the pattern `item_views` "mirrors") declares `CONSTRAINT section_views_site_fk FOREIGN KEY (site_id) REFERENCES site.sites(id) ON DELETE CASCADE` and an equivalent `professional_id` FK in the baseline (`20260526000000_baseline_standalone_user.sql:1235`). `item_views` and `content_popularity_scores` — both created 2026-07-09, well after the FK-safety convention (`GRANDFATHERED_CUTOFF = 20260514100000`) — dropped that FK entirely rather than just its `NOT VALID` qualifier. This is a real regression from the established sibling pattern, not a deliberate simplification (nothing in either migration's header explains the omission).
    - **Plain English:** When someone deletes their account, most of their data is cleaned up automatically because the database is told "when the parent row goes, delete the children too." These two newer analytics tables never got that instruction, so a deleted user's item-view and popularity-score rows just sit there forever, unlinked to anyone. It's not a live data leak (site IDs aren't reused), but it's clutter the database was supposed to clean up and never will.
    - **Evidence:**
        ```sql
        CREATE TABLE IF NOT EXISTS analytics.item_views (
            id           uuid PRIMARY KEY DEFAULT gen_random_uuid(),
            user_id      uuid,             -- site owner (denormalized; populated by controller, nullable = fail-open)
            site_id      uuid NOT NULL,
        ```
        ```sql
        CREATE TABLE IF NOT EXISTS analytics.content_popularity_scores (
            id           uuid PRIMARY KEY DEFAULT gen_random_uuid(),
            site_id      uuid NOT NULL,
        ```

- [ ] **#SCHEMA-4** · P2 — `site.shop_brands.selection_mode` and `.link_mode` are two-value enum columns without a `CHECK` constraint
    - **Where:** supabase/migrations/20260707030000_shop_brand_modes.sql:19-22
    - **Affects:** Data integrity for per-brand store rendering — a raw INSERT or direct DB fix could write an invalid `selection_mode`/`link_mode`; `UpdateShopBrandRequest` is the only guard today.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - `ADD CONSTRAINT shop_brands_selection_mode_check CHECK (selection_mode IN ('manual','latest')) NOT VALID` + `VALIDATE`.
        - `ADD CONSTRAINT shop_brands_link_mode_check CHECK (link_mode IN ('product','checkout')) NOT VALID` + `VALIDATE`.
    - **Technical:** The migration's own comment says "no CHECK constraints, matching the SQLite-test-mirror convention" — but this doesn't hold up: `site.sites.booking_mode` (same schema, same table family) *does* carry a DB `CHECK` (`sites_booking_mode_check`, referenced directly in `App\Models\Core\Site\Site::BOOKING_MODES`'s docblock) despite the identical SQLite-test-mirror concern, and the app-side `Site::SHOP_LINK_MODES`/`ShopBrand`'s two modes are exactly the shape the canonical `site.site_media.pool` `CHECK` pattern targets. The guard script's `NOT VALID`+`VALIDATE` requirement is the documented safe path, not a reason to omit the constraint.
    - **Plain English:** Two dropdown columns — each with only two valid choices — are stored as free text with no database-level "only these values" rule, even though a sibling column on the same table (`booking_mode`) already has exactly this kind of rule. A typo written directly to the database would be accepted silently. The fix is a one-line guardrail.
    - **Evidence:**
        ```sql
        ALTER TABLE site.shop_brands
            ADD COLUMN IF NOT EXISTS selection_mode text NOT NULL DEFAULT 'manual',
            ADD COLUMN IF NOT EXISTS link_mode text NOT NULL DEFAULT 'product',
            ADD COLUMN IF NOT EXISTS referral_query text NOT NULL DEFAULT '';
        ```

- [ ] **#SCHEMA-5** · P2 — `site.sites.shop_link_mode` is a two-value GLOBAL enum column without a `CHECK` constraint, unlike the sibling `booking_mode` column on the same table
    - **Where:** supabase/migrations/20260708120000_sites_shop_global_settings.sql:28
    - **Affects:** Every connected store on every site — `shop_link_mode` is the single global switch the public payload builder (`PublicIntegrationConnectionResource`) stamps onto every brand's `linkMode` at read time. An invalid value here has the largest blast radius of any finding in this audit (site-wide, not per-brand).
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - `ADD CONSTRAINT sites_shop_link_mode_check CHECK (shop_link_mode IN ('checkout','product')) NOT VALID` + `VALIDATE CONSTRAINT` in a follow-up migration.
    - **Technical:** `Site::SHOP_LINK_MODES = ['checkout', 'product']` is the app-side source of truth (`app/Models/Core/Site/Site.php:41`), and the migration comment explicitly cites the same "SQLite-test-mirror convention" rationale as `#SCHEMA-4` to skip it. But `Site::BOOKING_MODES` — a structurally identical two-value enum on the exact same table — *is* backed by a real DB `CHECK` (`sites_booking_mode_check`, per the model's own docblock: "mirrors the sites_booking_mode_check DB CHECK constraint"). There is no principled reason `booking_mode` gets a DB-level guarantee and `shop_link_mode` doesn't; both are small, pre-beta, low-row-count tables where the `NOT VALID`+`VALIDATE` split costs nothing.
    - **Plain English:** The master switch that controls whether every store link on every site goes straight to checkout or to the product page is stored as free text with no database guardrail — even though a nearly identical switch on the very same table (booking mode) already has one. Same fix, applied consistently.
    - **Evidence:**
        ```sql
        ALTER TABLE site.sites
            ADD COLUMN IF NOT EXISTS shop_link_mode  text    NOT NULL DEFAULT 'checkout',
        ```

## P3 — Nice to have

- [ ] **#SCHEMA-6** · P3 — `core.feedback.type` has a 4-value vocabulary but no `CHECK` constraint
    - **Where:** supabase/migrations/20260711153000_feedback_type_area_target.sql:46
    - **Affects:** Internal staff feedback-triage tool only — bad `type` values would confuse the staff list but touch no end-user or public surface.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - `ADD CONSTRAINT feedback_type_check CHECK (type IS NULL OR type IN ('error','good','bad_ui','idea')) NOT VALID` + `VALIDATE`.
    - **Technical:** The migration explicitly documents the 4-value vocabulary and the reasoning for skipping the constraint: the guard script's `NOT VALID`+`VALIDATE` split is "unwarranted complexity for a low-traffic internal tool," and the same table's `page_url`/`user_agent`/`viewport`/`app_version`/`request_id`/`reply_email` columns already carry no CHECK by the same precedent. `SubmitFeedbackRequest` is the enforcement point. This is a defensible, documented judgment call for a nullable column on a staff-only table — lower priority than the P2 findings above where an invalid value reaches a public-facing surface.
    - **Plain English:** The feedback form has four reaction buttons. The database column storing which button was pressed doesn't enforce those four values, so a future frontend bug could write a fifth value and the database would quietly accept it. Low risk (staff-only tool), trivial fix.
    - **Evidence:**
        ```sql
        ALTER TABLE core.feedback
            ADD COLUMN type text NULL,
        ```

- [ ] **#SCHEMA-7** · P3 — `core.user_segment_members` is granted `UPDATE, DELETE` for `app_backend` despite being an insert-only table at the model layer
    - **Where:** supabase/migrations/20260711000100_user_segments.sql:55; app/Models/Core/Segments/UserSegmentMember.php:21
    - **Affects:** Defence-in-depth only — no code path issues UPDATE/DELETE against this table today.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - `REVOKE UPDATE, DELETE ON core.user_segment_members FROM app_backend;` leaving `SELECT, INSERT` (mirrors the `audit.*` schema's SELECT/INSERT-only posture for append-only tables), or explicitly accept the current grant and drop this as a non-issue.
    - **Technical:** `UserSegmentMember` declares `public const UPDATED_AT = null` with the comment "membership rows are insert-only (created_at column only)" and has no `SoftDeletes` trait or delete method anywhere in the model. The migration's `GRANT SELECT, INSERT, UPDATE, DELETE ON core.user_segment_members TO app_backend;` is the standard boilerplate CRUD grant, not a deliberate append-only choice. The `audit.*` schema achieves append-only enforcement at exactly this privilege layer (SELECT/INSERT only) — this table could follow the same pattern, though the practical risk is nil since no application code path attempts UPDATE/DELETE.
    - **Plain English:** The segment-membership table is meant to be write-once — rows are added, never edited or removed by the app. But the database access card still technically allows edits and deletions. Nothing in the app tries to use that door, so this is tidying up a mismatch between the sign on the table and the keys that open it, not a live risk.
    - **Evidence:**
        ```sql
        GRANT SELECT, INSERT, UPDATE, DELETE ON core.user_segment_members TO app_backend;
        ```
        ```php
        public const UPDATED_AT = null; // membership rows are insert-only (created_at column only)
        ```

- [ ] **#SCHEMA-8** · P3 — `analytics.item_views` has no DB-level dedup key, relying entirely on app-side Redis
    - **Where:** supabase/migrations/20260709042911_create_item_views.sql (entire `CREATE TABLE`); app/Models/Analytics/ItemView.php:19
    - **Affects:** Duplicate rows on Redis outage or event re-delivery would inflate popularity scores until the 90-day purge window rolls them off.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - If the team wants DB-level self-healing regardless of Redis state, add a composite `UNIQUE (site_id, session_id, item_type, item_id)` (or narrower, matching the dedup grain) and switch the telemetry writer to `ON CONFLICT DO NOTHING`. Given the deliberate design tradeoff documented below, this can also be accepted as-is.
    - **Technical:** The model comment states plainly: "Dedup is app-side Redis (AnalyticsDedupGuard, 300s), not a DB column — same pattern as section-seen." This is a documented, deliberate tradeoff (a composite unique index adds write amplification to a high-ingest table) shared with the sibling `analytics.section_views` table, not an oversight. The practical exposure is bounded — duplicates only occur during a Redis outage, and rows purge after 90 days regardless.
    - **Plain English:** The "which items were viewed" table relies on a separate fast-cache service to prevent double-counting. If that cache has a hiccup, duplicate views could write directly to the database and briefly inflate popularity scores. Adding a database-level "no duplicates" rule would close that gap but slow down every write slightly — the team already made this tradeoff deliberately for the sibling table, and it's a defensible choice here too.
    - **Evidence:**
        ```php
        // Dedup is app-side Redis (AnalyticsDedupGuard,
        // 300s), not a DB column — same pattern as section-seen.
        ```

- [ ] **#SCHEMA-9** · P3 — `core.user_segments` and `core.user_segment_members` have RLS enabled but not `FORCE ROW LEVEL SECURITY`
    - **Where:** supabase/migrations/20260711000100_user_segments.sql:51-52
    - **Affects:** Forward-looking defence-in-depth only.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - `ALTER TABLE core.user_segments FORCE ROW LEVEL SECURITY;` and `ALTER TABLE core.user_segment_members FORCE ROW LEVEL SECURITY;` in a follow-up migration.
    - **Technical:** Created at timestamp `20260711000100`, before the `20260711160000_analytics_force_rls_parity.sql` hardening sweep landed later the same day. That sweep's own header is the controlling precedent for how this exact class of gap is calibrated in this repo: "the practical security delta here is near-nil today — these tables are owned by `postgres` (a superuser, which bypasses RLS regardless of FORCE) and the app connects as `app_backend` (BYPASSRLS)... This is consistency hygiene / forward-looking defence-in-depth... not a live exposure — re-tiered P3 from the audit's P2." That identical reasoning applies to these two tables, which carry the same owner/connection posture and were simply created a few hours before that sweep's cutoff.
    - **Plain English:** Two new staff-only tables got the first half of the lock installed but not the bolt that stops the table's owner from walking past it. In practice nobody can walk past it today — the owner is a superuser regardless. This is about matching the same pattern the team already applied to sibling tables the same day, so a future ownership change doesn't create a real gap.
    - **Evidence:**
        ```sql
        ALTER TABLE core.user_segments ENABLE ROW LEVEL SECURITY;
        ALTER TABLE core.user_segment_members ENABLE ROW LEVEL SECURITY;
        ```

- [ ] **#SCHEMA-10** · P3 — `core.feature_availability` has RLS enabled but not `FORCE ROW LEVEL SECURITY`
    - **Where:** supabase/migrations/20260711000200_feature_availability.sql:40
    - **Affects:** Same forward-looking defence-in-depth gap as `#SCHEMA-9`; this table controls which features/integrations are gated per segment.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - `ALTER TABLE core.feature_availability FORCE ROW LEVEL SECURITY;` in a follow-up migration.
    - **Technical:** Created at `20260711000200`, same pre-sweep window as `#SCHEMA-9`. Same precedent from `20260711160000_analytics_force_rls_parity.sql` applies — table owner is a superuser, `app_backend` is `BYPASSRLS`, so this is consistency hygiene, not a live exposure.
    - **Plain English:** Same half-installed lock as the segments tables — the feature-gating table needs the same bolt to match every other hardened table in the database.
    - **Evidence:**
        ```sql
        ALTER TABLE core.feature_availability ENABLE ROW LEVEL SECURITY;
        ```

- [ ] **#SCHEMA-11** · P3 — `core.early_access_signups` has RLS enabled but not `FORCE ROW LEVEL SECURITY`
    - **Where:** supabase/migrations/20260711000300_early_access_signups.sql:47
    - **Affects:** Same forward-looking defence-in-depth gap as `#SCHEMA-9`/`#SCHEMA-10`; this table holds PII (email, consent IP hash, invite token hash), slightly raising the stakes of a future ownership-change scenario even though today's exposure is nil.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - `ALTER TABLE core.early_access_signups FORCE ROW LEVEL SECURITY;` in a follow-up migration.
    - **Technical:** Third table in the same pre-sweep batch (`20260711000300`, before `20260711160000`). Same superuser-owner / `BYPASSRLS`-connection precedent applies — no live exposure today, but the PII content makes this the highest-priority item among the three FORCE-only gaps.
    - **Plain English:** The early-access signup list — with emails and invite tokens — has the same half-installed lock as the other two staff tables from the same day. Given it holds people's contact details, it's worth bolting first among this group.
    - **Evidence:**
        ```sql
        ALTER TABLE core.early_access_signups ENABLE ROW LEVEL SECURITY;
        ```

- [ ] **#SCHEMA-12** · P3 — `site.content_selection` has no RLS at all
    - **Where:** supabase/migrations/20260705150200_create_content_selection.sql:13-16
    - **Affects:** Defence-in-depth against PostgREST/Supabase client leakage. `app_backend` carries `BYPASSRLS` for the app path, so exposure is limited to a misconfigured PostgREST role.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add `ENABLE ROW LEVEL SECURITY` + `FORCE ROW LEVEL SECURITY` + owner-read/staff-read/`app_backend`-all policies, mirroring `20260710140000_rls_policies_new_tables.sql`'s shape for `analytics.item_views`.
        - Add a regression-guard test in `tests/Feature/Security/` mirroring `DesignKitsRlsTest`.
    - **Technical:** The migration's own comment frames this as a deliberate choice to match `site.workplaces` ("RLS is OFF to match the sibling 1:1 app-managed table site.workplaces — access is gated in the Laravel policy layer... not at the DB"). Per the `20260711160000_analytics_force_rls_parity.sql` precedent (see `#SCHEMA-9`), this repo consistently re-tiers RLS-posture gaps on `postgres`-owned, `app_backend`-only tables down from P2 to P3 — "not a live exposure... consistency hygiene / forward-looking defence-in-depth." The same logic applies here: no RLS at all is a larger gap than missing-FORCE, but the practical exploitability is identical (zero, absent a PostgREST misconfiguration), so the tier should match the repo's own calibration rather than DeepSeek's un-cross-checked P2.
    - **Plain English:** Every tenant-data cupboard in the database has a lock, except this one — the note on it says "we lock it at the front door instead" (the application code), which is true today since nothing can reach this cupboard except through that front door. The lock is still worth adding for when a second door gets built, but it isn't urgent.
    - **Evidence:**
        ```sql
        -- position is 1..15, unique per site. RLS is OFF to match the sibling 1:1
        -- app-managed table site.workplaces — access is gated in the Laravel policy
        -- layer (ContentSelectionPolicy), not at the DB. app_backend gets the standard
        -- CRUD grant.
        ```

- [ ] **#SCHEMA-13** · P3 — `site.workplaces` has no RLS at all
    - **Where:** supabase/migrations/20260701150000_create_workplaces.sql (entire `CREATE TABLE`)
    - **Affects:** Same exposure class as `#SCHEMA-12`; `site.workplaces` holds PII-adjacent identity fields (name, address, phone, opening hours, contact email) — the most sensitive of the RLS-only-gap findings in this audit.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add `ENABLE ROW LEVEL SECURITY` + `FORCE ROW LEVEL SECURITY` + owner-read/staff-read/`app_backend`-all policies.
        - Add a regression-guard test in `tests/Feature/Security/` mirroring `DesignKitsRlsTest`.
    - **Technical:** `site.workplaces` is the table `#SCHEMA-12`'s migration names as the pattern it deliberately matches — confirming it too has zero RLS. Same `20260711160000_analytics_force_rls_parity.sql` precedent applies for tier calibration: `postgres`-owned, `app_backend`-only (`BYPASSRLS`) tables get this treatment as P3 consistency hygiene, not P2 live exposure, in this repo's own established practice. Ordered last in this tier because it carries the most sensitive data (business name/address/phone/email) of the group.
    - **Plain English:** The workplace card table — business names, addresses, phone numbers — is the other cupboard without a lock, matched deliberately to the content-selection cupboard next to it. Worth locking given what it holds, but not urgent since nothing can reach it except the application's own front door today.
    - **Evidence:**
        ```sql
        CREATE TABLE IF NOT EXISTS site.workplaces (
            site_id          uuid PRIMARY KEY REFERENCES site.sites (id) ON DELETE CASCADE,
            name             text,
            address          text,
        ```

## Suggested Bundled Sessions

None — every finding in this audit is a `supabase/migrations/` schema change, and per the fix-flow doctrine every DB migration/schema change runs standalone with its own plan + sign-off, never bundled.

## Standalone — do NOT bundle

- **#SCHEMA-1 — item_views.item_type CHECK constraint** · DB migration/schema change.
- **#SCHEMA-2 — content_popularity_scores.content_type CHECK constraint** · DB migration/schema change.
- **#SCHEMA-3 — missing FK on item_views/content_popularity_scores** · DB migration/schema change (FK + backfill).
- **#SCHEMA-4 — shop_brands selection_mode/link_mode CHECK constraints** · DB migration/schema change.
- **#SCHEMA-5 — sites.shop_link_mode CHECK constraint** · DB migration/schema change (site-wide blast radius).
- **#SCHEMA-6 — feedback.type CHECK constraint** · DB migration/schema change.
- **#SCHEMA-7 — user_segment_members grant revoke** · DB migration/schema change (privilege grants).
- **#SCHEMA-8 — item_views dedup key** · DB migration/schema change (index + app writer change).
- **#SCHEMA-9 — user_segments/user_segment_members FORCE RLS** · DB migration/schema change.
- **#SCHEMA-10 — feature_availability FORCE RLS** · DB migration/schema change.
- **#SCHEMA-11 — early_access_signups FORCE RLS** · DB migration/schema change.
- **#SCHEMA-12 — content_selection RLS + policies + test** · DB migration/schema change.
- **#SCHEMA-13 — workplaces RLS + policies + test** · DB migration/schema change.
