`★ Insight ─────────────────────────────────────`
Key adjudication discoveries: (1) `SiteObserver` has `public bool $afterCommit = true` — TXN-1's entire premise is wrong, the cache bust fires after the transaction commits, not inside it. (2) Every `site.*` table in the baseline has RLS enabled, making `design_kits` the lone exception — SCHEMA-3 is real. (3) When a request contains only `design_kit` data, `$data` is empty after `unset($data['design_kit'])`, Eloquent's `save()` is a no-op (not dirty), `sites.updated_at` isn't bumped, and the timestamp-keyed `public.profile:*` cache never rotates — CCH-4 is a real correctness gap on the common path of design-only edits.
`─────────────────────────────────────────────────`

# Core Audit (8-Lens Bundle) — 2026-06-02

**Branch:** development
**Lens:** Bundle 'core' — security/policy (SEC), lifecycle correctness (LIFE), cache antipatterns (CACHE/CCH), database/queue scaling (SCALE), schema/RLS (SCHEMA), webhook idempotency (WHK), transaction boundaries (TXN)
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
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
- supabase/migrations/20260527070000_skeleton_system_cleanup.sql
- supabase/migrations/20260527080000_design_kit_initial_vars.sql
- supabase/migrations/20260527090000_design_kit_layout_vars.sql
- supabase/migrations/20260527100000_design_kit_expanded_vars.sql
- supabase/migrations/20260527110000_design_kit_derived_default_vars.sql
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
- app/Services/Site/UpdateSiteAction.php *(adjudicator read)*
- app/Models/Core/Site/Site.php *(adjudicator read)*
- app/Observers/Core/SiteObserver.php *(adjudicator read)*
- supabase/migrations/20260526000000_baseline_standalone_user.sql *(adjudicator grep — RLS coverage)*

**Dropped findings (with reason):**
- **SEC-4 / LIFE-2** — duplicates of SCHEMA-1 (same root cause, same Where)
- **WHK-1** — meta/scope finding; no actionable code issue
- **TXN-1** — incorrect premise. `SiteObserver` declares `public bool $afterCommit = true`, which defers all observer callbacks until after the wrapping `DB::transaction` commits. Cache invalidation does NOT fire inside the transaction. The actual gap (design-kit writes not rotating the `public.profile:*` key) is captured by CCH-4.
- **SCHEMA-5** — evidence claim is inaccurate. `sizing_tablet_header_height` IS surfaced in the API: `groupKitColumns()` matches the `sizing` single-token prefix and maps it to `sizing.tabletHeaderHeight`. It is not "invisible in API responses." Cannot confirm the finding as stated.

---

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 1 complete
- P2 Medium: 3 of 8 complete
- P3 Low: 0 of 10 complete

---

## P1 — Fix before pilot launch

- [ ] **#SCHEMA-1** · P1 — StaffUpdateSiteRequest design_kit validation entirely out of sync with site.design_kits schema
    - **Where:** app/Http/Requests/Api/Staff/UserSite/StaffUpdateSiteRequest.php (design_kit section — ~28 rules for dropped columns, ~13 columns missing)
    - **Affects:** Staff operators editing a professional's design kit via the staff dashboard. Every write returns HTTP 200 but `writeDesignKit()` discards all spacing/padding/tablet-tier values (columns were dropped by migration `20260529053028`). Meanwhile the new `space_*`, `color_placeholder`, `color_contrasting_*` columns are unvalidated — staff can submit malformed values for them with no 422 feedback.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Remove validation rules for every dropped column: `spacing_extra_small`, `spacing_small`, `spacing_general`, `spacing_large`, `spacing_desktop_*`, `padding_extra_small`, `padding_small`, `padding_general`, `padding_large`, `padding_desktop_*`, `padding_tablet_*`, `spacing_tablet_*`, `sizing_tablet_button_height`, `sizing_tablet_input_height`, `typography_tablet_font_size`.
        - Add validation rules to match `UpdateSiteRequest`: `space_xs`, `space_s`, `space_regular`, `space_medium`, `space_large`, `space_desktop_xs`, `space_desktop_s`, `space_desktop_regular`, `space_desktop_medium`, `space_desktop_large`, `color_placeholder`, `color_contrasting_bg`, `color_contrasting_text`.
        - Add a smoke test that diffs the `design_kit.*` validation keys between the two Form Requests so this can't drift again silently.
    - **Technical:** Migration `20260529053028_design_kit_unified_space_scale.sql` dropped 28 columns (the full `padding_*`, `spacing_*`, and `*_tablet_*` tiers) and added 10 `space_*` + `space_desktop_*` replacements. `StaffUpdateSiteRequest` was never updated. The controller's `writeDesignKit()` filters incoming keys against `information_schema.columns`, so writes for dropped columns are silently discarded — the operator receives HTTP 200 but the design kit is unchanged. Separately, the missing `space_*` and contrasting-color rules mean staff can submit values without format constraints (no `max:16` or `max:32` guard), letting unvalidated strings reach the DB for real columns that the user-facing request guards correctly.
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

- [x] **#SEC-1** · P2 — StaffUpdateSiteRequest `prepareForValidation` skips settings-field sanitisation
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

- [ ] **#CCH-3** · P2 — SiteCacheService fill lock uses the default Redis store, not a dedicated lock store
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

- [ ] **#CCH-1** · P2 — Plain `Cache::remember` on the `handle.resolve` hot path has no single-flight lock
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

- [x] **#LIFE-1** · P2 — Race condition in `writeDesignKit`: concurrent saves can silently lose design customisations
    - **Where:** app/Http/Controllers/Api/User/SiteManagement/UserSiteController.php:writeDesignKit() (lines 72–95 approximately)
    - **Affects:** Any professional who triggers two rapid design-kit saves (e.g., opens the editor in two tabs, or a slow network causes a double-submit). At 200 professionals this is rare, but when it occurs the later write silently overwrites the earlier one without any error.
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

- [x] **#SEC-2** · P2 — StaffUpdateSiteRequest subdomain validation omits the `core.user_handle_aliases` collision check
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

- [ ] **#SCHEMA-4** · P2 — `site.create_empty_design_kit()` trigger has no `ON CONFLICT` guard
    - **Where:** supabase/migrations/20260527070000_skeleton_system_cleanup.sql (trigger function, lines ~107–115)
    - **Affects:** Site creation — if a `design_kits` row already exists for the same `site_id` (backfill race, re-insert, or test fixture), the trigger's bare `INSERT` hits a PK violation and rolls back the entire `INSERT INTO site.sites`, failing site creation silently.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a new migration that replaces the trigger function with an idempotent version: `INSERT INTO site.design_kits (site_id) VALUES (NEW.id) ON CONFLICT (site_id) DO NOTHING;`
        - This is safe because an existing empty row is functionally identical to a newly-inserted one.
    - **Technical:** The migration comment explicitly notes "existing sites are backfilled separately (see Phase 2 step 2.4 in the plan — not in the migration so the backfill window stays predictable)." A concurrent backfill job or a re-insertion scenario (e.g., a test that creates then re-creates a site) will cause the trigger's `INSERT` to fail on the PK constraint for `site_id`. Since the trigger is `AFTER INSERT ON site.sites FOR EACH ROW`, Postgres propagates the trigger failure back to the parent statement, aborting the site creation. `ON CONFLICT DO NOTHING` costs nothing and makes the trigger idempotent.
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

        CREATE TRIGGER trg_create_empty_design_kit
          AFTER INSERT ON site.sites
          FOR EACH ROW EXECUTE FUNCTION site.create_empty_design_kit();
        ```

- [ ] **#SCHEMA-3** · P2 — `site.design_kits` is the only `site.*` table without Row Level Security enabled
    - **Where:** supabase/migrations/20260527070000_skeleton_system_cleanup.sql (CREATE TABLE site.design_kits, ~line 94)
    - **Affects:** All per-professional design configuration (colours, typography, spacing, button styles). Without RLS, any connection using the `app_backend` role can read and write every professional's design kit without restriction — application-layer authorization is the only guard.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add a migration with `ALTER TABLE site.design_kits ENABLE ROW LEVEL SECURITY; ALTER TABLE site.design_kits FORCE ROW LEVEL SECURITY;`
        - Add RLS policies matching the pattern used on `site.sites` in the baseline: a `SELECT` policy that joins to `site.sites.user_id` via `current_setting('app.actor_id')`, and `INSERT/UPDATE/DELETE` policies that restrict to the owning professional.
        - Consult the `site.sites` RLS policies in `supabase/migrations/20260526000000_baseline_standalone_user.sql` for the exact policy pattern to replicate.
    - **Technical:** The baseline migration (`20260526000000`) enables RLS on every table in the `site.*` schema: `site.sites`, `site.blocks`, `site.site_media`, `site.media_variants`, `site.site_subdomain_aliases`, `site.services`, `site.enquiries`, and others. `site.design_kits`, created in `20260527070000`, is the only table in the schema that was never given `ENABLE ROW LEVEL SECURITY`. A bug in application authorization, a misconfigured connection, or a direct SQL client could read or overwrite any professional's design tokens without restriction. The `app_backend` role has no row-level constraint on this table.
    - **Plain English:** Every filing cabinet in the building has a lock on it — except the one added last month. The application checks IDs at the door before letting anyone into the filing room, but if that check ever fails (a bug, a misconfigured tool, or someone with direct database access), there's no second lock on that particular cabinet. Everything a professional has chosen for their brand colours and fonts is inside, accessible to anyone who gets past the door.
    - **Evidence:**
        ```sql
        -- Baseline migration (20260526000000) — every site.* table has RLS:
        -- ── site.sites ──
        ALTER TABLE site.sites ENABLE ROW LEVEL SECURITY;
        -- ── site.blocks ──
        ALTER TABLE site.blocks ENABLE ROW LEVEL SECURITY;
        -- ── site.site_media ──
        ALTER TABLE site.site_media ENABLE ROW LEVEL SECURITY;
        -- ── site.services ──
        ALTER TABLE site.services ENABLE ROW LEVEL SECURITY;
        -- ... (all site.* tables) ...

        -- Skeleton cleanup migration (20260527070000) — design_kits created WITHOUT RLS:
        CREATE TABLE site.design_kits (
          site_id UUID PRIMARY KEY REFERENCES site.sites(id) ON DELETE CASCADE
        );
        -- No ALTER TABLE site.design_kits ENABLE ROW LEVEL SECURITY follows.
        ```

- [ ] **#CCH-4** · P2 — Design-kit writes do not invalidate the `public.profile:*` cache
    - **Where:** Write path: app/Http/Controllers/Api/User/SiteManagement/UserSiteController.php:writeDesignKit() + subsequent `invalidateSite()` call. Read path: app/Http/Controllers/Api/PublicSite/IndividualProfileController.php:show() (key `public.profile:{handle}:{updated_at_ts}`)
    - **Affects:** Every professional who saves design-kit changes (colours, fonts, spacing). When the request contains only `design_kit` data and no other site fields, `sites.updated_at` is not bumped, the timestamp-based cache key does not rotate, and the cached profile payload continues serving the old design kit until the TTL expires (~60 s default).
    - **Effort:** M (~2–4h)
    - **What to do:**
        - After writing the design kit, call `$site->touch()` to bump `sites.updated_at`. This causes the `SiteObserver` (which already has `$afterCommit = true`) to fire `invalidateSite()` and rotate the `public.profile:*` key naturally via the new timestamp.
        - Alternatively, add explicit invalidation of `handle.resolve:{$professional->handle_lc}` so the next request rebuilds the resolve cache with the new `updated_at_ts`, letting the old `public.profile:*` key orphan.
        - The second manual `invalidateSite()` call in `update()` can remain as it clears the legacy `site:payload:*` keys used by `SiteCacheService`.
    - **Technical:** The `public.profile:{handle}:{updated_at_ts}` cache key is content-addressed: it rotates when `sites.updated_at` changes. `UpdateSiteAction::execute()` saves the site inside a `DB::transaction`, but if `$data` is empty (because `design_kit` was the only field in the request and was unsettled before passing to `execute()`), Eloquent's `save()` is a no-op — `isDirty()` is false, no `UPDATE` is issued, `updated_at` stays at its previous value. The `SiteObserver` (correctly marked `$afterCommit = true`) therefore never fires. The `invalidateSite()` call in the controller clears `site:payload:*` and `emailBrand:*` keys, but not the `public.profile:*` key, which is keyed on the old timestamp and remains live for up to 60 s. The gap is confirmed by the controller comment: `"execute() already fired invalidateSite via $site->save(), but that ran BEFORE the raw design_kits write above"` — this works when site fields were also changed (updated_at bumped), but fails silently on design-kit-only saves.
    - **Plain English:** When a hairdresser changes their brand colour on the dashboard, they expect to see it immediately on their public profile. The system uses a "fingerprint" based on when the profile was last updated to know which cached version to show. When only design colours are changed, the profile's "last updated" timestamp doesn't change — so the system hands out the old fingerprint and keeps showing the old colours for up to a minute. The fix is to mark the profile as "just updated" whenever the design kit changes, so the old cached version gets replaced immediately.
    - **Evidence:**
        ```php
        // UserSiteController::update — design-kit-only request: $data = [] after unset
        $designKit = $data['design_kit'] ?? null;
        unset($data['design_kit']);

        $site = $action->execute($professional, $data); // $data = [] → save() is no-op → updated_at unchanged

        if (is_array($designKit)) {
            $this->writeDesignKit($site->id, $designKit);
            // execute() already fired invalidateSite via $site->save(), but that
            // ran BEFORE the raw design_kits write above — bust again so the new
            // kit (and the email-brand bundle that reads it) is reflected.
            app(SiteCacheService::class)->invalidateSite($site); // clears site:payload:* only
        }
        ```
        ```php
        // IndividualProfileController — cache key embeds the timestamp:
        'updated_at_ts' => $site?->updated_at?->timestamp
            ?? $pro->updated_at?->timestamp
            ?? 0,
        // ...
        $key = "public.profile:{$handleLc}:{$resolved['updated_at_ts']}"; // stale key served
        ```

---

## P3 — Nice to have

- [ ] **#CCH-2** · P3 — Unjittered TTL on `handle.resolve` cache causes fleet-wide synchronised expiry
    - **Where:** app/Http/Controllers/Api/PublicSite/IndividualProfileController.php (RESOLVE_CACHE_TTL constant + Cache::remember call)
    - **Affects:** Minor — all Redis nodes expire the resolve cache at the same wall-clock second, creating a small synchronised lookup spike every 30 s on high-traffic handles.
    - **Effort:** S (~0.5h)
    - **What to do:**
        - Route the resolve cache through `CacheLockService::rememberLocked` (which applies ±20 % jitter automatically) once CCH-1 is fixed — the jitter comes for free.
    - **Technical:** A literal `30` TTL passed to `Cache::remember` expires identically on every Redis node simultaneously. On a fleet of N Laravel workers all serving the same popular handle, all entries expire at the same second, creating N concurrent DB hits. The ±20 % jitter applied by `rememberLocked` desynchronises expiry across the fleet.
    - **Plain English:** Every server's copy of the "who owns this address" lookup expires at exactly the same second. If ten servers are running, they all try to look it up at once. Spreading the expiry randomly by a few seconds smooths that out.
    - **Evidence:**
        ```php
        private const RESOLVE_CACHE_TTL = 30;

        $resolved = Cache::remember(
            "handle.resolve:{$handleLc}",
            self::RESOLVE_CACHE_TTL,
            function () use ($handleLc) { ... }
        );
        ```

- [ ] **#CCH-5** · P3 — `IndividualProfileController` builds cache keys via ad-hoc string interpolation instead of `CacheKeyGenerator`
    - **Where:** app/Http/Controllers/Api/PublicSite/IndividualProfileController.php (both cache key constructions)
    - **Affects:** Future maintainability — a writer or invalidator that constructs the key differently (typo, different case handling) silently misses the cache.
    - **Effort:** S (~0.5h)
    - **What to do:**
        - Add `CacheKeyGenerator::handleResolve(string $handle): string` and `CacheKeyGenerator::publicProfile(string $handle, int $timestamp): string` methods, then replace the inline interpolations in the controller.
    - **Technical:** `CacheKeyGenerator` is the canonical key registry, ensuring every reader and writer uses the identical key shape. The controller builds `"handle.resolve:{$handleLc}"` and `"public.profile:{$handleLc}:{$resolved['updated_at_ts']}"` inline. If any future path (a cache-warm job, an invalidation helper, a test) constructs these keys slightly differently, the result is a silent miss with no error.
    - **Plain English:** The building has an official key-cutting machine that everyone is supposed to use, so every copy of a key is identical. This controller is carving its own keys by hand. The locks work fine today, but if someone else needs to use the same key and carves it slightly differently, neither key will fit and neither side will know why.
    - **Evidence:**
        ```php
        "handle.resolve:{$handleLc}"
        // ...
        $key = "public.profile:{$handleLc}:{$resolved['updated_at_ts']}";
        ```

- [ ] **#CCH-6** · P3 — Unjittered TTL on the negative-cache sentinel in `SiteCacheService`
    - **Where:** app/Services/Cache/SiteCacheService.php:buildPayloadFromDb() (two `Cache::put` calls for `MISS_SENTINEL`)
    - **Affects:** Synchronised expiry of all "no site here" sentinel entries — a burst of bot scans during a fixed 30 s window causes all sentinels to expire at the same moment, briefly multiplying DB hits.
    - **Effort:** S (~0.5h)
    - **What to do:**
        - Replace `now()->addSeconds(self::MISS_PRIMARY_TTL_SECONDS)` with `self::applyJitter(self::MISS_PRIMARY_TTL_SECONDS)` (the `JitteredTtl` trait is already used in `SiteCacheService`).
    - **Technical:** The same ±20 % jitter applied to positive payload TTLs via `writePayloadWithStale()` is absent from the negative-cache sentinel writes. When many bogus subdomains are probed in a narrow window, all sentinels expire together, causing a synchronised wave of `PublicSitePayload` view queries. The fix is one-line and uses the `JitteredTtl` trait that is already in scope.
    - **Plain English:** The system remembers "no profile here" for 30 seconds to avoid going back to the database for every request. But all servers set that timer at the same moment, so they all expire at the same moment — and then all ask the database at once. A small random variation in the timer prevents that.
    - **Evidence:**
        ```php
        Cache::put($key, self::MISS_SENTINEL, now()->addSeconds(self::MISS_PRIMARY_TTL_SECONDS));
        Cache::put($staleKey, self::MISS_SENTINEL, now()->addSeconds(self::MISS_PRIMARY_TTL_SECONDS * self::PAYLOAD_STALE_TTL_MULTIPLIER));
        ```

- [ ] **#LIFE-3** · P3 — `updateBookingSettings` uses inline `Validator::make` instead of a Form Request class
    - **Where:** app/Http/Controllers/Api/User/SiteManagement/UserSiteController.php:updateBookingSettings()
    - **Affects:** Architectural consistency — validation rules for this endpoint are embedded in the controller and cannot be reused or tested in isolation.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Extract the `booking_mode` and `manual_booking_url` rules into a dedicated `UpdateBookingSettingsRequest` Form Request class.
        - Replace the inline `Validator::make` block with a type-hint on the method parameter.
    - **Technical:** The CLAUDE.md architecture mandates Form Request classes for all input validation. Inline `Validator::make` in a controller bypasses the standard resolution hooks, is harder to test in isolation, and accumulates technical debt as further booking modes are added. The `updateBookingSettings` endpoint is also one of two places in this controller that does NOT go through a Form Request — the other endpoints use `UpdateSiteRequest` correctly.
    - **Plain English:** Every room in the house has a light switch by the door — except this one, which has a pull chain hanging from the middle of the ceiling. It still turns the light on, but it breaks the pattern and confuses anyone new to the codebase. This is a quick fix that brings the room in line with the rest of the house.
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

- [ ] **#SEC-5** · P3 — Color fields accept arbitrary strings with no format validation
    - **Where:** app/Http/Requests/Api/User/Site/UpdateSiteRequest.php and app/Http/Requests/Api/Staff/UserSite/StaffUpdateSiteRequest.php (all `design_kit.color_*` and `design_kit.button_*` rules)
    - **Affects:** The email Blade template, which renders colour values directly into inline CSS, and the public-profile design kit payload consumed by partna-pages. A value like `#fff;}body{display:none` (under 32 chars) would terminate the inline style rule in an email client.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `regex:/^#[0-9a-fA-F]{3,8}$|^[a-zA-Z]{3,20}$/` (hex literal or CSS named colour keyword) to every `design_kit.color_*` and `design_kit.button_*` rule in both Form Requests.
        - Or accept hex only: `regex:/^#[0-9a-fA-F]{3,8}$/` — this is the design kit's actual format.
    - **Technical:** Laravel's `{{ }}` Blade escapes HTML entities, not CSS context. A value like `red;display:none` for `color_accent` in the email template would inject raw CSS into an inline `style=""` attribute. The write path is authenticated (professional or staff only), so the attack vector is a compromised account or a professional deliberately breaking their own emails. The `max:32` guard prevents the most obvious injections but allows valid-looking CSS attacks under that length. This is P3 because the blast radius is limited to the authenticated professional's own profile and emails.
    - **Plain English:** The design panel accepts any text up to 32 characters as a colour value — it takes you at your word that it's a valid colour. Most people enter a proper colour code like `#3a6efc`, but someone could type `red;display:none` and the system would accept it and embed it in their emails, potentially hiding content in some email clients. A simple format check (must be a hex code) closes this.
    - **Evidence:**
        ```php
        // Both Form Requests use this pattern for all color/button fields:
        'design_kit.color_accent' => ['sometimes', 'nullable', 'string', 'max:32'],
        'design_kit.color_bg' => ['sometimes', 'nullable', 'string', 'max:32'],
        'design_kit.button_primary_bg' => ['sometimes', 'nullable', 'string', 'max:32'],
        // 20+ fields, none with a hex/regex constraint

        // Email Blade template uses the value in inline CSS:
        // style="background-color:{{ $brand->palette->bg }};"
        ```

- [ ] **#SCALE-2** · P3 — `information_schema.columns` queried on every design-kit write
    - **Where:** app/Http/Controllers/Api/User/SiteManagement/UserSiteController.php:writeDesignKit() (column discovery query)
    - **Affects:** Every design-kit save from the dashboard. At 200 professionals with occasional edits this is low volume, but each save costs an extra metadata round-trip that changes only at deploy time.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace the per-request `information_schema.columns` query with a short-lived application cache (e.g., `Cache::remember('design_kits:columns', 3600, fn () => ...)`) so it is fetched once per deploy rather than once per save.
        - Bust the cache key during deploy (e.g., via `artisan cache:clear` in the deploy script).
    - **Technical:** `information_schema` is a system catalog query — it doesn't scale with user data, but it does cost a Postgres round-trip and a catalog lock acquisition on every call. The column set for `site.design_kits` changes only when a migration runs (deploy time). Caching it with a conservative TTL (e.g., 1 hour) reduces the query from N saves/day to ~1/hour. Using a static PHP `static $columns = null;` guard would collapse it to once per PHP process lifetime, which is sufficient for this use case.
    - **Plain English:** Every time a professional saves their design settings, the system consults the database to find out which fields exist — even though those fields have not changed since the last software update. It's like checking the building's blueprint before every entry to confirm the rooms still exist. Caching the answer after the first check means the blueprint only needs to be read once, not once per visitor.
    - **Evidence:**
        ```php
        $columns = DB::connection('pgsql')
            ->table('information_schema.columns')
            ->where('table_schema', 'site')
            ->where('table_name', 'design_kits')
            ->pluck('column_name')
            ->all();
        ```

- [ ] **#SCHEMA-2** · P3 — Migration `20260527070000` runs an inline full-table-scan UPDATE on `site.sites`
    - **Where:** supabase/migrations/20260527070000_skeleton_system_cleanup.sql (step 5, `UPDATE site.sites SET settings = settings - 'design' WHERE settings ? 'design'`)
    - **Affects:** Migration authoring pattern — at current row counts (≤200) this ran instantly. As `site.sites` grows, any future migration following this pattern risks long lock holds that queue DML during deploy.
    - **Effort:** S (~0.5–1h) — process / documentation fix; the migration has already run.
    - **What to do:**
        - Document in the team's migration guidelines: any one-shot data scrub on a live table should be extracted into a post-deploy job (or a batched artisan command) rather than embedded inline. The same migration's own comments acknowledge this for the design_kits backfill but don't apply the pattern to the settings scrub.
        - For future similar cleanup migrations, use: `DECLARE updated_count INT; UPDATE ... LIMIT 1000 RETURNING *` in a loop, or dispatch an artisan command post-deploy.
    - **Technical:** `UPDATE site.sites SET settings = settings - 'design' WHERE settings ? 'design'` acquires row-level locks on every matching row for the duration of the migration transaction. On a large table with concurrent writes (new site creations, block saves), these row locks queue behind the UPDATE, extending the effective downtime window. The migration's comment on the design_kits backfill reads: "existing sites are backfilled separately … so the backfill window stays predictable" — the correct instinct was applied there but not to the settings scrub directly above it.
    - **Plain English:** During a software update, this migration paused to erase a sticky note from every user's file. With 200 users, that took milliseconds. With 50,000 users it would freeze new sign-ups while the janitor worked through every cabinet. The right pattern — used elsewhere in the same migration for a different task — is to schedule the cleanup as a background job after the update finishes, rather than blocking the update itself.
    - **Evidence:**
        ```sql
        -- Step 5 in 20260527070000_skeleton_system_cleanup.sql:
        UPDATE site.sites SET settings = settings - 'design' WHERE settings ? 'design';
        -- Inline full-table scan inside the migration transaction.

        -- The same migration's comment on design_kits backfill (correct pattern documented but not applied here):
        -- "existing sites are backfilled separately (see Phase 2 step 2.4 in the plan —
        --  not in the migration so the backfill window stays predictable)."
        ```

- [ ] **#SCALE-1** · P3 — `skeleton_id` CHECK constraint added without `NOT VALID` on `site.sites`
    - **Where:** supabase/migrations/20260527070000_skeleton_system_cleanup.sql (ALTER TABLE block adding `skeleton_id`)
    - **Affects:** Migration authoring pattern — this migration has already run. At current row counts (≤200) the validation scan was instant. On a table with millions of rows, `ADD COLUMN ... CHECK(...)` validates every existing row inline and holds an `ACCESS EXCLUSIVE` lock.
    - **Effort:** S (~0.5h) — process / documentation fix; the migration has already run.
    - **What to do:**
        - Document in team migration guidelines: CHECK constraints on large tables should use `ADD CONSTRAINT ... NOT VALID` first, then `VALIDATE CONSTRAINT` as a separate statement (or separate migration) to avoid holding an exclusive lock while the backfill scan runs.
        - Since the `DEFAULT 'skeleton-1'` ensures every row satisfies the constraint, the `NOT VALID` → `VALIDATE` split is safe and equivalent in outcome.
    - **Technical:** Postgres validates a `CHECK` constraint against every existing row at `ADD` time unless `NOT VALID` is specified. The `NOT VALID` flag adds the constraint as metadata-only (enforced for new writes immediately), then `ALTER TABLE ... VALIDATE CONSTRAINT` checks existing rows without blocking new DML. At ≤200 rows this migration ran in microseconds and posed no risk; the pattern is flagged for future migrations on this and similar tables.
    - **Plain English:** Adding a new quality rule to a database table normally requires the system to check every existing record against the rule before it can continue. On small tables this is instant, but on large ones it blocks other work for seconds or minutes. The safer approach is a two-step process: announce the rule (new records must follow it immediately), then check the backlog quietly without blocking anyone. This migration used the single-step approach, which is fine at current scale but worth changing as a habit.
    - **Evidence:**
        ```sql
        ALTER TABLE site.sites
          DROP COLUMN theme_id,
          ADD COLUMN skeleton_id TEXT NOT NULL
            DEFAULT 'skeleton-1'
            CHECK (skeleton_id IN ('skeleton-1','skeleton-2','skeleton-3','skeleton-4'));
        -- No NOT VALID + VALIDATE CONSTRAINT split.
        ```

- [ ] **#SCALE-3** · P3 — Design-kit DDL migrations lack `lock_timeout` / `statement_timeout` guards
    - **Where:** supabase/migrations/20260527080000 through 20260530130000 (10 migration files adding/dropping columns on `site.design_kits`)
    - **Affects:** Future deploy safety as `site.design_kits` grows. At 200 rows all DDL runs in microseconds; but the pattern of unguarded `ALTER TABLE` on a table served by the live public-profile endpoint normalises a risk that grows with scale.
    - **Effort:** S (~0.5h) — process / documentation fix; these migrations have already run.
    - **What to do:**
        - Add `SET LOCAL lock_timeout = '2s'; SET LOCAL statement_timeout = '10s';` at the top of every future migration that runs DDL against tables queried by live traffic (`site.design_kits`, `site.sites`, `site.blocks`).
        - For multi-column `ALTER TABLE` statements (e.g., the 22-drop + 10-add in `20260529053028`), verify the statement fits within the timeout on a staging clone before applying to production.
    - **Technical:** `ALTER TABLE` acquires an `ACCESS EXCLUSIVE` lock. If a long-running query holds a weaker lock on the same table when the migration runs, Postgres queues the DDL — and all subsequent DML queues behind the DDL. `lock_timeout` makes the migration fail fast rather than creating a silent blockage that cascades into public-profile request timeouts. `SET LOCAL` scopes the timeout to the migration transaction only, so it doesn't affect application queries.
    - **Plain English:** Every time these migrations reorganise the design settings database table, they wait — with unlimited patience — for any current reader to finish before they can proceed. If one reader is slow, the migration (and everything that comes after it in the queue) stalls indefinitely. Setting a 2-second patience limit means the migration fails fast if the table is busy, rather than causing a cascading slowdown that affects live visitors.
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

- [ ] **#SEC-3** · P3 — No application-level rate limiting on the public profile endpoint
    - **Where:** app/Http/Controllers/Api/PublicSite/IndividualProfileController.php (no throttle middleware, no `RateLimiter` calls in `show()`)
    - **Affects:** Handle enumeration — an attacker cycling unique handles at volume can map which handles exist without application-layer resistance. The 30 s resolve cache absorbs repeated lookups of the same handle, but each unique miss triggers a DB query.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a `throttle:60,1` (60 requests per minute per IP) or a named `throttle:public_profile` rate limiter on the public profile route definition.
        - Consider a separate per-handle throttle if burst probing of a single non-existent handle is a concern.
    - **Technical:** The endpoint has a 30 s negative-cache sentinel that absorbs repeated misses for the same handle, and `CacheLockService::rememberLocked` single-flights the payload build. Neither mechanism limits per-IP request volume across distinct handles. Cloudflare provides infrastructure-level DDoS protection, which is the primary defence here; an application throttle is defence-in-depth for enumeration patterns that Cloudflare's default rules don't catch (low-volume, varied-handle scanning).
    - **Plain English:** The front door of the building has a security guard (Cloudflare), but there's no sign-in book inside. A visitor could try thousands of different room numbers in a row and the system would answer each knock without slowing down. The existing "same room" protection only helps if they keep knocking on the same door — not if they sweep through all the doors. Adding a sign-in book (rate limit per IP) makes bulk sweeping impractical.
    - **Evidence:**
        ```php
        // IndividualProfileController — no throttle middleware, no RateLimiter calls:
        class IndividualProfileController extends ApiController
        {
            public function show(Request $request, string $handle): JsonResponse
            {
                $startedAt = microtime(true);
                $handleLc = strtolower(trim($handle));
                // resolve-cache, payload build, response — no rate limiting anywhere
            }
        }
        ```
