`★ Insight ─────────────────────────────────────`
**Pre-merge adjudicator discoveries:** (1) A complete dedup cascade — API-2/CFG-1/TEST-1 are the same root cause across three lens scans, as are CFG-2/TEST-2. (2) DeepSeek missed a live P1 bug: the `groupKitColumns` prefix map uses `'icon'` (singular) but four DB columns use the `icons_` (plural) prefix, silently dropping them from every public profile response. (3) MIG-3's "partial application" concern is a PostgreSQL misunderstanding — a single `ALTER TABLE` statement is atomic.
**Core adjudicator discoveries:** (1) `SiteObserver` has `public bool $afterCommit = true` — TXN-1's entire premise was wrong; the cache bust fires after the transaction commits. (2) Every `site.*` table in the baseline has RLS enabled, making `design_kits` the lone exception — SCHEMA-3 is real. (3) When a request contains only `design_kit` data, `$data` is empty after `unset($data['design_kit'])`, Eloquent's `save()` is a no-op, `sites.updated_at` isn't bumped, and the timestamp-keyed `public.profile:*` cache never rotates — CCH-4 is a real correctness gap on the common path of design-only edits.
`─────────────────────────────────────────────────`

# Design Kit Audit — 2026-06-02

**Branch:** development
**Lens:** Combined bundle — core 8-lens (SEC/LIFE/CCH/SCALE/SCHEMA/WHK/TXN) + pre-merge 4-lens (MIG/API/CFG/TEST)
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
- supabase/migrations/20260527070000_skeleton_system_cleanup.sql
- supabase/migrations/20260527080000_design_kit_initial_vars.sql
- supabase/migrations/20260527090000_design_kit_layout_vars.sql
- supabase/migrations/20260527100000_design_kit_expanded_vars.sql
- supabase/migrations/20260527110000_design_kit_derived_default_vars.sql
- supabase/migrations/20260527120000_design_kit_bg_image_toggle.sql
- supabase/migrations/20260527130000_design_kit_row_motion_vars.sql
- supabase/migrations/20260527140000_design_kit_responsive_vars.sql
- supabase/migrations/20260527150000_design_kit_header_height.sql
- supabase/migrations/20260527170000_design_kit_typography_uppercase.sql
- supabase/migrations/20260528030000_drop_design_kit_bg_image.sql
- supabase/migrations/20260528090000_drop_design_kit_row_height.sql
- supabase/migrations/20260529044737_design_kit_contrasting_colors.sql
- supabase/migrations/20260529053028_design_kit_unified_space_scale.sql
- supabase/migrations/20260530100000_design_kit_heading_scale.sql
- supabase/migrations/20260530110000_design_kit_icon_xl_overlay_opacity.sql
- supabase/migrations/20260530120000_design_kit_icon_xxl.sql
- supabase/migrations/20260530130000_design_kit_icon_stroke_widths.sql
- app/Http/Controllers/Api/User/SiteManagement/UserSiteController.php
- app/Http/Controllers/Api/Staff/StaffSite/StaffSiteController.php
- app/Http/Controllers/Api/PublicSite/IndividualProfileController.php
- app/Http/Requests/Api/User/Site/UpdateSiteRequest.php
- app/Http/Requests/Api/Staff/UserSite/StaffUpdateSiteRequest.php
- app/Http/Resources/SiteResource.php
- app/Http/Resources/PublicSite/IndividualProfileResource.php
- app/Services/PublicSite/IndividualProfilePayloadBuilder.php
- app/Services/Cache/CacheKeyGenerator.php
- app/Services/Cache/SiteCacheService.php
- app/Mail/Branding/EmailBrand.php
- app/Mail/Branding/EmailBrandDefaults.php
- app/Mail/Branding/EmailPalette.php
- app/Mail/Branding/ProEmailBrandResolver.php
- app/Jobs/Notifications/SendEnquiryConfirmationJob.php
- app/Jobs/Notifications/SendSubscriptionConfirmationJob.php
- resources/views/mail/layouts/partna.blade.php
- tests/Feature/Api/PublicSite/IndividualProfileControllerTest.php
- tests/Feature/Notifications/DesignKitWriteInvalidatesBrandTest.php
- app/Services/Site/UpdateSiteAction.php *(adjudicator read)*
- app/Models/Core/Site/Site.php *(adjudicator read)*
- app/Observers/Core/SiteObserver.php *(adjudicator read)*
- supabase/migrations/20260526000000_baseline_standalone_user.sql *(adjudicator grep — RLS coverage)*

**Dropped findings (with reason):**
- **SEC-4 / LIFE-2** (core draft) — duplicates of SCHEMA-1 (same root cause, same Where)
- **WHK-1** (core draft) — meta/scope finding; no actionable code issue
- **TXN-1** (core draft) — incorrect premise. `SiteObserver` declares `public bool $afterCommit = true`, which defers observer callbacks until after the transaction commits. Cache invalidation does NOT fire inside the transaction.
- **SCHEMA-5** (core draft) — evidence claim inaccurate. `sizing_tablet_header_height` IS surfaced via `groupKitColumns()` under `sizing.tabletHeaderHeight`.
- **API-2** (pre-merge) — superseded by **SCHEMA-1** (core), which covers the same root cause more comprehensively and additionally includes the missing `color_placeholder` / `color_contrasting_*` rules.
- **CFG-2** (pre-merge) — subset of SCHEMA-1; the three missing color columns are already enumerated in SCHEMA-1's "What to do."
- **MIG-4** (pre-merge) — same finding as SCHEMA-2 (core); SCHEMA-2 retained as it additionally documents the correct pattern via the migration's own comment.

---

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 3 complete
- P2 Medium: 0 of 14 complete
- P3 Low: 0 of 16 complete

---

## P1 — Fix before pilot launch

- [x] **#KIT-1** · P1 · S — `groupKitColumns` silently drops four `icons_*`-prefixed columns from every public profile response
    - **Where:** app/Services/PublicSite/IndividualProfilePayloadBuilder.php:482–492 (`$singleTokenPrefixes` map)
    - **Affects:** Public profile API (`GET /api/public/profiles/{handle}`) for every professional who has stored a value for any of `icons_xl_size`, `icons_xxl_size`, `icons_stroke_width`, or `icons_large_stroke_width`. Values are accepted by the write API and persisted to the DB, but are never returned in the profile response — the design system receives null for all four, forcing its code-side defaults regardless of user customisation.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `'icons' => 'icons'` to the `$singleTokenPrefixes` map in `groupKitColumns()`. This maps the `icons_` column prefix to the `icons` wire group, producing `icons.xlSize`, `icons.strokeWidth`, `icons.largeStrokeWidth`, etc.
        - Extend the `design_kits` stub in `IndividualProfileControllerTest` to include at least one `icons_xl_size` column and assert it appears under `designKit.icons.xlSize` in the response.
    - **Technical:** The prefix-routing logic in `groupKitColumns()` uses the first underscore position to extract a single-token prefix, then looks it up in `$singleTokenPrefixes`. The column `icon_size` produces token `icon` → group `icons` ✓. But `icons_xl_size` produces token `icons` (5 chars, up to the first `_`) → lookup `$singleTokenPrefixes['icons']` → `null` → the column is silently dropped via `continue`. All four affected columns (`icons_xl_size` from `20260530110000`, `icons_xxl_size` from `20260530120000`, `icons_stroke_width` and `icons_large_stroke_width` from `20260530130000`) share this prefix. Both `UpdateSiteRequest` and `StaffUpdateSiteRequest` correctly validate these fields for write — so data is persisted but never read back to the renderer. The test suite's design_kits stub only contains `color_*` and `typography_*` columns, so this bug has zero test coverage.
    - **Plain English:** Imagine a paint palette with four colour slots that you can fill in through the settings screen, and the app confirms the colours were saved. But every time someone looks at your public page, the app pulls out the palette and can't read those four slots — the label on the paint chip says "icons" (plural) but the app's reading guide only knows how to read "icon" (singular). The colours are saved correctly; the reading guide just has a typo in one entry that causes it to skip those slots every time.
    - **Evidence:**
        ```php
        // IndividualProfilePayloadBuilder — the prefix map missing 'icons' (plural):
        $singleTokenPrefixes = [
            'color'      => 'colors',
            'typography' => 'typography',
            'border'     => 'borders',
            'space'      => 'space',
            'motion'     => 'motion',
            'icon'       => 'icons',   // ← handles icon_size, icon_color ONLY
            'effect'     => 'effects',
            'sizing'     => 'sizing',
            'button'     => 'buttons',
            // 'icons' is absent → icons_xl_size / icons_stroke_width / etc. are dropped
        ];
        ```
        ```php
        // UpdateSiteRequest — these four fields are accepted for write but never returned:
        'design_kit.icons_xl_size'           => ['sometimes', 'nullable', 'string', 'max:16'],
        'design_kit.icons_xxl_size'          => ['sometimes', 'nullable', 'string', 'max:16'],
        'design_kit.icons_stroke_width'      => ['sometimes', 'nullable', 'string', 'max:16'],
        'design_kit.icons_large_stroke_width'=> ['sometimes', 'nullable', 'string', 'max:16'],
        ```

- [x] **#API-1** · P1 · M — StaffSiteController returns a raw PHP array, bypassing the Resource layer entirely
    - **Where:** app/Http/Controllers/Api/Staff/StaffSite/StaffSiteController.php:46–73
    - **Affects:** All staff viewing a professional's site record. The `buildPayload()` method assembles a plain PHP array directly from the `AllSiteData` database view. Any column added to that view in future automatically leaks to the staff API with no review step.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Create `app/Http/Resources/Staff/StaffSiteResource.php` extending `ApiResource` that explicitly lists the fields staff may see — mirroring the column set in `buildPayload()` today.
        - Replace the `buildPayload()` return value in both `show()` and `showByProfessional()` with `new StaffSiteResource($row)`.
        - Decide explicitly whether `blocks` (currently includes soft-deleted / inactive rows with full `settings` JSONB) is intentional for staff — document this in the Resource with `$this->when(...)` guards or a comment.
    - **Technical:** The architecture rule is absolute: "Resource classes for all API responses — never return raw Eloquent models." `buildPayload()` constructs a plain PHP array instead. No `StaffSiteResource` class exists in `app/Http/Resources/`. The consequence is that schema evolution (a new column on `all_site_data`) automatically propagates to the staff dashboard without any explicit field-approval step. Today the payload exposes `location_street_address`, `location_city`, `location_state`, `location_postcode`, and `location_country` unconditionally; a future column for e.g. raw auth tokens or internal risk scores would appear in exactly the same way.
    - **Plain English:** The staff dashboard's "view site" screen is fed by a raw database printout rather than a curated report. Every time someone adds a column to the underlying database view — perhaps an internal audit flag or a sensitive internal score — it instantly appears on the staff screen, with no approval step, no field-gating, and no way to audit the change. The fix is to put the same "report formatter" in place that every other part of the API already uses.
    - **Evidence:**
        ```php
        private function buildPayload(AllSiteData $row): array
        {
            $siteSettings = is_array($row->site_settings) ? $row->site_settings : [];

            return [
                'is_published' => (bool) $row->is_published,
                'site' => [
                    'id' => $row->site_id,
                    'subdomain' => $row->subdomain,
                    'skeleton_id' => $row->skeleton_id,
                    'settings' => $siteSettings,
                ],
                'professional' => [
                    'id' => $row->user_id,
                    'handle' => $row->handle,
                    'display_name' => $row->display_name,
                    'account_type' => $row->account_type,
                    'bio' => $row->bio,
                    'location_street_address' => $row->location_street_address,
                    'location_city' => $row->location_city,
                    'location_state' => $row->location_state,
                    'location_postcode' => $row->location_postcode,
                    'location_country' => $row->location_country,
                ],
                'blocks' => $row->blocks ?? [],
            ];
        }
        ```

- [ ] **#SCHEMA-1** · P1 · M — StaffUpdateSiteRequest design_kit validation entirely out of sync with site.design_kits schema
    - **Where:** app/Http/Requests/Api/Staff/UserSite/StaffUpdateSiteRequest.php (design_kit section — ~28 rules for dropped columns, ~13 columns missing)
    - **Affects:** Staff operators editing a professional's design kit via the staff dashboard. Every write returns HTTP 200 but `writeDesignKit()` discards all spacing/padding/tablet-tier values (columns were dropped by migration `20260529053028`). Meanwhile the new `space_*`, `color_placeholder`, `color_contrasting_*` columns are unvalidated — staff can submit malformed values for them with no 422 feedback.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Remove validation rules for every dropped column: `spacing_extra_small`, `spacing_small`, `spacing_general`, `spacing_large`, `spacing_desktop_*`, `padding_extra_small`, `padding_small`, `padding_general`, `padding_large`, `padding_desktop_*`, `padding_tablet_*`, `spacing_tablet_*`, `sizing_tablet_button_height`, `sizing_tablet_input_height`, `typography_tablet_font_size`.
        - Add validation rules to match `UpdateSiteRequest`: `space_xs`, `space_s`, `space_regular`, `space_medium`, `space_large`, `space_desktop_xs`, `space_desktop_s`, `space_desktop_regular`, `space_desktop_medium`, `space_desktop_large`, `color_placeholder`, `color_contrasting_bg`, `color_contrasting_text`.
        - Add a comment block at the top of the design_kit section of each request class pointing at the other: "Must stay in sync with UpdateSiteRequest / StaffUpdateSiteRequest — see TEST-5."
        - Add a CI-enforced structural test (see TEST-5) to prevent this drift from recurring silently.
    - **Technical:** Migration `20260529053028_design_kit_unified_space_scale.sql` dropped 28 columns (the full `padding_*`, `spacing_*`, and `*_tablet_*` tiers) and added 10 `space_*` + `space_desktop_*` replacements. `StaffUpdateSiteRequest` was never updated. The controller's `writeDesignKit()` filters incoming keys against `information_schema.columns`, so writes for dropped columns are silently discarded — the operator receives HTTP 200 but the design kit is unchanged. Separately, the missing `space_*` and contrasting-color rules mean staff can submit values without format constraints (no `max:16` or `max:32` guard), letting unvalidated strings reach the DB for real columns that the user-facing request guards correctly. The staff endpoint is the only path for staff to assist a professional with design issues; it is currently non-functional for spacing.
    - **Plain English:** The staff admin panel has a checklist of design kit fields from months ago. Someone rearranged the actual storage shelves without updating the checklist. When a staff operator checks boxes for old fields (like "spacing-small"), the system silently ignores the change and sends back an "OK" — nothing was actually saved. At the same time, the new storage shelves (like the unified space scale) aren't on the checklist at all, so staff can put anything they want into them without the form telling them they've made a mistake.
    - **Evidence:**
        ```php
        // StaffUpdateSiteRequest — validates columns dropped by 20260529053028:
        'design_kit.spacing_extra_small' => ['sometimes', 'nullable', 'string', 'max:16'],
        'design_kit.padding_extra_small' => ['sometimes', 'nullable', 'string', 'max:16'],
        'design_kit.padding_tablet_extra_small' => ['sometimes', 'nullable', 'string', 'max:16'],
        'design_kit.spacing_tablet_extra_small' => ['sometimes', 'nullable', 'string', 'max:16'],
        'design_kit.sizing_tablet_button_height' => ['sometimes', 'nullable', 'string', 'max:16'],
        'design_kit.typography_tablet_font_size' => ['sometimes', 'nullable', 'string', 'max:32'],
        // ... 22 more dropped-column rules ...

        // Missing entirely — columns that DO exist after 20260529053028:
        // 'design_kit.space_xs', 'design_kit.space_s', 'design_kit.space_regular',
        // 'design_kit.space_medium', 'design_kit.space_large',
        // 'design_kit.color_placeholder', 'design_kit.color_contrasting_bg',
        // 'design_kit.color_contrasting_text'
        ```
        ```sql
        -- Migration 20260529053028 — drops these (excerpt):
        DROP COLUMN spacing_extra_small,
        DROP COLUMN padding_extra_small,
        DROP COLUMN padding_tablet_extra_small,
        DROP COLUMN sizing_tablet_button_height,
        DROP COLUMN typography_tablet_font_size,
        -- ... 23 more drops ...
        -- Adds:
        ADD COLUMN space_xs TEXT NULL,
        ADD COLUMN space_s TEXT NULL,
        -- ... 8 more space_* columns
        ```

---

## P2 — Should fix

- [x] **#MIG-1** · P2 · S — `CREATE TRIGGER` in skeleton cleanup migration is not idempotent
    - **Where:** supabase/migrations/20260527070000_skeleton_system_cleanup.sql (near the end, after the `CREATE OR REPLACE FUNCTION` block)
    - **Affects:** Deploy reliability on any environment where the migration runner is re-applied after a partial failure — specifically relevant given the "Fresh-DB Provisioning Broken" situation. A second run will hit `ERROR: trigger "trg_create_empty_design_kit" already exists`.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `DROP TRIGGER IF EXISTS trg_create_empty_design_kit ON site.sites;` immediately before the `CREATE TRIGGER` statement.
        - Note: the function already uses `CREATE OR REPLACE FUNCTION` (idempotent) — only the trigger needs the guard.
    - **Technical:** The migration uses `DROP VIEW IF EXISTS` (idempotent) and `DROP TABLE IF EXISTS … CASCADE` (idempotent) throughout, but the trigger creation at the end is plain `CREATE TRIGGER` with no guard. If the migration is re-applied on a fresh DB provisioning attempt that failed after this point, or if the migration is manually retried, the trigger creation will fail with a duplicate-trigger error and block every subsequent migration. `CREATE TRIGGER IF NOT EXISTS` does not exist in PostgreSQL — the correct pattern is `DROP TRIGGER IF EXISTS … ON site.sites` before creation.
    - **Plain English:** The migration script that sets up the new design-kit auto-creation feature puts most of its safety guards in place — "remove the old view if it exists," "remove the old table if it exists" — but forgets one: "remove the old trigger if it exists." If you have to re-run the script (say, because a fresh test environment got halfway and died), it will crash when it tries to re-install the trigger that's already there. Adding the "remove first" step costs one line.
    - **Evidence:**
        ```sql
        CREATE OR REPLACE FUNCTION site.create_empty_design_kit()
        RETURNS TRIGGER AS $$
        BEGIN
          INSERT INTO site.design_kits (site_id) VALUES (NEW.id);
          RETURN NEW;
        END;
        $$ LANGUAGE plpgsql;

        -- No guard here — will fail on second run:
        CREATE TRIGGER trg_create_empty_design_kit
          AFTER INSERT ON site.sites
          FOR EACH ROW EXECUTE FUNCTION site.create_empty_design_kit();
        ```

- [x] **#SCHEMA-4** · P2 · S — `site.create_empty_design_kit()` trigger has no `ON CONFLICT` guard
    - **Where:** supabase/migrations/20260527070000_skeleton_system_cleanup.sql (trigger function, lines ~107–115)
    - **Affects:** Site creation — if a `design_kits` row already exists for the same `site_id` (backfill race, re-insert, or test fixture), the trigger's bare `INSERT` hits a PK violation and rolls back the entire `INSERT INTO site.sites`, failing site creation silently.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a new migration that replaces the trigger function with an idempotent version: `INSERT INTO site.design_kits (site_id) VALUES (NEW.id) ON CONFLICT (site_id) DO NOTHING;`
        - This is safe because an existing empty row is functionally identical to a newly-inserted one.
    - **Technical:** The migration comment explicitly notes "existing sites are backfilled separately." A concurrent backfill job or a re-insertion scenario (e.g., a test that creates then re-creates a site) will cause the trigger's `INSERT` to fail on the PK constraint for `site_id`. Since the trigger is `AFTER INSERT ON site.sites FOR EACH ROW`, Postgres propagates the trigger failure back to the parent statement, aborting the site creation. `ON CONFLICT DO NOTHING` costs nothing and makes the trigger idempotent.
    - **Plain English:** Every time a new profile is created, the system automatically sets up an empty design settings slot for it. But if that slot already exists — maybe someone set it up in advance — the system panics and refuses to finish creating the profile, cancelling the whole operation. The fix is a one-liner that says "if the slot already exists, just move on" instead of treating it as an emergency.
    - **Evidence:**
        ```sql
        CREATE OR REPLACE FUNCTION site.create_empty_design_kit()
        RETURNS TRIGGER AS $$
        BEGIN
          INSERT INTO site.design_kits (site_id) VALUES (NEW.id);
          RETURN NEW;
        END;
        $$ LANGUAGE plpgsql;
        ```

- [ ] **#SCHEMA-3** · P2 · M — `site.design_kits` is the only `site.*` table without Row Level Security enabled
    - **Where:** supabase/migrations/20260527070000_skeleton_system_cleanup.sql (CREATE TABLE site.design_kits, ~line 94)
    - **Affects:** All per-professional design configuration (colours, typography, spacing, button styles). Without RLS, any connection using the `app_backend` role can read and write every professional's design kit without restriction — application-layer authorization is the only guard.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add a migration with `ALTER TABLE site.design_kits ENABLE ROW LEVEL SECURITY; ALTER TABLE site.design_kits FORCE ROW LEVEL SECURITY;`
        - Add RLS policies matching the pattern used on `site.sites` in the baseline: a `SELECT` policy that joins to `site.sites.user_id` via `current_setting('app.actor_id')`, and `INSERT/UPDATE/DELETE` policies that restrict to the owning professional.
        - Consult the `site.sites` RLS policies in `supabase/migrations/20260526000000_baseline_standalone_user.sql` for the exact policy pattern to replicate.
    - **Technical:** The baseline migration (`20260526000000`) enables RLS on every table in the `site.*` schema: `site.sites`, `site.blocks`, `site.site_media`, `site.media_variants`, `site.site_subdomain_aliases`, `site.services`, `site.enquiries`, and others. `site.design_kits`, created in `20260527070000`, is the only table in the schema that was never given `ENABLE ROW LEVEL SECURITY`. A bug in application authorization, a misconfigured connection, or a direct SQL client could read or overwrite any professional's design tokens without restriction.
    - **Plain English:** Every filing cabinet in the building has a lock on it — except the one added last month. The application checks IDs at the door before letting anyone into the filing room, but if that check ever fails (a bug, a misconfigured tool, or someone with direct database access), there's no second lock on that particular cabinet. Everything a professional has chosen for their brand colours and fonts is inside, accessible to anyone who gets past the door.
    - **Evidence:**
        ```sql
        -- Baseline migration (20260526000000) — every site.* table has RLS:
        ALTER TABLE site.sites ENABLE ROW LEVEL SECURITY;
        ALTER TABLE site.blocks ENABLE ROW LEVEL SECURITY;
        ALTER TABLE site.site_media ENABLE ROW LEVEL SECURITY;
        ALTER TABLE site.services ENABLE ROW LEVEL SECURITY;
        -- ... (all site.* tables) ...

        -- Skeleton cleanup migration (20260527070000) — design_kits created WITHOUT RLS:
        CREATE TABLE site.design_kits (
          site_id UUID PRIMARY KEY REFERENCES site.sites(id) ON DELETE CASCADE
        );
        -- No ALTER TABLE site.design_kits ENABLE ROW LEVEL SECURITY follows.
        ```

- [ ] **#CCH-1** · P2 · S — Plain `Cache::remember` on the `handle.resolve` hot path has no single-flight lock
    - **Where:** app/Http/Controllers/Api/PublicSite/IndividualProfileController.php:76–96 (`Cache::remember` block)
    - **Affects:** All public profile requests on a cold or just-evicted resolve cache. Concurrent misses (e.g., a social-media spike) trigger parallel User + Site DB lookups for the same handle.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `Cache::remember("handle.resolve:{$handleLc}", ...)` with `$this->cache->rememberLocked("handle.resolve:{$handleLc}", self::RESOLVE_CACHE_TTL, ...)`. The injected `CacheLockService` is already available in the controller.
        - Apply `±20 %` jitter to the 30 s TTL by passing the TTL through `CacheLockService::rememberLocked` (which jitters automatically) rather than as a literal integer.
    - **Technical:** The payload cache (second cache layer) correctly uses `CacheLockService::rememberLocked` for single-flight fill. The resolve cache (first layer) does not — plain `Cache::remember` executes the closure concurrently for every caller on a cold miss. For a handle that just went viral, dozens of workers each issue two indexed DB queries before the first one writes the resolved value. The payload cache single-flight then collapses the expensive build, but the resolve layer still creates unnecessary DB read load. The `CacheLockService` instance is already constructor-injected as `$this->cache`.
    - **Plain English:** Think of the profile page as a two-step lookup: first find which person owns this web address, then build their full page. The second step has a guard that makes sure only one server does the work at a time. The first step doesn't — when twenty people land on the same profile at once after it's shared on social media, twenty servers all rush to look up the same person. Each lookup is fast, but they all happen simultaneously when they didn't need to.
    - **Evidence:**
        ```php
        $resolved = Cache::remember(
            "handle.resolve:{$handleLc}",
            self::RESOLVE_CACHE_TTL,
            function () use ($handleLc) {
                $pro = User::query()->where('handle_lc', $handleLc)->first();
                if (! $pro) {
                    return ['not_found' => true];
                }
                $site = Site::query()->where('user_id', $pro->id)->first();

                return [
                    'pro_id' => $pro->id,
                    'site_id' => $site?->id,
                    'updated_at_ts' => $site?->updated_at?->timestamp
                        ?? $pro->updated_at?->timestamp
                        ?? 0,
                ];
            }
        );
        ```

- [ ] **#CCH-3** · P2 · S — SiteCacheService fill lock uses the default Redis store, not a dedicated lock store
    - **Where:** app/Services/Cache/SiteCacheService.php (SWR fill-lock acquisition in `getPublicSitePayload`)
    - **Affects:** The stale-while-revalidate single-flight guarantee on the public site payload cache. A `Cache::flush()` on the main store would delete active fill locks, allowing simultaneous recompute by all waiting workers.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `Cache::lock('site:fill:'.$subdomain, 10)` with `Cache::store('cache_locks')->lock('site:fill:'.$subdomain, 10)` (two occurrences in `getPublicSitePayload`).
        - Verify a `cache_locks` store is defined in `config/cache.php`; if not, add a Redis connection pointing to the same Redis DB as the `CacheLockService` uses.
    - **Technical:** The `Cache` facade uses the default store (same Redis DB as cached payloads). A `Cache::flush()` — routine during a deploy or incident — wipes active lock keys alongside payload keys. After the flush, every queued request for the same subdomain acquires the lock simultaneously, defeating the SWR single-flight pattern. `CacheLockService::rememberLocked` avoids this by design; `SiteCacheService`'s manual lock code does not.
    - **Plain English:** The system uses a special "lock box" to make sure only one server rebuilds a page at a time when the cache is empty. But this lock box is kept in the same room as the cache itself. If the room gets cleared out during a server update, the lock disappears too — and suddenly every server tries to rebuild the same page simultaneously, which is the exact problem the lock was supposed to prevent.
    - **Evidence:**
        ```php
        // SiteCacheService::getPublicSitePayload — two occurrences:
        $fillLock = Cache::lock('site:fill:'.$subdomain, 10);
        // ...
        $fillLock = Cache::lock('site:fill:'.$subdomain, 10);
        try {
            $fillLock->block(5);
        } catch (LockTimeoutException) { ... }
        ```

- [ ] **#CCH-4** · P2 · M — Design-kit writes do not invalidate the `public.profile:*` cache
    - **Where:** Write path: app/Http/Controllers/Api/User/SiteManagement/UserSiteController.php:writeDesignKit() + subsequent `invalidateSite()` call. Read path: app/Http/Controllers/Api/PublicSite/IndividualProfileController.php:show() (key `public.profile:{handle}:{updated_at_ts}`)
    - **Affects:** Every professional who saves design-kit changes (colours, fonts, spacing). When the request contains only `design_kit` data and no other site fields, `sites.updated_at` is not bumped, the timestamp-based cache key does not rotate, and the cached profile payload continues serving the old design kit until the TTL expires (~60 s default).
    - **Effort:** M (~2–4h)
    - **What to do:**
        - After writing the design kit, call `$site->touch()` to bump `sites.updated_at`. This causes the `SiteObserver` (which already has `$afterCommit = true`) to fire `invalidateSite()` and rotate the `public.profile:*` key naturally via the new timestamp.
        - Alternatively, add explicit invalidation of `handle.resolve:{$professional->handle_lc}` so the next request rebuilds the resolve cache with the new `updated_at_ts`, letting the old `public.profile:*` key orphan.
        - The second manual `invalidateSite()` call in `update()` can remain as it clears the legacy `site:payload:*` keys used by `SiteCacheService`.
    - **Technical:** The `public.profile:{handle}:{updated_at_ts}` cache key is content-addressed: it rotates when `sites.updated_at` changes. `UpdateSiteAction::execute()` saves the site inside a `DB::transaction`, but if `$data` is empty (because `design_kit` was the only field in the request), Eloquent's `save()` is a no-op — `isDirty()` is false, no `UPDATE` is issued, `updated_at` stays at its previous value. The `SiteObserver` (correctly marked `$afterCommit = true`) therefore never fires. The `invalidateSite()` call in the controller clears `site:payload:*` and `emailBrand:*` keys, but not the `public.profile:*` key, which is keyed on the old timestamp and remains live for up to 60 s.
    - **Plain English:** When a hairdresser changes their brand colour on the dashboard, they expect to see it immediately on their public profile. The system uses a "fingerprint" based on when the profile was last updated to know which cached version to show. When only design colours are changed, the profile's "last updated" timestamp doesn't change — so the system hands out the old fingerprint and keeps showing the old colours for up to a minute. The fix is to mark the profile as "just updated" whenever the design kit changes, so the old cached version gets replaced immediately.
    - **Evidence:**
        ```php
        // UserSiteController::update — design-kit-only request: $data = [] after unset
        $designKit = $data['design_kit'] ?? null;
        unset($data['design_kit']);

        $site = $action->execute($professional, $data); // $data = [] → save() is no-op → updated_at unchanged

        if (is_array($designKit)) {
            $this->writeDesignKit($site->id, $designKit);
            app(SiteCacheService::class)->invalidateSite($site); // clears site:payload:* only
        }
        ```
        ```php
        // IndividualProfileController — cache key embeds the timestamp:
        $key = "public.profile:{$handleLc}:{$resolved['updated_at_ts']}"; // stale key served
        ```

- [ ] **#LIFE-1** · P2 · S — Race condition in `writeDesignKit`: concurrent saves can silently lose design customisations
    - **Where:** app/Http/Controllers/Api/User/SiteManagement/UserSiteController.php:writeDesignKit() (lines ~72–95)
    - **Affects:** Any professional who triggers two rapid design-kit saves (e.g., opens the editor in two tabs, or a slow network causes a double-submit). The later write silently overwrites the earlier one without any error.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Wrap the `writeDesignKit` body in `DB::connection('pgsql')->transaction()` and add `->lockForUpdate()` on the `site.design_kits` row before applying the partial update.
        - This serialises concurrent writes to the same design kit, ensuring each request reads and updates the current DB state.
    - **Technical:** The method reads the valid column set from `information_schema`, then runs an `UPDATE` with no lock. Two concurrent requests that target different columns each read the same row at commit time. The last writer wins on a column-by-column basis, but because each UPDATE writes only the keys it received (not a full merge), concurrent saves can produce a torn state: colours from request A, typography from request B. A `lockForUpdate()` inside a transaction serialises the write without adding latency on the single-user path.
    - **Plain English:** Imagine editing a collaborative document where two people are simultaneously typing in different sections. Normally a real-time editor merges both changes. This system doesn't — each "save" is like replacing the whole document with your version. If two saves land close together, the second one overwrites whatever the first one just set, without any warning. The fix is to make each save wait its turn before writing.
    - **Evidence:**
        ```php
        private function writeDesignKit(string $siteId, array $designKit): void
        {
            // ...
            $columns = DB::connection('pgsql')
                ->table('information_schema.columns')
                ->where('table_schema', 'site')
                ->where('table_name', 'design_kits')
                ->pluck('column_name')
                ->all();

            $valid = array_intersect_key($designKit, array_flip($columns));
            unset($valid['site_id']);

            if ($valid === []) {
                return;
            }

            DB::connection('pgsql')
                ->table('site.design_kits')
                ->where('site_id', $siteId)
                ->update($valid);   // no lock, no transaction
        }
        ```

- [ ] **#SEC-1** · P2 · S — StaffUpdateSiteRequest `prepareForValidation` skips settings-field sanitisation
    - **Where:** app/Http/Requests/Api/Staff/UserSite/StaffUpdateSiteRequest.php:prepareForValidation()
    - **Affects:** Staff-updated `hero_title`, `hero_subtitle`, `primary_button_text`, and `bio_text` — strings are stored with raw whitespace and artefacts rather than going through the `cleanString()` pass that the professional-facing path applies.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add the same `settings` sanitisation loop from `UpdateSiteRequest::prepareForValidation()` into `StaffUpdateSiteRequest::prepareForValidation()`, or extract it into a shared trait to prevent future drift.
    - **Technical:** `UpdateSiteRequest::prepareForValidation()` trims each text field and runs `static::cleanString()` before validation. `StaffUpdateSiteRequest::prepareForValidation()` only lowercases the subdomain. Any string a staff member pastes into the hero title — including leading/trailing whitespace, smart quotes, or control characters — is stored verbatim. Downstream renderers and meta-tag generators trust stored strings; the user-facing path cleans them, the staff path doesn't.
    - **Plain English:** The professional's own dashboard cleans up text before saving it — trimming extra spaces and fixing odd characters. The staff admin dashboard skips that cleaning step, so whatever a staff member types goes straight into the database as-is. This can cause subtle rendering glitches on the public profile page when staff make edits.
    - **Evidence:**
        ```php
        // UpdateSiteRequest — has cleanup:
        $settings = $this->input('settings');
        if (is_array($settings)) {
            foreach (['hero_title', 'hero_subtitle', 'primary_button_text', 'bio_text'] as $field) {
                if (! array_key_exists($field, $settings) || ! is_string($settings[$field])) {
                    continue;
                }
                $settings[$field] = static::cleanString($settings[$field]);
            }
            $merge['settings'] = $settings;
        }

        // StaffUpdateSiteRequest — subdomain only, no settings cleanup:
        protected function prepareForValidation(): void
        {
            if (is_string($this->subdomain ?? null)) {
                $this->merge([
                    'subdomain' => strtolower(trim($this->subdomain)),
                ]);
            }
        }
        ```

- [ ] **#SEC-2** · P2 · S — StaffUpdateSiteRequest subdomain validation omits the `core.user_handle_aliases` collision check
    - **Where:** app/Http/Requests/Api/Staff/UserSite/StaffUpdateSiteRequest.php:rules() (subdomain closure, ~lines 111–137)
    - **Affects:** Staff subdomain assignments — a staff member can set a subdomain that matches a handle preserved as an alias for redirect/SEO purposes, silently overwriting that redirect and breaking the former professional's incoming traffic.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add the `core.user_handle_aliases` check from `UpdateSiteRequest` into the staff subdomain closure, scoped to exclude the target professional's own aliases (`where('user_id', '!=', $professional->id)`).
        - Wrap the query in the same `try/catch(QueryException)` + `report($e)` + `Log::warning` block that the user path uses, so a DB error during the alias check is visible in Nightwatch rather than silently allowing the assignment.
    - **Technical:** `UpdateSiteRequest` checks three collision sources before accepting a subdomain: (a) `site.sites`, (b) `site.site_subdomain_aliases`, and (c) `core.user_handle_aliases` (old handles preserved for redirect/SEO). `StaffUpdateSiteRequest` checks only (a) and (b). A staff member could overwrite a legacy handle alias held by a different professional, redirecting that professional's old-URL traffic to the newly-assigned site. The missing `try/catch` also means a DB error during the alias check would propagate as a 500 rather than being handled gracefully.
    - **Plain English:** When a professional changes their public handle, their old address is kept as a "forwarding address" so existing links still work. The professional's own dashboard checks all three sources of name conflicts before allowing a new address. The staff dashboard only checks two — meaning staff can accidentally claim a forwarding address that belongs to someone else, silently breaking that professional's incoming links without any error message.
    - **Evidence:**
        ```php
        // UpdateSiteRequest — has the alias check:
        try {
            $existsInUserAliases = DB::connection('pgsql')
                ->table('core.user_handle_aliases')
                ->whereRaw('LOWER(handle) = LOWER(?)', [$value])
                ->where('user_id', '!=', $currentUserId)
                ->exists();
        } catch (QueryException $e) {
            report($e);
            Log::warning('Professional alias check failed in UpdateSiteRequest', ['error' => $e->getMessage()]);
            $existsInUserAliases = false;
        }

        // StaffUpdateSiteRequest — subdomain closure ends after site_subdomain_aliases check:
        $aliasExists = DB::table('site.site_subdomain_aliases')
            ->whereRaw('lower(subdomain) = ?', [strtolower($value)])
            ->exists();

        if ($aliasExists) {
            $fail('This subdomain is already taken.');
        }
        // No core.user_handle_aliases check follows.
        ```

- [ ] **#TEST-3** · P2 · M — No test verifies the CHECK constraint, cascading FK, or auto-create trigger from the skeleton cleanup migration
    - **Where:** supabase/migrations/20260527070000_skeleton_system_cleanup.sql (skeleton_id CHECK, design_kits FK, trg_create_empty_design_kit trigger)
    - **Affects:** Fresh database provisioning — if the migration applies incorrectly, the system would accept invalid skeleton IDs, allow orphan design_kits rows, or silently fail to create a kit on site creation. All three scenarios are currently invisible until a user reports a broken site. Especially relevant given the "Fresh-DB Provisioning Broken" issue.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add `tests/Feature/Migrations/SkeletonSystemConstraintsTest.php` with three tests:
            - `it('rejects invalid skeleton_id values')` — attempt to INSERT `skeleton_id = 'skeleton-99'` and assert a constraint violation is thrown.
            - `it('prevents orphan design_kits rows via FK')` — attempt to INSERT into `site.design_kits` with a non-existent `site_id` and assert FK violation.
            - `it('auto-creates an empty design_kits row on site insert')` — INSERT a `site.sites` row and assert exactly one matching row appears in `site.design_kits`. Note this requires the real PostgreSQL stack, not the SQLite test double — skip if running on SQLite.
    - **Technical:** The migration introduces three structural invariants that are never verified post-apply: the TEXT CHECK enum on `skeleton_id`, the `ON DELETE CASCADE` FK from `design_kits` to `sites`, and the `AFTER INSERT` trigger that auto-inserts the kit row. The SQLite test double does not enforce PostgreSQL CHECK constraints and does not run PostgreSQL triggers, so integration test coverage of these invariants is zero.
    - **Plain English:** When the builders finished the renovation, the architect specified three safety rules: only four approved skeletons are allowed, every design kit must belong to a real site, and a new kit is automatically created whenever a new site is built. No inspector has come to verify any of these three things actually work in a real database environment. Adding tests that run against the real database would catch a broken rule before customers see a broken site.
    - **Evidence:**
        ```sql
        ALTER TABLE site.sites
          ADD COLUMN skeleton_id TEXT NOT NULL
            DEFAULT 'skeleton-1'
            CHECK (skeleton_id IN ('skeleton-1','skeleton-2','skeleton-3','skeleton-4'));

        CREATE TABLE site.design_kits (
          site_id UUID PRIMARY KEY REFERENCES site.sites(id) ON DELETE CASCADE
        );

        CREATE TRIGGER trg_create_empty_design_kit
          AFTER INSERT ON site.sites
          FOR EACH ROW EXECUTE FUNCTION site.create_empty_design_kit();
        ```

- [ ] **#TEST-4** · P2 · S — Two-token responsive prefix path in `groupKitColumns` has no test coverage
    - **Where:** app/Services/PublicSite/IndividualProfilePayloadBuilder.php:474–506 (two-token prefix loop)
    - **Affects:** Public profile API — `space_desktop_*`, `sizing_desktop_*`, and `typography_desktop_*` columns would route to the wrong wire group or be dropped entirely if the two-token prefix loop is accidentally reordered or removed.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Extend the `design_kits` stub in `IndividualProfileControllerTest` to include `space_desktop_regular TEXT NULL`.
        - Add `it('groups two-token responsive prefix columns into the correct nested group')` — insert `space_desktop_regular = '2rem'` and assert the response contains `designKit.spaceDesktop.regular = '2rem'`.
        - Add `it('two-token prefix wins over single-token when they share an initial token')` — verify `space_desktop_regular` lands in `spaceDesktop`, not `space`.
    - **Technical:** The existing test `'groups stored design_kit columns into nested camelCase wire shape'` only seeds `color_accent` and `typography_font_heading` — both single-token prefixes. The two-token prefix loop (`space_desktop`, `sizing_desktop`, `typography_desktop`) runs first and is responsible for routing all responsive companion columns. It has zero test coverage. A refactor that reorders the two loops, or removes the two-token loop, would silently break all responsive columns in every public profile.
    - **Plain English:** The profile builder has two sorting machines — one for simple labels like `color_accent` and one for compound labels like `space_desktop_regular`. The quality checklist only tests the simple machine. If someone swaps the two machines' order during a future cleanup, compound labels get sorted into wrong bins and the public page looks broken — and no test catches it before it ships.
    - **Evidence:**
        ```php
        // The two-token prefix loop — currently untested:
        $twoTokenPrefixes = [
            'space_desktop'      => 'spaceDesktop',
            'sizing_desktop'     => 'sizingDesktop',
            'typography_desktop' => 'typographyDesktop',
        ];
        ```
        ```php
        // Test stub only has single-token columns — two-token path never exercises:
        DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS site.design_kits (
            site_id TEXT PRIMARY KEY,
            color_accent TEXT NULL,
            typography_font_heading TEXT NULL
        )');
        ```

- [ ] **#TEST-5** · P2 · M — No structural test catches drift between `UpdateSiteRequest` and `StaffUpdateSiteRequest` design_kit rules
    - **Where:** app/Http/Requests/Api/User/Site/UpdateSiteRequest.php, app/Http/Requests/Api/Staff/UserSite/StaffUpdateSiteRequest.php
    - **Affects:** Every future design_kit column addition — without a CI guard, the staff request class will silently fall behind again (as demonstrated by SCHEMA-1 and SEC-1 in this audit).
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add `tests/Feature/Requests/DesignKitRequestDriftTest.php` with a structural test that: (a) queries `information_schema.columns` for `site.design_kits` and asserts every column has a corresponding validation rule in both request classes, and (b) asserts no rule in either class references a column that doesn't exist in the table. Follow the `PolicyCoverageTest` pattern already in the codebase.
    - **Technical:** `UpdateSiteRequest` has ~50 design_kit rules; `StaffUpdateSiteRequest` has its own set. The drift revealed in this audit (SCHEMA-1: 28 stale columns + 13 missing; SEC-1: sanitisation gap) proves the two classes are not kept in sync by any automated check. The codebase already applies the structural-sweep pattern in `PolicyCoverageTest` and `MailableCategoryCoverageTest` — a `DesignKitRequestDriftTest` using `information_schema.columns` follows the same pattern and would catch future column additions wired to one request class but not the other.
    - **Plain English:** The design-kit form has around 50 fields. There are two doorways to the same room — one for users, one for staff. Today there is no nightly inventory that checks both doorways have the same set of keys. This audit found the staff doorway's key ring is missing 20+ keys the database no longer recognises, plus several it should have. A structural test is like that nightly inventory — if a new key is added to one ring but not the other, the build fails immediately, before the code ships.
    - **Evidence:**
        ```php
        // UpdateSiteRequest — space_* rules present:
        'design_kit.space_xs'      => ['sometimes', 'nullable', 'string', 'max:16'],
        'design_kit.space_s'       => ['sometimes', 'nullable', 'string', 'max:16'],
        'design_kit.space_regular' => ['sometimes', 'nullable', 'string', 'max:16'],
        ```
        ```php
        // StaffUpdateSiteRequest — no space_* rules at all; stale spacing_* rules instead:
        'design_kit.spacing_extra_small' => ['sometimes', 'nullable', 'string', 'max:16'],
        // … space_xs through space_desktop_large are absent
        ```

- [ ] **#TEST-6** · P2 · M — No test exercises the single-flight lock path in IndividualProfileController
    - **Where:** app/Http/Controllers/Api/PublicSite/IndividualProfileController.php:63–79 (`rememberLocked` call)
    - **Affects:** Public profile endpoint under traffic spikes. If `CacheLockService::rememberLocked` regresses (e.g. lock never releases, stale-while-revalidate stops serving), the symptom is 504s under load — the hardest failure mode to debug in production.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add `it('single-flights concurrent requests so only one payload is built')` — mock `IndividualProfilePayloadBuilder` with `->shouldReceive('build')->once()`, make two sequential requests with the same handle against a warm resolve cache, assert build was called exactly once and both responses are identical.
        - Add `it('handles a race where the site is deleted between resolve and payload cache reads')` — seed a resolve cache entry pointing at a now-deleted user ID, call the endpoint, assert 404 and that the resolve cache entry is subsequently forgotten.
    - **Technical:** No test in `IndividualProfileControllerTest` exercises the `rememberLocked` path with any mock on the builder — all tests let the builder run and seed their own DB fixtures. A lock-service regression cannot be caught by the current test suite until it manifests as load-induced 504s.
    - **Plain English:** When many people visit the same profile at once, the system has a "one person fetches the page, everyone else waits for the result" mechanism. This mechanism has never been tested — only one visitor at a time has ever been simulated. If a code change accidentally breaks the mechanism, the first sign is the server falling over under real traffic, not a failing test before the code ships.
    - **Evidence:**
        ```php
        // Controller — the single-flight path (untested for concurrent callers):
        $payload = $this->cache->rememberLocked(
            $key,
            $this->builder->cacheTtl(),
            function () use ($resolved) {
                $pro = User::find($resolved['pro_id']);
                if (! $pro) { return null; }
                $site = $resolved['site_id'] ? Site::find($resolved['site_id']) : null;
                return $this->builder->build($pro, $site);
            }
        );
        ```

- [ ] **#TEST-7** · P2 · S — `writeDesignKit()` is never called through a test; its FK-protection guard and column-filter logic are untested
    - **Where:** app/Http/Controllers/Api/User/SiteManagement/UserSiteController.php:84–103
    - **Affects:** User-facing site update endpoint (`PATCH /api/professional/site`). A bug in the `information_schema` intersection, the `unset($valid['site_id'])` guard, or the empty-kit short-circuit would silently corrupt or silently drop design kit writes with no test failure.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `it('persists only columns that exist on site.design_kits')` — POST a design_kit payload with one valid key (`color_accent`) and one bogus key (`nonexistent_column`); assert only `color_accent` is updated in the DB.
        - Add `it('silently ignores an empty design_kit array')` — POST `design_kit: []`; assert no DB write occurs.
        - Add `it('strips site_id if a caller attempts to rewrite the FK')` — POST `design_kit: { site_id: 'other-uuid', color_accent: '#fff' }`; assert `site_id` is unchanged and `color_accent` is updated.
    - **Technical:** `DesignKitWriteInvalidatesBrandTest` (the only test that touches design kit persistence) writes directly via `DB::table('site.design_kits')->update(...)`, completely bypassing `writeDesignKit()`. The method's three guarantees — only real columns pass through, the `site_id` FK cannot be overwritten, an empty payload is a no-op — are all unverified.
    - **Plain English:** The design-kit write path has a smart filter that checks which paint slots actually exist on the wall before hanging any colours, and it has a lock that stops callers from changing which wall the paint goes on. Neither of these safety features has ever been tested directly. The only test that checks "paint ended up on the wall" sneaked in through the back door and hung the paint itself, bypassing the filter entirely.
    - **Evidence:**
        ```php
        // writeDesignKit — the untested method with three safety invariants:
        private function writeDesignKit(string $siteId, array $designKit): void
        {
            $columns = DB::connection('pgsql')
                ->table('information_schema.columns')
                ->where('table_schema', 'site')
                ->where('table_name', 'design_kits')
                ->pluck('column_name')
                ->all();

            $valid = array_intersect_key($designKit, array_flip($columns));
            unset($valid['site_id']); // never let a caller rewrite the FK

            if ($valid === []) { return; }

            DB::connection('pgsql')
                ->table('site.design_kits')
                ->where('site_id', $siteId)
                ->update($valid);
        }
        ```
        ```php
        // DesignKitWriteInvalidatesBrandTest — bypasses writeDesignKit entirely:
        DB::connection('pgsql')->table('site.design_kits')
            ->where('site_id', $site->id)->update(['color_accent' => '#aa0000']);
        ```

---

## P3 — Nice to have

- [ ] **#CCH-2** · P3 · S — Unjittered TTL on `handle.resolve` cache causes fleet-wide synchronised expiry
    - **Where:** app/Http/Controllers/Api/PublicSite/IndividualProfileController.php (RESOLVE_CACHE_TTL constant + Cache::remember call)
    - **Affects:** Minor — all Redis nodes expire the resolve cache at the same wall-clock second, creating a small synchronised lookup spike every 30 s on high-traffic handles.
    - **Effort:** S (~0.5h)
    - **What to do:**
        - Route the resolve cache through `CacheLockService::rememberLocked` (which applies ±20 % jitter automatically) once CCH-1 is fixed — the jitter comes for free.
    - **Technical:** A literal `30` TTL passed to `Cache::remember` expires identically on every Redis node simultaneously. On a fleet of N Laravel workers all serving the same popular handle, all entries expire at the same second, creating N concurrent DB hits. The ±20 % jitter applied by `rememberLocked` desynchronises expiry across the fleet.
    - **Evidence:**
        ```php
        private const RESOLVE_CACHE_TTL = 30;

        $resolved = Cache::remember(
            "handle.resolve:{$handleLc}",
            self::RESOLVE_CACHE_TTL,
            function () use ($handleLc) { ... }
        );
        ```

- [ ] **#CCH-5** · P3 · S — `IndividualProfileController` builds cache keys via ad-hoc string interpolation instead of `CacheKeyGenerator`
    - **Where:** app/Http/Controllers/Api/PublicSite/IndividualProfileController.php (both cache key constructions)
    - **Affects:** Future maintainability — a writer or invalidator that constructs the key differently (typo, different case handling) silently misses the cache.
    - **Effort:** S (~0.5h)
    - **What to do:**
        - Add `CacheKeyGenerator::handleResolve(string $handle): string` and `CacheKeyGenerator::publicProfile(string $handle, int $timestamp): string` methods, then replace the inline interpolations in the controller.
    - **Technical:** `CacheKeyGenerator` is the canonical key registry, ensuring every reader and writer uses the identical key shape. The controller builds `"handle.resolve:{$handleLc}"` and `"public.profile:{$handleLc}:{$resolved['updated_at_ts']}"` inline. If any future path (a cache-warm job, an invalidation helper, a test) constructs these keys slightly differently, the result is a silent miss with no error.
    - **Evidence:**
        ```php
        "handle.resolve:{$handleLc}"
        // ...
        $key = "public.profile:{$handleLc}:{$resolved['updated_at_ts']}";
        ```

- [ ] **#CCH-6** · P3 · S — Unjittered TTL on the negative-cache sentinel in `SiteCacheService`
    - **Where:** app/Services/Cache/SiteCacheService.php:buildPayloadFromDb() (two `Cache::put` calls for `MISS_SENTINEL`)
    - **Affects:** Synchronised expiry of all "no site here" sentinel entries — a burst of bot scans during a fixed 30 s window causes all sentinels to expire at the same moment, briefly multiplying DB hits.
    - **Effort:** S (~0.5h)
    - **What to do:**
        - Replace `now()->addSeconds(self::MISS_PRIMARY_TTL_SECONDS)` with `self::applyJitter(self::MISS_PRIMARY_TTL_SECONDS)` (the `JitteredTtl` trait is already used in `SiteCacheService`).
    - **Technical:** The same ±20 % jitter applied to positive payload TTLs via `writePayloadWithStale()` is absent from the negative-cache sentinel writes. When many bogus subdomains are probed in a narrow window, all sentinels expire together. The fix is one-line and uses the `JitteredTtl` trait that is already in scope.
    - **Evidence:**
        ```php
        Cache::put($key, self::MISS_SENTINEL, now()->addSeconds(self::MISS_PRIMARY_TTL_SECONDS));
        Cache::put($staleKey, self::MISS_SENTINEL, now()->addSeconds(self::MISS_PRIMARY_TTL_SECONDS * self::PAYLOAD_STALE_TTL_MULTIPLIER));
        ```

- [ ] **#CFG-3** · P3 · S — `IndividualProfileController` hardcodes the resolve-cache TTL and slow-request threshold as PHP constants
    - **Where:** app/Http/Controllers/Api/PublicSite/IndividualProfileController.php:38–41
    - **Affects:** Operational agility — adjusting either value during a traffic incident or a performance investigation requires a code deploy rather than a config change.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `private const RESOLVE_CACHE_TTL = 30` with `config('partna.public_profile.resolve_cache_ttl', 30)`.
        - Replace `private const SLOW_REQUEST_THRESHOLD_MS = 1_000` with `config('partna.public_profile.slow_request_threshold_ms', 1000)`.
        - Add both keys under the existing `public_profile` section in `config/partna.php` and add entries to `.env.example`. The payload builder's TTL (`cacheTtl()`) is already config-driven — these two constants should match that pattern.
    - **Evidence:**
        ```php
        private const RESOLVE_CACHE_TTL = 30;
        private const SLOW_REQUEST_THRESHOLD_MS = 1_000;
        ```

- [ ] **#CFG-4** · P3 · S — `EmailBrand::partna()` hardcodes `'https://partna.au'` rather than reading from config
    - **Where:** app/Mail/Branding/EmailBrand.php:30
    - **Affects:** Non-production environments (staging, local dev) that send Partna-branded emails — footer links in those emails point to the live production site regardless of environment.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `siteUrl: 'https://partna.au'` with `siteUrl: (string) config('app.partna_marketing_url', 'https://partna.au')`.
        - Add `PARTNA_MARKETING_URL=https://partna.au` to `.env.example` with a comment.
        - Note: `EmailBrand::fromArray()` also falls back to `'https://partna.au'` — update both.
    - **Evidence:**
        ```php
        public static function partna(): self
        {
            return new self(
                isPartna: true,
                proName: (string) config('mail.from.name', 'Partna'),
                siteUrl: 'https://partna.au',  // hardcoded — not config-driven
                logoUrl: null,
                replyToEmail: null,
                palette: EmailBrandDefaults::defaults(),
            );
        }
        ```

- [ ] **#CFG-5** · P3 · S — `UpdateSiteRequest` accepts both `settings.charlie_enabled` (snake_case) and `settings.charlieEnabled` (camelCase) for the same setting
    - **Where:** app/Http/Requests/Api/User/Site/UpdateSiteRequest.php (settings group)
    - **Affects:** API consumers who inadvertently send both keys in the same request — the resulting `settings` array contains both, and whichever key the application code checks for wins.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Pick `settings.charlie_enabled` (snake_case, consistent with every other `settings.*` key) as the canonical form and remove `settings.charlieEnabled`.
        - If an active client sends `charlieEnabled`, normalise it in `prepareForValidation()` before removing the camelCase rule.
    - **Evidence:**
        ```php
        // Two rules for the same setting — only snake_case convention should survive:
        'settings.charlie_enabled'  => ['sometimes', 'boolean'],
        'settings.charlieEnabled'   => ['sometimes', 'boolean'],
        ```

- [ ] **#LIFE-3** · P3 · S — `updateBookingSettings` uses inline `Validator::make` instead of a Form Request class
    - **Where:** app/Http/Controllers/Api/User/SiteManagement/UserSiteController.php:updateBookingSettings()
    - **Affects:** Architectural consistency — validation rules for this endpoint are embedded in the controller and cannot be reused or tested in isolation.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Extract the `booking_mode` and `manual_booking_url` rules into a dedicated `UpdateBookingSettingsRequest` Form Request class.
        - Replace the inline `Validator::make` block with a type-hint on the method parameter.
    - **Technical:** The CLAUDE.md architecture mandates Form Request classes for all input validation. Inline `Validator::make` in a controller bypasses the standard resolution hooks and is harder to test in isolation. This is one of only two places in the controller that doesn't use a Form Request — the other endpoints use `UpdateSiteRequest` correctly.
    - **Evidence:**
        ```php
        $validator = Validator::make($request->all(), [
            'booking_mode' => ['required', 'string', Rule::in($allowedModes)],
            'manual_booking_url' => ['nullable', 'url', 'max:2048'],
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }
        ```

- [ ] **#MIG-5** · P3 · S — Early design-kit `ADD COLUMN` migrations lack `IF NOT EXISTS` guard
    - **Where:** supabase/migrations/20260527080000 through 20260527140000 (seven pre-unified-space migrations using plain `ADD COLUMN`)
    - **Affects:** Fresh database provisioning attempts where migration state is reset but schema is not — particularly relevant given the known "Fresh-DB Provisioning Broken" issue. A column that already exists will cause the migration to fail.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `IF NOT EXISTS` to each `ADD COLUMN` clause in those seven migrations (e.g. `ADD COLUMN IF NOT EXISTS color_accent TEXT NULL`).
        - Note: migrations `20260530100000` and `20260530110000` already use `IF NOT EXISTS` correctly — newer migrations have adopted the safe pattern.
    - **Evidence:**
        ```sql
        -- 20260527080000 — lacks IF NOT EXISTS:
        ALTER TABLE site.design_kits
          ADD COLUMN color_accent TEXT NULL,
          ADD COLUMN color_bg TEXT NULL;

        -- 20260530110000 — correctly uses IF NOT EXISTS:
        ALTER TABLE site.design_kits
            ADD COLUMN IF NOT EXISTS icons_xl_size TEXT NULL;
        ```

- [ ] **#API-3** · P3 · S — Both request classes allowlist orphan `typography_font_heading` / `typography_font_body` columns that store NULL and are never consumed
    - **Where:** app/Http/Requests/Api/User/Site/UpdateSiteRequest.php:139–141, app/Http/Requests/Api/Staff/UserSite/StaffUpdateSiteRequest.php:64–66
    - **Affects:** API consumers who write these fields — they receive HTTP 200 and reasonably assume the value was persisted, but the columns currently exist only as unset stubs. Note: the comment in the code ("nothing reads them") is slightly misleading — `groupKitColumns` would surface them as `typography.fontHeading` if non-null; they are simply never written by any client.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - If no active client sends these keys: remove the two rules from both request classes and drop the orphan columns via migration.
        - If legacy clients do send them: keep the rules but add a `// TODO sunset:` comment with a target milestone.
    - **Evidence:**
        ```php
        // UpdateSiteRequest — intentionally kept but misleadingly commented:
        // Orphan typography slots from the earlier wiped vars — left in
        // the allowlist so the request doesn't 422 on legacy clients,
        // but the columns store NULL and nothing reads them.
        'design_kit.typography_font_heading' => ['sometimes', 'nullable', 'string', 'max:64'],
        'design_kit.typography_font_body'    => ['sometimes', 'nullable', 'string', 'max:64'],
        ```

- [ ] **#SEC-3** · P3 · S — No application-level rate limiting on the public profile endpoint
    - **Where:** app/Http/Controllers/Api/PublicSite/IndividualProfileController.php (no throttle middleware, no `RateLimiter` calls in `show()`)
    - **Affects:** Handle enumeration — an attacker cycling unique handles at volume can map which handles exist without application-layer resistance. The 30 s resolve cache absorbs repeated lookups of the same handle, but each unique miss triggers a DB query.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a `throttle:60,1` (60 requests per minute per IP) or a named `throttle:public_profile` rate limiter on the public profile route definition.
    - **Technical:** Cloudflare provides infrastructure-level DDoS protection as the primary defence; an application throttle is defence-in-depth for enumeration patterns that Cloudflare's default rules don't catch (low-volume, varied-handle scanning). The blast radius is limited to the public endpoint.
    - **Evidence:**
        ```php
        // IndividualProfileController — no throttle middleware, no RateLimiter calls:
        class IndividualProfileController extends ApiController
        {
            public function show(Request $request, string $handle): JsonResponse
            {
                // resolve-cache, payload build, response — no rate limiting anywhere
            }
        }
        ```

- [ ] **#SEC-5** · P3 · S — Color fields accept arbitrary strings with no format validation
    - **Where:** app/Http/Requests/Api/User/Site/UpdateSiteRequest.php and app/Http/Requests/Api/Staff/UserSite/StaffUpdateSiteRequest.php (all `design_kit.color_*` and `design_kit.button_*` rules)
    - **Affects:** The email Blade template, which renders colour values directly into inline CSS. A value like `#fff;}body{display:none` (under 32 chars) would terminate the inline style rule in an email client.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `regex:/^#[0-9a-fA-F]{3,8}$/` (hex only, which is the design kit's actual format) to every `design_kit.color_*` and `design_kit.button_*` rule in both Form Requests.
    - **Technical:** Laravel's `{{ }}` Blade escapes HTML entities, not CSS context. A value like `red;display:none` for `color_accent` in the email template would inject raw CSS into an inline `style=""` attribute. The write path is authenticated (professional or staff only), so blast radius is limited to the authenticated professional's own profile and emails. The `max:32` guard prevents the most obvious injections but allows valid-looking CSS attacks under that length.
    - **Evidence:**
        ```php
        // Both Form Requests — no hex/regex constraint on any color field:
        'design_kit.color_accent' => ['sometimes', 'nullable', 'string', 'max:32'],
        // 20+ fields, none with a hex/regex constraint

        // Email Blade template uses the value in inline CSS:
        // style="background-color:{{ $brand->palette->bg }};"
        ```

- [ ] **#SCALE-2** · P3 · S — `information_schema.columns` queried on every design-kit write
    - **Where:** app/Http/Controllers/Api/User/SiteManagement/UserSiteController.php:writeDesignKit() (column discovery query)
    - **Affects:** Every design-kit save from the dashboard. At 200 professionals this is low volume, but each save costs an extra metadata round-trip that changes only at deploy time.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace the per-request `information_schema.columns` query with a short-lived application cache: `Cache::remember('design_kits:columns', 3600, fn () => ...)` so it is fetched once per hour rather than once per save.
        - Bust the cache key during deploy via `artisan cache:clear` in the deploy script.
    - **Evidence:**
        ```php
        $columns = DB::connection('pgsql')
            ->table('information_schema.columns')
            ->where('table_schema', 'site')
            ->where('table_name', 'design_kits')
            ->pluck('column_name')
            ->all();
        ```

- [ ] **#TEST-8** · P3 · S — The controller's double-bust cache invalidation pattern is not covered end-to-end
    - **Where:** app/Http/Controllers/Api/User/SiteManagement/UserSiteController.php:48–55
    - **Affects:** Public profile cache correctness after a design kit write. The second `invalidateSite()` after `writeDesignKit()` covers the email-brand bundle reading the updated kit — removing it as "redundant" would pass the existing test suite.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `it('invalidates the public profile cache after a design_kit write via the update endpoint')` — call the `PATCH /api/professional/site` endpoint with a `design_kit` payload, then call `GET /api/public/profiles/{handle}` (with an empty cache) and assert the new colour is in the response.
    - **Evidence:**
        ```php
        // Controller — the double-bust (second bust is untested via controller path):
        $site = $action->execute($professional, $data);     // fires invalidateSite #1
        if (is_array($designKit)) {
            $this->writeDesignKit($site->id, $designKit);
            app(SiteCacheService::class)->invalidateSite($site); // invalidateSite #2
        }
        ```

- [ ] **#SCHEMA-2** · P3 · S — Migration `20260527070000` runs an inline full-table-scan UPDATE on `site.sites`
    - **Where:** supabase/migrations/20260527070000_skeleton_system_cleanup.sql (step 5, `UPDATE site.sites SET settings = settings - 'design' WHERE settings ? 'design'`)
    - **Affects:** Migration authoring pattern — at current row counts (≤200) this ran instantly. As `site.sites` grows, any future migration following this pattern risks long lock holds that queue DML during deploy.
    - **Effort:** S (~0.5–1h) — process / documentation fix; the migration has already run.
    - **What to do:**
        - Document in the team's migration guidelines: any one-shot data scrub on a live table should be extracted into a post-deploy job rather than embedded inline. The same migration's own comments acknowledge this pattern for the design_kits backfill but don't apply it to the settings scrub directly above it.
        - For future similar cleanup migrations, use a chunked loop or dispatch an artisan command post-deploy.
    - **Evidence:**
        ```sql
        -- Step 5 in 20260527070000 — inline full-table scan:
        UPDATE site.sites SET settings = settings - 'design' WHERE settings ? 'design';

        -- The same migration's comment on design_kits backfill (correct pattern documented but not applied here):
        -- "existing sites are backfilled separately (see Phase 2 step 2.4 in the plan —
        --  not in the migration so the backfill window stays predictable)."
        ```

- [ ] **#SCALE-1** · P3 · S — `skeleton_id` CHECK constraint added without `NOT VALID` on `site.sites`
    - **Where:** supabase/migrations/20260527070000_skeleton_system_cleanup.sql (ALTER TABLE block adding `skeleton_id`)
    - **Affects:** Migration authoring pattern — this migration has already run. At current row counts (≤200) the validation scan was instant. On a table with millions of rows, `ADD COLUMN ... CHECK(...)` validates every existing row inline and holds an `ACCESS EXCLUSIVE` lock.
    - **Effort:** S (~0.5h) — process / documentation fix; the migration has already run.
    - **What to do:**
        - Document in team migration guidelines: CHECK constraints on large tables should use `ADD CONSTRAINT ... NOT VALID` first, then `VALIDATE CONSTRAINT` as a separate statement to avoid holding an exclusive lock while the backfill scan runs. Since the `DEFAULT 'skeleton-1'` ensures every row satisfies the constraint, the `NOT VALID` → `VALIDATE` split is safe and equivalent.
    - **Evidence:**
        ```sql
        ALTER TABLE site.sites
          DROP COLUMN theme_id,
          ADD COLUMN skeleton_id TEXT NOT NULL
            DEFAULT 'skeleton-1'
            CHECK (skeleton_id IN ('skeleton-1','skeleton-2','skeleton-3','skeleton-4'));
        -- No NOT VALID + VALIDATE CONSTRAINT split.
        ```

- [ ] **#SCALE-3** · P3 · S — Design-kit DDL migrations lack `lock_timeout` / `statement_timeout` guards
    - **Where:** supabase/migrations/20260527080000 through 20260530130000 (10 migration files adding/dropping columns on `site.design_kits`)
    - **Affects:** Future deploy safety as `site.design_kits` grows. At 200 rows all DDL runs in microseconds; but the pattern of unguarded `ALTER TABLE` on a table served by the live public-profile endpoint normalises a risk that grows with scale.
    - **Effort:** S (~0.5h) — process / documentation fix; these migrations have already run.
    - **What to do:**
        - Add `SET LOCAL lock_timeout = '2s'; SET LOCAL statement_timeout = '10s';` at the top of every future migration that runs DDL against tables queried by live traffic (`site.design_kits`, `site.sites`, `site.blocks`).
        - `SET LOCAL` scopes the timeout to the migration transaction only, so it doesn't affect application queries.
    - **Evidence:**
        ```sql
        -- No lock_timeout / statement_timeout before any of the 10 design_kit migrations, e.g.:
        -- 20260527080000:
        ALTER TABLE site.design_kits
          ADD COLUMN color_accent TEXT NULL,
          ADD COLUMN color_bg TEXT NULL, ...;

        -- 20260529053028 (22 drops + 10 adds in one statement):
        ALTER TABLE site.design_kits
          DROP COLUMN padding_extra_small,
          DROP COLUMN padding_small, ...
          ADD COLUMN space_xs TEXT NULL, ...;
        ```

`★ Insight ─────────────────────────────────────`
**Three systemic patterns this audit surfaces:** (1) **Request-schema drift** (SCHEMA-1, SEC-1, API-2/CFG-2 dropped as duplicates) — design-kit column changes have no mandatory code checklist; TEST-5's structural drift test would close that loop permanently as a CI gate. (2) **Partial SWR adoption** (CCH-1, CCH-3) — `CacheLockService::rememberLocked` is well-designed and already injected in the right places, but the resolve-cache hot path and the SWR fill-lock use the unguarded alternatives; the infrastructure is correct and the call-sites just need to use it. (3) **The `icons_*` blind spot** (KIT-1) — `groupKitColumns` mixes singular (`icon`) and plural (`colors`, `borders`) key prefixes; any future column starting with a plural-form group prefix will silently drop unless the map entry is added proactively.
`─────────────────────────────────────────────────`
