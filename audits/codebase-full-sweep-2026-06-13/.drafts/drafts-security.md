
<!-- ═══ LENS: security | CHUNK: auth-core ═══ -->

- [ ] **SEC-1** · P2 — CORS `allowed_origins` resolves to `[]` on deploy when config load order puts `cors.php` before `partna.php`
    - **Where:** config/cors.php:19
    - **Affects:** All browser-origin CORS preflight requests; main frontend origins relying on explicit allowlist entries (apex domain, alternative envs) silently denied.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace the inline `config('partna.frontend_origins', [])` in `config/cors.php` with a string env reference and move origin assembly into a deferred `AppServiceProvider::boot()` call that sets `config(['cors.allowed_origins' => ...])` after all config is guaranteed loaded.
        - Alternatively, duplicate the `allowed_origins` list into a dedicated `CORS_FRONTEND_ORIGINS` env var read directly by `config/cors.php` so there is zero cross-config dependency at load time.
    - **Technical:** `config/cors.php` calls `config('partna.frontend_origins', [])` during its own evaluation. The `LoadConfiguration` bootstrap loads config files in filesystem (alphabetical) order — `cors.php` evaluates before `partna.php`. When `partna` config hasn't been loaded yet, the `config()` helper returns the default `[]`. This means `HandleCors` (`fruitcake/laravel-cors` or Laravel's bundled CORS middleware) sees `allowed_origins: []` and rejects every explicit-origin request that isn't matched by the subdomain regex pattern. The `SecureHeaders::applyCors()` fallback path is NOT affected because it calls `config('partna.frontend_origins')` at request time (post-boot).
    - **Plain English:** Imagine the front door key is stored in two safes. Safe A (the CORS config) needs to read Safe B (the Partna config) to know which keys are valid. But Safe A gets opened before Safe B is filled, so it sees an empty list and locks everyone out — except people using the side entrance (the subdomain pattern match). The fix is to put the key list somewhere both safes can read after everything is loaded, or give each safe its own copy.
    - **Evidence:**
        ```php
        // config/cors.php — evaluated during bootstrap, before partna.php is loaded
        'allowed_origins' => config('partna.frontend_origins', []),
        ```
        ```php
        // config/partna.php — loaded later; the value below is never seen by config/cors.php
        'frontend_origins' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('PARTNA_FRONTEND_ORIGINS', ''))
        ))),
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **SEC-2** · P2 — Bot protection mode `off` in production only logs a warning; does not refuse boot
    - **Where:** app/Providers/BotProtectionServiceProvider.php:85-91
    - **Affects:** Every public mutation endpoint gated by `bot.token` middleware (enquiry, lead, waitlist, subscribe, report) — silently accepts unlimited unverified submissions in production.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Upgrade Guard 4 from `Log::warning` to a hard exception (like Guards 1–3) so deploys refuse to boot with `BOT_PROTECTION_MODE=off` in production.
        - Document the intentional override path: `BOT_PROTECTION_MODE=off` must only be set with an explicit deploy-time acknowledgement (e.g., `BOT_PROTECTION_MODE=off BOT_PROTECTION_OVERRIDE_ACK=true`).
    - **Technical:** `BotProtectionServiceProvider::runBootGuards()` checks four misconfiguration conditions. Guards 1–3 throw `CaptchaConfigurationException` (hard boot failure). Guard 4 (`mode=off` in production) only calls `Log::warning` — the application boots normally and `VerifyBotToken::handle()` hits the `if ($mode === 'off') { return $next($request); }` early-return on every protected route, bypassing all CAPTCHA verification. A `.env.example` copy-paste deploy leaves every form endpoint unprotected.
    - **Plain English:** The alarm system has four self-tests on startup. Three of them refuse to arm if something's wrong. The fourth just writes a sticky note saying "the alarm is off" and lets the building open anyway. If someone copies the example config file to production, every public form accepts submissions with no bot check.
    - **Evidence:**
        ```php
        // Guard 4: mode=off in production. Soft warn — log only, do not refuse boot.
        if ($env === 'production' && $mode === 'off') {
            Log::warning('bot_protection.mode_off_in_production', [
                'note' => 'BOT_PROTECTION_MODE=off disables all bot verification on every protected endpoint; set MODE=shadow or MODE=enforce in production.',
            ]);
        }
        ```
    - `[DRAFT, confidence: 0.9]`

- [ ] **SEC-3** · P2 — CORS subdomain regex pattern excludes apex domain (`partna.au` without `www`)
    - **Where:** config/cors.php:25
    - **Affects:** Browser requests originating from `https://partna.au` (apex domain) — CORS preflight fails unless the origin is explicitly listed in `PARTNA_FRONTEND_ORIGINS`.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a second regex pattern `#^https://partna\.au$#i` to `allowed_origins_patterns` so the apex domain is matched independently of the wildcard subdomain pattern.
        - Alternatively, ensure `https://partna.au` and `https://www.partna.au` are always in the `PARTNA_FRONTEND_ORIGINS` explicit list as a belt-and-suspenders measure.
    - **Technical:** The pattern `#^https://[a-z0-9-]+\.partna\.au$#i` requires at least one character (a subdomain label) before `.partna.au`. The regex quantifier `+` means "one or more," so `https://partna.au` (zero labels before the public suffix) does not match. The apex-domain frontend — which is the primary marketing and logged-out landing surface — would be CORS-denied on API calls unless `PARTNA_FRONTEND_ORIGINS` explicitly includes `https://partna.au`. Note: `https://www.partna.au` DOES match the pattern since `www` satisfies the `[a-z0-9-]+` requirement.
    - **Plain English:** The building has a bouncer with a guest list that says "anyone from a subdomain of partna.au is allowed in." The main entrance at `partna.au` itself isn't a subdomain — it's the front door — so the bouncer turns it away unless it's on a separate VIP list. If that VIP list is empty (see SEC-1), the front door can't get into its own API.
    - **Evidence:**
        ```php
        // config/cors.php — requires at least one subdomain label
        'allowed_origins_patterns' => [
            '#^https://[a-z0-9-]+\.partna\.au$#i',
        ],
        ```
    - `[DRAFT, confidence: 0.9]`

- [ ] **SEC-4** · P3 — `PerTargetReportThrottle` uses non-HMAC IP hashing, inconsistent with the codebase standard in `HashesClientData`
    - **Where:** app/Http/Middleware/Moderation/PerTargetReportThrottle.php:37
    - **Affects:** IP hash values in report-throttle Redis keys; prevents cross-correlation with lead/analytics IP hashes stored elsewhere.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace the inline `hash('sha256', $ip.'|'.$key)` with `hash_hmac('sha256', $ip, $key)` or delegate to `HashesClientData::hashIp()` so all IP hashing in the app uses the same scheme.
        - Audit other call sites for the concatenation pattern and standardise them.
    - **Technical:** `PerTargetReportThrottle` computes IP hashes via `hash('sha256', $request->ip().'|'.config('app.key'))` — a plain SHA-256 of concatenated values. The codebase standard (used by `LogLeadRateLimits`, `VerifyBotToken`, and other analytics paths) is `HashesClientData::hashIp()` which calls `hash_hmac('sha256', $ip, config('app.key'))`. The concatenation approach lacks the double-hash and padding structure of HMAC; while not practically exploitable for IP hashing (SHA-256 is resistant to length-extension in this key-at-end arrangement), the different hash values mean the same IP produces different identifiers across the two systems, making abuse-pattern correlation across the rate-limit and analytics pipelines impossible.
    - **Plain English:** Two security cameras in the same building use different methods to blur faces. Camera A blurs one way, Camera B another. When security reviews footage, they can't tell if the person at the front door is the same person who triggered the alarm at the back — the blurred images don't match. Using the same blur method everywhere lets you track patterns across the whole building.
    - **Evidence:**
        ```php
        // PerTargetReportThrottle.php — non-HMAC concatenation hash
        $ipHash = hash('sha256', $request->ip().'|'.config('app.key'));
        $key = "moderation:report:ip:{$ipHash}:target:{$type}:{$handle}";
        ```
        ```php
        // HashesClientData.php — the codebase standard (HMAC)
        protected function hashIp(?string $ip): ?string
        {
            if (! $ip) {
                return null;
            }
            return hash_hmac('sha256', $ip, config('app.key'));
        }
        ```
    - `[DRAFT, confidence: 0.8]`

<!-- ═══ LENS: security | CHUNK: user-surface ═══ -->

- [ ] **#SEC-1** · P0 — Analytics ingest requests accept `site_id` without verifying it matches the subdomain, enabling cross-tenant data injection
    - **Where:** app/Http/Requests/Api/PublicSite/Analytics/PageviewRequest.php:31, ClickRequest.php:35, PingRequest.php:28, SectionSeenRequest.php:27
    - **Affects:** All analytics data – an attacker can post pageviews, clicks, pings, or section-seen events for any professional’s site by supplying any existing `site_id`, poisoning metrics and “live now” counts.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Remove `site_id` from the public-facing request contract entirely; only accept `subdomain` (resolved from the route or `X-Site-Subdomain` header).
        - Resolve the site server-side from the canonical `subdomain` before writing events.
    - **Technical:** The current rules use `required_without:subdomain` and only check that the UUID `exists` in `site.sites`. There is no cross-reference to the subdomain. An attacker can send `{ site_id: "<any-valid-uuid>", ... }` and bypass subdomain-based routing, injecting data into another professional’s analytics. This is a direct tenant-boundary failure. The fix is to eliminate the untrusted `site_id` parameter from the public contract and derive the site deterministically from the validated subdomain.
    - **Plain English:** There’s a “please deliver to room number” field on the public form. The form only checks that the room number exists somewhere in the hotel, not that it matches the guest who provided the key card. Someone can fill in a different room number and the analytics go to the wrong guest’s dashboard. The fix is to remove that field and look up the right room from the key card alone.
    - **Evidence:**
        ```php
        // PageviewRequest.php
        'site_id' => ['required_without:subdomain', 'uuid', Rule::exists('pgsql.site.sites', 'id')],
        // ClickRequest.php
        'site_id' => ['required_without:subdomain', 'uuid', Rule::exists('pgsql.site.sites', 'id')],
        // PingRequest.php
        'site_id' => ['required_without:subdomain', 'uuid', Rule::exists('pgsql.site.sites', 'id')],
        // SectionSeenRequest.php
        'site_id' => ['required_without:subdomain', 'uuid', Rule::exists('pgsql.site.sites', 'id')],
        ```
    - `[DRAFT, confidence: 1.0]`

- [ ] **#SEC-2** · P2 — Public waitlist and email-subscribe forms lack bot-protection fields present in other public forms
    - **Where:** app/Http/Requests/Api/PublicSite/PublicWaitlistSignupRequest.php, app/Http/Requests/Api/PublicSite/PublicEmailSubscribeRequest.php
    - **Affects:** Waitlist signup and email subscription endpoints – susceptible to automated spam, list bombing, and abuse.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a hidden honeypot field (e.g., `website`) and a required `form_started_at_ms` timing field to both Form Requests, matching the pattern used in `PublicEnquiryRequest` and `PublicCustomerLeadRequest`.
        - Document the expected client-side behaviour (honeypot must remain empty, `form_started_at_ms` sent as millisecond epoch).
    - **Technical:** The other public-facing forms (`PublicEnquiryRequest`, `PublicCustomerLeadRequest`) include `'website' => ['nullable', 'string', 'max:255']` and `'form_started_at_ms' => ['required', 'integer', 'min:0']` as bot protection. `PublicWaitlistSignupRequest` and `PublicEmailSubscribeRequest` have no such fields, making them easier targets for scripted submission. Adding these fields brings them into line with the existing anti-bot strategy and allows the controller to reject requests that fill the honeypot or have an impossibly small time delta.
    - **Plain English:** Our checkout and contact forms have a hidden “prove you’re human” trapdoor and a timer that rejects submissions that happen too quickly. Two other forms that collect emails are missing both protections, so a script can flood them without hitting any speed bump. Adding the same trapdoor and timer makes them equally resistant to bots.
    - **Evidence:**
        ```php
        // PublicWaitlistSignupRequest.php — no honeypot or timing field
        public function rules(): array
        {
            return [
                'email' => ['required', 'email:rfc', 'max:255'],
                'name' => ['nullable', 'string', 'max:200'],
                // … no 'website' honeypot, no 'form_started_at_ms'
            ];
        }
        // PublicEmailSubscribeRequest.php — similarly lacks them
        ```
    - `[DRAFT, confidence: 0.9]`

- [ ] **#SEC-3** · P2 — Document download URL may leak draft documents if the public download endpoint lacks an `is_active` check
    - **Where:** app/Http/Controllers/Api/User/Account/UserDocumentController.php (buildDocumentPayload method, line ~138)
    - **Affects:** Any professional’s draft documents — a person who obtains or guesses a document’s UUID could download content that is not yet published.
    - **Effort:** M (~2–4h) — depends on the state of the public download controller
    - **What to do:**
        - In `buildDocumentPayload`, only include `download_url` when `$media->is_active` is true (i.e., the document is published).
        - Verify that the corresponding `GET /api/public/documents/{id}/download` controller checks both `is_active` and the site’s published status.
    - **Technical:** The payload unconditionally sets `download_url` for every document, regardless of its `is_active` flag. Since the download URL is a direct, unauthenticated path based only on a UUID, anyone with knowledge of the UUID can attempt to fetch the file. If the public download endpoint does not independently verify that the document is published (and that its owning site is published), draft documents become publicly accessible. The UUID reduces risk but does not eliminate it, especially if IDs appear in client-side logs or network inspectors.
    - **Plain English:** Imagine a filing cabinet where every drawer has a key and some drawers are marked “draft — do not open.” The dashboard is handing out a generic “open drawer” key for every drawer, regardless of the draft tag. If a visitor finds that key, they can open a draft drawer. The fix is to only hand out keys for drawers that are ready to be shown.
    - **Evidence:**
        ```php
        // UserDocumentController::buildDocumentPayload()
        return [
            // …
            'preview_url' => $previewUrl,
            'download_url' => '/api/public/documents/'.$media->id.'/download',
            // …
        ];
        // No check on $media->is_active before adding download_url
        ```
    - `[DRAFT, confidence: 0.7]` — the actual risk depends on the public download endpoint’s authorisation logic, which was not included in the audit scope.

- [ ] **#SEC-4** · P2 — Analytics ingest requests lack built-in anti-abuse validation, increasing risk of queue exhaustion
    - **Where:** app/Http/Requests/Api/PublicSite/Analytics/PageviewRequest.php, ClickRequest.php, PingRequest.php, SectionSeenRequest.php
    - **Affects:** Analytics processing pipeline — a flood of forged requests could exhaust the job queue and degrade site performance.
    - **Effort:** M (~2–4h) — requires both request-level hardening and route-level throttle tuning
    - **What to do:**
        - Apply strict per-IP and per-site `throttle` middleware to all analytics ingest routes.
        - Add a basic anti-bot signal to the Form Requests (e.g., a timestamp or nonce) that allows the worker to reject obviously automated bursts before queue dispatch.
    - **Technical:** The analytics ingest endpoints accept data from any origin (the public site’s JavaScript beacon) with no server-side origin validation visible in the provided code. The requests carry no anti-forgery token, no signed payload, and no honeypot field. While rate limiting is the primary defence, the absence of any lightweight request-level check means that even a modest attacker can push through a large volume of events, consuming queue capacity and potentially delaying legitimate jobs. A small, cheap client-side timestamp or nonce checked at the HTTP layer can cull the noisiest abuse before reaching the job system.
    - **Plain English:** The analytics pipeline is like a mailroom that accepts every envelope that arrives, no matter how many or how fast. There’s currently a doorman (rate limiter) but the envelope itself has no stamp. Adding a quick “is this envelope at least shaped like a real one?” check at the door catches the most obvious junk before it clogs the sorting machines.
    - **Evidence:**
        ```php
        // PageviewRequest.php — no honeypot, nonce, or timestamp
        public function rules(): array
        {
            return [
                'site_id' => ['required_without:subdomain', 'uuid', Rule::exists('pgsql.site.sites', 'id')],
                'subdomain' => ['required_without:site_id', 'string', 'max:63'],
                // … no anti-abuse fields
            ];
        }
        ```
    - `[DRAFT, confidence: 0.6]` — the presence or absence of throttle middleware on the routes was not verifiable from the provided files; this finding assumes the current protection is light.

<!-- ═══ LENS: security | CHUNK: public-staff-surface ═══ -->

- [ ] **#SEC-1** · P1 — StaffLinkBlockManagementController uses inline `abort_unless` ownership checks instead of `authorizeForUser` + Policy
    - **Where:** app/Http/Controllers/Api/Staff/UserSiteManagement/StaffLinkBlockManagementController.php:81-85 (update), :90-94 (destroy)
    - **Affects:** Staff users managing link blocks — ownership still enforced but bypasses Policy system (no pending-deletion guard, no audit trail via Policy)
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Replace `abort_unless($linkBlock->user_id === $professional->id && ..., 404)` with `$this->authorizeForUser($staff, 'update', $linkBlock)`
        - Register or confirm `BlockPolicy` handles `update`/`delete` abilities with ownership + pending-deletion checks via `BasePolicy`
        - Apply same pattern to `destroy()`, `reorder()`
    - **Technical:** Category 2. The doctrine mandates `authorizeForUser` through Policies — never inline `abort_unless` with ownership comparisons. Inline checks skip `denyIfPendingDeletion()` (423 on soft-deleted resources) and central auditability. CI rejects inline 403 aborts in controllers; these 404 aborts are the same anti-pattern under a different status code. The scoped binding comment acknowledges ownership but doesn't replace the Policy call.
    - **Plain English:** The staff dashboard has a shortcut for checking who owns a link block — it checks the ID directly instead of going through the building's security desk. It works, but bypasses the central logbook and the "account pending deletion" lock that the proper Policy system provides. Every staff action on a professional's content should go through the same Policy gate, so we never have to wonder which doors are locked and which have a handwritten note.
    - **Evidence:**
        ```php
        // StaffLinkBlockManagementController::update, line 81-85
        abort_unless(
            $linkBlock->user_id === $professional->id &&
            $linkBlock->block_group === 'links' &&
            $linkBlock->block_type === 'link',
            404
        );
        ```
    - `[DRAFT, confidence: 0.9]`

- [ ] **#SEC-2** · P1 — StaffSectionManagementController uses query-scoped ownership without `authorizeForUser` Policy gates
    - **Where:** app/Http/Controllers/Api/Staff/UserSiteManagement/StaffSectionManagementController.php (index, upsert, reorder, remove — all methods)
    - **Affects:** Staff users managing section blocks — every operation scopes by `user_id` in the query but never invokes a Policy
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add `$this->authorizeForUser($staff, 'view', $professional)` (or equivalent `staffViewSections` ability) at the top of `index()`
        - For `upsert()`, add a Policy gate on the professional's site or on the block skeleton before the upsert transaction
        - For `reorder()`, add a Policy gate confirming the staff member can manage sections for this professional
    - **Technical:** Category 2. All methods scope queries via `->where('user_id', $professional->id)` — this correctly isolates by tenant, but the architecture requires a Policy call so that `denyIfPendingDeletion()`, role restrictions, and audit coverage apply uniformly. A query scope alone is invisible to `PolicyCoverageTest` and doesn't fire the pending-deletion guard. The `upsert` method additionally creates blocks without a pre-create skeleton-authorization step.
    - **Plain English:** The staff section editor checks "does this section belong to this professional" by filtering the database query, like looking through a keyhole to confirm you're in the right room. But it never badges in at the front desk. The Policy system is the front desk — it checks whether this staff member is allowed to touch this professional's content at all, and whether the account is in a deletion grace period. Every staff operation should badge in first, then look through the keyhole.
    - **Evidence:**
        ```php
        // StaffSectionManagementController::index, line ~32
        $sections = Block::query()
            ->where('user_id', $professional->id)
            ->where('block_group', 'sections')
            ->orderBy('sort_order')
            ->get();
        ```
    - `[DRAFT, confidence: 0.9]`

- [ ] **#SEC-3** · P1 — StaffAccountDeletionController missing `authorizeForUser` on professional resource operations
    - **Where:** app/Http/Controllers/Api/Staff/StaffSite/StaffAccountDeletionController.php (initiate:22, cancel:52, show:71)
    - **Affects:** Staff-triggered account deletion — any staff member can initiate/cancel/view deletion state for any professional without a per-resource Policy gate
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `$this->authorizeForUser($staff, 'staffManage', $professional)` at the top of `initiate()`, `cancel()`, and `show()`
        - Confirm `UserPolicy::staffManage()` is registered and enforces the correct staff-role gating (admin-only for deletion initiation)
    - **Technical:** Category 2. The staff middleware proves staff identity, but the doctrine requires `authorizeForUser` on each individual resource operation — "the middleware proves staff identity; a Policy proves they can act on this specific resource." Account deletion is the most destructive staff action; a Policy gate here ensures role restrictions (e.g., only admin staff can initiate deletion) are enforced in one central, testable place rather than scattered across route middleware configuration.
    - **Plain English:** The account deletion tool is behind the staff-only door, which is good. But once inside, there's no second lock — any staff member can delete any professional's account. The Policy system is that second lock: it checks "is this specific staff member allowed to delete this specific account?" Right now the answer is always yes for anyone with a staff badge. We need the Policy to say "only senior staff" or "only with a reason logged."
    - **Evidence:**
        ```php
        // StaffAccountDeletionController::initiate — no authorizeForUser call
        public function initiate(
            StaffInitiateDeletionRequest $request,
            User $professional,
        ): JsonResponse {
            /** @var PartnaStaff $staff */
            $staff = $request->attributes->get('partna_staff');

            $result = $this->deletionService->adminInitiate(
                professional: $professional,
                // ... no Policy gate before acting on $professional
            );
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **#SEC-4** · P2 — Multiple staff controllers operate on professional resources without `authorizeForUser`
    - **Where:**
        - app/Http/Controllers/Api/Staff/StaffSite/StaffAnalyticsController.php:44 (summary)
        - app/Http/Controllers/Api/Staff/StaffSite/StaffEmailSubscriberController.php:29 (index), :91 (export)
        - app/Http/Controllers/Api/Staff/StaffSite/StaffEnquiryController.php:21 (index)
        - app/Http/Controllers/Api/Staff/StaffSite/StaffSiteController.php:20 (showByProfessional)
        - app/Http/Controllers/Api/Staff/StaffSite/StaffWorkplaceController.php:17 (show)
        - app/Http/Controllers/Api/Staff/StaffSite/StaffNotificationController.php:147 (indexForProfessional)
        - app/Http/Controllers/Api/Staff/UserSiteManagement/StaffDataExportController.php:29 (store)
        - app/Http/Controllers/Api/Staff/UserSiteManagement/StaffSiteManagementController.php:17 (update)
    - **Affects:** Staff read/write operations — analytics, email subscribers, enquiries, site data, workplace, notifications, data exports, site management
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add `$this->authorizeForUser($staff, 'staffManage', $professional)` (or a narrower per-controller ability like `staffViewAnalytics`, `staffViewSubscribers`) at the top of each affected method
        - Register these abilities on `UserPolicy` or split into dedicated Policies per resource type
        - For read-only endpoints (analytics, subscribers, enquiries, workplace), a single `staffView` ability on the professional may suffice; for write endpoints (site management update, data export), use `staffManage`
    - **Technical:** Category 2. The staff middleware (`staff` / `staff.admin`) proves the requester is a staff member, but the architecture requires a Policy call on every individual resource operation. Without it, there's no central place to add role gating (e.g., "only admin staff can export data"), no `denyIfPendingDeletion()` guard, and no audit trail through Policy events. `StaffCustomerManagementController` and `StaffServiceManagementController` demonstrate the correct pattern — these eight controllers should follow suit.
    - **Plain English:** Eight staff tools open the door with a staff badge but never check the room number. They assume any staff member can look at any professional's analytics, subscribers, enquiries, and site data. The Policy system is the room-key check — it would let us say "support staff can view enquiries but only admins can trigger a data export." Right now every staff member has a master key to every room. Adding `authorizeForUser` puts a policy-check lock on each door that we can configure centrally.
    - **Evidence:**
        ```php
        // StaffAnalyticsController::summary — no authorizeForUser
        public function summary(Request $request, User $professional): JsonResponse
        {
            // ... directly queries analytics for $professional with no Policy gate

        // StaffDataExportController::store — no authorizeForUser
        public function store(
            RequestStaffDataExportRequest $request,
            User $professional,
        ): JsonResponse {
            /** @var PartnaStaff $staff */
            $staff = $request->attributes->get('partna_staff');
            // ... dispatches export for $professional with no Policy gate
        ```
    - `[DRAFT, confidence: 0.9]`

- [ ] **#SEC-5** · P2 — SiteVisibilityController uses inline ownership scope instead of Policy gate
    - **Where:** app/Http/Controllers/Api/PublicSite/SiteVisibilityController.php:28-30
    - **Affects:** Professional toggling their own site visibility — ownership checked via query scope, bypassing Policy
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `Site::query()->where('user_id', $professional->id)->firstOrFail()` with a `$this->authorizeForUser($professional, 'update', $site)` call after resolving the site
        - Ensure `SitePolicy::update()` is registered and checked
    - **Technical:** Category 2. The controller correctly resolves the actor via `$request->attributes->get('professional')` (doctrine-compliant), but then scopes the site query by `user_id` inline instead of calling `authorizeForUser`. The inline scope works but bypasses `denyIfPendingDeletion()` — if the professional's account is in the 30-day deletion grace period, the Policy would return 423, but the inline query returns the site and allows the toggle.
    - **Plain English:** The publish/unpublish toggle checks "does this site belong to this professional" by filtering the database query. This is like checking a name on a mailbox instead of using the building's access card system. It works day-to-day, but if the professional's account is scheduled for deletion, the Policy system would lock the door — the inline check wouldn't. Every resource operation should go through the Policy card reader.
    - **Evidence:**
        ```php
        // SiteVisibilityController::update, line 28-30
        $site = Site::query()
            ->where('user_id', $professional->id)
            ->firstOrFail();

        $site->published = (bool) $request->validated('published');
        $site->save();
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **#SEC-6** · P2 — PublicConfigController exposes Google Maps API key on unauthenticated endpoint
    - **Where:** app/Http/Controllers/Api/PublicSite/PublicConfigController.php:58
    - **Affects:** Public visitors — the Google Maps API key is returned to any unauthenticated client
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Verify the Google Maps API key is restricted to HTTP referrers matching `*.partna.au` in the Google Cloud Console
        - If not already restricted, either restrict it or move the key to a server-side proxy endpoint instead of exposing it client-side
        - Document the restriction in the config comment so future key rotations preserve the restriction
    - **Technical:** Category 5. The endpoint returns `config('services.google_maps.api_key')` on `GET /api/public/config/integrations` — an unauthenticated, CDN-cacheable route. The code comment acknowledges this and asserts the key "must be HTTP-referrer-restricted." If that restriction is in place at the Google Cloud Console level, the risk is contained. If the key was provisioned without a referrer restriction, any third party could consume it against the project's quota. This is defense-in-depth: the comment is documentation, not enforcement.
    - **Plain English:** The frontend config endpoint hands out the Google Maps key to anyone who asks, like leaving a spare office key under the public welcome mat. The note on the mat says "this key only works if you're calling from our building" — which is true if Google is enforcing that rule. But if someone forgot to tell Google about the restriction, the key works from anywhere. We should verify the lock is on at Google's end, not just assume the note on the mat is enough.
    - **Evidence:**
        ```php
        // PublicConfigController::integrations, line 58
        return $this->successCached([
            'googleMapsApiKey' => config('services.google_maps.api_key'),
        ]);
        ```
    - `[DRAFT, confidence: 0.7]`

- [ ] **#SEC-7** · P2 — StaffNotificationController::store() and StaffUserController::updateStatus()/bulkUpdateStatus() use inline `$request->validate()` instead of Form Request classes
    - **Where:**
        - app/Http/Controllers/Api/Staff/StaffSite/StaffNotificationController.php:27-46 (store)
        - app/Http/Controllers/Api/Staff/UserSiteManagement/StaffUserController.php:120-124 (updateStatus), :148-157 (bulkUpdateStatus)
    - **Affects:** Staff notification creation and professional status changes — validation rules live in the controller, not in dedicated Form Request classes
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Extract `store()` validation rules into a `StoreStaffNotificationRequest` Form Request class
        - Extract `updateStatus()` and `bulkUpdateStatus()` validation into dedicated Form Request classes
        - Register them on the route definitions so validation runs before the controller method
    - **Technical:** Category 6. The architecture requires every `POST`/`PATCH`/`PUT` route to resolve a `FormRequest` class. Inline `$request->validate()` skips the Form Request lifecycle — no `authorize()` method, no pre-validation hooks, harder to test in isolation, and invisible to `php artisan route:list` validation column. The notification store has complex rules (category allowlist, date constraints, conditional email fields) that belong in a dedicated class.
    - **Plain English:** Three staff endpoints write their validation rules on a sticky note inside the controller instead of using the standard Form Request filing system. It works, but the rules can't be reused, tested independently, or auto-documented. It's like having building codes written on the wall of each room instead of in the central permit office. Extract them into proper Form Request files so every mutation endpoint follows the same validation pattern.
    - **Evidence:**
        ```php
        // StaffNotificationController::store, line 27
        $data = $request->validate([
            'user_id' => ['nullable', 'uuid'],
            'type' => ['required', 'string', 'max:50'],
            // ... 15 more inline rules
        ]);

        // StaffUserController::updateStatus, line 120
        $data = $request->validate([
            'status' => ['required', 'string', 'in:active,suspended'],
        ]);
        ```
    - `[DRAFT, confidence: 0.8]`

- [ ] **#SEC-8** · P2 — StaffUserController::index() exposes PII (primary_email, phone) for all professionals in list view
    - **Where:** app/Http/Controllers/Api/Staff/UserSiteManagement/StaffUserController.php:47-65
    - **Affects:** All staff roles accessing the professional list — every professional's email and phone are returned regardless of staff role
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Either remove `primary_email` and `phone` from the list view (search by email still works server-side without returning it)
        - Or gate these fields behind a staff role check (`$staff->isAdmin()`) so only admin staff see PII in list context
        - Align with `StaffAccountDeletionController::show()` which explicitly selects non-PII columns for support staff
    - **Technical:** Category 10. The index endpoint returns `primary_email` and `phone` for every professional in the paginated list. `StaffAccountDeletionController::show()` explicitly omits PII columns, stating "support staff don't need staff identity; admin investigations can hit the DB directly." The list view should follow the same principle — search and filtering can use email/phone server-side without exposing them in the response payload to every staff role.
    - **Plain English:** The staff directory lists every professional's email and phone number on the main search page — like a reception desk that shows every tenant's personal contact details to anyone with a staff badge. The account deletion tool already hides this info for support staff. The main directory should follow the same rule: use email for searching but don't display it. Admin staff who need the full list can use a dedicated detail view or DB access.
    - **Evidence:**
        ```php
        // StaffUserController::index, line 47-53
        $professionals = $page->getCollection()->map(function (User $p) {
            return [
                'id' => $p->id,
                'handle' => $p->handle,
                'display_name' => $p->display_name,
                'status' => $p->status,
                'primary_email' => $p->primary_email,
                'phone' => $p->phone,
                // ...
            ];
        });
        ```
    - `[DRAFT, confidence: 0.8]`

- [ ] **#SEC-9** · P3 — StaffFeatureFlagController and StaffFeatureFlagOverrideController use inline `abort_if` for staff auth re-check
    - **Where:**
        - app/Http/Controllers/Api/Staff/FeatureFlag/StaffFeatureFlagController.php:23,33,43,53 (every method)
        - app/Http/Controllers/Api/Staff/FeatureFlag/StaffFeatureFlagOverrideController.php:21,31,65
    - **Affects:** Staff feature flag management — redundant auth check is defense-in-depth but inconsistent with Policy pattern
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Remove the inline `abort_if($request->attributes->get('partna_staff') === null, 401)` — the staff middleware already guarantees this attribute is set
        - If additional role gating is desired (admin-only), add a Policy ability rather than an inline check
    - **Technical:** Category 2. Feature flags are platform-level models, not tenant-owned, so the strict Policy-per-resource requirement is softer here. The inline `abort_if` is checking authentication (is there a staff actor?) which the `staff` / `staff.admin` middleware already guarantees. It's harmless defense-in-depth but sets a precedent for inline auth checks that could drift into authorization territory. The staff middleware attribute check is sufficient; remove the redundant inline abort or replace with a Policy gate if role restrictions are needed.
    - **Plain English:** The feature flag tools check the staff badge twice — once at the door (middleware) and again at each desk (inline code). The second check doesn't hurt but it's inconsistent with how other staff tools work. It's like having two security guards check the same ID. Pick one — the door guard is enough, and it keeps the pattern consistent across all staff endpoints.
    - **Evidence:**
        ```php
        // StaffFeatureFlagController::index, line 23
        abort_if($request->attributes->get('partna_staff') === null, 401, 'Unauthenticated');
        ```
    - `[DRAFT, confidence: 0.75]`

<!-- ═══ LENS: security | CHUNK: outbound-services ═══ -->

- [ ] **SEC-1** · P2 — Cloudflare Worker 301 open redirect – alias target not validated
    - **Where:** cloudflare-worker/src/index.js ~ the alias redirect block
    - **Affects:** Visitors following a stale subdomain alias — a poisoned KV entry could redirect them to an arbitrary origin.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Validate that `entry.redirect` starts with `https://` and the host ends with `.partna.au` (or exactly match `*.partna.au`), rejecting the redirect otherwise.
        - Ensure `SyncSubdomainToKvJob` writes only validated redirect URLs, making the worker validation a defence-in-depth layer.
    - **Technical:** The Worker is the first point of contact for all sitepage traffic. An alias entry written by the single KV writer (`SyncSubdomainToKvJob`) is expected to be a canonical `https://<handle>.partna.au` origin, but the Worker does not verify this. If an attacker manages to write a KV entry with `{"type":"alias","redirect":"https://evil.com"}`, any request to that subdomain would 301‑redirect the visitor to `https://evil.com/…` (full path + query) without any domain whitelisting. In the browser this is a permanent redirect; it can be cached and used for phishing.
    - **Plain English:** Imagine the help desk re‑routing your call. The worker trusts the note left by the backend to say “send this visitor to desk B.” If someone sneaks in a note that says “send them to an outside line,” the worker blindly does it. We need to check that the destination is always one of our own desks.
    - **Evidence:**
        ```js
        if (entry.type === "alias" && typeof entry.redirect === "string") {
          // Preserve the deep link …
          const target = `${entry.redirect.replace(/\/$/, "")}${url.pathname}${url.search}`;
          const h = new Headers({
            Location: target,
            "Cache-Control": "max-age=0, must-revalidate",
          });
          applySecurityHeaders(h);
          return new Response(null, {status: 301, headers: h});
        }
        ```
    - `[DRAFT, confidence: 0.8]`

- [ ] **SEC-2** · P3 — SmartLinkImageService logs potentially signed image URLs
    - **Where:** app/Services/SmartLinks/SmartLinkImageService.php ~ the `rehost` method catch block
    - **Affects:** Log retention – an image source URL containing a temporary token or signature could be persisted in Nightwatch / log aggregators.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Redact query strings (or the entire URL) from the log context, or hash the URL like `md5($source)`.
        - Alternatively, log only the host and path without the query portion.
    - **Technical:** When commerce‑family image re‑ingest fails, the catch block writes the raw `$sourceUrl` into a `Log::warning` entry. Some CDN‑signed image URLs (e.g., temporary Shopify `?v=` params, or signed S3 URLs) can carry short‑lived access tokens. Log aggregators like Nightwatch retain these records outside the normal GDPR erasure pipeline, so a token that lives in logs could outlive the token’s intended lifetime. The risk is low because most source URLs are public, but a single leak of a signed URL is irreversible once ingested into log storage.
    - **Plain English:** Our logbook sometimes copies down the full web address of an image it tried to fetch. If that address had a “temporary pass” in it (like a one‑time ticket), the ticket is now written down forever. We should only note the door number, not the ticket.
    - **Evidence:**
        ```php
        } catch (\Throwable $e) {
            Log::warning('SmartLink image rehost failed', [
                'source' => $sourceUrl,
                'message' => $e->getMessage(),
            ]);
            return null;
        }
        ```
    - `[DRAFT, confidence: 0.6]`

- [ ] **SEC-3** · P2 — MetadataParser missing LIBXML_NONET flag allows XXE on user‑fetched HTML
    - **Where:** app/Services/SmartLinks/MetadataParser.php: `$dom->loadHTML(...)` inside `parse()`
    - **Affects:** Any smart‑link or platform scraper that passes user‑pointed HTML through the parser — an attacker hosting a malicious page could attempt XML External Entity injection.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `LIBXML_NONET | LIBXML_NOENT` (or `LIBXML_NONET` with entity substitution disabled) to the `loadHTML` call to block network access during parsing.
        - Ensure the same flag is used wherever `DOMDocument` is created in the codebase (currently only this class is affected; `PinterestScraper` already uses safe flags for `SimpleXML`).
    - **Technical:** `MetadataParser::parse()` loads third‑party HTML with `$dom->loadHTML(...)` and no option flags. Without `LIBXML_NONET`, the parser may resolve external entities defined in the document, allowing a crafted HTML page to trigger outbound HTTP requests (XXE → SSRF) or read local files (in older libxml versions). While PHP ≥ 8.0 disables external entity loading by default at the libxml level, explicitly passing the safe flags is the defence‑in‑depth pattern used elsewhere in the project (e.g., `PinterestScraper` with `LIBXML_NONET`). The parser processes every fetch of an oEmbed/Open‑Graph page and every commerce page scrape, so the attack surface is wide.
    - **Plain English:** We hand a stranger’s letter to an envelope‑opening machine. By default the machine is safe nowadays, but we haven’t locked its “make a phone call” feature. The envelope could contain a hidden instruction to dial a private number inside our building. Locking that feature costs nothing and makes sure a future firmware downgrade doesn’t reopen the risk.
    - **Evidence:**
        ```php
        $dom = new \DOMDocument;
        $prev = libxml_use_internal_errors(true);
        // Force UTF-8 so multibyte titles/og survive parsing.
        $dom->loadHTML('<?xml encoding="UTF-8">'.$html);
        ```
    - `[DRAFT, confidence: 0.7]`
