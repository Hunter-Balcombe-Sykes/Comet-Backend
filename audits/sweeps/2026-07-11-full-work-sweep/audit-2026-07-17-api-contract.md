# API Contract & Resource Leakage Audit — 2026-07-17

**Branch:** audit/full-work-sweep-2026-07-17
**Lens:** API Contract & Resource Leakage — raw model fields bleeding through, over-fetching, inconsistent pagination
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4.5`
**Source files audited:**
- app/Http/Controllers/Api/User/Account/UserAccountDeletionController.php
- app/Http/Controllers/Api/User/Account/UserSelfController.php
- app/Http/Controllers/Api/User/Profile/SectorController.php
- app/Http/Controllers/Api/User/SiteManagement/CustomDomainController.php
- app/Http/Resources/PublicSite/IndividualProfileResource.php
- app/Http/Resources/UserDashboardResource.php
- app/Http/Controllers/Api/Platforms/BookingController.php
- app/Http/Controllers/Api/Platforms/DisplaySettingsController.php
- app/Http/Controllers/Api/Platforms/FreshaController.php
- app/Http/Controllers/Api/Platforms/GoogleBusinessController.php
- app/Http/Controllers/Api/Platforms/InstagramController.php
- app/Http/Controllers/Api/Platforms/MenuController.php
- app/Http/Controllers/Api/Platforms/OnlineOrderingController.php
- app/Http/Controllers/Api/Platforms/ReservationsController.php
- app/Http/Controllers/Api/Platforms/SquareController.php
- app/Http/Controllers/Api/PublicSite/PublicIntegrationController.php
- app/Http/Controllers/Api/PublicSite/PublicMenuController.php
- app/Http/Controllers/Api/Staff/Analytics/StaffAggregateAnalyticsController.php
- app/Http/Controllers/Api/Staff/Feedback/StaffFeedbackController.php
- app/Http/Controllers/Api/Staff/UserSiteManagement/StaffUserController.php

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 1 complete
- P2 Medium: 0 of 0 complete
- P3 Low: 0 of 3 complete

---

## P1 — Fix before pilot launch

- [ ] **#API-1** · P1 — `UserSelfController::update()` returns a Resource that unconditionally reads two relations neither `fresh()` nor the controller preloads
    - **Where:** app/Http/Controllers/Api/User/Account/UserSelfController.php:73-88, app/Http/Resources/UserDashboardResource.php:41,47-49
    - **Affects:** Every authenticated user calling `PATCH /api/user/me` (the profile-settings save flow — display name, contact info, account type, sector) — the single most common self-service dashboard write.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Pull Cloud logs first (`cloud env:logs partna development --minutes 60 | grep -i lazy`) to confirm whether this is already firing in the live-serving "development" Cloud env before treating it as theoretical.
        - Mirror `show()`'s pattern in `update()`: reload with the `site` relation explicit (`$professional->fresh(['site'])`) and re-set `partnaStaff` via the same fresh `PartnaStaff` lookup `show()` already does, instead of handing `UserDashboardResource` a relation-less `fresh()` model.
        - Add a `PATCH /api/me` regression test that runs in the default (non-production) test environment and asserts `assertOk()` specifically to catch any future unguarded relation access in `UserDashboardResource` the same way.
    - **Technical:** `AppServiceProvider.php:315` sets `Model::preventLazyLoading(! app()->isProduction())` — in every non-production environment (local, CI/testing, and per this repo's own 2026-06-16 note, plausibly the "development" Laravel Cloud environment that currently serves *both* API domains including production sitepages), accessing an unloaded Eloquent relation throws `LazyLoadingViolationException` instead of silently querying. `Model::fresh()` never carries over previously-loaded relations regardless of what was loaded on the original instance — it always issues a brand-new query with zero eager loads unless explicitly passed via `$with`. `UserSelfController::update()` returns `new UserDashboardResource($professional->fresh())` with no `$with` argument, and `UserDashboardResource::toArray()` reads `$this->partnaStaff` (line 41) and `$this->site` (lines 47-49) unconditionally — neither guarded by `whenLoaded()` — so both trips attempt a lazy load on a model that has no relations loaded. In strict-lazy-loading environments this 500s; in production it silently costs two extra queries per update instead of the intended zero (the doc comment on `partnaStaff` explicitly says "never lazy-loaded here", confirming this was a known constraint the `update()` path violates). DeepSeek's draft caught the `partnaStaff` half of this (tiered P3, "the load succeeds") but missed that `site` is affected by the identical gap and that the codebase's own strict-mode guard turns this from "wasteful" into "throws" outside production.
    - **Plain English:** When someone saves their profile settings (name, phone, account type), the server rebuilds a fresh copy of their record from the database before sending back a confirmation. But that fresh copy is deliberately "bare" — none of the related information (their site, their staff status) comes along automatically — and the confirmation-building code reads both of those without checking whether they're actually there first. In every environment except the real live server, the app is configured to crash loudly the instant this happens, specifically so problems like this get caught before real users see them. Because the environment that's actually serving live customer traffic right now is technically not flagged as "production" in this app's config, there's a real chance this affects real profile-save attempts, not just testing. The fix is small: explicitly fetch the related data before building the response, exactly like the "view profile" endpoint already does correctly.
    - **Evidence:**
        ```php
        public function update(UpdateUserRequest $request)
        {
            $professional = $this->currentUser($request);
            $this->authorizeForUser($professional, 'update', $professional);

            $validated = $request->validated();

            DB::transaction(function () use ($professional, $validated): void {
                $professional->fill($validated);
                $professional->save();
            });

            return $this->success([
                'professional' => new UserDashboardResource($professional->fresh()),
            ]);
        }
        ```
        ```php
        'is_staff' => $this->partnaStaff !== null,
        ...
        'custom_domain' => $this->site?->custom_domain,
        'custom_domain_status' => $this->site?->custom_domain_status,
        'custom_domain_primary' => (bool) ($this->site?->custom_domain_primary ?? false),
        ```
        ```php
        // Strict-mode N+1 trap: throw on unloaded relation access outside production
        // so tests/local catch lazy loading instead of leaking slow queries to prod.
        Model::preventLazyLoading(! app()->isProduction());
        ```

## P3 — Nice to have

- [ ] **#API-2** · P3 — `StaffUserController::show()` mixes a Resource class and hand-built arrays in one response body
    - **Where:** app/Http/Controllers/Api/Staff/UserSiteManagement/StaffUserController.php:96-138
    - **Affects:** Staff dashboard consumers of `GET /api/staff/professionals/{professional}` — the `integrations` and `design_summary` keys bypass the Resource layer entirely.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Extract the `integrations` grouping/mapping into a dedicated `StaffIntegrationSummaryResource`.
        - Extract `design_summary` into a `StaffDesignSummaryResource` wrapping `$designKitVars`.
    - **Technical:** `professional` goes through `UserStaffResource`, but `integrations` is built via `$professional->integrationConnections()->get(...)->groupBy(...)->map(...)` and `design_summary` is a raw associative array built inline from `$designKitVars`. This is staff-only data with no PII exposure risk beyond what `UserStaffResource` already carries — the issue is purely maintainability: a future column rename or removal has to be hunted down in controller code instead of one auditable Resource class.
    - **Plain English:** This staff page returns a professional's info like a neatly packaged box, but two of the sections (their connected integrations and their site's design summary) are loose items tossed in without the same packaging. If the underlying data changes later, someone has to hunt through controller code instead of updating one file.
    - **Evidence:**
        ```php
        $integrations = $professional->integrationConnections()
            ->orderBy('platform')
            ->get(['id', 'platform', 'is_active', 'last_refreshed_at', 'last_refresh_status'])
            ->groupBy('platform')
            ->map(fn ($group, $platform) => [
                'platform' => (string) $platform,
                'connection_count' => $group->count(),
                'is_active' => $group->contains(fn ($row) => (bool) $row->is_active),
                'last_refreshed_at' => $group->pluck('last_refreshed_at')->max(),
                'has_refresh_error' => $group->contains(fn ($row) => $row->last_refresh_status === 'error'),
            ])
            ->values();
        ```
        ```php
        'design_summary' => $professional->site ? [
            'architecture_id' => $professional->site->architecture_id,
            'stored_var_count' => count($designKitVars),
            'theme_mode' => $designKitVars['theme_mode'] ?? null,
            'surface_type' => $designKitVars['surface_type'] ?? null,
            'font_heading' => $designKitVars['font_heading'] ?? null,
            'font_body' => $designKitVars['font_body'] ?? null,
            'accent_color' => $designKitVars['accent_color'] ?? null,
            'design_kit' => $designKitVars,
        ] : null,
        ```

- [ ] **#API-3** · P3 — `PublicMenuController::show()` builds the public menu payload without a Resource class
    - **Where:** app/Http/Controllers/Api/PublicSite/PublicMenuController.php:75-131
    - **Affects:** Unauthenticated public sitepage visitors — the menu payload has no allowlisting guardrail between the Eloquent models and the wire.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Create `PublicMenuItemResource` / `PublicMenuCategoryResource` wrapping the Eloquent models with an explicit field list.
        - Replace the manual `$menu->categories->map(...)` chain with `PublicMenuCategoryResource::collection($menu->categories)->resolve()`.
    - **Technical:** The response is built entirely by hand (`$item->name`, `$item->description`, `$item->platformLinks->map(...)`, etc.) with no Resource class between the models and the JSON. Every field currently exposed is appropriate for a public visitor, so there is no active leak today — but this is the one endpoint in the file list that is both unauthenticated and has no Resource-layer allowlist, so a future column added to `menu_items` (an internal cost field, a moderation flag) would reach the public wire silently instead of requiring an explicit opt-in.
    - **Plain English:** This is the public menu page — anyone on the internet can see it. The response is built by hand, picking fields one by one. That's fine today, but if someone adds a new internal-only column to the menu table later, nothing stops it from silently showing up here. A Resource class acts like a bouncer at the door — only fields explicitly listed get through, on purpose, every time.
    - **Evidence:**
        ```php
        $categories = $menu->categories
            ->map(fn ($cat) => [
                'name' => $cat->name,
                'popularityRank' => $categoryRanks[(string) $cat->id] ?? null,
                'items' => $cat->items->map(fn ($item) => [
                    'id' => (string) $item->id,
                    'name' => $item->name,
                    'description' => $item->description,
                    'imageUrl' => $item->image_url,
                    'price' => $item->base_price !== null
                        ? number_format((float) $item->base_price, 2)
                        : null,
                    'platforms' => $item->platformLinks->map(fn (MenuItemPlatform $p) => [
                        'platform' => $p->platform,
                        'pickupUrl' => $this->textOrNull($p->pickup_url),
                        'deliveryUrl' => $this->textOrNull($p->delivery_url),
                        'pickupPrice' => $this->numberOrNull($p->pickup_price),
                        'deliveryPrice' => $this->numberOrNull($p->delivery_price),
                    ])->values()->toArray(),
                    'popularityRank' => $itemRanks[(string) $item->id] ?? null,
                ])->values()->toArray(),
            ])
            ->filter(fn ($cat) => count($cat['items']) > 0)
            ->values()
            ->toArray();
        ```

- [ ] **#API-4** · P3 — `MenuController::show()` builds the authenticated dashboard menu payload by hand, duplicating `PublicMenuController`'s shaping logic under different field names
    - **Where:** app/Http/Controllers/Api/Platforms/MenuController.php:171-222
    - **Affects:** Authenticated dashboard users — the same underlying menu data is manually re-shaped here with different key names (`image` vs `imageUrl`, `basePrice` unformatted vs `price` formatted) than the public surface.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Extract the category/item shaping in `categories()`/`platforms()` into `MenuItemResource`/`MenuCategoryResource`.
        - Share these Resource classes with `PublicMenuController` (#API-3), using `$this->when(...)` to gate any dashboard-only fields, so both surfaces read from one auditable transformation path instead of two hand-maintained ones.
    - **Technical:** `categories()` and `platforms()` manually iterate the same `MenuItem`/`MenuItemPlatform` relations `PublicMenuController` iterates, but rename fields differently (`image` vs `imageUrl`) and skip the 2dp price formatting the public surface applies. Any future field addition or rename has to be made correctly in both places or the two surfaces drift further apart — this is the same root cause as #API-3 and should be fixed in the same session.
    - **Plain English:** The menu data is shaped by hand in two different files — one for the public page, one for the owner's dashboard. They do almost the same job but with different field names in each. If a new menu field gets added later, someone has to remember to update both places identically. Sharing one Resource class with a simple on/off switch for dashboard-only fields fixes that.
    - **Evidence:**
        ```php
        private function categories(?Menu $menu): array
        {
            if ($menu === null) {
                return [];
            }

            return $menu->categories->map(fn ($category) => [
                'name' => $category->name,
                'items' => $category->items->map(fn (MenuItem $item) => [
                    'id' => (string) $item->id,
                    'name' => $item->name,
                    'description' => $item->description,
                    'image' => $item->image_url,
                    'rating' => $item->rating,
                    'ratingCount' => $item->rating_count,
                    'badges' => $item->badges,
                    'basePrice' => $item->base_price,
                    'pickupPrice' => $item->pickup_price,
                    'pickupSource' => $item->pickup_source,
                    'deliveryPrice' => $item->delivery_price,
                    'deliverySource' => $item->delivery_source,
                    'currency' => $item->currency,
                    'platforms' => $this->platforms($item),
                ])->all(),
            ])->all();
        }
        ```

## Suggested Bundled Sessions

- **Bundle 1 — Menu Resource extraction:** #API-3, #API-4
    - **Why grouped:** Same root cause (no Resource class for menu shaping) across the public and dashboard surfaces, with the same duplicated-field-mapping symptom — fix in one session so both surfaces land on shared Resource classes together.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

- **Bundle 2 — Staff detail response cleanup:** #API-2
    - **Why grouped:** Single isolated controller, no dependency on the menu work.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

## Standalone — do NOT bundle

- **#API-1 — `UserSelfController::update()` missing relation preload** · P1 with a plausible live-production crash path (the environment CLAUDE.md documents as currently serving real sitepage traffic is not confirmed to run with `APP_ENV=production`). Needs its own Cloud-log verification pass before implementation, and its own sign-off given the crash-on-a-common-path risk profile.
