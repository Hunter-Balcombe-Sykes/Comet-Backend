# Architecture & Service Boundaries Audit — 2026-05-24

**Branch:** development
**Lens:** Architecture hygiene, service boundary correctness, dead code post-standalone-strip
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
- `app/Http/Controllers/Api/PublicSite/BootstrapController.php`
- `app/Http/Controllers/Api/Professional/Analytics/ProfessionalAnalyticsController.php`
- `app/Http/Controllers/Api/Professional/Uploads/ProfessionalUploadController.php`
- `app/Http/Controllers/Api/Professional/SiteManagement/ProfessionalGalleryController.php`
- `app/Http/Controllers/Api/Professional/SiteManagement/ProfessionalLinkBlockController.php`
- `app/Services/Professional/ConfirmationPreferenceService.php`
- `app/Services/Professional/SectionVisibilityService.php`
- `app/Services/Accounts/AccountCapabilities.php`
- `app/Services/Accounts/AccountCapabilitySet.php`

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 1 complete
- P2 Medium: 0 of 4 complete
- P3 Low: 0 of 4 complete

---

## P1 — Fix before pilot launch

- [ ] **#ARCH-1** · P1 — Bootstrap returns HTTP 200 `{}` for disabled/suspended accounts instead of 403
    - **Where:** `app/Http/Controllers/Api/PublicSite/BootstrapController.php:114–116`
    - **Affects:** Any professional with `status = 'disabled'`, `'suspended'`, or `'pending_deletion'` who calls the bootstrap endpoint. They receive HTTP 200 with an empty JSON object `{}` instead of the intended 403 error. The frontend is likely unable to detect the error, potentially allowing partially-executed onboarding flows for accounts that should be blocked.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `return $this->error(...)` inside the `DB::transaction` closure with `throw new RuntimeException('ACCOUNT_DISABLED')`.
        - In the outer `catch (RuntimeException $e)` block, add a branch for `'ACCOUNT_DISABLED'` that returns `$this->error('Account is disabled. Contact support.', 403)`.
        - Alternatively, check the account status *before* the transaction opens (line 113 shows it only runs for existing professionals, so this check is safe outside the transaction).
    - **Technical:** `DB::transaction(function () { ... return $this->error(...); })` returns the `JsonResponse` object as the closure's return value, storing it in `$result`. Then `return $this->success($result)` at line 187 calls `response()->json($result)`, which calls `json_encode()` on the `JsonResponse` instance. Because `JsonResponse` does not implement `\JsonSerializable`, `json_encode()` produces `{}`. The HTTP status of the outer call is 200 — the intended 403 is silently discarded. The `EMAIL_ALREADY_REGISTERED` branch correctly uses `throw new RuntimeException(...)` to escape the closure; the disabled-account check should follow the same pattern.
    - **Plain English:** Think of the transaction closure like a box — you can only pass a message out of it by returning a value. When a disabled account hits this code, the controller puts a "403 Forbidden" letter inside the box. But when the box is opened, the calling code doesn't read the letter — it just wraps the entire sealed box in a "200 OK" envelope and sends that to the user. The user gets a blank response with a success status code, and the actual error is lost. Every other error path in this function correctly throws an exception to break out of the box — this one path forgot to.
    - **Evidence:**
        ```php
        // Inside DB::transaction closure (line 114–116)
        if (in_array($professional->status, ['disabled', 'suspended', 'pending_deletion'], true)) {
            return $this->error('Account is disabled. Contact support.', 403);
        }

        // After the transaction (line 187) — $result is the JsonResponse object from above
        return $this->success($result);
        ```

---

## P2 — Should fix

- [ ] **#ARCH-2** · P2 — Analytics `summary()` bundles date parsing, raw SQL, and cache orchestration in a single 350-line controller method
    - **Where:** `app/Http/Controllers/Api/Professional/Analytics/ProfessionalAnalyticsController.php:26`
    - **Affects:** All developers maintaining or extending analytics. The raw SQL (visit counts, link clicks, grouped time series) lives alongside HTTP parameter parsing and cache key construction, making it impossible to unit-test the SQL logic in isolation and expensive to extend (e.g., adding a new metric requires touching a 350-line method).
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Extract an `AnalyticsQueryService` that accepts a `Professional`, date range, and `group_by` value, and returns the raw data arrays.
        - Move cache orchestration (`CacheLockService`) to the service or a dedicated `AnalyticsCacheService`.
        - The controller method becomes: resolve professional → build params → delegate to service → return resource.
    - **Technical:** The method uses `DB::select()` with heredoc SQL strings, date arithmetic, and `CacheLockService::remember()` all inline. Because the controller extends `ApiController` and uses `ResolveCurrentProfessional`, it already has the right shape for a thin delegate — the business logic just never got moved. No correctness gap today, but the method grows with every new analytics dimension and becomes untestable without an HTTP request.
    - **Plain English:** The analytics endpoint is doing three jobs at once: figuring out what dates the user asked for, running the actual database queries, and managing the cache. It's like asking one person to be the waiter, chef, and restaurant manager simultaneously. Works fine now, but as soon as you want to add a new stat (or test that the database query is correct), you have to wade through all three roles mixed together.
    - **Evidence:**
        ```php
        public function summary(Request $request): JsonResponse
        {
            $professional = $this->currentProfessional($request);
            $days = (int) $request->query('days', 30);
            $days = max(1, min(365, $days));
            $groupBy = mb_strtolower(trim((string) $request->query('group_by', 'day')));
            // ... ~300 more lines of date parsing, raw SQL, and cache orchestration
        }
        ```

- [ ] **#ARCH-3** · P2 — Upload controller bundles pool-limit enforcement, R2 streaming, video probing, and job dispatch in a single `upload()` method
    - **Where:** `app/Http/Controllers/Api/Professional/Uploads/ProfessionalUploadController.php`
    - **Affects:** Developers extending the upload pipeline. Adding a new media type (e.g., audio) or changing pool-limit logic requires modifying a method that also owns streaming and job dispatch, inflating blast radius for any change.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Extract a `MediaUploadService` (or extend the existing `MediaService`) that accepts the validated request data, handles R2 streaming, video probing, and dispatches the appropriate job.
        - The controller method becomes: validate → authorize → delegate to service → return resource.
    - **Technical:** The `upload()` method currently calls `$request->file()`, streams to R2, invokes `VideoProbeService` conditionally, checks pool limits against `SiteMedia::query()`, and dispatches `ProcessVideoJob` — all without an intermediate service layer. Pool-limit enforcement in particular belongs in a service so it can be called from other contexts (e.g., a CLI repair command) without going through HTTP.
    - **Plain English:** The upload handler currently acts as the traffic cop, storage worker, video inspector, and job scheduler all at once. If the business rule for how many photos are allowed changes, you'd have to find it buried inside the method that also handles the actual file transfer. Splitting these into separate, focused helpers makes it safe to change one without accidentally breaking another.
    - **Evidence:**
        ```php
        public function upload(MediaUploadRequest $request): JsonResponse
        {
            // pool-limit check via SiteMedia::query()
            // R2 streaming via Storage::disk('r2')
            // video probing via VideoProbeService
            // job dispatch via ProcessVideoJob::dispatch()
        }
        ```

- [ ] **#ARCH-4** · P2 — `ACTION_UNSELECT_PRODUCT` constant and its associated DB rows are dead post-standalone-strip, but the constant ships in API responses
    - **Where:** `app/Services/Professional/ConfirmationPreferenceService.php:15,21`
    - **Affects:** The `getForProfessional()` return value always includes `unselect_product: bool` in the API response. Frontend clients that read this key will be reading and potentially storing a preference for an action that no longer exists. Any future product feature using this key name risks a stale-preference collision.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Remove `ACTION_UNSELECT_PRODUCT` from `SUPPORTED_ACTIONS` and from the `defaultMap()` return shape.
        - Remove the constant itself once no external callers reference it (grep confirms no call sites outside this file).
        - Update the `@return` docblock shape annotation on both `getForProfessional()` and `updateForProfessional()`.
        - Add a Supabase migration to `DELETE FROM core.professional_confirmation_preferences WHERE action_key = 'unselect_product'` to clean up any existing rows.
    - **Technical:** `SUPPORTED_ACTIONS` drives both the DB query (`whereIn('action_key', self::SUPPORTED_ACTIONS)`) and the `defaultMap()` initializer. The constant is included in every `getForProfessional()` response regardless of whether any row exists for it. There are no Shopify product selection flows remaining in the codebase post-strip, so the key is permanently orphaned.
    - **Plain English:** The preferences API is advertising a setting ("skip confirmation when un-selecting a product") for a feature that was removed from the app. It's like a restaurant menu still listing a dish the kitchen stopped making — it wastes space, confuses customers, and creates a mess if someone eventually tries to order it again under a different name.
    - **Evidence:**
        ```php
        public const ACTION_UNSELECT_PRODUCT = 'unselect_product';

        public const SUPPORTED_ACTIONS = [
            self::ACTION_DELETE_CUSTOMER,
            self::ACTION_DELETE_MEDIA,
            self::ACTION_UNSELECT_PRODUCT,  // dead post-strip — no product selection flows remain
        ];
        ```

- [ ] **#ARCH-5** · P2 — Bootstrap controller owns waitlist, email-dedup, site provisioning, notification, and cache invalidation as a single procedural blob
    - **Where:** `app/Http/Controllers/Api/PublicSite/BootstrapController.php:29`
    - **Affects:** Developers maintaining signup/re-bootstrap flows. The `bootstrap()` method is ~200 lines of mixed concerns: waitlist gate, email dedup via raw `whereRaw`, site provisioning, email subscription, cache invalidation, and welcome notification dispatch — all inside a single `DB::transaction` closure.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Extract a `ProfessionalBootstrapService::bootstrap(string $uid, array $data): array` method that owns the transaction, user create/update, site provisioning, and cache invalidation.
        - The controller handles: waitlist gate checks, rate limiting, request validation, calling the service, and HTTP response formatting.
        - `ensureSidestUpdatesSubscription()` and `createWelcomeNotification()` can remain as private controller helpers or move to the service if they need reuse.
    - **Technical:** The `DB::transaction` closure directly instantiates `new User([...])`, calls `Site::query()->where(...)`, invokes `app(ProfessionalCacheService::class)`, and calls `$this->createWelcomeNotification()` — touching four different domain concerns without a service boundary. The ARCH-1 P1 bug (disabled-account error swallowed inside the closure) is a direct consequence of this structural issue: escaping the closure correctly requires understanding that `$this->error()` returns a response object, not a PHP exception.
    - **Plain English:** The signup endpoint is doing too much itself instead of delegating to specialists. It's like a receptionist who also does the paperwork, sets up your office, sends your welcome email, and updates the phone directory — all at the same time, without any separation. When one step goes wrong (like the disabled-account check), the failure gets buried inside all the other work. Breaking these into separate responsibilities makes each piece testable and makes failures obvious.
    - **Evidence:**
        ```php
        $result = DB::transaction(function () use ($uid, $data) {
            $professional = User::query()->where('auth_user_id', $uid)->first();
            // ... email dedup via whereRaw
            $professional = new User([...]); // direct model instantiation
            $site = Site::query()->where('professional_id', $professional->id)->first();
            $site = $this->siteProvisioning->createSiteWithRetry(...);
            app(ProfessionalCacheService::class)->invalidateProfessional($professional);
            $this->createWelcomeNotification($professional);
            return [...];
        });
        ```

---

## P3 — Nice to have

- [ ] **#ARCH-6** · P3 — Gallery `store()` is a permanent 410 stub that should be removed
    - **Where:** `app/Http/Controllers/Api/Professional/SiteManagement/ProfessionalGalleryController.php`
    - **Affects:** Route table cleanliness; any frontend or external client that calls this endpoint and receives a 410 without explanation.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Remove the route registration for `POST /gallery` (or whichever verb maps to `store()`).
        - Remove the `store()` method from the controller.
        - If the route is documented in `docs/api.md`, mark it removed.
    - **Technical:** A 410 stub exists to signal permanent removal to clients already using the route. Once the route is removed from the router, the stub serves no purpose and silently inflates the route table. Dead routes complicate `php artisan route:list` output and can confuse generated API docs.
    - **Plain English:** There's a door in the building that only opens to show a sign saying "this door is permanently closed." Once clients know it's gone, the door itself should be removed — leaving it adds confusion without any benefit.
    - **Evidence:**
        ```php
        public function store(): JsonResponse
        {
            return $this->error('This endpoint has been removed.', 410);
        }
        ```

- [ ] **#ARCH-7** · P3 — `SectionVisibilityService::professionalHasBookingIntegration()` is hardcoded to `return false`
    - **Where:** `app/Services/Professional/SectionVisibilityService.php`
    - **Affects:** Any site section gated on booking integration visibility. Currently all such sections are always hidden, which is correct post-strip. The risk is that a future developer adds a booking integration and doesn't realize this method needs to be updated — the hardcoded `false` will silently suppress sections.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a `// NOTE: booking integrations removed post-standalone-strip (2026-05-22). Update this when booking is re-introduced.` comment on the method.
        - Alternatively, delete the method and inline `false` at every call site, then add a `// TODO(booking-reintegration):` comment at each site.
    - **Technical:** A hardcoded `return false` with no comment creates a silent invariant that future code will violate without a clear error. The comment approach costs one line and prevents a confusing "why are my booking sections invisible?" incident during reintegration.
    - **Plain English:** The app currently has a method that always answers "no, there's no booking integration" — which is correct now, but there's no note explaining why. When someone adds booking back later, they'll spend time debugging why nothing shows up before realising this method exists and always returns no.
    - **Evidence:**
        ```php
        private function professionalHasBookingIntegration(string $professionalId): bool
        {
            return false; // booking integrations removed post-strip
        }
        ```

- [ ] **#ARCH-8** · P3 — `AccountCapabilitySet` carries 15 always-false constructor parameters post-standalone-strip
    - **Where:** `app/Services/Accounts/AccountCapabilitySet.php`
    - **Affects:** Code readability; developer comprehension of what capabilities are actually active. Every `AccountCapabilities::for($professional)` call constructs an 18-parameter object where 15 parameters are always `false`.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Remove all capability parameters that correspond to stripped features (Shopify, Stripe, commerce, bookings, affiliates).
        - Keep only the capabilities that can actually vary at runtime for the individual-standalone configuration.
        - If capability flags will be reintroduced at reintegration time, leave a `// stripped: re-add at reintegration` comment block rather than inert parameters.
    - **Technical:** The `AccountCapabilities::for()` factory passes constants or `false` literals for all commerce/booking/affiliate-related flags. These parameters flow through to `AccountCapabilitySet` properties that are then read by notification dispatchers and route guards. Because they are always `false`, no functional bug exists — but the cognitive overhead of maintaining 18 parameters when 3 are meaningful inflates every future capabilities-related review.
    - **Plain English:** The capabilities object is like an application form with 18 checkboxes, but 15 of them are always unchecked because those features were removed. The form still works, but anyone reading it has to figure out which checkboxes matter and which are just historical artifacts.
    - **Evidence:**
        ```php
        return new AccountCapabilitySet(
            canManageShopify: false,
            canManageProducts: false,
            canManageOrders: false,
            canManageAffiliates: false,
            canManageBookings: false,
            // ... 10 more always-false flags
            canManageSite: true,
            canManageMedia: true,
            canManageAnalytics: $capabilities->analytics_enabled,
        );
        ```

- [ ] **#ARCH-9** · P3 — `buildBlockFields()` is a 60+ line data-shaping method living in a controller
    - **Where:** `app/Http/Controllers/Api/Professional/SiteManagement/ProfessionalLinkBlockController.php`
    - **Affects:** Developers working on link block types. Adding a new block field type requires modifying a controller, which should own only HTTP concerns.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Move `buildBlockFields()` to a `LinkBlockFieldBuilder` class or into the existing block-related service.
        - The controller calls `LinkBlockFieldBuilder::build($block)` and passes the result to the resource.
    - **Technical:** `buildBlockFields()` is a pure data transformation — it takes a block model and returns a structured array. Pure transformations belong either in a Resource class (if they're about API shape) or a Service/Builder (if they involve domain logic). In a controller they're unreachable from tests that don't go through HTTP and they grow every time a new block type is added.
    - **Plain English:** There's a function inside the "handle HTTP requests" layer that's really doing a data-formatting job. It's like having the receptionist maintain the product catalog — they can do it, but it's not their job, and it makes both roles harder to manage.
    - **Evidence:**
        ```php
        private function buildBlockFields(LinkBlock $block): array
        {
            // 60+ lines mapping block->type to field definitions
            return match($block->type) {
                'link' => [...],
                'text' => [...],
                // ...
            };
        }
        ```
