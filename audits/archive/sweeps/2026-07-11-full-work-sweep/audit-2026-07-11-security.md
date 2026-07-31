# Security Audit — 2026-07-11

**Branch:** HEAD
**Lens:** Security: auth boundaries, tenant isolation, mass assignment, inbound callbacks, secrets, injection, SSRF, upload safety, PII exposure
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4.5`
**Source files audited:**
- app/Http/Middleware/Auth/VerifySupabaseJwt.php
- app/Http/Middleware/AddPublicCacheHeaders.php
- app/Http/Middleware/VerifyBotToken.php
- app/Http/Middleware/Auth/VerifySupabaseHookSignature.php
- app/Http/Middleware/Auth/RequireAal2.php
- app/Http/Middleware/IdempotencyKey.php
- app/Http/Middleware/Context/EnforcePendingDeletionReadOnly.php
- app/Http/Controllers/Concerns/DetectsClientInfo.php
- app/Http/Controllers/Api/User/SiteManagement/UserSectionBlockController.php
- app/Http/Controllers/Api/User/SiteManagement/UserWorkplaceController.php
- app/Http/Controllers/Api/User/Profile/SectorController.php
- app/Http/Controllers/Api/User/Analytics/UserAnalyticsController.php
- app/Http/Controllers/Api/User/Analytics/DevInsightsController.php
- app/Http/Controllers/Api/User/Account/UserSelfController.php
- app/Http/Requests/Api/User/Content/UploadContentImageRequest.php
- app/Http/Requests/Concerns/SniffsFileMimeType.php
- app/Http/Requests/Api/PublicSite/Analytics/ItemSeenRequest.php
- app/Http/Requests/Api/PublicSite/Analytics/SectionDwellRequest.php
- app/Http/Requests/Platforms/UpdateShopBrandRequest.php
- app/Http/Controllers/Api/PublicSite/AnalyticsController.php
- app/Http/Controllers/Api/PublicSite/BootstrapController.php
- app/Http/Controllers/Api/PublicSite/PublicEarlyAccessController.php
- app/Http/Controllers/Api/Platforms/BookingController.php
- app/Http/Controllers/Api/Platforms/ReservationsController.php
- app/Http/Controllers/Api/Platforms/OnlineOrderingController.php
- app/Http/Controllers/Api/Platforms/DisplaySettingsController.php
- app/Http/Controllers/Api/Platforms/ShopController.php
- app/Jobs/Platforms/EnrichLinkCardJob.php
- app/Services/Platforms/LinkCardScraper.php
- app/Services/Platforms/GenericShopScraper.php
- app/Services/Platforms/ShopProviderDetector.php
- app/Services/Webhooks/StandardWebhookVerifier.php
- app/Providers/AppServiceProvider.php
- config/cors.php
- routes/api/user.php

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 1 complete
- P2 Medium: 0 of 4 complete
- P3 Low: 0 of 1 complete

---

## P1 — Fix before pilot launch

- [ ] **#SEC-1** · P1 — Content-library image upload trusts client-declared MIME instead of byte-sniffing
    - **Where:** app/Http/Requests/Api/User/Content/UploadContentImageRequest.php:16-23
    - **Affects:** Content library image uploads (`POST` to the content-image endpoint) — a disguised file (e.g. a script renamed `.png`) can pass validation.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `use SniffsFileMimeType;` to `UploadContentImageRequest` and call `$this->assertImageMimeBytes($this->file('image'), $v, 'image')` from a `withValidator()` hook, matching `UploadImageRequest` and `UploadDesignMediaRequest` exactly.
        - No pixel-bomb guard needs adding here — `ImageVariantService::loadImage()` (used downstream by `ContentController::storeUpload()`) already enforces `partna.image_max_pixels` via a header-only `getimagesize()` before any GD decode, so that protection already applies to this path once the file reaches variant processing.
    - **Technical:** The rules array uses only Laravel's `'image'` + `'mimes:jpeg,png,webp'`, both of which trust client-supplied metadata (extension / declared Content-Type) rather than the file's actual bytes. The two sibling upload Form Requests (`UploadImageRequest`, `UploadDesignMediaRequest`) both additionally apply `SniffsFileMimeType::assertImageMimeBytes()`, an `finfo`-based magic-byte check — this file is the one upload path in the codebase that skips it, a genuine deviation from the house pattern documented in the lens's category 6.
    - **Plain English:** When someone uploads a photo to their content library, the system currently checks the file's label (like "this is a .png") but not what's actually inside the file — like accepting a package because the shipping label says "books" without opening it. Every other upload spot in the app double-checks the actual contents; this one form forgot to add that extra check.
    - **Evidence:**
        ```php
        public function rules(): array
        {
            $imageMaxKb = (int) config('partna.image_max_upload_size', 10240);

            return [
                'image' => [
                    'required',
                    'file',
                    'image',
                    'mimes:jpeg,png,webp',
                    "max:{$imageMaxKb}",
                ],
                'alt_text' => ['sometimes', 'nullable', 'string', 'max:255'],
                'caption' => ['sometimes', 'nullable', 'string', 'max:200'],
            ];
        }
        ```

## P2 — Should fix

- [ ] **#SEC-2** · P2 — JWT revocation gate skipped when a token carries no `session_id` claim
    - **Where:** app/Http/Middleware/Auth/VerifySupabaseJwt.php:86-96 (and the mirrored fallback path at :180-189)
    - **Affects:** "Sign out everywhere" / admin-forced-logout correctness — a cryptographically valid but un-revocable token, if one were ever issued without `session_id`, would keep working until natural expiry.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Confirm with the Supabase project config that every standard user access token carries `session_id` (the code comment already flags this as a "before hardening to a 401" TODO).
        - Once confirmed, replace the `Log::warning` branch with an early 401 rejection so a session-id-less token can never silently bypass the revocation check.
    - **Technical:** Both the JWKS-verified path and the Auth-Server-fallback path skip `TokenRevocationService::isRevoked()` entirely when `session_id` is absent from claims, logging a warning instead of rejecting. This is real but narrow: it requires Supabase itself to omit the claim on a standard token, which the surrounding code comments indicate has not been observed — this is a pre-emptive hardening gap, not an active exploit path (no attacker-controlled input forces this condition today). Fits the "hardening / defense-in-depth" P2 anchor rather than a live P0/P1 bypass.
    - **Plain English:** Every login session gets a serial number so we can cancel it individually if you sign out everywhere or an admin force-logs you out. If a token somehow arrived without that serial number, we currently just write a note in the log instead of refusing it — meaning that one token couldn't be cancelled early. This hasn't been seen happening, but the code should refuse rather than merely note it, pre-launch.
    - **Evidence:**
        ```php
        $sessionId = isset($claims['session_id']) ? (string) $claims['session_id'] : '';
        if ($sessionId === '') {
            // Revocation gate is skipped for tokens that carry no session_id.
            // Log so we can confirm this case never legitimately fires before
            // hardening to a 401 rejection.
            Log::warning('jwt.missing_session_id', [
                'request_id' => $requestId,
                'operation' => __METHOD__,
                'uid' => $uid,
            ]);
        }
        ```

- [ ] **#SEC-3** · P2 — Several user-mutation endpoints skip the explicit `authorizeForUser` Policy gate
    - **Where:** app/Http/Controllers/Api/User/SiteManagement/UserSectionBlockController.php:112-281 (`upsert`, `reorder`, `remove`); app/Http/Controllers/Api/User/SiteManagement/UserWorkplaceController.php:42-230 (`upsert`, `destroy`, `setPreviousWebsite`); app/Http/Controllers/Api/User/Profile/SectorController.php:19-41 (`update`)
    - **Affects:** Section-block, workplace-card, and sector mutation endpoints — no explicit Policy check at the controller call site, relying entirely on structural scoping.
    - **Effort:** M (~2-4h)
    - **What to do:**
        - Add `$this->authorizeForUser($pro, 'update', $site)` (or the equivalent ability on the owning model) as the first line of each mutation method, mirroring `UserSelfController::update()` and `UserSiteController::update()`.
        - No new Policy classes are needed — `Block`, `Workplace`, and `User` already have registered policies (`SitePolicy`, `SitePolicy`, `UserSelfPolicy` respectively, per `AppServiceProvider::boot()`); this is purely a missing call-site.
    - **Technical:** All three controllers resolve `$site`/`$user` exclusively through the authenticated actor's own 1:1 relationships (`ResolveCurrentSite`/`ResolveCurrentUser`) and scope every query by `user_id`/`site_id`, so there is no live cross-tenant IDOR here — a caller cannot reach another tenant's row through these endpoints regardless of the missing Policy call. Verified against `routes/api/user.php:41`: every one of these routes sits inside `Route::middleware(['user.api', EnforcePendingDeletionReadOnly::class, 'throttle:authenticated'])`, so the pending-deletion 423 gate DeepSeek's draft cited as the missing protection is already enforced at the HTTP layer for all of them — the middleware, not a per-controller Policy call, is what currently blocks a pending-deletion account from mutating these resources. The residual gap is pure doctrine-consistency: rule #2 of the Authorization Doctrine requires an explicit `authorizeForUser` call on every mutation, and its absence here means a future ability added to `SitePolicy`/`UserSelfPolicy` (e.g. an AAL2-gated action) would have no enforcement point on these three controllers. Downgraded from DeepSeek's P0 (three separate drafts, inconsistently tiered at P0/P0/P1) to a single consistent P2, per the "no live exploit path today" calibration anchor — this was three findings for one root cause and is merged here.
    - **Plain English:** Every action a professional takes on their own dashboard should pass through a security checkpoint that double-checks "is this really your data, and is your account in good standing?" For three edit screens (page sections, workplace details, industry picker), the checkpoint is skipped — but a separate, already-in-place safety net (a site-wide rule blocking writes for accounts mid-deletion) still catches the one real-world risk this would have created. This is a "add the missing checkpoint anyway" fix for consistency and future-proofing, not an active leak today.
    - **Evidence:**
        ```php
        // UserSectionBlockController::upsert() — resolves user + site, then proceeds
        // to mutate without any Policy gate.
        public function upsert(UpsertSectionBlockRequest $request, string $blockType)
        {
            $pro = $this->currentUser($request);
            $site = $this->currentSite($pro);
            $data = $request->validated();
            // ... no authorizeForUser call — proceeds directly to mutation ...
        }
        ```
        ```php
        // UserWorkplaceController::upsert() — same pattern.
        public function upsert(UpsertWorkplaceRequest $request): JsonResponse
        {
            $professional = $this->currentUser($request);
            $site = $this->currentSite($professional);
            $data = $request->validated();
            // ...
            $workplace = Workplace::firstOrNew(['site_id' => (string) $site->id]);
            $workplace->fill($attributes);
            // ...
            $workplace->save();
        }
        ```
        ```php
        // SectorController::update() — same pattern.
        public function update(UpdateSectorRequest $request): JsonResponse
        {
            $user = $this->currentUser($request);
            $sector = $request->validated()['sector'];
            $changed = $user->sector !== $sector;
            $user->sector = $sector;
            $user->sector_source = 'manual';
            $user->save();
        }
        ```

- [ ] **#SEC-4** · P2 — Four mutation endpoints validate inline instead of via a Form Request class
    - **Where:** app/Http/Controllers/Api/Platforms/BookingController.php:46; app/Http/Controllers/Api/Platforms/ReservationsController.php:50; app/Http/Controllers/Api/Platforms/OnlineOrderingController.php:57; app/Http/Controllers/Api/Platforms/DisplaySettingsController.php:60-63
    - **Affects:** Booking/reservations/online-ordering URL-detect endpoints and the per-integration display-toggle PATCH — validation logic lives outside `app/Http/Requests/`, off the codebase's centralized validation surface.
    - **Effort:** M (~2-4h)
    - **What to do:**
        - Extract each inline `$request->validate(...)` block into a dedicated `FormRequest` class under `app/Http/Requests/Platforms/`.
        - Type-hint the new Form Request on each controller method in place of `Request`.
    - **Technical:** All four call sites use inline `$request->validate([...])` on `POST`/`PATCH` routes, which the project's architecture doctrine requires to resolve a dedicated `FormRequest` class. The inline rules themselves are adequate (`url` fields capped at `max:1000`, `toggles` validated as a boolean array against a known-key allowlist) — this is a code-organization/consistency gap, not an exploitable validation hole, since the values are subsequently normalized and re-validated downstream (`LinkCardScraper::normalizeUrl()`, an explicit unknown-toggle-key rejection).
    - **Plain English:** Every "save" action in the app is supposed to go through a standard, dedicated checklist file that's easy to find during a security review. These four endpoints wrote their checklist directly inside the controller instead of in the usual filing cabinet — the checks themselves are fine, but a future reviewer scanning the checklist folder would miss them.
    - **Evidence:**
        ```php
        // BookingController.php:46 — POST /platforms/booking/detect
        $validated = $request->validate(['url' => ['required', 'string', 'max:1000']]);
        ```
        ```php
        // ReservationsController.php:50 — POST /platforms/reservations/detect
        $validated = $request->validate(['url' => ['required', 'string', 'max:1000']]);
        ```
        ```php
        // OnlineOrderingController.php:57 — POST /platforms/online-ordering/entries
        $validated = $request->validate(['url' => ['required', 'string', 'max:1000']]);
        ```
        ```php
        // DisplaySettingsController.php:60-63 — PATCH /platforms/{platform}/display-settings
        $validated = $request->validate([
            'toggles' => ['required', 'array'],
            'toggles.*' => ['boolean'],
        ]);
        ```

- [ ] **#SEC-5** · P2 — Applicant email logged unhashed on the bootstrap account-conflict path
    - **Where:** app/Http/Controllers/Api/PublicSite/BootstrapController.php:160-164
    - **Affects:** Users whose email is already registered — their raw email address persists in Nightwatch / the log aggregator beyond the request lifecycle.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `'email' => $email` with `'email_hash' => hash('sha256', mb_strtolower((string) $email))`, matching the existing pattern at `PublicEarlyAccessController::store()` (honeypot-hit logging).
        - Extend `PiiLogHygieneSweepTest` to cover the `EMAIL_ALREADY_REGISTERED` log path.
    - **Technical:** `translateBootstrapException()` writes the raw `$email` string into a `Log::info` call reachable on every duplicate-signup attempt. The codebase already has the canonical mitigation elsewhere (`PublicEarlyAccessController` hashes the email before logging on its honeypot path) — this is the one bootstrap-path log call that didn't get the same treatment. Internal-log-only exposure (Nightwatch, not a public response), so tiered as a hygiene gap rather than a live data breach.
    - **Plain English:** When someone tries to sign up with an email that's already taken, we write a log note that includes their full email address — that note then sits in our internal monitoring tool indefinitely. Elsewhere in the app we've already learned to fingerprint the email instead of storing it plainly for this exact kind of log; this one spot didn't get the same fix.
    - **Evidence:**
        ```php
        if ($e->getMessage() === 'EMAIL_ALREADY_REGISTERED') {
            Log::info('Bootstrap rejected: email already registered to another auth user', [
                'uid' => $uid,
                'email' => $email,
            ]);
        ```

## P3 — Nice to have

- [ ] **#SEC-6** · P3 — `setPreviousWebsite` validates inline instead of via a Form Request
    - **Where:** app/Http/Controllers/Api/User/SiteManagement/UserWorkplaceController.php:212-216
    - **Affects:** `PATCH /site/workplace/previous-website` — same organizational nit as #SEC-4, kept separate since it's a single-field, already-adequate validation (`nullable|url|max:2048`).
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Extract into a dedicated `SetPreviousWebsiteRequest` class and type-hint it on the controller method.
    - **Technical:** Same pattern as #SEC-4 (inline `$request->validate()` on a mutating route instead of a dedicated `FormRequest`), but split out at P3 rather than merged into that bundle — the validation here is a single well-formed `url` rule with no adjacent security-relevant fields, making this pure code-organization polish rather than a review-visibility risk.
    - **Plain English:** Same "checklist filed in the wrong drawer" issue as the other four endpoints, but for a single simple field (an old website URL) that's already validated correctly — lowest-priority cleanup of the bunch.
    - **Evidence:**
        ```php
        public function setPreviousWebsite(Request $request): JsonResponse
        {
            $validated = $request->validate([
                'previous_website' => ['nullable', 'url', 'max:2048'],
            ]);
        ```

## Suggested Bundled Sessions

- **Bundle 1 — Auth-doctrine consistency (site-management controllers):** #SEC-2, #SEC-3
    - **Why grouped:** Both are `VerifySupabaseJwt` / `authorizeForUser` doctrine-hardening items with no live exploit path today; can land as one review pass over the auth-adjacent controller layer.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

- **Bundle 2 — Form Request extraction:** #SEC-4, #SEC-6
    - **Why grouped:** Identical mechanical fix (inline `$request->validate()` → dedicated `FormRequest` class) across five endpoints in the Platforms controller family.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

## Standalone — do NOT bundle

- **#SEC-1 — Content-image upload MIME byte-sniff** · standalone: touches the upload/injection-safety surface (Form Request + trait wiring) and should get its own focused review given the file-upload attack surface it closes.
- **#SEC-5 — Bootstrap email log hygiene** · standalone: small, isolated, single-file PII-hygiene fix — no reason to couple it to the doctrine-consistency or Form-Request bundles above.
