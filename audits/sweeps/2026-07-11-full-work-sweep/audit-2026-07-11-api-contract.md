# API Contract & Resource Leakage Audit — 2026-07-12

**Branch:** HEAD
**Lens:** API Contract & Resource Leakage: raw model fields bleeding through, over-fetching, inconsistent pagination
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4.5`
**Source files audited:**
- `app/Http/Controllers/Api/User/Content/ContentController.php`
- `app/Http/Controllers/Api/User/Uploads/UserDesignMediaController.php`
- `app/Http/Controllers/Api/User/Account/UserSelfController.php`
- `app/Http/Controllers/Api/User/Analytics/DevInsightsController.php`
- `app/Http/Controllers/Api/User/Analytics/UserAnalyticsController.php`
- `app/Http/Controllers/Api/PublicSite/PublicMenuController.php`
- `app/Http/Controllers/Api/PublicSite/SiteVisibilityController.php`
- `app/Http/Controllers/Api/Staff/Segments/StaffSegmentController.php`
- `app/Http/Controllers/Api/Staff/UserSiteManagement/StaffUserController.php`
- `app/Http/Controllers/Api/Staff/UserSiteManagement/StaffIntegrationManagementController.php`
- `app/Http/Controllers/Api/Staff/StaffSite/StaffNotificationController.php`
- `app/Http/Controllers/Api/Platforms/ShopController.php`
- `app/Http/Resources/SiteResource.php`
- `app/Http/Resources/UserDashboardResource.php`
- `app/Http/Resources/UserPublicResource.php`
- `app/Http/Resources/UserStaffResource.php`
- `app/Http/Resources/Staff/StaffUserListResource.php`
- `app/Http/Resources/Content/ContentLibraryUploadResource.php`
- `app/Http/Resources/DesignMediaResource.php`
- `app/Http/Resources/NotificationListingResource.php`
- `app/Http/Resources/PublicSite/IndividualProfileResource.php`
- `app/Http/Controllers/Concerns/ReturnsPaginatedResponse.php`

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 0 complete
- P3 Low: 0 of 9 complete

---

## P3 — Nice to have

- [ ] **#API-1** · P3 — `UserDashboardResource` leaks `auth_user_id` (Supabase UUID) to the frontend dashboard
    - **Where:** `app/Http/Resources/UserDashboardResource.php:14`
    - **Affects:** The authenticated professional's own dashboard (`/me`, bootstrap, profile update responses).
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Remove `'auth_user_id' => $this->auth_user_id` from `UserDashboardResource::toArray()`.
        - If any dashboard flow genuinely needs the UUID, surface it as a dedicated field via the controller (as `UserSelfController::show()` already does with `uid`) rather than baking it into the shared Resource.
    - **Technical:** `auth_user_id` is the internal FK bridging `core.users` to Supabase's `auth.users`. It's not a cross-audience leak (this Resource is scoped to the owning user's own dashboard), but it's an unnecessary internal identity-bridge field with no dashboard use case — auth is JWT/session-based, not client-supplied-UUID-based. Removing it shrinks the surface a future bug (frontend code using it for an ad-hoc identity check) could exploit.
    - **Plain English:** The user's dashboard receives a copy of their internal ID badge — the one the system uses to match them to the login provider. The frontend never needs to look at this badge; it authenticates with a session ticket. Handing it out on every page load is unnecessary and slightly risky if frontend code ever mistakes it for something to check against.
    - **Evidence:**
        ```php
        return [
            'id' => (string) $this->id,
            'auth_user_id' => $this->auth_user_id,
        ```

- [ ] **#API-2** · P3 — `SiteResource` exposes `user_id` unconditionally — internal FK surfaced with no dashboard use
    - **Where:** `app/Http/Resources/SiteResource.php:54`
    - **Affects:** Every dashboard endpoint returning `SiteResource` — `UserSelfController::show()`, `UserSiteController::show()`/`update()`/`updateBookingSettings()`, `SiteVisibilityController::update()` (all authenticated-owner routes; verified `SiteResource` has no public-surface call site).
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Remove `user_id` from the dashboard payload, or gate it behind `$this->when($request->routeIs('staff.*'), ...)` if staff callers want it explicit (staff already get it separately via `UserStaffResource`/`StaffUserController::show()`).
    - **Technical:** `SiteResource` includes `'user_id' => $this->user_id` unconditionally. All six call sites are authenticated-owner or staff routes (verified via grep — no public-facing usage), so this isn't an audience-confusion leak; it's redundant internal-FK exposure the owner already has via their own user profile response. Same root-cause pattern as #API-1 (unnecessary internal ID field on an own-resource Resource) — tiered identically.
    - **Plain English:** The site's API response includes a copy of the owner's internal account number, which the owner already sees on their profile page. Printing it twice isn't a secret leak, but it's unneeded duplication that invites confusion later.
    - **Evidence:**
        ```php
        return array_merge([
            'id' => (string) $this->id,
            'user_id' => $this->user_id,
            'subdomain' => $this->subdomain,
        ```

- [ ] **#API-3** · P3 — `ContentLibraryUploadResource` missing `updated_at` — clients can't detect a re-uploaded image
    - **Where:** `app/Http/Resources/Content/ContentLibraryUploadResource.php:27-35`
    - **Affects:** Dashboard content library — re-uploading an image replaces the row in-place (same `id`), and the client has no signal it changed.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `'updated_at' => $media->updated_at?->toIso8601String()` to the returned array.
    - **Technical:** The Resource emits `created_at` but not `updated_at`. `SiteMedia` rows in the content pool are updated in-place on re-upload (same `id`, same `purpose`), so `updated_at` is the only wire signal a previously-fetched image has changed. Without it, the SPA either skips caching entirely or risks showing a stale variant.
    - **Plain English:** Imagine a photo frame that shows a picture. You swap the photo, but the label still shows the original date, so nothing looks changed. Adding an "updated" date to the label tells the viewer the photo was recently swapped.
    - **Evidence:**
        ```php
        return [
            'id' => (string) $media->id,
            'url' => is_string($url) && $url !== '' ? $url : null,
            'alt_text' => $media->alt_text,
            'caption' => $media->caption,
            'media_type' => $media->media_type,
            'processing_state' => $media->processing_state,
            'created_at' => $media->created_at?->toIso8601String(),
        ];
        ```

- [ ] **#API-4** · P3 — `UserAnalyticsController` returns plain arrays with no acknowledgment of the exception, unlike its sibling `DevInsightsController`
    - **Where:**
        - `app/Http/Controllers/Api/User/Analytics/DevInsightsController.php:24-25` (documented exception)
        - `app/Http/Controllers/Api/User/Analytics/UserAnalyticsController.php:116` (undocumented)
    - **Affects:** The authenticated professional's live analytics dashboard (`summary()`, `insights()`, `live()`).
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add the same `@see`-style acknowledgment `DevInsightsController` already carries to `UserAnalyticsController`, so future readers understand the plain-array shape is deliberate for aggregate analytics reads.
        - Do not force these into Resource classes: the payloads are hand-aggregated stdClass/array data from `DB::table()` reads over `analytics.*`, not model representations — a Resource here would just be a re-wrapped array with no allowlist benefit.
    - **Technical:** Both controllers read directly from `analytics.*` tables via the query builder (never Eloquent models), so this isn't a raw-model-return violation. `DevInsightsController` already has an explicit docblock ("Plain-array response (no Resource) ... the same ad-hoc norm the sibling UserAnalyticsController uses") acknowledging this is a deliberate, shared pattern — but that acknowledgment lives on the wrong controller. `UserAnalyticsController::summary()` powers the live dashboard and has no comparable note, so a future contributor reading only that file could mistake the plain-array shape for an oversight rather than a convention.
    - **Plain English:** Most endpoints serve data on a standard plate; the two analytics endpoints serve it on a napkin — same food, different container, and that's fine since it's deliberate. Right now only one of the two napkin-stations has a note explaining why. Add the same note to the other one so nobody "fixes" it by accident.
    - **Evidence:**
        ```php
        // DevInsightsController — explicit docblock acknowledgment
        /**
         * Plain-array response (no Resource), no cache — the same ad-hoc norm the sibling
         * UserAnalyticsController uses for its analytics reads.
         */
        ```
        ```php
        // UserAnalyticsController::summary() — no such acknowledgment
        $data['insights'] = $this->analytics->insights($professional, $site);

        return $this->success($data);
        ```

- [ ] **#API-5** · P3 — `StaffSegmentController::users()` manually maps `User` rows instead of a Resource class
    - **Where:** `app/Http/Controllers/Api/Staff/Segments/StaffSegmentController.php:175-182`
    - **Affects:** Staff dashboard segment-membership preview; future staff-facing fields added to `User`.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Extract a small `StaffSegmentMemberResource` (or reuse `StaffUserListResource` if its shape is close enough) so the field allowlist for this view lives in one auditable place alongside the other staff User resources.
    - **Technical:** The endpoint already paginates correctly (`->paginate($perPage)` + `paginatedResponse()`), so this is purely category (2)/(1)-adjacent: the row-mapping bypasses the Resource-class allowlist pattern used by the sibling staff User views (`StaffUserListResource`, `UserStaffResource`). Fields shipped today are limited and non-sensitive, but there's no single `toArray()` to audit against those other two Resources going forward.
    - **Plain English:** Three staff screens show user info — the main list, the detail page, and this segment-members list. The first two use pre-approved field lists; this one builds its list by hand inline, so a sensitive field added to the User model later won't automatically show up here, but it also won't be blocked from being copy-pasted in.
    - **Evidence:**
        ```php
        $users = collect($page->items())->map(fn (User $user) => [
            'id' => $user->id,
            'handle' => $user->handle,
            'display_name' => $user->display_name,
            'account_type' => $user->account_type?->value,
            'sector' => $user->sector,
            'created_at' => $user->created_at?->toIso8601String(),
        ]);
        ```

- [ ] **#API-6** · P3 — `ShopController::selection()` builds a public-compat payload inline, duplicating `ShopBrandResource`'s field contract
    - **Where:** `app/Http/Controllers/Api/Platforms/ShopController.php:415-427`
    - **Affects:** Authenticated `GET /api/platforms/shop/selection` (dashboard/compat Shop-card read); future changes to the brand payload shape.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Route `$primary` through a small dedicated compat Resource (or a static `ShopBrandResource::toCompat()` helper) instead of hand-destructuring — centralizes the field list against the per-brand `ShopBrandResource::toArray()` used elsewhere in this same controller.
    - **Technical:** `selection()` is an authenticated compatibility endpoint (route confirmed under `routes/api/platforms.php`, gated by the standard user auth stack — not a public route despite the "partna-pages" comment referring to the eventual consumer) that flattens the primary brand into `{url, provider, discountCode, products}` via manual array construction, while `removeBrand()`/`setProducts()` in the same file correctly return `ShopBrandResource::collection(...)->resolve()`. Two field lists for the same underlying brand data can drift.
    - **Plain English:** The shop-card summary is hand-picked from the stored brand data in one spot, while the rest of the dashboard reads the same brand data through a proper template. If the template gains a field, this hand-written summary won't automatically get it.
    - **Evidence:**
        ```php
        $selection = $primary ? [
            'url' => $primary['url'],
            'provider' => $primary['provider'] ?? 'shopify',
            'discountCode' => $primary['discountCode'] ?? '',
            'products' => $primary['products'],
        ] : null;

        return $this->success(['selection' => $selection]);
        ```

- [ ] **#API-7** · P3 — `StaffUserController::show()` duplicates `StaffIntegrationManagementController::integrationsSummary()` inline and hand-builds `design_summary`
    - **Where:**
        - `app/Http/Controllers/Api/Staff/UserSiteManagement/StaffUserController.php:99-135`
        - `app/Http/Controllers/Api/Staff/UserSiteManagement/StaffIntegrationManagementController.php:60-65` (the existing helper it duplicates)
    - **Affects:** Staff viewing a professional's detail page; consistency with the dedicated integrations-tab endpoint.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Extract the shared integrations-summary shape into a `StaffIntegrationSummaryResource` (or a shared service method both controllers call) so `StaffUserController::show()` and `StaffIntegrationManagementController` can't drift.
        - Fold `design_summary` into `StaffSiteResource` behind a fluent `withDesignSummary()`, mirroring `SiteResource::withRationale()`'s existing pattern in this codebase.
    - **Technical:** `StaffUserController::show()` independently re-implements the integration-grouping query and shape that `StaffIntegrationManagementController::integrationsSummary()` already builds (verified: near-identical `groupBy('platform')` → `{platform, connection_count, is_active, last_refreshed_at, has_refresh_error}` shape). Two call sites for one contract means a future field addition (e.g. `last_refresh_error`) is easy to add in one place and forget in the other.
    - **Plain English:** The same "which integrations are connected" summary is built in two different places in the code. If someone updates one, the other can silently fall out of sync — a shared template keeps them identical by construction.
    - **Evidence:**
        ```php
        'integrations' => $integrations,
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

- [ ] **#API-8** · P3 — Content/design upload endpoints manually pre-materialize Resources with `->toArray()`, bypassing the app's own inline-Resource pattern
    - **Where:**
        - `app/Http/Controllers/Api/User/Content/ContentController.php:64` (`library()`), `:102` (`storeUpload()`)
        - `app/Http/Controllers/Api/User/Uploads/UserDesignMediaController.php:55` (`index()`), `:86` (`upload()`)
    - **Affects:** Dashboard content-library and design-media upload responses.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Stop calling `->toArray($request)` manually; embed the Resource object directly in the returned array (e.g. `$images[$purpose] = $media instanceof SiteMedia ? new DesignMediaResource($media) : null;`), matching the pattern `UserSelfController::show()` already uses for `'professional' => new UserDashboardResource($pro)`. Laravel's JSON encoder resolves each nested `JsonResource` via `jsonSerialize()`/`resolve()` automatically, which correctly handles any future `when()`/`mergeWhen()` conditional additions — manual `->toArray()` does not.
    - **Technical:** `$this->success()` in this codebase is a thin wrapper over `response()->json($data)` — it does **not** apply Laravel's automatic `data`-key wrapping (that only happens when a `JsonResource` is returned directly as the route's response and its `toResponse()` runs). So the "lost envelope" framing doesn't apply here; both `->toArray($request)` and embedding the Resource object produce the same wire shape today, because neither `ContentLibraryUploadResource` nor `DesignMediaResource` currently uses `$this->when()`/`mergeWhen()`/`merge()`. The real (currently-latent) risk is narrower: `->toArray()` skips the `resolve()`/`jsonSerialize()` step that flattens Laravel's internal `MergeValue`/`MissingValue` conditional-attribute wrappers — if either Resource later adds a `when()`-gated field, the four call sites here would silently serialize a raw internal PHP object into the JSON response instead of a scalar. Fix is mechanical and removes the landmine before it's tripped.
    - **Plain English:** Four spots in the code build a piece of the response by hand instead of letting the standard template do it. It works fine today because the templates are simple, but if someone later adds a conditional field to those templates (a common pattern elsewhere in this codebase), these four spots would silently ship broken data instead of the conditional value. Switching them to the standard hand-off closes that trap before it's ever sprung.
    - **Evidence:**
        ```php
        // ContentController::library()
        ->map(fn (SiteMedia $m) => (new ContentLibraryUploadResource($m))->toArray($request))
        ```
        ```php
        // ContentController::storeUpload()
        return $this->success((new ContentLibraryUploadResource($media))->toArray($request), 201);
        ```
        ```php
        // UserDesignMediaController::index()
        $images[$purpose] = $media instanceof SiteMedia ? (new DesignMediaResource($media))->toArray(request()) : null;
        ```
        ```php
        // UserDesignMediaController::upload()
        return $this->success((new DesignMediaResource($media))->toArray(request()), 201);
        ```

- [ ] **#API-9** · P3 — `PublicMenuController::show()` manually constructs the public menu payload with no Resource-class allowlist
    - **Where:** `app/Http/Controllers/Api/PublicSite/PublicMenuController.php:74-104`
    - **Affects:** Unauthenticated public sitepage visitors (the menu endpoint is the highest-risk surface in this lens — public, CDN-adjacent, unauthenticated); future developers adding fields to `Menu`/`MenuCategory`/`MenuItem`.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Extract a `PublicMenuResource` / `PublicMenuCategoryResource` pair with an explicit field allowlist, matching the pattern `IndividualProfileResource` uses (including its "INTENTIONAL EXCLUSIONS" docblock convention) for the platform's other public-surface Resource.
    - **Technical:** `show()` iterates `$menu->categories`/`$cat->items` and maps Eloquent attributes to response keys via inline closures. Every field shipped today is deliberately curated and non-sensitive (name, description, image, price, rating, badges) — this is not a live leak — but it is the **only** public-data controller in the audited scope that bypasses the Resource-class allowlist pattern the rest of the PublicSite surface uses (`IndividualProfileResource`, `PublicIntegrationConnectionResource`). Unlike the other manual-array findings in this audit (#API-5 through #API-8, all authenticated/staff surfaces), this one sits directly on the unauthenticated public wire, so a future field addition here has no allowlist gate before reaching an unauthenticated visitor.
    - **Plain English:** This is like writing a package slip by hand every time instead of using a template. It's accurate today, but if someone later adds a new field to the menu item and copies a nearby line to do it, they could accidentally put something not meant for public eyes onto a document anyone can view. A template (Resource class) prevents that by only having slots for public-safe fields — the same protection the rest of the public profile page already has.
    - **Evidence:**
        ```php
        $categories = $menu->categories
            ->map(fn ($cat) => [
                'name' => $cat->name,
                'popularityRank' => $categoryRanks[(string) $cat->id] ?? null,
                'items' => $cat->items->map(fn ($item) => [
                    'name' => $item->name,
                    'description' => $item->description,
                    'imageUrl' => $item->image_url,
                    'price' => $item->base_price !== null
                        ? number_format((float) $item->base_price, 2)
                        : null,
                    'rating' => $item->rating,
                    'ratingCount' => $item->rating_count,
                    'badges' => $item->badges,
                    'popularityRank' => $itemRanks[(string) $item->id] ?? null,
                ])->values()->toArray(),
            ])
            ->filter(fn ($cat) => count($cat['items']) > 0)
            ->values()
            ->toArray();
        ```

- [ ] **#API-10** · P3 — `StaffNotificationController::index()` uses `->limit()` instead of `->paginate()`, omitting pagination metadata used by every sibling staff list endpoint
    - **Where:** `app/Http/Controllers/Api/Staff/StaffSite/StaffNotificationController.php:159-174`
    - **Affects:** Staff dashboard notification history view; clients cannot discover whether more than `limit` rows exist.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `->limit($limit)->get()` with `->paginate($limit)` and route the result through the existing `paginatedResponse()` helper (already used by `StaffUserController::index()` and `StaffSegmentController::users()` in this same audit) so `meta.current_page`/`last_page`/`next_page_url` ship consistently.
    - **Technical:** The endpoint caps results at a configurable `limit` (1–200, default 50) via `Notification::query()->orderByDesc('created_at')->limit($limit)->get()` and returns a flat `{ notifications: [...] }` body with no pagination metadata. The codebase already has a standard `ReturnsPaginatedResponse::paginatedResponse()` trait (verified) that other staff list endpoints use — this is the one outlier. A client with 200+ notifications has no way to know a page 2 exists or fetch it.
    - **Plain English:** Every other staff list screen tells the dashboard "here's page 1 of 12, click for more." The notification history screen just says "here are up to 200 notifications" with no indication of whether there's more. If there are 250, the last 50 are invisible with no way to reach them.
    - **Evidence:**
        ```php
        $limit = max(1, min((int) $request->query('limit', 50), 200));

        $query = Notification::query()->orderByDesc('created_at')->limit($limit);
        // ...
        return $this->success([
            'notifications' => $query->get()
                ->map(fn (Notification $n) => (new NotificationListingResource($n))->resolve())
                ->values(),
        ]);
        ```

## Suggested Bundled Sessions

- **Bundle 1 — Own-resource internal-FK hygiene:** #API-1, #API-2
    - **Why grouped:** identical root cause (an internal identity/FK field exposed unconditionally on a self-scoped Resource with no consumer use case) — one small PR touching two Resource files.
    - **Model:** follow the file's Execution policy (Plan: Opus · Implement: Sonnet · Review: Sonnet).

- **Bundle 2 — Analytics response-shape documentation:** #API-4
    - **Why grouped:** single-file docblock addition, no code-behavior change.
    - **Model:** Plan+Implement combinable (S effort) · Review: Sonnet.

- **Bundle 3 — Manual-array-to-Resource cleanup (authenticated/staff surfaces):** #API-5, #API-6, #API-7, #API-8
    - **Why grouped:** same root-cause pattern (controller hand-builds response arrays instead of routing through a Resource class) across Staff and User surfaces; none touch auth/money/schema, all mechanical extractions with existing sibling Resources to model from.
    - **Model:** follow the file's Execution policy (Plan: Opus · Implement: Sonnet · Review: Sonnet). #API-7 is the largest (M effort, two extractions) — implement it last in the bundle so the reviewer can check it in isolation.

- **Bundle 4 — Content/design upload Resource embedding:** #API-3, #API-9
    - **Why grouped:** both touch `ContentLibraryUploadResource`'s contract area (one is a field addition, the other is a related consumption-pattern fix on the sibling public menu endpoint) — bundled for reviewer context locality, not a shared root cause with Bundle 3.
    - **Model:** follow the file's Execution policy (Plan: Opus · Implement: Sonnet · Review: Sonnet).

## Standalone — do NOT bundle

- **#API-10 — Add pagination metadata to `StaffNotificationController::index()`** · standalone because it changes response shape (adds a `meta` envelope) on a live staff endpoint — verify no staff-dashboard client depends on the current flat `{ notifications: [...] }` shape before shipping, independent of the other bundles.
