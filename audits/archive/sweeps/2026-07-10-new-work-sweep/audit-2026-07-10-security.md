# Security Audit — 2026-07-10

**Branch:** audit-fix/analytics-master-2026-07-10
**Lens:** Security: auth boundaries, tenant isolation, mass assignment, inbound callbacks, secrets, injection, SSRF, upload safety, PII exposure (chunked scan — auth-core, user-surface, public-staff-surface, outbound-services, outbound-platforms)
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4.5`
**Source files audited:**
- app/Providers/PlatformRegistryServiceProvider.php
- app/Http/Middleware/VerifyBotToken.php
- app/Http/Middleware/Auth/VerifySupabaseJwt.php
- app/Http/Middleware/Auth/VerifySupabaseHookSignature.php
- app/Services/Webhooks/StandardWebhookVerifier.php
- app/Http/Middleware/IdempotencyKey.php
- app/Http/Controllers/Api/Internal/EnvCheckController.php
- app/Http/Controllers/Api/User/SiteManagement/UserSectionBlockController.php
- app/Http/Controllers/Api/User/SiteManagement/UserWorkplaceController.php
- app/Http/Controllers/Api/User/Account/UserSelfController.php
- app/Http/Controllers/Api/User/Analytics/DevInsightsController.php
- app/Http/Controllers/Api/User/Analytics/UserAnalyticsController.php
- app/Http/Controllers/Api/User/Profile/SectorController.php
- app/Http/Controllers/Api/User/Content/ContentController.php
- app/Http/Requests/Api/User/Content/UploadContentImageRequest.php
- app/Http/Requests/Concerns/SniffsFileMimeType.php
- app/Services/Media/ImageVariantService.php
- app/Services/Media/MediaUploadService.php
- app/Http/Requests/Api/User/UpdateUserRequest.php
- app/Models/Core/User/User.php
- app/Policies/UserSelfPolicy.php
- app/Http/Controllers/Api/Platforms/BookingController.php
- app/Http/Controllers/Api/Platforms/ReservationsController.php
- app/Http/Controllers/Api/Platforms/GenericPlatformController.php
- app/Http/Controllers/Api/Platforms/DisplaySettingsController.php
- app/Http/Controllers/Api/Platforms/RefreshController.php
- app/Http/Controllers/Api/Platforms/GoogleBusinessController.php
- app/Http/Controllers/Api/Platforms/ShopController.php
- app/Http/Controllers/Api/Platforms/OnlineOrderingController.php
- app/Http/Controllers/Api/Platforms/InstagramController.php
- app/Http/Controllers/Api/Platforms/Concerns/ManagesIntegrationConnection.php
- app/Http/Controllers/Api/Staff/UserSiteManagement/StaffUserController.php
- app/Http/Controllers/Api/Staff/StaffSite/StaffAnalyticsController.php
- app/Http/Controllers/Api/PublicSite/AnalyticsController.php
- app/Policies/IntegrationConnectionPolicy.php
- app/Models/Core/Site/IntegrationConnection.php
- app/Services/Http/SafeUrlFetcher.php
- app/Services/Platforms/FreshaScraper.php
- app/Services/Platforms/GenericShopScraper.php
- app/Services/Platforms/ShopProviderDetector.php
- app/Services/Platforms/LinkCardScraper.php
- app/Services/Platforms/GoogleBusinessService.php
- app/Services/Platforms/MenuApifyScraper.php
- app/Services/Platforms/IntegrationConnectionCacheRefresher.php
- routes/api.php, routes/api/publicSite.php
- tests/Feature/Security/BotProtectionCoverageTest.php

## Progress

- P2 Medium: 0 of 9 complete
- P3 Low: 0 of 2 complete

---

## P2 — Should fix

- [ ] **#SEC-1** · P2 — Raw exception messages from third-party HTTP calls logged unscrubbed across three platform service files
    - **Where:** app/Services/Platforms/FreshaScraper.php:216-221; app/Services/Platforms/MenuApifyScraper.php:375; app/Services/Platforms/IntegrationConnectionCacheRefresher.php:47-51
    - **Affects:** Log aggregator (Nightwatch log stream) — exception text from Guzzle/HTTP failures (which can embed destination URLs, upstream response fragments, or internal identifiers) persists in the general-access log rather than only the access-controlled exception tracker.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `'error' => $e->getMessage()` / `'message' => $e->getMessage()` in these three log calls with a static operation label (e.g. `'fresha_graphql_exception'`).
        - `IntegrationConnectionCacheRefresher::purge()` already calls `report($e)` immediately before the `Log::warning` — the full exception is already captured in Nightwatch's exception tracker; the log line is a pure duplicate with no additional access control.
    - **Technical:** All three call sites embed `$e->getMessage()` directly into a `Log::*` call. `FreshaScraper::fetchEmployeeServices()` has no `report()` call at all, so the log line is the *only* record of the failure. `IntegrationConnectionCacheRefresher::purge()` calls `report($e)` first (line 46) — meaning the exception already lands in Nightwatch's access-controlled tracker — so the subsequent `Log::warning` with the raw message is a redundant, less-controlled copy. Neither is a confirmed active leak today, but per the lens's log-hygiene category this is worth tightening before real user data starts flowing through these paths.
    - **Plain English:** When a call to an outside service fails, the error message sometimes contains bits of the request or response — like a receipt that occasionally prints more than it should. Three places in the code write that raw error text into the general log file, which is read more broadly than the dedicated error-tracking system. The fix: log a short, fixed label like "Fresha call failed" instead of the raw text — the full detail is (or can be) captured in the properly access-controlled error tracker instead.
    - **Evidence:**
        ```php
        // FreshaScraper.php:216-221
        Log::warning('fresha.employee_services.failed', [
            'reason' => 'exception',
            'slug' => $slug,
            'employee_id' => $employeeId,
            'error' => $e->getMessage(),
        ]);
        ```
        ```php
        // MenuApifyScraper.php:375
        Log::info('menu.apify.threw', ['platform' => $platform, 'user_id' => $userId, 'attempt' => $attempt, 'error' => $e->getMessage()]);
        ```
        ```php
        // IntegrationConnectionCacheRefresher.php:46-51
        report($e);
        Log::warning('IntegrationConnectionCacheRefresher purge failed', [
            'platform_connection_id' => $connection->id,
            'user_id' => $connection->user_id,
            'message' => $e->getMessage(),
        ]);
        ```

- [ ] **#SEC-2** · P2 — RUM beacon logs handle + coarse geolocation to the general log stream (already rate-limited; log-persistence hygiene only)
    - **Where:** app/Http/Controllers/Api/PublicSite/AnalyticsController.php:483-492; routes/api.php:114-115
    - **Affects:** Every visitor whose browser fires the RUM performance beacon — their site handle, country, and truncated user-agent are written to the general `Log::info` stream indefinitely.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Route RUM timing data to a dedicated metrics/time-series channel instead of the general application log, or drop `handle`/`country`/`ua` from the logged context (they're not needed to compute percentile timings).
    - **Technical:** Verified this endpoint IS already rate-limited (`throttle:analytics` middleware, routes/api.php:114-115) and bot-UA-filtered (`isBotUserAgent()` check before logging) — the original draft's claim of "no rate limiting" does not hold and is corrected here. The remaining, legitimate concern is narrower than originally scoped: `handle` (public, already in the URL) plus `country` (coarse, from `cf-ipcountry`) plus a truncated UA persist in the general log stream rather than a purpose-built metrics store, which is log-hygiene rather than an access-control gap.
    - **Plain English:** Every sitepage has a hidden beacon that reports how fast the page loaded. It's already rate-limited and filters out bots, so it can't be used to flood the system. The remaining, smaller issue: it writes the site name, visitor's country, and browser string into the same general log file used for errors, rather than a dedicated analytics store — tidier to send it there instead, but not an urgent gap.
    - **Evidence:**
        ```php
        Log::info('rum', [
            'handle' => strtolower($handle),
            'ttfb_ms' => isset($payload['ttfb']) ? (int) $payload['ttfb'] : null,
            'dom_ms' => isset($payload['dom']) ? (int) $payload['dom'] : null,
            'load_ms' => isset($payload['load']) ? (int) $payload['load'] : null,
            'fcp_ms' => isset($payload['fcp']) ? (int) $payload['fcp'] : null,
            'lkg' => isset($payload['lkg']) ? (bool) $payload['lkg'] : false,
            'ua' => substr((string) $request->userAgent(), 0, 256),
            'country' => $request->header('cf-ipcountry'),
        ]);
        ```

- [ ] **#SEC-3** · P2 — Staff bulk-status action logs one line per affected professional to the general log stream
    - **Where:** app/Http/Controllers/Api/Staff/UserSiteManagement/StaffUserController.php:167-173
    - **Affects:** Staff compliance-sweep audit trail — a suspend/reactivate sweep of up to 100 accounts writes 100 `Log::info` lines carrying `user_id` + `new_status` into the general log.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Collapse to one summary log entry (`'count' => count($updated), 'ids' => $updated`) or route through the append-only `audit` schema once the placeholder `#OPS-2` audit-log pipeline lands.
    - **Technical:** The comment `// Audit log per professional (placeholder for #OPS-2 audit log)` acknowledges this is an interim measure. This endpoint is already behind `staff.admin` + fresh-AAL2 (`requiresFreshAal2` gate confirmed at controller lines 135-141) — so it's not reachable by an untrusted actor — but a per-row log write to the general stream (rather than the append-only `audit` schema that's the house pattern for compliance trails) means the durable record of "who suspended what, when" lives somewhere without the audit schema's tamper-evidence guarantees.
    - **Plain English:** When staff suspend or reactivate a batch of accounts, the system writes one log line per account into the same general log used for routine debugging. It would be better as one compact summary entry, or routed into the dedicated, append-only compliance ledger once that pipeline exists — this endpoint is already staff+MFA gated, so this is a record-keeping tidiness issue, not an access-control gap.
    - **Evidence:**
        ```php
        foreach ($updated as $id) {
            Log::info('staff-bulk-status: professional status changed', [
                'action' => 'staff-bulk-status',
                'user_id' => $id,
                'new_status' => $status,
            ]);
        }
        ```

- [ ] **#SEC-4** · P2 — `FreshaScraper::fetchEmployeeServices()` bypasses `SafeUrlFetcher`, using raw `Http::post()` directly
    - **Where:** app/Services/Platforms/FreshaScraper.php:204-211
    - **Affects:** Outbound-fetch defense-in-depth — the one GraphQL call in this class that doesn't route through the shared SSRF guard every sibling method (`fetchLocation`, `fetchMenu`) uses.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Route this POST through `SafeUrlFetcher` (or add a `tryPostJson`-style method to it) instead of raw `Http::post()`, for consistency with the rest of the class.
    - **Technical:** `self::GRAPHQL_URL` is a hardcoded constant (`'https://www.fresha.com/graphql'`) — confirmed no user input reaches this URL today, so this is not an active SSRF vector. The architectural contract, however, is that every outbound HTTP call in this subsystem goes through one guard; this is the sole exception in the file, and a future refactor that parameterizes the endpoint or copies this pattern elsewhere would silently inherit the bypass.
    - **Plain English:** Think of `SafeUrlFetcher` as a single security checkpoint every outbound web request in this part of the app is supposed to pass through. One call in the Fresha integration has its own private side door instead. Today that door only leads to Fresha's own fixed address, so there's no danger yet — but if someone later reuses this pattern with an address that isn't fixed, there'd be no checkpoint to stop it.
    - **Evidence:**
        ```php
        $response = Http::withHeaders([
            'content-type' => 'application/json',
            'x-client-platform' => 'web',
            'x-client-version' => $clientVersion,
            'x-graphql-operation-name' => 'mutation BookingFlow_Initialize_Mutation',
            'origin' => 'https://www.fresha.com',
            'User-Agent' => self::SCRAPE_USER_AGENT,
        ])->timeout(12)->post(self::GRAPHQL_URL, $payload);
        ```

- [ ] **#SEC-5** · P2 — `UploadContentImageRequest` doesn't use the house `SniffsFileMimeType` trait like its sibling upload Form Requests
    - **Where:** app/Http/Requests/Api/User/Content/UploadContentImageRequest.php:16-23
    - **Affects:** `POST /content/uploads` — a file with a mismatched byte-level MIME surfaces as an uncaught-exception 500 instead of a clean 422, and skips the early, friendly validation error the sibling upload paths give.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add the `SniffsFileMimeType` trait (already used by `UploadDesignMediaRequest` and `UploadImageRequest`) and call `assertImageMimeBytes()` from a `withValidator()` hook, for consistency and a clean 422.
    - **Technical:** Verified this is **not** an actual upload-safety gap: `MediaUploadService::upload()` → `ImageVariantService::storeOriginal()`/`loadImage()` unconditionally byte-sniffs via `finfo` (`assertImageMime()`) and rejects any pixel count over `partna.image_max_pixels` *before* GD bitmap allocation, regardless of what the Form Request validated. A mismatched-MIME or oversized-pixel upload today throws `UnprocessableImageException`, which `MediaUploadService::upload()` catches and rewraps as `OriginalStoreFailedException` (line 109-119), which the controller does catch and return as a 500 with the exception's message — so the file IS rejected, just via an awkward code path and status code instead of the friendly 422 the sibling `SniffsFileMimeType`-using Form Requests give. Downgraded from the draft's P1 (which assumed no downstream protection existed) — the real gap is inconsistency + error-code hygiene, not a bypass.
    - **Plain English:** Two other file-upload forms in this app double-check that an uploaded file's actual bytes match what it claims to be, and cap how many pixels an image can have before the system tries to open it. This particular upload form skips that extra check — but the system underneath it (which processes every upload regardless of form) still catches and rejects a bad file; it just comes back as a generic "something broke" error instead of a clear "that's not a valid image" message. Worth making consistent, but nothing slips through today.
    - **Evidence:**
        ```php
        'image' => [
            'required',
            'file',
            'image',
            'mimes:jpeg,png,webp',
            "max:{$imageMaxKb}",
        ],
        ```

- [ ] **#SEC-6** · P2 — Seven state-mutating endpoints validate via inline `$request->validate()` instead of a dedicated Form Request class
    - **Where:** app/Http/Controllers/Api/User/SiteManagement/UserWorkplaceController.php:214-216 (`setPreviousWebsite`); app/Http/Controllers/Api/Platforms/BookingController.php:46 (`detect`); app/Http/Controllers/Api/Platforms/ReservationsController.php:50 (`detect`); app/Http/Controllers/Api/Platforms/OnlineOrderingController.php:57 (`addEntry`); app/Http/Controllers/Api/Platforms/DisplaySettingsController.php:60-63 (`update`); app/Http/Controllers/Api/Platforms/GoogleBusinessController.php:209 (`applySync`); app/Http/Controllers/Api/Platforms/InstagramController.php:165 (`validateUsername`)
    - **Affects:** Every endpoint listed — validation logic lives in the controller rather than a reusable, independently-testable Form Request, and skips `BaseFormRequest`'s shared normalisation hooks.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Extract each inline validation call into a dedicated Form Request class extending `BaseFormRequest`, matching the pattern used everywhere else in the codebase.
    - **Technical:** All seven citations verified verbatim against source. The actual validation rules present at each site are reasonable (bounded `max:` lengths, `Rule::in`/registry checks) — this is a structural-consistency gap (house pattern requires a `FormRequest` class per mutating route), not a demonstrated validation bypass. All same root cause → same tier.
    - **Plain English:** Most forms in this app go through a standard, reusable inspection step before their data is used. These seven spots instead do a quick one-off check written directly in the handling code. The checks themselves are fine today, but they won't automatically pick up future shared improvements (like new sanitisation rules) the way the standard inspection step would.
    - **Evidence:**
        ```php
        // UserWorkplaceController::setPreviousWebsite
        $validated = $request->validate([
            'previous_website' => ['nullable', 'url', 'max:2048'],
        ]);
        ```
        ```php
        // BookingController::detect / ReservationsController::detect / OnlineOrderingController::addEntry
        $validated = $request->validate(['url' => ['required', 'string', 'max:1000']]);
        ```
        ```php
        // DisplaySettingsController::update
        $validated = $request->validate([
            'toggles' => ['required', 'array'],
            'toggles.*' => ['boolean'],
        ]);
        ```
        ```php
        // GoogleBusinessController::applySync
        $platform = $request->validate(['platform' => ['required', 'string', 'max:40', new PlatformInRegistry]])['platform'];
        ```
        ```php
        // InstagramController::validateUsername
        $validated = $request->validate(['username' => ['required', 'string', 'max:200']]);
        ```

- [ ] **#SEC-7** · P2 — Eventbrite host-detection regex's TLD segment isn't anchored, allowing `eventbrite.com.evil.com`-style host confusion
    - **Where:** app/Providers/PlatformRegistryServiceProvider.php:289
    - **Affects:** Smart-detect URL routing — a URL on an attacker-registered domain like `eventbrite.com.evil.com` is misclassified as Eventbrite and handed to the Eventbrite scraper/connect flow instead of being treated as an unrecognised link.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Tighten to an explicit TLD allowlist, e.g. `~(^|\.)eventbrite\.(com|co\.uk|com\.au|ca|ie|nz|de|fr|es|it|nl|at|be|ch|se|no|dk|fi|pt|pl|sg|hk|in|mx|br|ar|cl|pe)$~`.
        - Add a regression test asserting `eventbrite.com.evil.com` is NOT detected as Eventbrite.
    - **Technical:** Confirmed real: `[a-z.]+` is a character class, not TLD-aware — `.` inside `[...]` is literal, so it matches any run of letters and dots after `eventbrite.`, including `com.evil.com`. Downgraded from the draft's P1: every outbound fetch of the resulting URL still passes through `SafeUrlFetcher`'s public-IP/scheme checks regardless of which platform it was misdetected as, so this is a *misclassification* bug (wrong scraper parses the page, likely just failing or misparsing) rather than an authentication/SSRF bypass — it doesn't cross the "ships bad behavior in a documented scenario" bar for P1 since exploiting it requires an attacker to register and control a specific look-alike domain.
    - **Plain English:** The system has a bouncer that decides which specialist scraper handles a pasted link, based on matching its web address. The Eventbrite bouncer's rule is written loosely enough that an address like `eventbrite.com.evil.com` — a trick address designed to look related to Eventbrite — would also pass. It doesn't open any security hole on its own (the deeper safety check that blocks fetching internal/private addresses still applies), but the bouncer's rule should be tightened to a specific list of real Eventbrite web addresses.
    - **Evidence:**
        ```php
        $r->get('eventbrite')->detect(new HostMatch('~(^|\.)eventbrite\.[a-z.]+$~'));
        ```

- [ ] **#SEC-8** · P2 — Five `IntegrationConnection`-adjacent mutation endpoints skip `IntegrationConnectionPolicy` despite the trait already having a correctly-gated equivalent
    - **Where:** app/Http/Controllers/Api/Platforms/BookingController.php:152-160 (`clearBooking`); app/Http/Controllers/Api/Platforms/ReservationsController.php:156-164 (`clearReservations`); app/Http/Controllers/Api/Platforms/DisplaySettingsController.php:82-97 (`update`); app/Http/Controllers/Api/Platforms/RefreshController.php:67-82 (`refresh`); app/Http/Controllers/Api/Platforms/ShopController.php:418-431 (`forget`)
    - **Affects:** Booking/reservations disconnect, display-toggle updates, manual refresh, and full shop disconnect — all mutate `IntegrationConnection` (or its `ShopBrand`/`ShopProduct` children) via a `where('user_id', ...)`/relation-scoped query or an already-tenant-resolved `$connection`, but never call `authorizeForUser` against `IntegrationConnectionPolicy`.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - `BookingController::clearBooking()` / `ReservationsController::clearReservations()`: wrap each `$row->delete()` in `$this->authorizeForUser($user, 'delete', $row)` — mirroring the trait's own `ManagesIntegrationConnection::forgetAllConnections()` (app/Http/Controllers/Api/Platforms/Concerns/ManagesIntegrationConnection.php:376-382), which already does exactly this for its own bulk-delete path.
        - `DisplaySettingsController::update()` / `RefreshController::refresh()`: call `authorizeForUser($user, 'update', $connection)` per row before mutating.
        - `ShopController::forget()`: authorize the parent `IntegrationConnection` before cascading the child `ShopBrand`/`ShopProduct` deletes.
    - **Technical:** Downgraded from the draft's P0/P1 across these five findings — verified every cited query is already tenant-scoped (via `$user->integrationConnections()` relation or an explicit `where('user_id', $user->id)`/`connectionFor($user)` resolution), so there is **no cross-tenant vector today**: a user cannot reach another tenant's rows through any of these code paths. What's real is a Policy-layer consistency gap against the codebase's own established pattern — `ManagesIntegrationConnection::forgetAllConnections()` (used by `GenericPlatformController::forget()`) already loops and calls `authorizeForUser($user, 'delete', $connection)` per row; these five call sites reimplement the same "delete every connection for a family" idea without that call, losing `IntegrationConnectionPolicy::update()`'s `denyIfPendingDeletion()` check (though `EnforcePendingDeletionReadOnly` middleware already blocks non-GET requests globally for pending-deletion accounts, so this is belt-and-suspenders, not the last line of defense).
    - **Plain English:** Several "disconnect this integration" and "refresh this integration" actions currently work correctly because the database query itself only ever looks at your own data — there's no way today to reach someone else's rows through them. But the codebase has an established, correct pattern elsewhere (used when clearing all of a platform's connections) that explicitly double-checks "is this really yours, and are you allowed to touch it right now?" before deleting. These five spots skip that explicit double-check and rely only on the query filter. It's not exploitable today, but it's inconsistent with the app's own rulebook and should be brought in line.
    - **Evidence:**
        ```php
        // BookingController::clearBooking
        private function clearBooking(User $user): void
        {
            foreach ([Platform::Fresha->value, Platform::Square->value] as $providerPlatform) {
                foreach ($user->integrationConnections()->where('platform', $providerPlatform)->get() as $row) {
                    $row->delete();   // soft-delete; observer purges the sitepage cache
                }
            }
            $this->forgetConnection($user);   // the custom 'booking' row, if any
        }
        ```
        ```php
        // ShopController::forget
        $connection = $this->connectionFor($user);
        if ($connection) {
            $brandIds = ShopBrand::where('connection_id', $connection->id)->pluck('id');
            ShopProduct::whereIn('brand_id', $brandIds)->delete();
            ShopBrand::where('connection_id', $connection->id)->delete();
        }
        ```
        ```php
        // The correct, already-existing in-repo pattern (ManagesIntegrationConnection::forgetAllConnections):
        protected function forgetAllConnections(User $user): void
        {
            foreach ($this->connectionsFor($user) as $connection) {
                $this->authorizeForUser($user, 'delete', $connection);
                $connection->delete();
            }
        }
        ```

- [ ] **#SEC-9** · P2 — Six self-scoped controllers mutate or read a user's own data without invoking the registered Policy layer
    - **Where:** app/Http/Controllers/Api/User/SiteManagement/UserSectionBlockController.php (all methods); app/Http/Controllers/Api/User/SiteManagement/UserWorkplaceController.php (all methods); app/Http/Controllers/Api/User/Account/UserSelfController.php:22-31 (`show`); app/Http/Controllers/Api/User/Profile/SectorController.php:19-28 (`update`); app/Http/Controllers/Api/User/Analytics/DevInsightsController.php:67-69 (`index`); app/Http/Controllers/Api/User/Analytics/UserAnalyticsController.php:28-30 (`summary`)
    - **Affects:** Section-block CRUD, workplace read/write/delete, self-profile read, sector self-update, and both analytics-read endpoints. All resolve strictly to the calling user's own site/data via `$this->currentUser($request)`/`$this->currentSite($pro)` — no request-supplied ID selects the resource.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - `Block` (via `UserSectionBlockController`) and `Workplace` (via `UserWorkplaceController`) already have a registered Policy (`Gate::policy(Block::class, SitePolicy::class)` / `Gate::policy(Workplace::class, SitePolicy::class)`, `AppServiceProvider.php:173,200`) — add `$this->authorizeForUser($pro, 'view'|'update', $resource)` calls to actually invoke it.
        - `User` already has `UserSelfPolicy` registered (`AppServiceProvider.php:180`) — add `$this->authorizeForUser($pro, 'view', $pro)` to `UserSelfController::show()` and `SectorController::update()` (its sibling method, `update()` in `UserSelfController`, already does this correctly at line 66 — use it as the template).
        - `DevInsightsController`/`UserAnalyticsController` read raw `analytics.*` tables with no Eloquent model to gate — add a `viewAnalytics` ability on `Site`/`SitePolicy` and call it.
    - **Technical:** Downgraded from the draft's P1 — none of these six take an externally-supplied resource ID; every one resolves the current actor's own site/user via `ResolveCurrentUser`/`ResolveCurrentSite`, so there is no tenant-boundary vector today, and `EnforcePendingDeletionReadOnly` middleware already blocks all non-GET methods globally during pending deletion regardless of Policy invocation. What's real is a doctrine-consistency gap: the codebase's stated rule is `authorizeForUser` on every resource action, and two of these models (`Block`, `Workplace`) already have a Policy registered that these controllers simply never call — meaning `UserSelfPolicy::update()`'s `requiresFreshAal2()` gate (config-flagged, currently off — `config('partna.mfa.require_fresh_aal2_for_profile_update')`) would silently not apply to `SectorController`/`UserWorkplaceController` writes if that flag is ever turned on, per the documented Auth Phase 2/3 rollout. Note: `DevInsightsController` is a real, recently-shipped feature (commit `cc657f81`, "feat(analytics): dev-insights endpoint + accept listen/watch/link impressions (#234)") — the fix here is adding the missing Policy call, not removing or feature-flagging the endpoint as the original draft suggested.
    - **Plain English:** Six screens in the dashboard — your sections, your workplace card, your own profile, your sector, and two analytics views — only ever show or change *your own* data, so there's no way today for one user to reach another's. But the app's rulebook says every action like this should also pass through a named permission check, partly so that if a future rule (like "require a recent second login step for sensitive profile edits") gets turned on, it automatically applies everywhere it should. Right now these six would quietly be skipped by that future rule since they never call the permission check at all.
    - **Evidence:**
        ```php
        // UserSectionBlockController::index — scoped by relation, no authorizeForUser
        $allSectionBlocks = $pro->sectionBlocks()
            ->where('site_id', $site->id)
            ->orderBy('sort_order')
            ->get();
        ```
        ```php
        // UserWorkplaceController::upsert — scoped by site_id, no authorizeForUser
        $workplace = Workplace::firstOrNew(['site_id' => (string) $site->id]);
        $workplace->fill($attributes);
        ```
        ```php
        // UserSelfController::show — no authorizeForUser (contrast with update() at line 66, which has it)
        public function show(UserShowRequest $request)
        {
            $uid = $request->attributes->get('supabase_uid');

            $pro = $this->currentUser($request);

            $cache = app(UserCacheService::class);

            $payload = [
                'professional' => new UserDashboardResource($pro),
        ```
        ```php
        // SectorController::update — no authorizeForUser
        public function update(UpdateSectorRequest $request): JsonResponse
        {
            $user = $this->currentUser($request);
            $sector = $request->validated()['sector']; // null or a valid slug

            // sector_source is not fillable (service-written) — assign directly.
            $changed = $user->sector !== $sector;
            $user->sector = $sector;
            $user->sector_source = 'manual';
            $user->save();
        ```

## P3 — Nice to have

- [ ] **#SEC-10** · P3 — `GoogleBusinessController` uses `forceFill()->saveQuietly()` on columns that are already in `IntegrationConnection::$fillable`
    - **Where:** app/Http/Controllers/Api/Platforms/GoogleBusinessController.php:114-117, 231
    - **Affects:** Google Business connect + sync-apply — no functional difference today, but the pattern needlessly bypasses mass-assignment protection.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `forceFill([...])->saveQuietly()` with `fill([...])->saveQuietly()` at both call sites (keep `saveQuietly()` — it's deliberately avoiding a duplicate observer fire, per the adjacent comment).
    - **Technical:** Verified `place_id`, `apify_status`, and `payload` are **already** present in `IntegrationConnection::$fillable` (app/Models/Core/Site/IntegrationConnection.php:39-54) — correcting the original draft, which recommended *adding* them to `$fillable` as the fix. Since these columns are already allowed, `forceFill()` provides zero additional capability here; it only means that if a future developer ever removed one of these columns from `$fillable` (e.g. to lock it down), this call site would silently keep writing to it anyway, unprotected. Purely a latent-footgun / consistency issue, not a live privilege-escalation path.
    - **Plain English:** Two spots in the Google Business integration write to the database using a "skip all safety checks" method, even though the safety checks would have allowed those exact fields anyway. It's like disabling a smoke detector in a room that isn't on fire — harmless today, but if someone later decides that field should be locked down, this code wouldn't respect it. Simple to switch to the normal, protected write method with no behavior change.
    - **Evidence:**
        ```php
        $row->forceFill([
            'place_id' => $data['placeId'],
            'apify_status' => $enrich ? 'pending' : null,
        ])->saveQuietly();
        ```
        ```php
        $gb->forceFill(['payload' => [...$payload, 'syncFindings' => $findings]])->saveQuietly();
        ```

- [ ] **#SEC-11** · P3 — `ShopController::updateBrand()` still accepts and writes two per-brand fields that were made global and are no longer read
    - **Where:** app/Http/Controllers/Api/Platforms/ShopController.php:192-220
    - **Affects:** Shop brand settings — `selectionMode`/`linkMode` writes here are inert on the read path (per the method's own comment), which is confusing for future maintainers but not a security issue.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Remove `selectionMode`/`linkMode` from the accepted fields in `updateBrand()` and `UpdateShopBrandRequest`, documenting that they're now exclusively set via `/platforms/shop/settings`.
    - **Technical:** The controller's own comment states these fields became a single GLOBAL site setting as of 2026-07-08 and that "the dashboard no longer sends them" — this is confirmed dormant, dead-weight code, not a live bug (the `selectionMode === 'latest'` immediate-sync side effect is explicitly noted as harmless).
    - **Plain English:** Two settings that used to apply per-brand were changed a couple of days ago to apply to the whole shop at once. This endpoint still accepts the old per-brand version and saves it, but nothing reads it back anymore — like a light switch that's been disconnected from the light but still clicks. Not dangerous, just confusing; worth removing.
    - **Evidence:**
        ```php
        if (array_key_exists('selectionMode', $validated)) {
            $updates['selection_mode'] = $validated['selectionMode'];
        }
        if (array_key_exists('linkMode', $validated)) {
            $updates['link_mode'] = $validated['linkMode'];
        }
        ```

## Suggested Bundled Sessions

- **Bundle 1 — Platform-connect input hygiene:** #SEC-7, #SEC-6, #SEC-5
    - **Why grouped:** all three are Form Request / validation-layer consistency work in the platform-connect + content-upload surface; none touch the Policy layer.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.
- **Bundle 2 — Log hygiene sweep:** #SEC-1, #SEC-2, #SEC-3
    - **Why grouped:** identical root cause (unscrubbed/duplicate data in `Log::*` calls) and identical fix pattern across unrelated files.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.
- **Bundle 3 — Platform-services internal cleanup:** #SEC-4, #SEC-10, #SEC-11
    - **Why grouped:** all three are same-subsystem (`app/Services/Platforms` + its controllers) consistency/dead-weight cleanups with no live exploit.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

## Standalone — do NOT bundle

- **#SEC-9 — Six self-scoped controllers missing `authorizeForUser`** · touches the authorization/Policy layer (`Block`/`Workplace`/`User` Policies) — run alone with its own plan + sign-off even though tiered P2.
- **#SEC-8 — Five `IntegrationConnection` mutation endpoints missing `authorizeForUser`** · touches the authorization/Policy layer (`IntegrationConnectionPolicy`) — run alone with its own plan + sign-off even though tiered P2.
