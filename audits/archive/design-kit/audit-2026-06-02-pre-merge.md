`★ Insight ─────────────────────────────────────`
**Adjudication pass reveals:** (1) A complete dedup cascade — API-2/CFG-1/TEST-1 are the same root cause across three lens scans, as are CFG-2/TEST-2. (2) DeepSeek missed a live P1 bug: the `groupKitColumns` prefix map uses `'icon'` (singular) but four DB columns use the `icons_` (plural) prefix, silently dropping them from every public profile response. (3) MIG-3's "partial application" concern is a PostgreSQL misunderstanding — a single `ALTER TABLE` statement is atomic, so partial column drops are impossible.
`─────────────────────────────────────────────────`

# Pre-Merge Sweep Audit — 2026-06-02

**Branch:** development
**Lens:** Bundle 'pre-merge' audit — migration safety (MIG-*), API contract (API-*), configuration hygiene (CFG-*), and test coverage gaps (TEST-*)
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
- supabase/migrations/20260527070000_skeleton_system_cleanup.sql
- supabase/migrations/20260527080000_design_kit_initial_vars.sql
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
- app/Services/PublicSite/IndividualProfilePayloadBuilder.php
- app/Http/Resources/PublicSite/IndividualProfileResource.php
- app/Mail/Branding/EmailBrand.php
- tests/Feature/Api/PublicSite/IndividualProfileControllerTest.php
- tests/Feature/Notifications/DesignKitWriteInvalidatesBrandTest.php

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 3 complete
- P2 Medium: 0 of 7 complete
- P3 Low: 0 of 7 complete

---

## P1 — Fix before pilot launch

- [ ] **#API-1** · P1 — StaffSiteController returns a raw PHP array, bypassing the Resource layer entirely
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

- [ ] **#API-2** · P1 — StaffUpdateSiteRequest still validates 20+ columns dropped by migration 20260529053028 while missing all replacement `space_*` rules
    - **Where:** app/Http/Requests/Api/Staff/UserSite/StaffUpdateSiteRequest.php (spacing/padding/tablet groups, ~lines 85–120)
    - **Affects:** Staff updating a professional's design kit via the staff API. Submissions of `spacing_*` / `padding_*` / `*_tablet_*` values receive HTTP 200 but are silently discarded by `writeDesignKit()` (the columns no longer exist, so `array_intersect_key` against `information_schema` filters them out). The replacement `space_xs`, `space_s`, `space_regular`, `space_medium`, `space_large` and their `space_desktop_*` companions are absent from the rules array, so Laravel's `$request->validated()` strips them — staff cannot write the new unified space scale at all.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Remove from `StaffUpdateSiteRequest::rules()`: all `design_kit.spacing_*`, `design_kit.padding_*`, `design_kit.spacing_tablet_*`, `design_kit.padding_tablet_*`, `design_kit.sizing_tablet_button_height`, `design_kit.sizing_tablet_input_height`, and `design_kit.typography_tablet_font_size` rules — these columns were dropped in migration `20260529053028`.
        - Add the missing rules matching `UpdateSiteRequest`: `design_kit.space_xs`, `space_s`, `space_regular`, `space_medium`, `space_large` and all five `design_kit.space_desktop_*` companions.
        - Add a comment block at the top of the design_kit section of each request class pointing at the other: "Must stay in sync with UpdateSiteRequest / StaffUpdateSiteRequest — see TEST-5."
        - Add a CI-enforced structural test (see TEST-5) to prevent this drift from recurring silently.
    - **Technical:** Migration `20260529053028_design_kit_unified_space_scale.sql` dropped the entire `padding_*` (8 columns, base + desktop), `spacing_*` (8 columns, base + desktop), and tablet-tier columns (12 columns), replacing them with a five-step `space_*` scale + `space_desktop_*` overrides. `UpdateSiteRequest` was updated to match; `StaffUpdateSiteRequest` was not. The `writeDesignKit()` method in `UserSiteController` silently drops non-existent column keys via its `information_schema.columns` intersection — so staff receive 200 on writes that save nothing. The staff endpoint is the only path for staff to assist a professional with design issues; it is currently non-functional for spacing.
    - **Plain English:** The customer support dashboard's design editor is speaking an old dialect that the database no longer understands. When a staff member tries to adjust a professional's spacing settings to help resolve a layout complaint, the system nods politely and throws the change away — no error, no warning. Meanwhile the new spacing controls that replaced the old ones aren't wired into the staff form at all, so there is no way for staff to set them. The user-facing editor was updated when the database changed; the staff editor was forgotten.
    - **Evidence:**
        ```php
        // StaffUpdateSiteRequest — still validating columns dropped in 20260529053028:
        'design_kit.spacing_extra_small' => ['sometimes', 'nullable', 'string', 'max:16'],
        'design_kit.spacing_small'       => ['sometimes', 'nullable', 'string', 'max:16'],
        'design_kit.spacing_general'     => ['sometimes', 'nullable', 'string', 'max:16'],
        'design_kit.spacing_large'       => ['sometimes', 'nullable', 'string', 'max:16'],
        'design_kit.padding_extra_small' => ['sometimes', 'nullable', 'string', 'max:16'],
        'design_kit.padding_small'       => ['sometimes', 'nullable', 'string', 'max:16'],
        'design_kit.padding_tablet_extra_small' => ['sometimes', 'nullable', 'string', 'max:16'],
        'design_kit.sizing_tablet_button_height' => ['sometimes', 'nullable', 'string', 'max:16'],
        'design_kit.typography_tablet_font_size' => ['sometimes', 'nullable', 'string', 'max:32'],
        // space_xs … space_large and space_desktop_* are ABSENT — staff cannot write them
        ```
        ```sql
        -- 20260529053028 — the migration that dropped these:
        ALTER TABLE site.design_kits
          DROP COLUMN spacing_extra_small,
          DROP COLUMN padding_extra_small,
          DROP COLUMN padding_tablet_extra_small,
          DROP COLUMN sizing_tablet_button_height,
          DROP COLUMN typography_tablet_font_size,
          -- … plus 22 more …
          ADD COLUMN space_xs TEXT NULL,
          ADD COLUMN space_s  TEXT NULL;
        ```

- [ ] **#KIT-1** · P1 — `groupKitColumns` silently drops four `icons_*`-prefixed columns from every public profile response
    - **Where:** app/Services/PublicSite/IndividualProfilePayloadBuilder.php:482–492 (`$singleTokenPrefixes` map)
    - **Affects:** Public profile API (`GET /api/public/profiles/{handle}`) for every professional who has stored a value for any of `icons_xl_size`, `icons_xxl_size`, `icons_stroke_width`, or `icons_large_stroke_width`. Values are accepted by the write API and persisted to the DB, but are never returned in the profile response — the design system receives null for all four, forcing its code-side defaults regardless of user customisation.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `'icons' => 'icons'` to the `$singleTokenPrefixes` map in `groupKitColumns()`. This maps the `icons_` column prefix to the `icons` wire group, producing `icons.xlSize`, `icons.strokeWidth`, `icons.largeStrokeWidth`, etc.
        - Extend the `design_kits` stub in `IndividualProfileControllerTest` to include at least one `icons_xl_size` column and assert it appears under `designKit.icons.xlSize` in the response (currently only single-token prefix columns are stubbed and tested).
    - **Technical:** The prefix-routing logic in `groupKitColumns()` uses the first underscore position to extract a single-token prefix, then looks it up in `$singleTokenPrefixes`. The column `icon_size` produces token `icon` → group `icons` ✓. But `icons_xl_size` produces token `icons` (5 chars, up to the first `_`) → lookup `$singleTokenPrefixes['icons']` → `null` → the column is silently dropped via `continue`. All four affected columns (`icons_xl_size` from `20260530110000`, `icons_xxl_size` from `20260530120000`, `icons_stroke_width` and `icons_large_stroke_width` from `20260530130000`) share this prefix. Both `UpdateSiteRequest` and `StaffUpdateSiteRequest` correctly validate these fields for write — so data is persisted but never read back to the renderer. The test suite's design_kits stub (created inline in `IndividualProfileControllerTest`) only contains `color_*` and `typography_*` columns, so this bug has zero test coverage.
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

---

## P2 — Should fix

- [ ] **#MIG-1** · P2 — `CREATE TRIGGER` in skeleton cleanup migration is not idempotent
    - **Where:** supabase/migrations/20260527070000_skeleton_system_cleanup.sql (near the end, after the `CREATE OR REPLACE FUNCTION` block)
    - **Affects:** Deploy reliability on any environment where the migration runner is re-applied after a partial failure — specifically relevant given the "Fresh-DB Provisioning Broken" situation noted in project memory. A second run will hit `ERROR: trigger "trg_create_empty_design_kit" already exists`.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `DROP TRIGGER IF EXISTS trg_create_empty_design_kit ON site.sites;` immediately before the `CREATE TRIGGER` statement.
        - Note: the function already uses `CREATE OR REPLACE FUNCTION` (idempotent) — only the trigger needs the guard.
    - **Technical:** The migration uses `DROP VIEW IF EXISTS` (idempotent) and `DROP TABLE IF EXISTS … CASCADE` (idempotent) throughout, but the trigger creation at the end is plain `CREATE TRIGGER` with no guard. If Supabase's migration runner is re-applied on a fresh DB provisioning attempt that failed after this point, or if the migration is manually retried, the trigger creation will fail with a duplicate-trigger error and block every subsequent migration. `CREATE TRIGGER IF NOT EXISTS` does not exist in PostgreSQL — the correct pattern is `DROP TRIGGER IF EXISTS … ON site.sites` before creation.
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

- [ ] **#TEST-6** · P2 — No test exercises the single-flight lock path in IndividualProfileController
    - **Where:** app/Http/Controllers/Api/PublicSite/IndividualProfileController.php:63–79 (`rememberLocked` call)
    - **Affects:** Public profile endpoint under traffic spikes. If `CacheLockService::rememberLocked` regresses (e.g. lock never releases, stale-while-revalidate stops serving), the symptom is 504s under load — the hardest failure mode to debug in production.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add `it('single-flights concurrent requests so only one payload is built')` — mock `IndividualProfilePayloadBuilder` with `->shouldReceive('build')->once()`, make two sequential requests with the same handle against a warm resolve cache, assert build was called exactly once and both responses are identical.
        - Add `it('handles a race where the site is deleted between resolve and payload cache reads')` — seed a resolve cache entry pointing at a now-deleted user ID, call the endpoint, assert 404 and that the resolve cache entry is subsequently forgotten.
    - **Technical:** `IndividualProfileController::show()` uses `CacheLockService::rememberLocked` (jittered SWR + single-flight) to prevent stampede when many visitors hit the same profile simultaneously. The lock acquisition, timeout, and stale-serve paths are all inside `CacheLockService`. No test in `IndividualProfileControllerTest` exercises the `rememberLocked` path with any mock on the builder — all tests let the builder run and seed their own DB fixtures. A lock-service regression cannot be caught by the current test suite until it manifests as load-induced 504s.
    - **Plain English:** When many people visit the same profile at once, the system has a "one person fetches the page, everyone else waits for the result" mechanism. This mechanism has never been tested — only one visitor at a time has ever been simulated. If a code change accidentally breaks the mechanism (say, the "wait" part stops working), the first sign is the server falling over under real traffic, not a failing test before the code ships.
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
        ```php
        // IndividualProfileControllerTest — all tests call the real builder; no mock on build():
        it('returns 200 with the skeleton-system envelope shape for an individual', ...);
        it('groups stored design_kit columns into nested camelCase wire shape', ...);
        // no it('single-flights concurrent requests …')
        ```

- [ ] **#TEST-4** · P2 — Two-token responsive prefix path in `groupKitColumns` has no test coverage
    - **Where:** app/Services/PublicSite/IndividualProfilePayloadBuilder.php:474–506 (two-token prefix loop)
    - **Affects:** Public profile API — `space_desktop_*`, `sizing_desktop_*`, and `typography_desktop_*` columns would route to the wrong wire group or be dropped entirely if the two-token prefix loop is accidentally reordered or removed.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Extend the `design_kits` stub in `IndividualProfileControllerTest` to include `space_desktop_regular TEXT NULL`.
        - Add `it('groups two-token responsive prefix columns into the correct nested group')` — insert `space_desktop_regular = '2rem'` and assert the response contains `designKit.spaceDesktop.regular = '2rem'`.
        - Add `it('two-token prefix wins over single-token when they share an initial token')` — verify `space_desktop_regular` lands in `spaceDesktop`, not `space`.
    - **Technical:** The existing test `'groups stored design_kit columns into nested camelCase wire shape'` only seeds `color_accent` and `typography_font_heading` — both single-token prefixes. The two-token prefix loop (`space_desktop`, `sizing_desktop`, `typography_desktop`) runs first and is responsible for routing all responsive companion columns. It has zero test coverage. A refactor that reorders the two loops, or removes the two-token loop assuming the single-token loop is sufficient, would silently break all responsive columns in every public profile.
    - **Plain English:** The profile builder has two sorting machines — one for simple labels like `color_accent` and one for compound labels like `space_desktop_regular`. The quality checklist only tests the simple machine. If someone swaps the two machines' order during a future cleanup, compound labels get sorted into wrong bins and the public page looks broken — and no test catches it before it ships.
    - **Evidence:**
        ```php
        // The two-token prefix loop — currently untested:
        $twoTokenPrefixes = [
            'space_desktop'      => 'spaceDesktop',
            'sizing_desktop'     => 'sizingDesktop',
            'typography_desktop' => 'typographyDesktop',
        ];
        foreach ($twoTokenPrefixes as $prefix => $candidateGroup) {
            if (str_starts_with($column, $prefix.'_')) {
                $group = $candidateGroup;
                $rest  = substr($column, strlen($prefix) + 1);
                break;
            }
        }
        ```
        ```php
        // Test stub only has single-token columns — two-token path never exercises:
        DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS site.design_kits (
            site_id TEXT PRIMARY KEY,
            color_accent TEXT NULL,
            color_bg TEXT NULL,
            color_text TEXT NULL,
            typography_font_heading TEXT NULL,
            typography_font_body TEXT NULL
        )');
        ```

- [ ] **#TEST-5** · P2 — No structural test catches drift between `UpdateSiteRequest` and `StaffUpdateSiteRequest` design_kit rules
    - **Where:** app/Http/Requests/Api/User/Site/UpdateSiteRequest.php, app/Http/Requests/Api/Staff/UserSite/StaffUpdateSiteRequest.php
    - **Affects:** Every future design_kit column addition — without a CI guard, the staff request class will silently fall behind again (as demonstrated by the API-2 and CFG-2 findings in this audit).
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add `tests/Feature/Requests/DesignKitRequestDriftTest.php` with a structural test that: (a) queries `information_schema.columns` for `site.design_kits` and asserts every column has a corresponding validation rule in both request classes, and (b) asserts no rule in either class references a column that doesn't exist in the table. Follow the `PolicyCoverageTest` pattern already in the codebase.
        - Add a simpler companion test: `it('accepts a valid partial design_kit payload')` and `it('rejects settings.design with 422')` against both classes using `$request->validateResolved()`.
    - **Technical:** `UpdateSiteRequest` has ~50 design_kit rules; `StaffUpdateSiteRequest` has its own set. The drift revealed in this audit (API-2: 20+ stale columns; CFG-2: 3 missing color columns) proves the two classes are not kept in sync by any automated check. The codebase already applies the structural-sweep pattern in `PolicyCoverageTest` and `MailableCategoryCoverageTest` — a `DesignKitRequestDriftTest` using `information_schema.columns` follows the same pattern and would catch future column additions that are wired to one request class but not the other.
    - **Plain English:** The design-kit form has around 50 fields. There are two doorways to the same room — one for users, one for staff. Today there is no nightly inventory that checks both doorways have the same set of keys. This audit found the staff doorway's key ring is missing 20+ keys the database no longer recognises, plus 3 keys it should have. A structural test is like that nightly inventory — if a new key is added to one ring but not the other, the build fails immediately, before the code ships.
    - **Evidence:**
        ```php
        // UpdateSiteRequest — space_* rules present:
        'design_kit.space_xs'      => ['sometimes', 'nullable', 'string', 'max:16'],
        'design_kit.space_s'       => ['sometimes', 'nullable', 'string', 'max:16'],
        'design_kit.space_regular' => ['sometimes', 'nullable', 'string', 'max:16'],
        'design_kit.space_medium'  => ['sometimes', 'nullable', 'string', 'max:16'],
        'design_kit.space_large'   => ['sometimes', 'nullable', 'string', 'max:16'],
        ```
        ```php
        // StaffUpdateSiteRequest — no space_* rules at all; stale spacing_* rules instead:
        'design_kit.spacing_extra_small' => ['sometimes', 'nullable', 'string', 'max:16'],
        'design_kit.spacing_small'       => ['sometimes', 'nullable', 'string', 'max:16'],
        // … space_xs through space_desktop_large are absent
        ```

- [ ] **#TEST-7** · P2 — `writeDesignKit()` is never called through a test; its FK-protection guard and column-filter logic are untested
    - **Where:** app/Http/Controllers/Api/User/SiteManagement/UserSiteController.php:84–103
    - **Affects:** User-facing site update endpoint (`PATCH /api/professional/site`). A bug in the `information_schema` intersection, the `unset($valid['site_id'])` guard, or the empty-kit short-circuit would silently corrupt or silently drop design kit writes with no test failure.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `it('persists only columns that exist on site.design_kits')` — POST a design_kit payload with one valid key (`color_accent`) and one bogus key (`nonexistent_column`); assert only `color_accent` is updated in the DB.
        - Add `it('silently ignores an empty design_kit array')` — POST `design_kit: []`; assert no DB write occurs.
        - Add `it('strips site_id if a caller attempts to rewrite the FK')` — POST `design_kit: { site_id: 'other-uuid', color_accent: '#fff' }`; assert `site_id` is unchanged and `color_accent` is updated.
    - **Technical:** `DesignKitWriteInvalidatesBrandTest` (the only test that touches design kit persistence) writes directly via `DB::table('site.design_kits')->update(...)`, completely bypassing `writeDesignKit()`. The method's three guarantees — (1) only real columns pass through, (2) the `site_id` FK cannot be overwritten, (3) an empty payload is a no-op — are all unverified. The `information_schema` query is particularly critical: if Supabase changes the visibility rules for `information_schema.columns` under the `app_backend` role, `$valid` would become an empty array and all writes would silently stop working.
    - **Plain English:** The design-kit write path has a smart filter that checks which paint slots actually exist on the wall before hanging any colours, and it has a lock that stops callers from changing which wall the paint goes on. Neither of these safety features has ever been tested directly. The only test that checks "paint ended up on the wall" sneaked in through the back door and hung the paint itself, bypassing the filter entirely. If the filter jams or the lock is accidentally removed in a refactor, no test will catch it.
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

- [ ] **#TEST-3** · P2 — No test verifies the CHECK constraint, cascading FK, or auto-create trigger from the skeleton cleanup migration
    - **Where:** supabase/migrations/20260527070000_skeleton_system_cleanup.sql (skeleton_id CHECK, design_kits FK, trg_create_empty_design_kit trigger)
    - **Affects:** Fresh database provisioning — if the migration applies incorrectly (wrong constraint expression, trigger not firing, FK direction reversed), the system would accept invalid skeleton IDs, allow orphan design_kits rows, or silently fail to create a kit on site creation. All three scenarios are currently invisible until a user reports a broken site. This is especially relevant given the "Fresh-DB Provisioning Broken" issue in project memory.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add `tests/Feature/Migrations/SkeletonSystemConstraintsTest.php` with three tests:
            - `it('rejects invalid skeleton_id values')` — attempt to INSERT `skeleton_id = 'skeleton-99'` and assert a constraint violation is thrown.
            - `it('prevents orphan design_kits rows via FK')` — attempt to INSERT into `site.design_kits` with a non-existent `site_id` and assert FK violation.
            - `it('auto-creates an empty design_kits row on site insert')` — INSERT a `site.sites` row and assert exactly one matching row appears in `site.design_kits`. Note this requires the real PostgreSQL stack (Supabase), not the SQLite test double — skip if running on SQLite.
        - Note: the existing `IndividualProfileControllerTest` stubs `design_kits` in SQLite, which cannot enforce PostgreSQL CHECK constraints or triggers — these tests require a real Postgres connection.
    - **Technical:** The migration introduces three structural invariants that are never verified post-apply: the TEXT CHECK enum on `skeleton_id`, the `ON DELETE CASCADE` FK from `design_kits` to `sites`, and the `AFTER INSERT` trigger that auto-inserts the kit row. The SQLite test double used throughout the test suite does not enforce PostgreSQL CHECK constraints (SQLite ignores them) and does not run PostgreSQL triggers, so integration test coverage of these invariants is zero. A fresh provisioning failure — e.g. the trigger not firing because the function body has a bug, or the CHECK being accidentally dropped — would not surface until a user creates an account and their design kit is missing.
    - **Plain English:** When the builders finished the renovation, the architect specified three safety rules: only four approved skeletons are allowed, every design kit must belong to a real site, and a new kit is automatically created whenever a new site is built. No inspector has come to verify any of these three things actually work in a real database environment. The practice test environment (SQLite) quietly ignores all three rules, so the tests pass even if the rules are broken. Adding tests that run against the real database would catch a broken rule before customers see a broken site.
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

- [ ] **#CFG-2** · P2 — StaffUpdateSiteRequest missing validation rules for three color columns added in migration 20260529044737
    - **Where:** app/Http/Requests/Api/Staff/UserSite/StaffUpdateSiteRequest.php (colors group, after `color_accent_contrast`)
    - **Affects:** Staff cannot write `color_placeholder`, `color_contrasting_bg`, or `color_contrasting_text` via the staff API — Laravel's `$request->validated()` strips any key absent from `rules()`, so these keys never reach `writeDesignKit()`.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add to `StaffUpdateSiteRequest::rules()` immediately after `design_kit.color_accent_contrast`:
            ```php
            'design_kit.color_placeholder'      => ['sometimes', 'nullable', 'string', 'max:32'],
            'design_kit.color_contrasting_bg'   => ['sometimes', 'nullable', 'string', 'max:32'],
            'design_kit.color_contrasting_text' => ['sometimes', 'nullable', 'string', 'max:32'],
            ```
        - The structural drift test (TEST-5) will prevent this class of omission from recurring.
    - **Technical:** Migration `20260529044737_design_kit_contrasting_colors.sql` added `color_contrasting_bg`, `color_contrasting_text`, and `color_placeholder` to `site.design_kits`. `UpdateSiteRequest` was updated with matching rules; `StaffUpdateSiteRequest` was not. The columns exist in the DB and `writeDesignKit()` would persist them — but because they are absent from the staff request's `rules()` array, `$request->validated()` silently strips them before the controller ever sees them. (Note: `typography_desktop_h1/h2_font_size` and `effect_overlay_opacity` are present in `StaffUpdateSiteRequest` — the draft finding CFG-2 overstated the scope; only these three color columns are confirmed missing.)
    - **Plain English:** Three colour palette slots were added to the design-kit system — a placeholder colour and two contrasting colours for alternate-background sections. The user-facing settings form was updated to include them; the staff form was not. If a support agent needs to help a professional set their contrasting colours, they physically cannot — the staff form doesn't know those slots exist, so the values are silently thrown away.
    - **Evidence:**
        ```php
        // UpdateSiteRequest — has all three (StaffUpdateSiteRequest is missing them):
        'design_kit.color_placeholder'      => ['sometimes', 'nullable', 'string', 'max:32'],
        'design_kit.color_contrasting_bg'   => ['sometimes', 'nullable', 'string', 'max:32'],
        'design_kit.color_contrasting_text' => ['sometimes', 'nullable', 'string', 'max:32'],
        ```
        ```sql
        -- 20260529044737 — confirms the columns exist in DB:
        ALTER TABLE site.design_kits
          ADD COLUMN color_contrasting_bg  TEXT NULL,
          ADD COLUMN color_contrasting_text TEXT NULL,
          ADD COLUMN color_placeholder     TEXT NULL;
        ```

---

## P3 — Nice to have

- [ ] **#TEST-8** · P3 — The controller's double-bust cache invalidation pattern is not covered end-to-end
    - **Where:** app/Http/Controllers/Api/User/SiteManagement/UserSiteController.php:48–55
    - **Affects:** Public profile cache correctness after a design kit write. The `invalidateSite()` after `writeDesignKit()` is the only invalidation that covers the email-brand bundle reading the updated kit — removing it as "redundant" would pass the existing test suite.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `it('invalidates the public profile cache after a design_kit write via the update endpoint')` — call the `PATCH /api/professional/site` endpoint with a `design_kit` payload, then call `GET /api/public/profiles/{handle}` (with an empty cache) and assert the new colour is in the response.
    - **Technical:** `update()` calls `$action->execute()` (fires `invalidateSite`) BEFORE `writeDesignKit()`, then calls `invalidateSite` a second time after the design kit write. The second call is intentional — the email-brand resolver reads `site.design_kits` inside `CacheLockService::rememberLocked`, and without the second bust it would serve stale palette data until TTL expiry. `DesignKitWriteInvalidatesBrandTest` covers the invalidation path but writes directly to the DB, bypassing the controller — a developer removing the "redundant" second bust would see all existing tests pass.
    - **Plain English:** When someone saves new colours, the system clears the public page's cache twice — once for the general site update, and once specifically for the colour change (because the cache for the colour data runs separately and needs its own clearing). The test that checks "the colour change cleared the right cache" bypasses the save button entirely and writes directly to the database, so it would never catch someone deleting the second cache-clear step.
    - **Evidence:**
        ```php
        // Controller — the double-bust (second bust is untested via controller path):
        $site = $action->execute($professional, $data);     // fires invalidateSite #1
        if (is_array($designKit)) {
            $this->writeDesignKit($site->id, $designKit);
            app(SiteCacheService::class)->invalidateSite($site); // invalidateSite #2
        }
        ```

- [ ] **#MIG-4** · P3 — `UPDATE site.sites SET settings = settings - 'design'` is not batched
    - **Where:** supabase/migrations/20260527070000_skeleton_system_cleanup.sql:42
    - **Affects:** Deploy time on a table that grows to tens of thousands of rows. A single-statement UPDATE holds row-level locks for its entire duration, blocking concurrent writes to `site.sites`.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - At current (pre-beta, zero-user) scale this migration has already run on an effectively empty table — no immediate action needed.
        - For future large-table migrations of this kind, use a chunked loop (`WHERE settings ? 'design' LIMIT 1000`) or consider a deferred backfill job rather than inline DDL.
    - **Technical:** A single `UPDATE site.sites SET settings = settings - 'design' WHERE settings ? 'design'` acquires row-level locks on every matching row for the full duration. On a table with thousands of rows and concurrent reads/writes from the Astro Worker subrequest path, the lock hold time could cause visible latency spikes. At current scale (pre-beta, no live users) this is academic, but it establishes a pattern that will need to change before any future large-table backfill migration is authored.
    - **Plain English:** The migration that strips old design settings out of every site record does all the work at once — like repainting every door in an office building simultaneously, blocking everyone from walking through any door. At zero occupancy this is fine. When the building is full, you'd want to do one floor at a time. No action needed now; worth noting as a pattern to avoid in future large-data migrations.
    - **Evidence:**
        ```sql
        -- Single-statement UPDATE — no batching:
        UPDATE site.sites SET settings = settings - 'design' WHERE settings ? 'design';
        ```

- [ ] **#MIG-5** · P3 — Early design-kit `ADD COLUMN` migrations lack `IF NOT EXISTS` guard
    - **Where:** supabase/migrations/20260527080000 through 20260527140000 (all pre-unified-space migrations that use plain `ADD COLUMN`)
    - **Affects:** Fresh database provisioning attempts where migration state is reset but schema is not — particularly relevant given the known "Fresh-DB Provisioning Broken" issue. A column that already exists will cause the migration to fail.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `IF NOT EXISTS` to each `ADD COLUMN` clause in the seven migrations listed above (e.g. `ADD COLUMN IF NOT EXISTS color_accent TEXT NULL`).
        - Note: migrations `20260530100000` and `20260530110000` already use `IF NOT EXISTS` correctly — newer migrations have adopted the safe pattern.
    - **Technical:** PostgreSQL 9.6+ supports `ALTER TABLE … ADD COLUMN IF NOT EXISTS`. Migrations that lack this guard will fail if re-applied to a DB where the columns already exist (e.g. after a manual `supabase db reset` that clears migration history without clearing the schema). The later migrations (`20260530*`) were authored with `IF NOT EXISTS`; the earlier ones (`20260527080000`–`20260527140000`) were not. Given the project's documented fresh-provisioning issues, idempotent `ADD COLUMN` is worth the one-word change.
    - **Plain English:** Seven early database scripts that add new design-setting columns don't say "only add this if it doesn't exist yet." If someone runs a database reset and the system tries to re-apply these scripts on a database that already has the columns, the scripts will crash. The newer scripts added after May 30 already say "only add if not exists" — the earlier ones just need the same one-word safety phrase added.
    - **Evidence:**
        ```sql
        -- 20260527080000 — lacks IF NOT EXISTS (contrast with newer migrations):
        ALTER TABLE site.design_kits
          ADD COLUMN color_accent TEXT NULL,
          ADD COLUMN color_bg TEXT NULL,
          ADD COLUMN color_text TEXT NULL,
          ADD COLUMN typography_font_heading TEXT NULL,
          ADD COLUMN typography_font_body TEXT NULL;
        ```
        ```sql
        -- 20260530110000 — correctly uses IF NOT EXISTS:
        ALTER TABLE site.design_kits
            ADD COLUMN IF NOT EXISTS icons_xl_size TEXT NULL,
            ADD COLUMN IF NOT EXISTS effect_overlay_opacity TEXT NULL;
        ```

- [ ] **#API-3** · P3 — Both request classes allowlist orphan `typography_font_heading` / `typography_font_body` columns that store NULL and are never consumed
    - **Where:** app/Http/Requests/Api/User/Site/UpdateSiteRequest.php:139–141, app/Http/Requests/Api/Staff/UserSite/StaffUpdateSiteRequest.php:64–66
    - **Affects:** API consumers who write these fields — they receive HTTP 200 and reasonably assume the value was persisted, but the columns exist only as NULL-only stubs with nothing reading them downstream.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - If no active client sends these keys: remove the two rules from both request classes and add a migration that formally drops the orphan columns, cleaning up the DB.
        - If legacy clients do send them and removing the rules would cause 422 responses: keep the rules but add a `// TODO sunset: remove when all clients drop typography_font_heading` comment with a target milestone, and open a tracking issue.
    - **Technical:** The columns `typography_font_heading` and `typography_font_body` were added in `20260527080000` and are explicitly commented in both request classes as "orphan slots… columns store NULL and nothing reads them." They pass validation and survive the `information_schema` filter in `writeDesignKit()` (the columns exist), so a client sending them receives 200 and the value is written to DB. However, `groupKitColumns` maps `typography_font_heading` → `typography.fontHeading` in the public response — meaning the stored value WOULD appear in the profile if non-null. The comment in the code is therefore slightly misleading: the values are stored and would be read; it's just that no client currently sets them. Clarify intent and sunset accordingly.
    - **Plain English:** Two light switches on the design-kit panel are labelled as disconnected in the code's comments — but they're actually connected to a live wire; it's just that no remote currently sends a signal to them. A developer reading the comment might remove the wiring thinking it's dead. Deciding whether to remove them or formally document them as deprecated prevents the confusion.
    - **Evidence:**
        ```php
        // UpdateSiteRequest — intentionally kept but misleadingly commented:
        // Orphan typography slots from the earlier wiped vars — left in
        // the allowlist so the request doesn't 422 on legacy clients,
        // but the columns store NULL and nothing reads them.
        'design_kit.typography_font_heading' => ['sometimes', 'nullable', 'string', 'max:64'],
        'design_kit.typography_font_body'    => ['sometimes', 'nullable', 'string', 'max:64'],
        ```
        ```php
        // StaffUpdateSiteRequest — identical comment and rules:
        'design_kit.typography_font_heading' => ['sometimes', 'nullable', 'string', 'max:64'],
        'design_kit.typography_font_body'    => ['sometimes', 'nullable', 'string', 'max:64'],
        ```

- [ ] **#CFG-4** · P3 — `EmailBrand::partna()` hardcodes `'https://partna.au'` rather than reading from config
    - **Where:** app/Mail/Branding/EmailBrand.php:30
    - **Affects:** Non-production environments (staging, local dev) that send Partna-branded emails — footer links in those emails point to the live production site regardless of environment.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `siteUrl: 'https://partna.au'` with `siteUrl: (string) config('app.partna_marketing_url', 'https://partna.au')`.
        - Add `PARTNA_MARKETING_URL=https://partna.au` to `.env.example` with a comment.
        - Note: `EmailBrand::fromArray()` also falls back to `'https://partna.au'` — update both.
    - **Technical:** Every pro-branded email already constructs the site URL dynamically from `$user->handle`: `'https://'.$user->handle.'.partna.au'`. Only the Partna-platform-branded variant is hardcoded. Staging and development environments that trigger transactional emails via the `EmailBrand::partna()` path will produce footer links pointing to the production marketing site, which is potentially confusing for test recipients and may surface in pre-launch QA.
    - **Plain English:** When the staging server sends a Partna-branded email — for example, a policy update — the "visit Partna" link in the footer always points to the live production website. A tester or staff member receiving that email clicks through and lands on the real site, not the staging environment. Making the URL configurable per environment means staging emails can link to the staging site.
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

- [ ] **#CFG-5** · P3 — `UpdateSiteRequest` accepts both `settings.charlie_enabled` (snake_case) and `settings.charlieEnabled` (camelCase) for the same setting
    - **Where:** app/Http/Requests/Api/User/Site/UpdateSiteRequest.php (settings group)
    - **Affects:** API consumers who inadvertently send both keys in the same request — the resulting `settings` array contains both, and whichever key the application code checks for wins. Clients reading the API response to confirm what was saved may be surprised.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Pick `settings.charlie_enabled` (snake_case, consistent with every other `settings.*` key) as the canonical form and remove `settings.charlieEnabled`.
        - If an active client sends `charlieEnabled` and cannot be updated simultaneously, normalize it in `prepareForValidation()`: `if ($this->has('settings.charlieEnabled')) { $this->merge(['settings' => ['charlie_enabled' => $this->input('settings.charlieEnabled')]]); }` — then remove the camelCase rule.
    - **Technical:** Every other `settings.*` key uses snake_case (`show_branding`, `services_auto_sync_enabled`, `booking_mode`, `manual_booking_url`). The `charlieEnabled` entry appears to be a legacy alias left when the key was renamed. When both keys are present in a single request, both pass validation and both are inserted into the `settings` array that `UpdateSiteAction` merges into the JSONB column — producing a `settings` object with both `charlie_enabled` and `charlieEnabled` keys. Application code that checks only one form will silently ignore the other.
    - **Plain English:** There are two labels on the same toggle — one says `charlie_enabled`, the other says `charlieEnabled`. Both work, but if someone accidentally flips both at once, the system doesn't know which one to trust. Every other toggle in the same panel uses the underscore style. Pick one label and remove the other.
    - **Evidence:**
        ```php
        // Two rules for the same setting — only snake_case convention should survive:
        'settings.charlie_enabled'  => ['sometimes', 'boolean'],
        'settings.charlieEnabled'   => ['sometimes', 'boolean'],
        ```

- [ ] **#CFG-3** · P3 — `IndividualProfileController` hardcodes the resolve-cache TTL and slow-request threshold as PHP constants
    - **Where:** app/Http/Controllers/Api/PublicSite/IndividualProfileController.php:38–41
    - **Affects:** Operational agility — adjusting either value during a traffic incident or a performance investigation requires a code deploy rather than a config change.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `private const RESOLVE_CACHE_TTL = 30` with `config('partna.public_profile.resolve_cache_ttl', 30)`.
        - Replace `private const SLOW_REQUEST_THRESHOLD_MS = 1_000` with `config('partna.public_profile.slow_request_threshold_ms', 1000)`.
        - Add both keys under the existing `public_profile` section in `config/partna.php` and add entries to `.env.example`. The payload TTL is already config-driven (`config('partna.public_profile.cache_ttl_seconds', 60)`) — these two constants should match that pattern.
    - **Technical:** The 30-second resolve-cache TTL and 1-second slow-request threshold are operationally significant: the TTL bounds how quickly a handle rename propagates without a cache bust; the threshold controls the sensitivity of Nightwatch's `slow_public_profile` warning. Making them constants means adjusting them under load requires a deploy cycle. The payload builder's TTL (`cacheTtl()`) is already read from config — these two controller constants should be moved to follow the same pattern for consistency.
    - **Plain English:** Two operational dials — how long the system caches a handle lookup before re-checking, and how slow a page load has to be before it's flagged — are soldered to fixed values inside the code. To turn either dial, a developer has to write code, get it reviewed, and deploy. Moving them to config means they can be adjusted with a settings change, which is much faster during an incident or a performance investigation.
    - **Evidence:**
        ```php
        /** Resolve-cache TTL in seconds — short window to absorb traffic without
         * needing mutation-driven invalidation. */
        private const RESOLVE_CACHE_TTL = 30;

        /** Slow-request threshold; anything above logs a warning for Nightwatch. */
        private const SLOW_REQUEST_THRESHOLD_MS = 1_000;
        ```

`★ Insight ─────────────────────────────────────`
**Three patterns this audit surface that compound into systemic risk:** (1) The `groupKitColumns` bug (KIT-1) and the `StaffUpdateSiteRequest` staleness (API-2 + CFG-2) both stem from the same root: design-kit column changes don't have a mandatory code checklist — the structural drift test (TEST-5) would close that loop permanently. (2) The `groupKitColumns` prefix map uses a mix of singular (`icon`) and plural (`colors`, `borders`) keys, which is what caused the `icons_*` blind spot — if you add more columns that start with a plural-form group prefix, the same bug will recur. (3) The two-token prefix map (`space_desktop`, `sizing_desktop`, `typography_desktop`) has no entry for `icons_desktop` — worth confirming now whether any future responsive icon column is planned, to add the prefix entry proactively.
`─────────────────────────────────────────────────`
