# Schema / RLS / search_path Audit — 2026-07-17

**Branch:** audit/full-work-sweep-2026-07-17
**Lens:** Schema / RLS / search_path: database-side correctness, constraint coverage, migration safety
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4.5`
**Source files audited:**
- app/Models/Core/Site/Menu.php
- app/Models/Core/Site/MenuItem.php
- app/Models/Core/Site/Site.php
- app/Models/Core/User/User.php
- supabase/migrations/20260711170000_users_email_unique_case_insensitive.sql
- supabase/migrations/20260712000000_retire_staff_account_type.sql
- supabase/migrations/20260713120000_reconcile_instagram_gallery_unification.sql
- supabase/migrations/20260714200000_architecture_one_to_staple.sql
- supabase/migrations/20260714210000_drop_effect_surface.sql
- supabase/migrations/20260714220000_add_aesthetic_axes.sql
- supabase/migrations/20260714230000_drop_glass_satellites.sql
- supabase/migrations/20260715090000_menu_item_currency_and_dining_modes.sql

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 2 complete
- P3 Low: 0 of 4 complete

---

## P2 — Should fix

- [ ] **SCHEMA-1** · P2 — Inline data backfill (`UPDATE` over matching rows) inside `20260713120000_reconcile_instagram_gallery_unification.sql`
    - **Where:** supabase/migrations/20260713120000_reconcile_instagram_gallery_unification.sql:16-41
    - **Affects:** `site.sites` and `site.platform_connections` — both statements require a full sequential scan to evaluate their `WHERE`/join predicates (no index backs `platform_connections.platform`+`display_settings ? 'gallery'`, nor the `site.sites` join key for this purpose), and both are data mutations executed inside a schema-migration transaction rather than a post-deploy path.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Extract the two `UPDATE` statements into a post-deploy artisan command (or a job dispatched after the migration transaction commits), leaving only schema-shape changes (if any) inside the migration itself — this is the exact pattern `docs/migration-guidelines.md` §"Full-table-scan data scrubs (#SCHEMA-2)" already prescribes, using nearly this same `site.sites`/`settings` shape as its own canonical "avoid" example.
        - If the team judges current row counts too small to matter (as was explicitly done for `20260714200000_architecture_one_to_staple.sql`, which carries a documented `guard:no-unsafe-migrations:disable-file` exemption for a 10-row `site.sites` table), add the same kind of explicit row-count justification comment rather than leaving the risk undocumented.
    - **Technical:** `scripts/guard-no-unsafe-migrations.php` only lints 4 patterns (unindexed `CREATE INDEX`, `ADD CONSTRAINT FK`/`CHECK` without `NOT VALID`, and `SET NOT NULL`) — inline data backfills are not covered by that CI guard, so this pattern only gets caught by manual/audit review. This migration is structurally the same anti-pattern `docs/migration-guidelines.md` already documents under the `#SCHEMA-2` heading (its own "avoid" example is `UPDATE site.sites SET settings = settings - 'design' WHERE settings ? 'design';`), so the fix here should follow that doc's own "prefer" pattern: a post-deploy command dispatched after the migration transaction commits.
    - **Plain English:** Think of the database as a shared filing system. This migration reaches in and rewrites data on two tables (which store, and Instagram connections) while everyone else is trying to read and write at the same time. The house style guide for this exact situation already exists and says "don't do data cleanup inside a schema change — do it as a separate step after the schema change ships," but this migration does it inline anyway.
    - **Evidence:**
        ```sql
        WITH ig AS (
            SELECT pc.user_id,
                   bool_or((pc.display_settings ->> 'gallery') IS DISTINCT FROM 'false') AS any_on
            FROM site.platform_connections pc
            WHERE pc.platform = 'instagram' AND pc.is_active = true
            GROUP BY pc.user_id
        )
        UPDATE site.sites s
        SET content_instagram_auto_enabled = CASE
                WHEN s.content_instagram_auto_enabled IS NULL THEN ig.any_on
                WHEN s.content_instagram_auto_enabled = true AND ig.any_on = false THEN false
                ELSE s.content_instagram_auto_enabled
            END
        FROM ig
        WHERE s.user_id = ig.user_id;

        UPDATE site.platform_connections
        SET display_settings = NULLIF(display_settings - 'gallery', '{}'::jsonb)
        WHERE platform = 'instagram'
          AND display_settings ? 'gallery';
        ```

- [ ] **SCHEMA-2** · P2 — `site.sites.shop_link_mode` is an enum-like `text` column with no `CHECK` constraint (recurring, previously flagged)
    - **Where:** app/Models/Core/Site/Site.php:34-42
    - **Affects:** Any write path setting `shop_link_mode` — the column accepts any string, not just `'checkout'`/`'product'`; a bad value would silently corrupt the public shop-link behavior for every connected store on that site.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - `ALTER TABLE site.sites ADD CONSTRAINT sites_shop_link_mode_check CHECK (shop_link_mode IN ('checkout', 'product')) NOT VALID;` followed by `VALIDATE CONSTRAINT` in a separate statement — required to pass `scripts/guard-no-unsafe-migrations.php` (Check 3 fails any `ADD CONSTRAINT ... CHECK` without `NOT VALID`).
        - Keep the app-side validation as the first line of defense; the DB constraint is the backstop.
    - **Technical:** The `Site` model's own comment concedes "no DB CHECK, matching the SQLite-test-mirror convention" — but that rationale is applied inconsistently: `BOOKING_MODES` on the same model *does* have a backing DB `CHECK` (`sites_booking_mode_check`) despite the identical SQLite-test-mirror limitation, so `shop_link_mode` is the outlier, not the norm. This exact gap was already raised as `#SCHEMA-7` in the 2026-07-10 audit (`audits/sweeps/2026-07-10-new-work-sweep/CONSOLIDATED.md`) and remains unfixed. The canonical pattern to mirror is `site.sites.architecture_id`'s `sites_architecture_id_check` CHECK.
    - **Plain English:** The column that controls how every connected store's "buy" link behaves only has two valid settings, but the database itself doesn't enforce that — only the app code does. If a bug or a bad data import ever writes something other than those two values, the database will happily store it, and the public store link could quietly break. This was already flagged as a gap a week ago and hasn't been fixed yet.
    - **Evidence:**
        ```php
        /**
         * Allowed GLOBAL shop link modes — mirrors the value the shop-settings
         * request validates (no DB CHECK, matching the SQLite-test-mirror
         * convention). 'checkout' deep-links product cards straight to the store
         * cart/checkout; 'product' links to the product page. Applied to EVERY
         * connected store — the public payload stamps each brand's linkMode from
         * site.sites.shop_link_mode. Default 'checkout' (direct-to-checkout ON).
         */
        public const SHOP_LINK_MODES = ['checkout', 'product'];
        ```

## P3 — Nice to have

- [ ] **SCHEMA-3** · P3 — Unindexed `DELETE` on `site.design_kit_contributions` in `20260714210000_drop_effect_surface.sql`
    - **Where:** supabase/migrations/20260714210000_drop_effect_surface.sql:11
    - **Affects:** `site.design_kit_contributions` — the `target_var` column carries no index, so this (and every sibling migration below) forces a sequential scan.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Low urgency: `site.design_kit_contributions` is a small, per-site provenance table (bounded by site count × factor count, not a hot read-path table — confirmed no app code queries it on the public sitepage path), and this exact `DELETE ... WHERE target_var = '<retired-column>'` cleanup has already run identically at least five times before this pair (`20260710210000_surfaces_backend.sql`, `20260710160000_design_kit_theme_surface_rework.sql`, `20260710190000_semantic_text_scale_and_vocab_remap.sql`, `20260709064322`/`20260705000000` font-slug migrations) without incident — this is an established, accepted convention, not a novel risk.
        - If the design-kit column-retirement cadence keeps recurring at this rate, add `CREATE INDEX CONCURRENTLY idx_design_kit_contributions_target_var ON site.design_kit_contributions (target_var);` once, rather than re-flagging each future retirement migration.
    - **Technical:** No CI guard covers this pattern (`scripts/guard-no-unsafe-migrations.php` only checks index/constraint/NOT-NULL patterns, not `DELETE`), and the table's only indexes are `idx_design_kit_contributions_site` / `idx_design_kit_contributions_site_integration` plus the `UNIQUE (site_id, source, target_var)` — none has `target_var` as a leading column. Given the table's small, bounded size at current scale and the repeated precedent, this is hardening/write-amplification hygiene rather than an active risk.
    - **Plain English:** This step wipes out old configuration rows for a design option that no longer exists, using a filter the database can't look up quickly — so it has to check every row. On the small housekeeping table involved, that's harmless today, but it's the same shortcut the team has now taken six times in a row; worth a one-time index if this keeps happening.
    - **Evidence:**
        ```sql
        DELETE FROM site.design_kit_contributions WHERE target_var = 'effect_surface';
        ```

- [ ] **SCHEMA-4** · P3 — Unindexed `DELETE` on `site.design_kit_contributions` in `20260714230000_drop_glass_satellites.sql`
    - **Where:** supabase/migrations/20260714230000_drop_glass_satellites.sql:11-12
    - **Affects:** Same table/root cause as SCHEMA-3.
    - **Effort:** S (~0.5–1h)
    - **What to do:** Same as SCHEMA-3 — no action needed beyond the same one-time index if the pattern keeps recurring.
    - **Technical:** Identical root cause to SCHEMA-3 (same migration series, same table, same missing index), tiered identically per the "same root cause, same tier" rule.
    - **Plain English:** Same situation as the previous item, just for three more retired design options removed in the very next migration.
    - **Evidence:**
        ```sql
        DELETE FROM site.design_kit_contributions
        WHERE target_var IN ('effect_scrim_blur', 'effect_glass_blur', 'motion_glass_shine_duration');
        ```

- [ ] **SCHEMA-5** · P3 — `DROP COLUMN` without a rename-to-deprecated cycle in `20260714210000_drop_effect_surface.sql`
    - **Where:** supabase/migrations/20260714210000_drop_effect_surface.sql:13
    - **Affects:** `site.design_kits.effect_surface` — theoretical risk window if this migration is applied to Supabase before the corresponding app-code deploy lands.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - No change needed for this specific pair: confirmed via grep that `app/Services/Design/Presets/PresetTargetableColumns.php`, `PlatformMixFactor.php`, and `Http/Requests/Concerns/DesignKitValidationRules.php` already only reference `effect_surface` in retirement comments, not as an active read/write target — the app-side decoupling landed in the same change as this migration, and Laravel Cloud's deploy model here works in the safe order (code deploys automatically on push; `supabase db push` for this migration is a separate, manual, developer-controlled step run after the code is already live).
        - This exact direct-drop pattern is already the established convention for `site.design_kits` column retirement (`20260528090000_drop_design_kit_row_height.sql`, `20260710210000_surfaces_backend.sql`, `20260527070000_skeleton_system_cleanup.sql`) — if the team wants to formalize it, add a short note to `supabase/migrations/CONVENTIONS.md` codifying "drop directly once app code in the same change stops referencing the column, and apply the migration after the code deploy is confirmed live" so a future reviewer doesn't need to re-derive the safety argument each time.
    - **Technical:** DeepSeek's draft treated this as a live mixed-version-deploy hazard, but that specific risk (old app instances still writing/reading a dropped column) doesn't apply here — the read/write path was already fully decoupled from `effect_surface` before this migration is applied, and the pattern has been used at least three times before on this same table with no reported incident. Downgraded from the draft's P2 to P3: worth documenting the convention, not worth a rename-cycle rework.
    - **Plain English:** Removing a shelf from a warehouse is only dangerous if a worker's shopping list still says "go to that shelf." Here, the list (the app code) was updated in the same change to stop mentioning the shelf before the shelf actually gets removed, and removal happens as a separate, deliberate step the team controls — so nobody collides with a wall. This has been the team's practice for design-option cleanups several times already without a problem.
    - **Evidence:**
        ```sql
        ALTER TABLE site.design_kits DROP COLUMN IF EXISTS effect_surface;
        ```

- [ ] **SCHEMA-6** · P3 — `DROP COLUMN` without a rename-to-deprecated cycle in `20260714230000_drop_glass_satellites.sql`
    - **Where:** supabase/migrations/20260714230000_drop_glass_satellites.sql:14-17
    - **Affects:** `site.design_kits.effect_scrim_blur` / `effect_glass_blur` / `motion_glass_shine_duration` — same root cause and same mitigations as SCHEMA-5.
    - **Effort:** S (~0.5–1h)
    - **What to do:** Same as SCHEMA-5 — no action needed for this pair beyond optionally documenting the convention.
    - **Technical:** Identical root cause to SCHEMA-5 (same migration series, same table, same already-decoupled app code), tiered identically.
    - **Plain English:** Same as the previous item, just three shelves at once instead of one — the workers' list was already updated first, so there's nothing to collide with.
    - **Evidence:**
        ```sql
        ALTER TABLE site.design_kits
            DROP COLUMN IF EXISTS effect_scrim_blur,
            DROP COLUMN IF EXISTS effect_glass_blur,
            DROP COLUMN IF EXISTS motion_glass_shine_duration;
        ```

## Suggested Bundled Sessions

None — every finding in this audit is a direct DB migration/schema change, which the fix-flow policy always routes to standalone execution (see below), matching how the prior schema-rls audit (`audits/sweeps/2026-07-10-new-work-sweep/CONSOLIDATED.md`) handled the same category of findings.

## Standalone — do NOT bundle

- **SCHEMA-1 — reconcile-instagram-gallery migration backfill** · DB migration/schema change (data backfill inside a migration transaction).
- **SCHEMA-2 — sites.shop_link_mode CHECK** · DB migration/schema change.
- **SCHEMA-3 — drop_effect_surface.sql DELETE** · DB migration/schema change.
- **SCHEMA-4 — drop_glass_satellites.sql DELETE** · DB migration/schema change.
- **SCHEMA-5 — drop_effect_surface.sql DROP COLUMN** · DB migration/schema change.
- **SCHEMA-6 — drop_glass_satellites.sql DROP COLUMN** · DB migration/schema change.
