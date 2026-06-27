`★ Insight ─────────────────────────────────────`
**Adjudication approach for this pass:**
1. AUTH-1 downgraded P0→P1: the uncached model fetch is a serious perf gap, not a security bypass or crash.
2. CACHE-5 upgraded P3→P2 and expanded: `/api/me` runs 3–4 uncached queries beyond the auth model, hitting the highest-traffic dashboard endpoint.
3. Two new P3 findings added: `Site::where()` redundancy in `BrandDesignController` (site is already eager-loaded on `$pro`), and double `$site->fresh()` in the store-settings update path.
4. `06e0ed6` (`perf(auth): eager-load site in getByAuthId`) partially addresses AUTH-1 (eliminates the second round-trip for the site relation) but the Professional model itself remains uncached — cited in the Technical section.
`─────────────────────────────────────────────────`

# Performance & Caching Audit — 2026-05-07

**Branch:** development-v2
**Lens:** Performance and caching audit for hot authenticated read endpoints. Focus areas: (1) per-request overhead in middleware; (2) cache-service consumption — hot read endpoints bypassing CacheLockService::rememberLocked; (3) N+1 query patterns; (4) synchronous external API calls on read paths; (5) redundant work within a request. Laravel 12 + Supabase Postgres (~10–40ms RTT) + Redis.
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
- app/Http/Middleware/Auth/VerifySupabaseJwt.php
- app/Http/Middleware/Context/LoadCurrentProfessional.php
- app/Http/Middleware/BrandFundingGate.php
- app/Http/Middleware/Context/EnforcePendingDeletionReadOnly.php
- app/Http/Middleware/EnsureBrandAccount.php
- app/Http/Middleware/EnsureAffiliateAccount.php
- app/Http/Middleware/RequirePlan.php
- app/Http/Middleware/FeatureGate.php
- app/Http/Middleware/SecureHeaders.php
- app/Http/Middleware/AddPublicCacheHeaders.php
- app/Http/Middleware/Auth/EnsurePartnaAdmin.php
- app/Http/Middleware/Auth/EnsurePartnaStaff.php
- app/Http/Middleware/Auth/VerifyEmbeddedApiKey.php
- app/Http/Middleware/Auth/VerifyHydrogenApiKey.php
- app/Http/Middleware/Auth/VerifyShopifySessionToken.php
- app/Http/Middleware/Logging/LogLeadRateLimits.php
- app/Services/Cache/CacheLockService.php
- app/Services/Cache/CacheKeyGenerator.php
- app/Services/Cache/ProfessionalCacheService.php
- app/Services/Cache/SiteCacheService.php
- app/Services/Cache/AnalyticsCacheService.php
- app/Services/Professional/SectionVisibilityService.php
- app/Http/Controllers/Api/Professional/ProfessionalController.php
- app/Http/Controllers/Api/Professional/ProfessionalSiteSelfManagement/ProfessionalSiteController.php
- app/Http/Controllers/Api/Professional/ProfessionalSiteSelfManagement/ProfessionalServiceController.php
- app/Http/Controllers/Api/Professional/ProfessionalSiteSelfManagement/ProfessionalSectionBlockController.php
- app/Http/Controllers/Api/Professional/ProfessionalSiteSelfManagement/ProfessionalLinkBlockController.php
- app/Http/Controllers/Api/Professional/Uploads/ProfessionalUploadController.php
- app/Http/Controllers/Api/Professional/ProfessionalCustomerController.php
- app/Http/Controllers/Api/Professional/Notifications/NotificationController.php
- app/Http/Controllers/Api/Professional/ProfessionalAnalyticsController.php
- app/Http/Controllers/Api/Professional/BrandAffiliateInviteController.php
- app/Http/Controllers/Api/Professional/BrandPartnerController.php
- app/Http/Controllers/Api/Professional/Store/BrandStoreSettingsController.php
- app/Http/Controllers/Api/Professional/Store/BrandDesignController.php
- app/Http/Controllers/Api/Professional/ShopifyIntegration/ShopifyIntegrationController.php

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: **2 of 2 complete** ✅
- P2 Medium: **5 of 5 complete** ✅
- P3 Low: **2 of 2 complete** ✅

**All findings closed by `fdb7655` perf(api): cache hot read endpoints and auth-path Professional model (2026-05-07).** Verified against working tree on 2026-05-08.

---

## P1 — Fix before pilot launch

- [x] **#EXT-1** · P1 — Synchronous storefront HTTP probe blocks every /api/brand/store-settings read ✅ closed in `fdb7655` (`BrandStoreSettingsController::cachedStorefrontStatus` wraps probe in 60s `rememberLocked`)
    - **Where:** app/Http/Controllers/Api/Professional/Store/BrandStoreSettingsController.php — `show()` and `update()` both call `checkStorefrontStatus()`
    - **Affects:** Every brand that opens their store settings dashboard page. Adds up to 8 seconds of blocking network latency (5s timeout + 3s connect timeout) to a read endpoint that is otherwise fast.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Cache the result of `checkStorefrontStatus()` in Redis with a short TTL (60–120s) keyed by `"brand:{$pro->id}:storefront-status"`, using `CacheLockService::rememberLockedNullable`.
        - Remove the live HTTP call from `show()` entirely; serve the cached status instead.
        - Keep the live call in `deploy()` and `update()` write paths (or dispatch it as a background job there), so the cache refreshes when something actually changes.
        - Return a `status_checked_at` timestamp in the response so the frontend can show staleness if desired.
    - **Technical:** `BrandStoreSettingsController::show()` calls `$this->checkStorefrontStatus($storeSettings, $site?->subdomain)`, which runs `Http::withOptions(['timeout' => 5, 'connect_timeout' => 3])->get($url)` — a live outbound HTTP request to the brand's Oxygen/Hydrogen storefront. This executes synchronously on every GET, blocking the JSON response until the HTTP call settles or times out. The storefront status only changes on deploys or domain reconfiguration, both of which are explicit write actions with known triggers. The `checkStorefrontStatus` return value (`'live'`, `'redirecting'`, `'unreachable'`) is a textbook short-TTL cache candidate.
    - **Plain English:** Every time a brand opens their store settings page, the server picks up the phone and calls the brand's live storefront to check if it's running — before responding to the dashboard. If the storefront is slow to answer, or the internet has a hiccup, the settings page hangs for up to 8 seconds. The storefront status almost never changes (only during a redeploy), so instead of making a live call every single visit, the server should just remember the last answer for a minute and show that — refreshing it only when a deploy actually happens.
    - **Evidence:**
        ```php
        // BrandStoreSettingsController.php — show() response body
        'storefront_status' => $storeSettings
            ? $this->checkStorefrontStatus($storeSettings, $site?->subdomain ?? '')
            : 'unreachable',
        ```
        ```php
        // BrandStoreSettingsController.php — checkStorefrontStatus()
        private function checkStorefrontStatus(BrandStoreSettings $settings, string $subdomain): string
        {
            $url = $settings->storefrontBaseUrl($subdomain);

            try {
                $response = Http::withOptions([
                    'allow_redirects' => false,
                    'timeout' => 5,
                    'connect_timeout' => 3,
                ])->get($url);
        ```

- [x] **#AUTH-1** · P1 — Professional model fetched from Postgres on every authenticated request despite payload cache existing ✅ closed in `fdb7655` (`ProfessionalCacheService::getByAuthId` caches hydrated model with `site`+`squareIntegration` eager-loaded; busted by `invalidateProfessional` and `invalidateSite`)
    - **Where:** app/Services/Cache/ProfessionalCacheService.php — `getByAuthId()` · app/Http/Middleware/Context/LoadCurrentProfessional.php — `handle()`
    - **Affects:** Every authenticated API request across all 14 hot endpoints. With ~10–40ms Supabase round-trip time, this single uncached query accounts for 25–60% of request latency on every authenticated path.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add a short-TTL model cache (60s) in `getByAuthId`, keyed by `"pro:model:{$id}"`, using `CacheLockService::rememberLocked` (SWR + single-flight + jitter). Cache the hydrated `Professional` Eloquent model with the `site` relation loaded.
        - Add `"pro:model:{$professional->id}"` to the keys list in `invalidateProfessional()` so the model cache is busted on any profile write.
        - Add a `CacheKeyGenerator::professionalModel(string $id): string` method to keep the key naming consistent.
        - Consider also adding `squareIntegration` to the eager-load in `getByAuthId` so that `/api/me`'s `$pro->load('squareIntegration')` call is also absorbed into this single cached fetch.
    - **Technical:** `LoadCurrentProfessional::handle()` calls `$this->professionalCache->getByAuthId($uid)`. Inside `getByAuthId`, the professional's UUID is retrieved from Redis via `getIdByAuthId` (30-minute TTL, single-flight — this part is already fast), but then `Professional::query()->with('site')->find($id)` is executed — a live Postgres query on every authenticated request. Recent commit `06e0ed6` (`perf(auth): eager-load site in getByAuthId`) eliminated the *second* round-trip by eager-loading `site` into the same query, but the first round-trip itself remains uncached. The payload cache (`getPayloadByAuthId`, 1-hour TTL) already stores the professional data as an array, but `getByAuthId` doesn't consume it because downstream code requires an Eloquent model instance. Caching the Eloquent model (which PHP serializes cleanly) with a 60-second TTL plus SWR achieves near-zero database cost for the auth path under normal traffic.
    - **Plain English:** Every time any logged-in user makes any request — loading the dashboard, updating a service, checking notifications — the server walks to the database to look up who they are, even if they made a request one second ago. Their profile almost never changes between requests. It's like a hotel front desk that walks to the filing room to find your reservation card every single time you walk past, instead of keeping your name on a Post-it at the desk. The fix is to keep a 60-second copy of the profile in a fast nearby store (Redis), only going back to the database when the copy has expired or when the profile actually changes.
    - **Evidence:**
        ```php
        // LoadCurrentProfessional.php
        $professional = $this->professionalCache->getByAuthId($uid);
        ```
        ```php
        // ProfessionalCacheService.php — getByAuthId()
        public function getByAuthId(string $authUserId): ?Professional
        {
            $id = $this->getIdByAuthId($authUserId);  // ← Redis hit, 30-min TTL
            if (! $id) {
                return null;
            }

            // ↓ UNCACHED — raw Postgres query on every authenticated request
            $professional = Professional::query()->with('site')->find($id);
            if (! $professional) {
                return null;
            }
        ```
        ```php
        // ProfessionalCacheService.php — payload cache exists but is unused by the auth path
        public function getPayloadByAuthId(string $authUserId): ?array
        {
            $id = $this->getIdByAuthId($authUserId);

            return $id ? $this->getPayloadById($id) : null;  // ← array cache, 1-hr TTL
        }
        ```

---

## P2 — Should fix

- [x] **#CACHE-2** · P2 — /api/services bypasses existing active-services cache for the common unfiltered case ✅ closed in `fdb7655` (default branch now serves from new `getDashboardServices()` helper, distinct from `getActiveServices` public-site view)
    - **Where:** app/Http/Controllers/Api/Professional/ProfessionalSiteSelfManagement/ProfessionalServiceController.php — `index()`
    - **Affects:** Dashboard services list on every load. The cache already exists and is busted on every write; the controller simply doesn't use it.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - When all filter flags are at their defaults (`include_archived=false`, `only_archived=false`, `grouped=false`, `source=all`), return `$cache->getActiveServices($pro->id)` from `ProfessionalCacheService` instead of running the raw query.
        - When any filter is non-default, fall through to the existing raw query — filtered views are rarer and harder to cache-key cheaply.
        - No invalidation work needed: `invalidateProfessional()` already busts `CacheKeyGenerator::professionalServices($id)` on every service write.
    - **Technical:** `ProfessionalServiceController::index()` always executes `Service::query()->where('professional_id', $pro->id)->orderBy('sort_order')->orderBy('created_at')->get()` with optional soft-delete and source filters applied after the fact. `ProfessionalCacheService::getActiveServices()` wraps an equivalent unfiltered-active query in `CacheLockService::rememberLocked` with a 30-minute TTL and ±20% jitter. The cache key is already invalidated by `invalidateProfessional()`, which fires on every service store/update/destroy/restore via the model observer. The common dashboard load sends no filter params — this case can be served entirely from cache with no correctness risk.
    - **Plain English:** There's a shelf in the storeroom (Redis) labelled "this professional's active services," stocked fresh every time services change. Every time the dashboard loads the services list with no special filters, the controller ignores the shelf and walks to the warehouse (database) instead. The shelf is already being kept up to date for free — the fix is just to check it first.
    - **Evidence:**
        ```php
        // ProfessionalServiceController.php — index() always executes a raw query
        $servicesQuery = Service::query()
            ->where('professional_id', $pro->id);

        if ($source === 'manual') {
            $servicesQuery->whereNull('square_variation_id');
        } elseif ($source === 'square' || $source === 'smart') {
            $servicesQuery->whereNotNull('square_variation_id');
        }

        // ...

        $services = $servicesQuery->orderBy('sort_order')->orderBy('created_at')->get();
        ```
        ```php
        // ProfessionalCacheService.php — cache exists and is invalidated on writes, but unused by the controller
        public function getActiveServices(string $professionalId): array
        {
            return $this->cacheLock->rememberLocked(
                CacheKeyGenerator::professionalServices($professionalId),
                now()->addMinutes(30),
                fn () => Service::query()
                    ->where('professional_id', $professionalId)
                    ->where('is_active', true)
                    ->whereNull('deleted_at')
                    ->orderBy('sort_order')
                    ->get()
                    ->toArray()
            );
        }
        ```

- [x] **#CACHE-3** · P2 — /api/links bypasses existing cached link-blocks helper ✅ closed in `fdb7655` (`ProfessionalLinkBlockController::index` now serves from `SiteCacheService::getSiteLinkBlocks`, active-only)
    - **Where:** app/Http/Controllers/Api/Professional/ProfessionalSiteSelfManagement/ProfessionalLinkBlockController.php — `index()`
    - **Affects:** Dashboard link blocks list on every load. `SiteCacheService::getSiteLinkBlocks` is cached with single-flight + SWR; the controller ignores it.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Before switching, confirm that `Block::active()` scope and `$pro->linkBlocks()` return the same set for the management view — if `linkBlocks()` surfaces inactive blocks for the dashboard toggle UI, a separate all-blocks cache key is needed rather than reusing the existing active-only one.
        - If scopes match (or the management view only needs active blocks): resolve the site from the already-loaded `$pro->site` and return `app(SiteCacheService::class)->getSiteLinkBlocks($pro->site->id)`.
        - If the management view requires inactive blocks too: add a `getSiteAllLinkBlocks(string $siteId): array` method to `SiteCacheService` with a 5-minute TTL, and bust it alongside the existing active-only key in `invalidateSite()`.
    - **Technical:** `ProfessionalLinkBlockController::index()` calls `$pro->linkBlocks()->orderBy('sort_order')->get()` — a raw Eloquent relation query — on every request. `SiteCacheService::getSiteLinkBlocks($siteId)` wraps `Block::where('site_id', $siteId)->where('block_group', 'links')->active()->orderBy('sort_order')->get()` in `CacheLockService::rememberLocked` with a 15-minute TTL, ±20% jitter, and SWR, and is already busted by `invalidateSite()` on every write. Note: `$pro->site` is already loaded via the auth middleware eager-load (commit `06e0ed6`), so `$pro->site->id` avoids a second round-trip. The `/api/me` endpoint already uses `getSiteLinkBlocks` for the dashboard home payload; using it here brings the two endpoints into consistent behavior.
    - **Plain English:** The link blocks list has a ready-made cached version that keeps itself fresh and handles traffic bursts gracefully. The dashboard home page already uses this cache. But the dedicated link management endpoint asks the database directly every time — like having vending machines on every floor but choosing to walk to the warehouse for every snack. The risk to check before applying: the cached version might only include visible (active) links; if the management page needs to show hidden ones too, a slightly different cache is needed.
    - **Evidence:**
        ```php
        // ProfessionalLinkBlockController.php — index() bypasses the cache
        public function index(IndexLinkBlockRequest $request)
        {
            $pro = $this->currentProfessional($request);

            return $this->success([
                'blocks' => $pro->linkBlocks()->orderBy('sort_order')->get(),
            ]);
        }
        ```
        ```php
        // SiteCacheService.php — cached version with SWR + jitter, unused by the link controller
        public function getSiteLinkBlocks(string $siteId): array
        {
            return $this->cacheLock->rememberLocked(
                CacheKeyGenerator::siteBlocks($siteId, 'links'),
                self::PAYLOAD_TTL_SECONDS,
                fn () => Block::query()
                    ->where('site_id', $siteId)
                    ->where('block_group', 'links')
                    ->active()
                    ->orderBy('sort_order')
                    ->get()
                    ->toArray()
            );
        }
        ```

- [x] **#CACHE-5** · P2 — /api/me executes 3–4 uncached DB queries beyond the auth model fetch on every dashboard load ✅ closed in `fdb7655` (extras collapsed to `getBrandStoreSettings` 30m + `getBrandPartnerStatus` 5m; new `BrandStoreSettingsObserver` and `BrandProfileObserver` bust on writes; `squareIntegration` absorbed by AUTH-1 eager-load)
    - **Where:** app/Http/Controllers/Api/Professional/ProfessionalController.php — `show()`
    - **Affects:** Every authenticated user loading the dashboard. Adds 3–4 Supabase round-trips (~30–120ms at 10–40ms RTT each) to the primary entry point before any business logic runs.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add `getBrandStoreSettings(string $professionalId): ?array` to `ProfessionalCacheService` using `rememberLockedNullable` with a 15–30 minute TTL. Bust it from `invalidateProfessional()` and from the store-settings update path.
        - Add a `getBrandPartnerStatus(string $brandProfessionalId): array` helper (or cache-aside pattern) that stores the `BrandProfile->brand_status` + `Professional->display_name` tuple under `"pro:{$brandPartnerId}:brand-status-info"` with a 5-minute TTL. Bust on `BrandProfile` status changes.
        - For `squareIntegration`: include it in the `getByAuthId` eager-load (`->with(['site', 'squareIntegration'])`) once AUTH-1's model cache is in place, so it is absorbed into the single cached model fetch instead of being a separate lazy-load at the controller layer.
    - **Technical:** `ProfessionalController::show()` (the `/api/me` endpoint) executes these uncached queries after the professional is already loaded: (1) `$pro->load('squareIntegration')` — one Eloquent relation query; (2) `BrandStoreSettings::where('professional_id', $pro->id)->first()` — one lookup query; and for non-brand professionals with a configured brand partner: (3) `BrandProfile::where('professional_id', $brandPartnerId)->first()` and (4) `Professional::find($brandPartnerId)`. All four are simple primary-key or FK lookups on data that changes infrequently. `BrandStoreSettings` changes only when the brand configures store settings. The brand partner status changes at most a few times per day on admin action. Caching each with appropriate TTLs and invalidation on writes eliminates 30–120ms of database latency from every dashboard page load.
    - **Plain English:** When any user opens the dashboard, the server makes four separate trips to the database to look up things that almost never change: the brand's store settings, whether Square is connected, and — for affiliates — the connected brand's status and name. It's like the front desk calling four different departments to confirm the same information at the start of every shift. Each of those calls can be answered from a memory note (cache) that stays fresh for 5–30 minutes and is torn up the moment the underlying information changes.
    - **Evidence:**
        ```php
        // ProfessionalController.php — show()
        $pro->load('squareIntegration');
        $brandStoreSettings = BrandStoreSettings::where('professional_id', $pro->id)->first();
        ```
        ```php
        // ProfessionalController.php — show(), affiliate-only branch
        if ($pro->professional_type !== 'brand') {
            $brandPartnerId = $siteSettings['brand_partner']['professional_id'] ?? null;
            if ($brandPartnerId) {
                $brandProfile = BrandProfile::where('professional_id', $brandPartnerId)->first();
                $primaryBrandStatus = $brandProfile?->brand_status ?? BrandStatus::Onboarding->value;
                $primaryBrandName = \App\Models\Core\Professional\Professional::find($brandPartnerId)?->display_name ?? null;
            }
        }
        ```

- [x] **#CACHE-4** · P2 — /api/me/notifications uncached with two Postgres queries on every poll ✅ closed in `fdb7655` (wrapped in 15s `rememberLocked` keyed by `(pro, limit, include_dismissed)`; `markRead`/`dismiss` bust common variants)
    - **Where:** app/Http/Controllers/Api/Professional/Notifications/NotificationController.php — `index()`
    - **Affects:** Dashboard notification bell. Polled by the frontend on a timer (likely every 30–60 seconds per active session). Every user, every session, steady-state.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Wrap the combined list + unread count in `CacheLockService::rememberLocked` keyed by `"pro:{$professionalId}:notifications:{$limit}:{$includeDismissed}"`, with a 15–30 second TTL.
        - Bust the cache key from `markRead()`, `dismiss()`, and from the notification fan-out writer (wherever new notifications are published to a professional). The 15–30s TTL is a safety net if a bust is missed.
        - At minimum (if full caching feels risky), combine the list and count into a single query with a `COUNT(*) OVER()` window or a subquery, eliminating the second round-trip for the unread badge number.
    - **Technical:** `NotificationController::index()` issues two independent Postgres queries: a paginated list query with LEFT JOIN on `notification_receipts` plus time-window and read/dismissed filtering, and a separate `(clone $base)->whereNull('r.read_at')->whereNull('r.dismissed_at')->count()` for the badge number. With a polling interval of 30–60 seconds and multiple concurrent sessions per brand, this produces a steady stream of dual-query traffic against `notifications.notifications` and `notifications.notification_receipts`. CacheLockService with a 15–30s TTL collapses that to one regeneration per professional per window, and write-side cache busting keeps the data accurate within seconds of any change.
    - **Plain English:** The notification bell in the dashboard refreshes automatically every 30 seconds or so to check for new messages. Each refresh sends two separate questions to the database: "what are my notifications?" and "how many are unread?" Even when the answers are "none" and "zero" for hours, both questions go out on every refresh for every active user. A 15-second memory note (cache) that gets torn up the moment anything changes would cut that traffic by 90%+ while still making alerts feel instant.
    - **Evidence:**
        ```php
        // NotificationController.php — index()
        $rows = $listQuery
            ->orderByDesc('n.created_at')
            ->limit($limit + 1)
            ->get([
                'n.id',
                'n.professional_id',
                'n.type',
                'n.title',
                'n.body',
                'n.cta_url',
                'n.primary_action_label',
                'n.secondary_action_label',
                'n.secondary_action_url',
                'n.severity',
                'n.starts_at',
                'n.ends_at',
                'n.created_at',
                'r.read_at',
                'r.dismissed_at',
            ]);  // Query 1 — notification list

        $unreadCount = (clone $base)
            ->whereNull('r.read_at')
            ->whereNull('r.dismissed_at')
            ->count();  // Query 2 — unread badge count
        ```

- [x] **#CACHE-1** · P2 — /api/images endpoint completely uncached; full SiteMedia + variants query on every poll ✅ closed in `fdb7655` (two key shapes: enumerable `siteImagesView` 30s keys for filtered gallery views busted by `invalidateSite`; fingerprint `siteImagesPolling` 5s TTL keys for `?ids[]` upload polling)
    - **Where:** app/Http/Controllers/Api/Professional/Uploads/ProfessionalUploadController.php — `index()`
    - **Affects:** Dashboard image gallery on load and during upload flows. Clients poll this endpoint every few seconds while waiting for `processing_state` to flip from `pending` → `ready`. Multiple concurrent polls per upload session hit raw Postgres each time.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Wrap the `index()` query in `CacheLockService::rememberLocked` keyed by a fingerprint of `site_id` + filter params (pool, media_type, ids), using `CacheKeyGenerator::siteImages($site->id)` as the base key with filter-param suffixes.
        - Use a short TTL for the polling path (`?ids[]=uuid`): 5s is enough to collapse concurrent polls without staling out on processing-state transitions.
        - Use a longer TTL (15–30s) for the unfiltered gallery load.
        - Bust `siteImages($site->id)` in `SiteCacheService::invalidateSite()` (it is already listed in the `invalidateSite` keys array — ensure upload, delete, and reorder paths all call `invalidateSite`).
    - **Technical:** `ProfessionalUploadController::index()` builds a `SiteMedia::query()` against Supabase, applies optional `pool`, `media_type`, and `ids` filters, eager-loads `mediaVariants`, then iterates every result through `buildMediaPayload()` which calls `$media->variantUrls()` for each item. No caching layer exists at any level. `CacheKeyGenerator::siteImages(string $siteId)` already exists but no controller consumes it. The `?ids[]=uuid` polling path is the highest-frequency caller: during a multi-file upload session, the client polls every 3–5 seconds per in-progress upload. CacheLockService's SWR and single-flight semantics would prevent stampedes on popular sites, and the 5s TTL is short enough that a state transition (pending → ready) surfaces within one poll window.
    - **Plain English:** While uploading photos, the dashboard checks every few seconds to see if thumbnails have been generated — like checking the oven every 30 seconds to see if the food is ready. Each of those checks asks the database for the full list of images and all their thumbnail variants, even when nothing has changed since the last check a few seconds ago. A 5-second memory cache would serve all those rapid-fire checks from memory, hitting the database only once per 5-second window per user — saving tens of database trips per upload session without any visible delay to the user.
    - **Evidence:**
        ```php
        // ProfessionalUploadController.php — index()
        $query = SiteMedia::query()
            ->where('site_id', $site->id)
            ->where('is_active', true)
            ->orderBy('pool')
            ->orderBy('sort_order')
            ->orderBy('created_at');

        if ($mediaTypeFilter !== 'all') {
            $query->where('media_type', $mediaTypeFilter);
        }

        if (request()->has('pool')) {
            $pool = strtolower(trim(request()->input('pool')));
            if (in_array($pool, ['gallery', 'content'], true)) {
                $query->where('pool', $pool);
            }
        }

        if (request()->has('ids')) {
            $ids = array_filter((array) request()->input('ids'), fn ($id) => is_string($id) && Str::isUuid($id));
            if (! empty($ids)) {
                $query->whereIn('id', array_values($ids));
            }
        }

        $query->with('mediaVariants');

        $items = $query->get()->map(fn (SiteMedia $item) => $this->buildMediaPayload($item, includeVariants: true));
        ```

---

## P3 — Nice to have

- [x] **#PERF-2** · P3 — BrandStoreSettingsController::update() calls $site->fresh() twice, paying two identical Postgres queries ✅ closed in `fdb7655` (`$freshSite = $site?->fresh()` stashed once at line 225)
    - **Where:** app/Http/Controllers/Api/Professional/Store/BrandStoreSettingsController.php — `update()`, final return block
    - **Affects:** Every brand that saves store settings. Two identical `SELECT * FROM sites WHERE id = ?` queries fire where one would suffice.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Assign the result of the first `fresh()` call to a local variable and reuse it: `$freshSite = $site?->fresh(); $freshSiteSettings = is_array($freshSite?->settings) ? $freshSite->settings : [];`
    - **Technical:** The expression `is_array($site?->fresh()?->settings) ? $site->fresh()->settings : []` calls `fresh()` twice — once to check if settings is an array, and again unconditionally on the true branch. Each call issues a fresh `SELECT` against Supabase. Because the `fresh()` call is in the conditional guard, even static analysis tools may miss this as redundant. The fix is a two-line assignment that eliminates the second round-trip with zero behaviour change.
    - **Plain English:** When a brand saves store settings, the server checks the database to confirm the save worked — which is correct. But it accidentally checks twice in a row, asking the same question twice before answering the brand. Storing the answer from the first check and reusing it cuts one unnecessary database trip with no change to what the user sees.
    - **Evidence:**
        ```php
        // BrandStoreSettingsController.php — update(), return block
        $freshSiteSettings = is_array($site?->fresh()?->settings) ? $site->fresh()->settings : [];
        $freshDesign = is_array($freshSiteSettings['design'] ?? null) ? $freshSiteSettings['design'] : [];
        ```

- [x] **#PERF-1** · P3 — BrandDesignController::show() queries Site::where() despite $pro->site already eager-loaded ✅ closed in `fdb7655` (now reads `$pro->site` directly — eager-loaded by AUTH-1)
    - **Where:** app/Http/Controllers/Api/Professional/Store/BrandDesignController.php — `show()`
    - **Affects:** Every brand loading the /api/brand/design endpoint. One redundant Postgres round-trip per call.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `Site::where('professional_id', $pro->id)->first()` with `$pro->site`. The professional is resolved by `currentProfessional($request)` which calls `getByAuthId()`, and since commit `06e0ed6` that method eager-loads `site` via `->with('site')`. The relation is already in memory.
        - If the `site` relation could be null (new account without a site), handle that the same way the current code handles a null return from `Site::where()`.
    - **Technical:** `BrandDesignController::show()` calls `Site::where('professional_id', $pro->id)->first()` to fetch the site model. As of commit `06e0ed6` (`perf(auth): eager-load site in getByAuthId`), `$pro->site` is guaranteed to be loaded into the Eloquent relation cache by the time any controller action runs — accessing it does not issue a query. The `Site::where()` call therefore duplicates a query that has already been answered for free. A one-line change eliminates the extra round-trip.
    - **Plain English:** After the server figures out who is logged in (which already includes loading their site information), the brand design page asks the database for the site information again — even though it's already in memory from 10 milliseconds ago. It's like a waiter taking your order, walking to the kitchen, then coming back to ask your name again before going back to the kitchen. The server already has everything it needs; it just needs to use what it already found.
    - **Evidence:**
        ```php
        // BrandDesignController.php — show()
        $site = Site::where('professional_id', $pro->id)->first();
        $settings = is_array($site?->settings) ? $site->settings : [];
        $design = is_array($settings['design'] ?? null) ? $settings['design'] : [];
        ```
        ```php
        // ProfessionalCacheService.php — getByAuthId() — site is already in memory after this
        $professional = Professional::query()->with('site')->find($id);
        ```

`★ Insight ─────────────────────────────────────`
**Three structural patterns to watch going forward:**
1. The *cache-exists-but-controller-ignores-it* pattern (CACHE-2, CACHE-3) will keep recurring as new endpoints are added. A PR checklist item — "does a cache method exist for this data?" — would catch these at review time rather than in an audit.
2. AUTH-1 is architecturally the highest-leverage fix: once the Professional model is cached for 60s with SWR, CACHE-5's `squareIntegration` eager-load also becomes free (absorbed into the cached model), removing a second P2 item for no additional effort.
3. `$site->fresh()` called inside a ternary guard (PERF-2) is a PHP footgun — the two call-sites look like one expression, but they evaluate independently. A linter rule or team convention of "never call `->fresh()` twice in the same expression" would prevent this class of bug.
`─────────────────────────────────────────────────`
