Good — I now have the key verifications I need:

1. **QrCode route** (`routes/web.php`) has only `throttle:public-site` middleware — no auth, no publication gate. IDOR-1 confirmed.
2. **SitePolicy** (lines 72-84) cross-checks `$resource->getAttributes()['site_id']` against the preloaded site's `id` before resolving ownership. The `setRelation` pattern in gallery/document/upload controllers is **architecturally safe** — I was going to flag this as a new finding, but the policy explicitly guards against it. Not a finding.
3. IDOR-2 and IDOR-6 (staff route findings) fall under the always-drop rule — staff middleware already gates, no bypass identified.

`★ Insight ─────────────────────────────────────`
The `SitePolicy::resolveOwnerId` method uses `$resource->getAttributes()['site_id']` (raw attribute, not the relation) and cross-checks it against the injected site's `id`. This is a robust defense: even if a caller passes a wrong site via `setRelation`, the FK cross-check catches the mismatch. This is a clean, non-obvious defense-in-depth pattern worth studying.
`─────────────────────────────────────────────────`

---

# IDOR & Tenant Isolation Audit — 2026-05-31

**Branch:** development
**Lens:** IDOR and tenant isolation, cross-user data access, missing user_id scoping, mass-assignment, unvalidated request params, Form Request coverage gaps, handle/subdomain resolution authz at 10k scale
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
- app/Http/Controllers/Api/PublicSite/QrCodeController.php
- app/Http/Controllers/Api/PublicSite/PublicSiteController.php
- app/Http/Controllers/Api/PublicSite/PublicSignupAvailabilityController.php
- app/Http/Controllers/Api/PublicSite/PublicCustomerLeadController.php
- app/Http/Controllers/Api/PublicSite/PublicEnquiryController.php
- app/Http/Controllers/Api/Staff/StaffSite/StaffNotificationController.php
- app/Http/Controllers/Concerns/ResolvesSubdomainFromHost.php
- app/Policies/SitePolicy.php
- routes/web.php

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 3 complete
- P3 Low: 0 of 2 complete

---

## P2 — Should fix

- [ ] **#IDOR-1** · P2 — QrCodeController generates QR codes for any professional without checking publication or account status
    - **Where:** app/Http/Controllers/Api/PublicSite/QrCodeController.php:16-27  (routes/web.php:6-8)
    - **Affects:** Any user who has obtained a professional's UUID (via handle resolution, public API, or referral links) can generate a working QR code for an unpublished or suspended profile — including one the professional never intended to make public.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Load the professional's associated Site and check `$site->is_published` before generating the QR.
        - If no site exists or the site is not published, `abort(404)`.
        - Optionally also check `$professional->status === 'active'` to mirror the same gate used by `PublicSiteResolver::resolvePublishedSite`.
    - **Technical:** The route (`GET /p/{professionalId}.svg`) carries only `throttle:public-site` middleware — no auth, no publication guard. `QrCodeController::svg` resolves any `User` by primary key and checks only that `$professional->partna_url` is non-null. It does not check `site->is_published` or `professional->status`. Adjudication confirms this via `routes/web.php`: the route has no additional middleware beyond throttle. Compare: every other public-facing data endpoint in this codebase goes through `PublicSiteResolver::resolvePublishedSite`, which requires both `is_published = true` and `status = 'active'` on the associated user. The QR endpoint is the sole gap. UUIDs are unguessable, but they surface through handle resolution (`/api/public/login-identifier`), logged URLs, analytics beacons, and shared QR images — not purely theoretical.
    - **Plain English:** Every professional on the platform gets a QR code that visitors can scan to land on their profile page. The problem is the QR-code generator will print a code for *any* professional — even ones whose pages are hidden or whose accounts have been suspended. It's like a copy shop printing a "Grand Opening" poster for a store that never unlocked its front door. The poster works fine; the door is still locked. But the poster shouldn't exist.
    - **Evidence:**
        ```php
        // routes/web.php
        Route::get('/p/{professionalId}.svg', [QrCodeController::class, 'svg'])
            ->where('professionalId', '[0-9a-fA-F-]{36}')
            ->middleware('throttle:public-site');

        // QrCodeController::svg — no publication check
        public function svg(string $userId, Request $request): Response
        {
            $professional = User::query()
                ->whereKey($userId)
                ->first();

            if (! $professional || ! $professional->partna_url) {
                abort(404);
            }
            // QR code generated unconditionally for any found professional
        ```

- [ ] **#IDOR-2** · P2 — PublicSiteController::showByHeader reads raw X-Site-Subdomain header without length or character validation
    - **Where:** app/Http/Controllers/Api/PublicSite/PublicSiteController.php:52-76
    - **Affects:** Any caller that can set arbitrary HTTP headers — including misconfigured proxies or deliberate abuse — can send a multi-kilobyte or non-alphanumeric string into the cache key and alias-lookup layers, generating unbounded unique cache entries.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Before the `strtolower(trim(...))` call, add a length check (`strlen($subdomain) > 63 → 400`) and a character set check matching the `show()` path's Form Request (`regex:/^[a-z0-9-]+$/i`).
        - Alternatively, extract the validation into a shared private method used by both `show()` and `showByHeader()`.
    - **Technical:** `show()` passes through `PublicSiteShowRequest` which enforces `max:63` and `regex:/^[a-z0-9-]+$/i`. `showByHeader()` accepts a plain `Request` and performs only a null/is-string guard, then passes the value directly into `SiteCacheService::getPublicSitePayload($subdomain)` and into a `SiteSubdomainAlias` raw-query. The Redis cache key is `public.site.payload.{subdomain}`. A stream of random 4 KB strings produces a cache-key explosion with no validation barrier, consuming Redis memory and polluting the LRU eviction pool. At 10k-scale with the Cloudflare Worker calling this endpoint on every cache miss, even a small proportion of garbage headers causes measurable cache churn. The character-set gap also means substrings such as `../../` or null-bytes could pass through to the alias query (though the `whereRaw` parameterization prevents SQL injection — this is a cache-layer correctness issue).
    - **Plain English:** The main door to the public site data has a bouncer who checks that your address is a real street name before letting you through. The side door — used by the Cloudflare Worker's header-based lookup — has no bouncer at all. Someone could walk up to the side door and hand over a 4,000-character nonsense address, and the system would dutifully store "no entry found" for that address in the cache, filling it up with garbage over time. Enough garbage and legitimate visitors start hitting misses they shouldn't.
    - **Evidence:**
        ```php
        // show() — validated path
        public function show(PublicSiteShowRequest $request): Response
        {
            $subdomain = strtolower($request->validated()['subdomain']); // max:63, regex enforced

        // showByHeader() — unvalidated path
        public function showByHeader(Request $request)
        {
            $subdomain = $request->header('X-Site-Subdomain');
            if (! $subdomain || ! is_string($subdomain)) {
                return $this->error('Missing X-Site-Subdomain header.', 400);
            }
            $subdomain = strtolower(trim($subdomain));
            // No regex, no max length — straight to cache/DB
            $payload = $this->siteCache->getPublicSitePayload($subdomain);
        ```

- [ ] **#IDOR-3** · P2 — ResolvesSubdomainFromHost trusts X-Site-Subdomain first, enabling cross-tenant lead and enquiry injection
    - **Where:** app/Http/Controllers/Concerns/ResolvesSubdomainFromHost.php:16-21
    - **Affects:** All public form-submission endpoints that use this trait (`PublicCustomerLeadController::store`, `PublicEnquiryController::submit`, `PublicEmailSubscriptionController::subscribe`). An attacker who makes a direct HTTP request to `api.partna.au` with `X-Site-Subdomain: victim` can inject leads, enquiries, and email subscriptions into any professional's inbox, regardless of which site they claim to be acting on behalf of.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - **Infrastructure fix (preferred):** Configure the Cloudflare Worker to explicitly strip any client-supplied `X-Site-Subdomain` header before forwarding to the backend, then inject the Worker-resolved value. This ensures the header value is always server-authoritative.
        - **Application-layer fallback:** Change `resolveSiteSubdomain()` to prefer host-derived resolution over the header. Accept the header only when the `Host` yields no recognisable subdomain (i.e., the request arrives at `api.partna.au` rather than `<handle>.partna.au`). Log and reject when the header value contradicts the Host.
        - Add a comment in the trait (and the Worker) documenting which side is responsible for stripping.
    - **Technical:** `resolveSiteSubdomain()` checks `X-Site-Subdomain` first and returns immediately if non-empty, with no cross-check against the Host header or route parameter. Cloudflare Workers pass through unknown custom headers by default — unless the Worker explicitly calls `request.headers.delete('X-Site-Subdomain')` before the origin fetch, a client who crafts a direct request to `api.partna.au` can supply any value. The subdomain is public (it's the URL everyone shares), so no secret knowledge is required. The downstream consequence is real data pollution: `PublicCustomerLeadController` creates or updates Customer rows under the targeted professional's account; `PublicEnquiryController` creates `Enquiry` rows with the targeted `user_id`; `PublicEmailSubscriptionController` adds email subscriptions to the targeted professional's marketing list.
    - **Plain English:** When a visitor fills out a contact form on `alice.partna.au`, the Astro frontend attaches a sticky note to the API request saying "this form is from Alice's site." The backend trusts whatever that sticky note says. The problem is nothing stops someone from making a request directly to the API with a sticky note that says "Bob's site" — they could then spam Bob's inbox with fake enquiries or fake leads, all attributed to his customers page, without ever visiting Bob's site at all. The fix is to make the sticky note tamper-evident at the Cloudflare layer.
    - **Evidence:**
        ```php
        protected function resolveSiteSubdomain(Request $request): ?string
        {
            $fromHeader = trim((string) $request->header('X-Site-Subdomain', ''));
            if ($fromHeader !== '') {
                return strtolower($fromHeader);  // trusted unconditionally, no host cross-check
            }
            // ... falls back to query params, then host
        ```

## P3 — Nice to have

- [ ] **#IDOR-4** · P3 — StaffNotificationController::store uses inline validation with no Form Request class and no UUID existence check
    - **Where:** app/Http/Controllers/Api/Staff/StaffSite/StaffNotificationController.php:25-36
    - **Affects:** Staff-created notifications targeted at a non-existent `user_id` are silently accepted and persisted; the notification never surfaces to anyone, producing dead rows and obscuring operator error.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Extract the inline `$request->validate([...])` block into a `StoreStaffNotificationRequest` Form Request class in `app/Http/Requests/Api/Staff/`.
        - Add `Rule::exists('core.users', 'id')` to the `user_id` rule so phantom UUIDs are rejected at the validation layer rather than silently creating orphan DB rows.
        - Reuse the existing `Rule::in([...])` pattern for `category` that other staff Form Requests use.
    - **Technical:** Every other staff write endpoint in the codebase uses a dedicated Form Request (e.g., `StaffInitiateDeletionRequest`, `StaffUpdateCustomerRequest`, `UpdateNotificationEmailPoliciesRequest`). `StaffNotificationController::store` is the sole exception: it uses inline `$request->validate([...])`. The `user_id` rule is `['nullable', 'uuid']` — UUID-format only. A staff operator who makes a typo in a UUID (e.g., copy-paste error on a support ticket) will receive a 201, but the notification exists only as a phantom row. There is no validation error, no log, and no surfacing mechanism. The missing `exists:` check also means the `SendTransactionalNotificationEmailJob` dispatch path can fire for a non-existent `user_id`, causing a quiet job failure.
    - **Plain English:** The tool that lets support staff send a message to a specific professional will accept any made-up ID number. If a staff member miscopies a professional's ID (easy to do under pressure on a support call), the system says "OK, done!" — but the message was sent to nobody. There's no error, no warning. The fix is to make the system check that the professional actually exists before accepting the request, same as every other staff form does.
    - **Evidence:**
        ```php
        public function store(Request $request): JsonResponse
        {
            $data = $request->validate([
                'user_id' => ['nullable', 'uuid'],  // format-only; no exists() check
                'type' => ['required', 'string', 'max:50'],
                'title' => ['required', 'string', 'max:255'],
                // ...
            ]);
            // ...
            $notification = Notification::query()->create([
                ...$data,
                'category' => $data['category'] ?? null,
            ]);
        ```

- [ ] **#IDOR-5** · P3 — PublicSignupAvailabilityController exposes a second email-enumeration channel via the Supabase orphan-recovery check
    - **Where:** app/Http/Controllers/Api/PublicSite/PublicSignupAvailabilityController.php:47-58
    - **Affects:** Unauthenticated visitors can determine whether any email address has a *confirmed Supabase auth account* even when that email has no corresponding Laravel `User` row — covering partially-onboarded users who never completed the bootstrap step.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Apply a per-IP sub-rate-limit specifically on the Supabase admin API call path — separate from the route-level throttle. A simple Redis increment with a short TTL (e.g., 5 attempts per IP per 60 seconds) on the orphan-check branch will prevent automated email-list probing of the Supabase admin API.
        - Log orphan-check hits with a hashed email and IP hash so Nightwatch can alert on anomalous volume (repeated hits from one IP, or a high fraction of requests triggering the branch).
    - **Technical:** The endpoint is by design an availability oracle — it always returns `{email: {available: bool, exists: bool}}`. The route throttle limits volume. The additional Supabase admin call (`$this->supabaseAdmin->findUserByEmail($email)`) is a *second channel* not covered by the throttle, because the throttle is per-route, not per-internal-API-call. An attacker who hits the endpoint exactly at the throttle rate will trigger a Supabase admin API call on every request that misses the Laravel `User` table. Over a large email list, this is indistinguishable from normal traffic at the route level but constitutes systematic probing of the Supabase auth database. The code's own comment acknowledges this: *"Fail-open: Supabase admin API outage shouldn't block all signups."* The orphan path has no dedicated rate limit or anomaly alert.
    - **Plain English:** The signup form's "is this email taken?" check has two steps: first look in the app's own database, then (if not found there) check the identity provider for accounts that started signing up but never finished. The first check has a speed limit; the second check doesn't have its own limit. An automated script that sends one request per second can continuously check whether random email addresses have ever started signing up on the platform, building a list of real email addresses, without triggering the existing speed limit on the overall endpoint.
    - **Evidence:**
        ```php
        if (! $emailExists) {
            try {
                $supabaseUser = $this->supabaseAdmin->findUserByEmail($email);
                if ($supabaseUser !== null && $supabaseUser['email_confirmed_at'] !== null) {
                    $emailExists = true;
                }
            } catch (RuntimeException $e) {
                // Fail-open: Supabase admin API outage shouldn't block all signups.
                Log::warning('Signup availability: Supabase orphan check failed, falling back to Laravel-only', [
                    'error' => $e->getMessage(),
                ]);
            }
        }
        ```
