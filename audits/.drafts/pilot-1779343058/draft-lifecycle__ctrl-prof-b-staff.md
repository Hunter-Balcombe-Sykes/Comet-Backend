- [ ] **LIFE-1** · P1 — `StaffNotificationController::store` creates notifications without an idempotency key, risking duplicates on retry
    - **Where:** app/Http/Controllers/Api/Staff/StaffSite/StaffNotificationController.php:72-80
    - **Affects:** Staff-created notifications (policy updates, incidents, feature announcements). A double-click or network retry creates two identical notification rows + two email dispatches.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a `UNIQUE` constraint on `(professional_id, title, body, created_at::date)` or an explicit `idempotency_key` column with `UNIQUE` + `INSERT … ON CONFLICT DO NOTHING`.
        - Pass a client-generated `idempotency_key` in the request body and include it in the create payload.
    - **Technical:** The canonical replacement is `lockForUpdate + UNIQUE`. Without a unique constraint backing the write, a retried POST creates a duplicate row. The downstream `SendTransactionalNotificationEmailJob` and `SendStaffBroadcastEmailsJob` dispatches also double-fire from the duplicate creation — two emails per retry. At the scale target (~40K daily notifications), even a 1% retry rate means ~400 duplicate notifications/day. The dedup mechanism in `NotificationPublisher` only prevents re-publishing the same dedupe key, not duplicate row creation.
    - **Plain English:** Imagine a staff member clicks "Send Announcement" and the browser hangs. They click again. The system creates two identical announcements and sends two emails to every recipient. The fix is to stamp each announcement with a unique "receipt number" the database can use to recognize and skip duplicates.
    - **Evidence:**
        ```php
        $notification = Notification::query()->create([
            ...$data,
            'category' => $data['category'] ?? null,
        ]);

        $sendEmail = (bool) ($data['send_email'] ?? false);
        $emailListKey = $data['email_list_key'] ?? 'sidest_updates';

        if ($sendEmail) {
            if ($notification->professional_id !== null && $notification->category !== null) {
                SendTransactionalNotificationEmailJob::dispatch(
                    $notification->id,
                    $notification->category,
                    $notification->professional_id,
                );
            } elseif ($notification->professional_id === null) {
                SendStaffBroadcastEmailsJob::dispatch($notification->id, $emailListKey);
            }
        }
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **LIFE-2** · P1 — `SquareIntegrationController::syncServicesNow` and `pushServiceNow` make synchronous vendor API calls in web request handlers, blocking user-facing p99
    - **Where:** app/Http/Controllers/Api/Professional/SquareIntegration/SquareIntegrationController.php:288-309 and :316-339
    - **Affects:** Brands triggering manual Square sync or service push from the dashboard. At 200 brands × occasional manual sync, Square API latency (200–800ms) propagates directly to the dashboard response time.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Return 202 Accepted immediately and dispatch an async job (`SyncSquareCatalogDeltaJob` or a new `PushServiceToSquareJob`).
        - Let the frontend poll for completion or surface the result via a notification when the job finishes.
    - **Technical:** These endpoints are intentionally synchronous per the docblock ("This endpoint is used by the manual refresh button and must work without queue workers"). However, this design choice means every brand pressing "Sync Now" holds a PHP-FPM worker + Postgres connection for the duration of a Square REST API round-trip. At the scale target with 200 brands, concurrent sync requests during a Square outage or degradation will exhaust the FPM pool. The canonical pattern from the Stripe payout work is to never make synchronous vendor calls in web request handlers — vendor latency must not propagate to user-facing p99.
    - **Plain English:** When a brand clicks "Sync Now," the server personally walks over to Square's servers, waits for an answer, and only then responds to the brand's browser. If Square is slow, the brand's dashboard freezes. If several brands click at once, the whole dashboard slows down. The fix is to hand the task to a background worker and tell the browser "we're on it — check back in a moment."
    - **Evidence:**
        ```php
        // SquareIntegrationController::syncServicesNow
        try {
            $stats = $syncService->syncFromSquare($pro, fullSync: true);
        } catch (SquareApiException $e) {
            // ...
        }

        // SquareIntegrationController::pushServiceNow
        try {
            $syncService->pushServiceToSquare($service, 'upsert');
        } catch (\Throwable $e) {
            // ...
        }
        ```
    - `[DRAFT, confidence: 0.80]`

- [ ] **LIFE-3** · P1 — `FreshaIntegrationController::syncServicesNow` and `pushServiceNow` mirror the same synchronous vendor call anti-pattern
    - **Where:** app/Http/Controllers/Api/Professional/FreshaIntegration/FreshaIntegrationController.php:249-266 and :272-297
    - **Affects:** Same as LIFE-2 but for Fresha-connected brands.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Same fix as LIFE-2: return 202 and dispatch an async job.
    - **Technical:** Identical pattern to `SquareIntegrationController` — `syncFromFresha` and `pushServiceToFresha` are called synchronously in the request thread. The Fresha integration is flagged as "scaffolded-and-unverified" in project memory, which means this path is unlikely to be exercised at scale today, but the pattern must be corrected before the integration goes live to avoid the same FPM-pool exhaustion risk.
    - **Plain English:** Same problem as the Square sync — the dashboard waits for Fresha's servers to respond before showing anything to the brand. Fix it the same way: hand off to a background worker.
    - **Evidence:**
        ```php
        // FreshaIntegrationController::syncServicesNow
        try {
            $stats = $syncService->syncFromFresha($pro, fullSync: true);
        } catch (FreshaApiException $e) {
            [$message, $status] = $this->buildFreshaErrorMessage($e);
            return $this->error($message, $status);
        }

        // FreshaIntegrationController::pushServiceNow
        try {
            $syncService->pushServiceToFresha($service, 'upsert');
        } catch (\Throwable $e) {
            // ...
        }
        ```
    - `[DRAFT, confidence: 0.80]`

- [ ] **LIFE-4** · P2 — Inline `abort_if($request->attributes->get('partna_staff') === null, 401, 'Unauthenticated')` repeated in 7 staff controller methods instead of using middleware
    - **Where:** app/Http/Controllers/Api/Staff/FeatureFlag/StaffFeatureFlagController.php:23,32,42,52 and app/Http/Controllers/Api/Staff/FeatureFlag/StaffFeatureFlagOverrideController.php:21,37,82
    - **Affects:** All staff-admin endpoints. Every method repeats the same staff-existence check inline. If a new staff controller is added without this check, the endpoint is silently open.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Extract the check into a `staff.auth` middleware or add it to the existing `staff.admin` middleware group.
        - Apply it once in `routes/api/staff.php` via a route group.
    - **Technical:** This is the authorization equivalent of inline validation — the same gate repeated in every method, with no central enforcement. The canonical replacement is `Policy + Form Request` for authorization; here the equivalent is middleware. A missing check on a new controller method means the endpoint accepts requests without a staff actor, leading to NPEs downstream or, worse, silent authorization bypass.
    - **Plain English:** Every staff-only door has its own keypad with the same code. If someone adds a new door and forgets to install the keypad, the door is unlocked. The fix is to put one lock on the hallway entrance instead of one on every door.
    - **Evidence:**
        ```php
        // StaffFeatureFlagController — repeated 4 times:
        abort_if($request->attributes->get('partna_staff') === null, 401, 'Unauthenticated');

        // StaffFeatureFlagOverrideController — repeated 3 times:
        abort_if($request->attributes->get('partna_staff') === null, 401, 'Unauthenticated');
        ```
    - `[DRAFT, confidence: 0.90]`

- [ ] **LIFE-5** · P2 — `StaffShopifyEventReplayController::invoke` dispatches synchronous Shopify API fetch + job processing in a web request handler
    - **Where:** app/Http/Controllers/Api/Staff/ProfessionalSiteManagement/StaffShopifyEventReplayController.php:127-157
    - **Affects:** Staff replaying Shopify order webhooks. A Shopify REST API call + `ProcessShopifyOrderWebhookJob::dispatchSync` both run on the request thread.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Fetch the order payload inside a queued job rather than the controller, or accept that the staff replay endpoint is low-traffic and the synchronous design is intentional for debuggability (the comment says "dispatchSync so any failure surfaces in the staff response").
        - If keeping synchronous, add explicit timeout handling and a prominent note that this endpoint blocks a worker.
    - **Technical:** The controller calls `$this->shopifyClient->rest(...)` synchronously, then `ProcessShopifyOrderWebhookJob::dispatchSync(...)`. Each of these is a 200–1000ms operation. The `dispatchSync` design is intentional per the comment, but the Shopify REST fetch before it doubles the blocking window. At the scale target, this endpoint is staff-only and low-traffic, so it's unlikely to cause FPM exhaustion — but it's the same anti-pattern shape as LIFE-2/LIFE-3 and should at minimum be documented.
    - **Plain English:** A staff tool that re-fetches an order from Shopify runs both the fetch and the processing while the staff member waits. For an occasional support tool this is fine, but it's the same "wait for Shopify" pattern that causes problems at higher traffic.
    - **Evidence:**
        ```php
        // StaffShopifyEventReplayController::invoke
        $response = $this->shopifyClient->rest(
            method: 'GET',
            shop: $shop,
            accessToken: (string) $integration->access_token,
            path: $path,
        );
        // ... then ...
        ProcessShopifyOrderWebhookJob::dispatchSync(
            brandProfessionalId: (string) $professional->id,
            orderPayload: $orderPayload,
            shopifyEventId: $shopifyEventId,
            source: 'manual',
        );
        ```
    - `[DRAFT, confidence: 0.70]`

- [ ] **LIFE-6** · P2 — `SquareIntegrationController::buildSquareErrorMessage` and `FreshaIntegrationController::buildFreshaErrorMessage` use `str_contains` on vendor error strings to decide reconnect advice — fragile and version-unstable
    - **Where:** app/Http/Controllers/Api/Professional/SquareIntegration/SquareIntegrationController.php:85-98 and app/Http/Controllers/Api/Professional/FreshaIntegration/FreshaIntegrationController.php:83-96
    - **Affects:** Error messages shown to brands when Square/Fresha sync fails. A vendor API change to the error message format silently breaks the reconnect-advice heuristic.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `str_contains` on the error message with typed exception handling: catch `SquareApiException` subtypes (or check HTTP status codes) rather than parsing the message body.
        - If the vendor SDK doesn't expose typed exceptions, at minimum pin the string match to a known set and log a warning when the error message doesn't match any known pattern.
    - **Technical:** This is the same anti-pattern as `catch (QueryException $e) + str_contains($e->getMessage(), 'UNIQUE')` — string-matching on vendor error output is fragile across API version bumps and localization changes. The canonical replacement is `UniqueConstraintViolationException` (typed catch). Here, the equivalent would be checking `$e->status` (HTTP status code) for 401/403 rather than searching the message body for 'unauthorized'.
    - **Plain English:** The code reads Square's error messages like a human scanning for keywords — "does this say 'unauthorized'?" If Square ever rewords their error messages, the reconnect suggestion silently disappears. Better to check the error code number, which is stable.
    - **Evidence:**
        ```php
        // Square:
        $shouldSuggestReconnect =
            str_contains($lower, 'resource not found') ||
            str_contains($lower, 'unauthorized') ||
            str_contains($lower, 'access token') ||
            str_contains($lower, 'merchant');

        // Fresha:
        $shouldSuggestReconnect =
            str_contains($lower, 'resource not found') ||
            str_contains($lower, 'unauthorized') ||
            str_contains($lower, 'access token') ||
            str_contains($lower, 'business');
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **LIFE-7** · P2 — `AffiliateOrdersController::parseStatusFilter` uses inline `abort_unless()` for request validation instead of a Form Request class
    - **Where:** app/Http/Controllers/Api/Professional/Affiliate/AffiliateOrdersController.php:123-127
    - **Affects:** Affiliate order list endpoint — invalid `?status=` values get a raw 422 without structured validation errors matching the project's API envelope.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Extract the `status` query parameter validation into a dedicated Form Request class (e.g., `ListAffiliateOrdersRequest`) with a `rules()` method.
    - **Technical:** The canonical replacement is `Policy + Form Request`. Inline `abort_unless` for validation bypasses Laravel's validation pipeline — no automatic 422 envelope, no validation error structure consistent with the rest of the API. The project already has ~40 Form Request classes; this is an outlier.
    - **Plain English:** This endpoint hand-validates one of its parameters in the controller method instead of using the project's standard "validation gate" pattern. It works, but it's inconsistent with every other endpoint and harder to test.
    - **Evidence:**
        ```php
        private function parseStatusFilter(Request $request): ?string
        {
            $status = $request->query('status');
            if ($status === null || $status === '') {
                return null;
            }
            abort_unless(in_array($status, ['pending', 'processing', 'paid', 'reversed'], true), 422, 'Invalid status filter.');

            return (string) $status;
        }
        ```
    - `[DRAFT, confidence: 0.90]`

- [ ] **LIFE-8** · P2 — `StaffInviteController::assertBrandWithFunding` is an inline role+funding gate repeated before 4 controller methods instead of being a middleware or Policy
    - **Where:** app/Http/Controllers/Api/Staff/ProfessionalSiteManagement/StaffInviteController.php:228-249 (used by `store`, `bulk`, `importCsv`, `resend`)
    - **Affects:** 4 staff invite write endpoints. If a new write endpoint is added without calling `assertBrandWithFunding`, the brand-funding gate is bypassed.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Extract into a route middleware (e.g., `staff.invite.brand_with_funding`) and apply it to the route group for staff invite writes.
    - **Technical:** Same shape as LIFE-4 — a gate repeated per-method instead of enforced at the route layer. The canonical replacement is middleware for route-level enforcement. The check verifies (a) the route-bound professional is a brand and (b) the brand has a payment method. Both conditions are stateless and belong in middleware.
    - **Plain English:** Four endpoints each independently check "is this a brand with a payment method?" If someone adds a fifth endpoint and forgets to copy the check, staff could send invites for a brand that hasn't added a payment method — circumventing the funding safety net.
    - **Evidence:**
        ```php
        // Called at the top of store(), bulk(), importCsv(), resend():
        if ($error = $this->assertBrandWithFunding($professional)) {
            return $error;
        }

        private function assertBrandWithFunding(Professional $professional): ?JsonResponse
        {
            if (mb_strtolower(trim((string) $professional->professional_type)) !== 'brand') {
                return $this->error('This professional is not a brand account.', 422);
            }

            if (! app(StripeConnectService::class)->brandHasPaymentMethod($professional)) {
                return response()->json([...], 402);
            }

            return null;
        }
        ```
    - `[DRAFT, confidence: 0.90]`

- [ ] **LIFE-9** · P3 — `ProfessionalUploadController` log context uses `'pro_id'` instead of the canonical `'professional_id'` — breaks Nightwatch correlation
    - **Where:** app/Http/Controllers/Api/Professional/Uploads/ProfessionalUploadController.php:81-87
    - **Affects:** Observability — Nightwatch cannot correlate media upload logs with the same professional's Stripe/webhook/notification logs because the key name differs.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Rename `'pro_id'` to `'professional_id'` in all log calls within this controller (at least the `upload` method and `dispatchVideoJob` catch block).
        - Sweep the codebase for other `'pro_id'` log keys and canonicalize to `'professional_id'`.
    - **Technical:** The canonical replacement is `Log-with-context`. Nightwatch (and any log aggregator) correlates log entries by field name. Using `pro_id` in some log calls and `professional_id` in others means a professional's activity is fragmented across two disjoint log streams. At the scale target with 10K+ daily job invocations, this makes incident triage materially slower.
    - **Plain English:** Some log entries tag the user as `pro_id` and others as `professional_id`. It's like filing half your receipts under "Office Supplies" and half under "Stationery" — they're the same thing, but searching for one misses the other.
    - **Evidence:**
        ```php
        Log::info('Media upload started', [
            'pro_id' => $pro->id,
            'site_id' => $site->id,
            'pool' => $pool,
            'media_type' => $mediaType,
            'file_size_kb' => $file->getSize() / 1024,
        ]);
        ```
    - `[DRAFT, confidence: 0.95]`

- [ ] **LIFE-10** · P3 — Multiple staff controllers use inline `$request->validate([...])` instead of Form Request classes
    - **Where:** app/Http/Controllers/Api/Staff/ProfessionalSiteManagement/StaffBrandAffiliateLinkController.php:36-38, :82-85; StaffCommissionVoidController.php:57-59; StaffInviteController.php:75-81, :112-115, :132-135; StaffStoreSettingsController.php:24-27, :57; StaffProfessionalController.php:109-111, :126-128 (and ~10+ more across the staff controller surface)
    - **Affects:** API contract consistency. Validation errors from these endpoints use Laravel's default 422 format rather than the project's structured error envelope (`$this->error(...)`).
    - **Effort:** L (~1–2d) — systematic refactor across ~15–20 endpoints.
    - **What to do:**
        - Create a Form Request class for each endpoint that currently uses inline `$request->validate(...)`.
        - This is a P3 polish item, not a correctness issue — the endpoints function correctly; the inconsistency is a developer-experience and API-contract concern.
    - **Technical:** The canonical replacement is `Policy + Form Request` (the `a11feb2` refactor pattern). Inline `validate()` bypasses the project's `ApiController::error()` envelope — validation failures render as Laravel's default JSON 422 response instead of the `{ "error": "...", "code": 422, ... }` shape used by every Form-Request-gated endpoint. At the scale target with 200 brands, inconsistent error shapes make frontend error-handling brittle.
    - **Plain English:** Some endpoints use the project's standard "validation checkpoint" (Form Requests) and return errors in a consistent format. Others hand-validate and return errors in Laravel's default format. The frontend team has to handle both shapes. Standardizing on Form Requests makes every endpoint behave the same way.
    - **Evidence:**
        ```php
        // StaffBrandAffiliateLinkController::store
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ]);

        // StaffCommissionVoidController::void
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
        ]);

        // StaffStoreSettingsController::update
        $data = $request->validate([
            'default_commission_rate' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'payout_hold_days' => ['sometimes', 'integer', 'in:0,7,14,28'],
        ]);
        ```
    - `[DRAFT, confidence: 0.90]`

- [ ] **LIFE-11** · P3 — `StaffLinkBlockManagementController::update` and `destroy` use inline `abort_unless` for ownership verification instead of a Policy
    - **Where:** app/Http/Controllers/Api/Staff/ProfessionalSiteManagement/StaffLinkBlockManagementController.php:59-64, :69-75
    - **Affects:** Staff link-block management. Functional today but inconsistent with the project's authorization doctrine.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Either add a `LinkBlockPolicy` with `update`/`delete` abilities and call `$this->authorizeForUser($professional, 'delete', $linkBlock)`, or accept that staff controllers operate under a different authorization model (staff-is-God) and document the exception.
    - **Technical:** The canonical replacement is `Policy + Form Request`. The project doctrine states "Authorization through Policies, never inline." The `abort_unless` check here is correct in behavior (blocks cross-professional access) but bypasses the Policy system, making authorization invisible to `Gate::before` hooks, audit tooling, and policy-level testing.
    - **Plain English:** This door has a working lock, but it's a different brand of lock from every other door in the building. It works, but a security audit has to check it separately.
    - **Evidence:**
        ```php
        // StaffLinkBlockManagementController::update
        abort_unless(
            $linkBlock->professional_id === $professional->id &&
            $linkBlock->block_group === 'links' &&
            $linkBlock->block_type === 'link',
            404
        );

        // StaffLinkBlockManagementController::destroy
        abort_unless(
            $linkBlock->professional_id === $professional->id &&
            $linkBlock->block_group === 'links' &&
            $linkBlock->block_type === 'link',
            404
        );
        ```
    - `[DRAFT, confidence: 0.80]`

- [ ] **LIFE-12** · P3 — `StaffNotificationController::store` passes `$data['severity']` through `Notification::severityForFrontendType()` which can return `null`, then inserts it without a schema-level CHECK constraint on the `severity` column
    - **Where:** app/Http/Controllers/Api/Staff/StaffSite/StaffNotificationController.php:50-51
    - **Affects:** Schema correctness — a null severity slips through to the database, making notification-severity filtering unreliable.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a `CHECK` constraint on `notifications.severity` (e.g., `CHECK (severity IN ('info', 'warning', 'critical'))`) per the `64db1f2` pattern.
        - Or coerce `null` to a default (`'info'`) in the controller before create.
    - **Technical:** The canonical replacement is the `64db1f2` pattern for `orders.rate_source` — VARCHAR-backed enums need `CHECK` constraints to prevent invalid values at the database level. The `Notification::severityForFrontendType()` method can return `null` when called with an unrecognized type, and `null` passes through to the insert without rejection.
    - **Plain English:** The notification severity field can end up empty in the database because there's no rule at the database level saying "this must be one of info, warning, or critical." An application bug that sends a weird type results in a silently-empty severity.
    - **Evidence:**
        ```php
        $data['type'] = Notification::normalizeFrontendType($data['type'] ?? null, $data['severity'] ?? null);
        $data['severity'] = Notification::severityForFrontendType($data['type']);

        // ... later:
        $notification = Notification::query()->create([
            ...$data,
            'category' => $data['category'] ?? null,
        ]);
        ```
    - `[DRAFT, confidence: 0.75]`
