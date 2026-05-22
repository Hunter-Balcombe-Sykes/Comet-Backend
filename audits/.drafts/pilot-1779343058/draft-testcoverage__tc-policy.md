- [ ] **#TEST-1** · P1 — CommissionPolicy view/update/delete/startConnect/viewProjections abilities have zero test coverage
    - **Where:** app/Policies/CommissionPolicy.php (view, update, delete, startConnect, viewProjections, viewOwnTransactions)
    - **Affects:** Every brand/affiliate accessing commission records, team members with delegated financial-analytics capability, Stripe Connect onboarding flow.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add `it('view: brand owner can view', ...)`, `it('view: affiliate can view own', ...)`, `it('view: team member with financial read can view', ...)`, `it('view: unrelated actor gets 404', ...)` covering the BrandAccessService delegation path.
        - Add `it('update: brand owner can update', ...)` + deny cases for pending_deletion, non-owner, affiliate.
        - Add `it('startConnect: self only', ...)` + `it('startConnect: different professional denied', ...)`.
        - Add `it('viewProjections: only matching affiliate', ...)`.
        - Add `it('viewOwnTransactions: brand sees brand-side, affiliate sees affiliate-side', ...)`.
    - **Technical:** CommissionPolicyAbilityTest.php tests viewOwnPayouts, managePaymentMethod, and manageWallet but none of the core CRUD abilities. The `view` method in particular delegates to `BrandAccessService::canReadBrandFinancialAnalytics()` — a Mockery mock of BrandAccessService is set up in the test file's `beforeEach` but never exercised for the `view` ability. If the capability check changes or the team-member auth model shifts, there is zero test to catch the regression.
    - **Plain English:** The authorization rules for who can see commission financial records have a complex chain: brand owner → yes, affiliate → yes for their own, team member → yes if they have the "read financials" key. None of this chain has automated tests. If someone refactors the team-permission system, commission visibility could break silently — either leaking data or hiding records that should be visible.
    - **Evidence:**
        ```php
        // app/Policies/CommissionPolicy.php - untested methods
        public function view(Professional $actor, Model $record): bool|Response
        {
            // ...
            // Brand team member with financial read capability (UNTESTED PATH)
            if ($this->brandAccess->canReadBrandFinancialAnalytics($actor, $brandId)) {
                return true;
            }
            // ...
        }

        public function viewProjections(Professional $pro, BrandAffiliateRollup $skeleton): bool
        public function viewOwnTransactions(Professional $pro, CommissionPayout $skeleton): bool
        public function update(Professional $actor, Model $record): bool|Response
        public function delete(Professional $actor, Model $record): bool|Response
        public function startConnect(Professional $actor, Professional $pro): bool
        ```
        ```php
        // tests/Feature/Policies/CommissionPolicyAbilityTest.php — only covers these methods:
        //   viewOwnPayouts (6 tests)
        //   managePaymentMethod (4 tests)
        //   manageWallet (4 tests)
        // tests/Feature/Policies/CommissionPolicyTest.php — only:
        //   managePaymentMethod allow + deny (2 tests)
        ```
    - `[DRAFT, confidence: 0.95]`

- [ ] **#TEST-2** · P1 — LoadCurrentProfessional middleware has zero test coverage
    - **Where:** app/Http/Middleware/Context/LoadCurrentProfessional.php
    - **Affects:** Every authenticated request. This middleware gates the bootstrap flow (new sign-ups), email sync, collision handling, and suspended-account blocking.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add `it('returns 401 when supabase_uid is missing', ...)` and `it('returns 401 when uid is not a UUID', ...)`.
        - Add `it('returns 403 with bootstrap_required when no professional exists', ...)` — this is the post-signup resume path.
        - Add `it('returns 403 when account is suspended', ...)`.
        - Add `it('syncs primary_email from verified JWT claims', ...)`.
        - Add `it('handles email sync UniqueConstraintViolation gracefully', ...)`.
    - **Technical:** This middleware runs on every single authenticated request after supabase.jwt. It resolves the professional via cache, enforces account status, and reconciles primary_email on drift. A regression here — e.g., the bootstrap_required error shape changing — would silently break the frontend sign-up flow because the SPA relies on that exact JSON structure to route users back into the "about" step. The email-sync collision path (`UniqueConstraintViolationException`) is completely untested; a production collision would log a warning but no test proves it doesn't 500.
    - **Plain English:** Every time someone logs into Partna, this middleware looks up their account, checks it's not suspended, and silently fixes their email if it changed in Supabase. There are zero automated tests for any of these steps. If a future change accidentally returns the wrong error code for a half-finished sign-up, the frontend will dead-end users at a blank screen instead of sending them back into the sign-up flow.
    - **Evidence:**
        ```php
        // app/Http/Middleware/Context/LoadCurrentProfessional.php
        // Critical untested paths:
        if (! $professional) {
            // Verified auth user with no Partna profile — they bailed mid-signup
            return response()->json([
                'error' => 'bootstrap_required',
                'message' => 'Finish setting up your Partna account.',
            ], 403);
        }

        // Email sync with collision handling:
        try {
            $professional->primary_email = $claimedEmail;
            $professional->save();
        } catch (UniqueConstraintViolationException $e) {
            Log::warning('LoadCurrentProfessional email sync collision', [...]);
        }
        ```
    - `[DRAFT, confidence: 0.95]`

- [ ] **#TEST-3** · P1 — VerifyShopifySessionToken middleware has zero test coverage
    - **Where:** app/Http/Middleware/Auth/VerifyShopifySessionToken.php
    - **Affects:** Every Shopify embedded-app route. JTI replay protection, JWT validation (9 distinct rejection reasons), tenant resolution, lenient mode for connect-account flow.
    - **Effort:** L (~1–2d)
    - **What to do:**
        - Add `it('rejects with token_missing when no Authorization header', ...)`.
        - Add `it('rejects with sig_invalid on bad signature', ...)`.
        - Add `it('rejects with aud_mismatch when aud != api_key', ...)`.
        - Add `it('rejects with dest_invalid for non-myshopify dest', ...)`.
        - Add `it('rejects with iss_mismatch when iss != dest', ...)`.
        - Add `it('rejects with jti_missing when no jti claim', ...)`.
        - Add `it('rejects with cache_unavailable (503) when Redis is down', ...)`.
        - Add `it('rejects with jti_replay on repeated use', ...)`.
        - Add `it('rejects with shop_unlinked (404) when no professional matches', ...)`.
        - Add `it('lenient mode skips shop resolution and sets domain only', ...)`.
        - Add `it('allows up to jti_max_uses within the 120s window', ...)`.
        - Add `it('returns 500 when api_key/api_secret config is missing', ...)`.
    - **Technical:** This middleware has 9 distinct rejection codes, JTI atomic-counter replay protection via Redis Lua, lenient vs default mode, and a JWT::$leeway save/restore pattern to prevent clock-skew bleed. The JTI Lua script (`INCR + conditional EXPIRE`) is non-trivial — a test suite that overrides `jti_max_uses` to 1 and fires two requests with the same JWT is the only way to prove replay protection works across both Redis and array-cache fallback paths. Without tests, a refactor of the Cache facade or the Lua script could silently weaken replay protection to none.
    - **Plain English:** When Shopify merchants use the Partna embedded app, every request carries a Shopify-signed token. This middleware checks the token is real, hasn't expired, hasn't been replayed, and maps to the right Partna account. It has nine different ways to reject a bad request. None of these nine are tested. It's like having a security checkpoint with nine inspection stations, and zero security cameras to confirm the guards are actually checking IDs.
    - **Evidence:**
        ```php
        // app/Http/Middleware/Auth/VerifyShopifySessionToken.php — 9 rejection codes, all untested
        //   1. token_missing       — no Authorization header
        //   2. sig_invalid         — JWT::decode threw
        //   3. aud_mismatch        — aud != SHOPIFY_API_KEY
        //   4. dest_invalid        — dest host does not end .myshopify.com
        //   5. iss_mismatch        — iss host != dest host
        //   6. jti_missing         — no jti claim
        //   7. cache_unavailable   — JTI counter increment threw (503)
        //   8. jti_replay          — jti use-count exceeded max
        //   9. shop_unlinked       — no professional linked
        //
        // JTI Lua script — atomic counter on Redis:
        $script = <<<'LUA'
        local current = redis.call('INCR', KEYS[1])
        if current == 1 then
            redis.call('EXPIRE', KEYS[1], ARGV[1])
        end
        return current
        LUA;
        ```
    - `[DRAFT, confidence: 0.95]`

- [ ] **#TEST-4** · P1 — VerifySupabaseEmailHookSignature middleware has zero test coverage
    - **Where:** app/Http/Middleware/Auth/VerifySupabaseEmailHookSignature.php
    - **Affects:** POST /internal/email-hooks/supabase — the endpoint Supabase calls to deliver send-email-hook events. Signature bypass means forged email events.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `it('returns 503 when email_hook_secret config is empty', ...)`.
        - Add `it('returns 401 when signature header is missing or invalid', ...)`.
        - Add `it('passes through with valid webhook-id/timestamp/signature', ...)`.
    - **Technical:** This middleware uses the Standard Webhooks HMAC verification pattern (webhook-id + webhook-timestamp + webhook-signature headers). It delegates verification to `SupabaseEmailHookSignatureVerifier`. A misconfigured deploy (missing secret) returns 503 — but no test asserts this 503 response shape, so a frontend or monitoring system relying on it has no contract guarantee. The signature-pass and signature-fail paths are both untested; a regression in the verifier service would go undetected until production webhook deliveries start failing.
    - **Plain English:** Supabase sends Partna an email-delivery webhook with a cryptographic signature to prove it's really from Supabase. This middleware checks that signature. There are zero tests for it. If someone accidentally changes the signature library or the secret config key, forged email events could be accepted — or real ones could be rejected — with no test to catch it.
    - **Evidence:**
        ```php
        // app/Http/Middleware/Auth/VerifySupabaseEmailHookSignature.php
        $valid = $this->verifier->verify(
            configuredSecret: $secret,
            webhookId: $webhookId,
            webhookTimestamp: $webhookTimestamp,
            webhookSignatureHeader: $webhookSignature,
            rawBody: $rawBody,
        );

        if (! $valid) {
            return response()->json([
                'error' => true,
                'message' => 'Invalid webhook signature.',
            ], 401);
        }
        // No corresponding test file for this middleware exists in the provided test suite.
        ```
    - `[DRAFT, confidence: 0.95]`

- [ ] **#TEST-5** · P1 — BrandFundingGate middleware has zero test coverage
    - **Where:** app/Http/Middleware/BrandFundingGate.php
    - **Affects:** Brand-side affiliate-invite write endpoints. A broken gate means brands without payment methods could send invites, leaving the platform holding the float for commission payouts.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `it('passes through when brand has payment method', ...)`.
        - Add `it('returns 402 with brand_funding_required code when no payment method', ...)`.
        - Add `it('passes through for non-brand professionals', ...)`.
        - Add `it('returns 402 with structured payload including connect_path', ...)`.
    - **Technical:** This middleware gates invite creation on `stripe_payment_method_id` being non-null via `StripeConnectService::brandHasPaymentMethod()`. The 402 response carries a structured `code: 'brand_funding_required'` payload that the dashboard reads to render a funding-gate dialog. If the response shape changes (e.g., code key renamed or connect_path dropped), the frontend breaks silently — no toast, no redirect, just a dead invite button. The non-brand pass-through path is also untested; if a staff JWT accidentally hits an invite route and the middleware started rejecting it, a regression test would catch it.
    - **Plain English:** Before a brand can send affiliate invites, Partna checks they have a card on file — because every sale that affiliate makes becomes a commission the brand has to pay. This check is the bouncer at the door. There are zero tests for the bouncer. If the "payment required" response format changes, the dashboard button just stops working with no error message.
    - **Evidence:**
        ```php
        // app/Http/Middleware/BrandFundingGate.php
        if ($this->connectService->brandHasPaymentMethod($professional)) {
            return $next($request);
        }

        return response()->json([
            'message' => 'A payment method is required before sending affiliate invites.',
            'code' => 'brand_funding_required',
            'data' => [
                'reason' => 'no_payment_method',
                'connect_path' => '/account/settings?section=payments',
            ],
        ], 402);
        // No test file provided for this middleware.
        ```
    - `[DRAFT, confidence: 0.95]`

- [ ] **#TEST-6** · P1 — VerifyHydrogenApiKey production fail-closed behavior untested
    - **Where:** app/Http/Middleware/Auth/VerifyHydrogenApiKey.php:14-19
    - **Affects:** All `/internal/hydrogen/*` routes — deployment tokens, brand storefront config rewrite endpoint.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `it('bypasses in local/testing when api_key config is empty', ...)`.
        - Add `it('throws RuntimeException in production when api_key config is empty', ...)`.
        - Add `it('returns 403 when header is missing', ...)`.
        - Add `it('returns 403 when header does not match', ...)`.
        - Add `it('passes through when header matches configured key', ...)`.
    - **Technical:** Commit `4416acf4` (F6) fixed the P0 silent-bypass bug where an empty config env var would open every `/internal/hydrogen/*` route. The fix gates the bypass on `app()->environment(['local', 'testing'])` and throws in production. But the test that verifies this fix (this is the behavior that must never regress) does not appear in the provided test files. A single env-var misconfig on a production deploy would still open these routes — the fix is code, not a test. The test is the safety net.
    - **Plain English:** This was already flagged as a top-priority security issue (the Hydrogen API key bypass). The code fix is in place, but there's no test that proves it stays fixed. It's like installing a deadbolt but never checking that it actually latches after you close the door. One configuration mistake on a deploy and those internal endpoints go wide open again — and no test fails to warn you.
    - **Evidence:**
        ```php
        // app/Http/Middleware/Auth/VerifyHydrogenApiKey.php — the P0 fix, untested
        if ($expected === '') {
            if (app()->environment(['local', 'testing'])) {
                return $next($request);
            }

            throw new \RuntimeException(
                'services.hydrogen.api_key is not configured — refusing to fall through to bypass outside local/testing.'
            );
        }
        // No test file in the provided tests asserts the production-throw path
        // or exercises the happy-path hash_equals comparison.
        ```
    - `[DRAFT, confidence: 0.95]`

- [ ] **#TEST-7** · P2 — SitePolicy has zero test coverage including complex SiteMedia ownership resolution
    - **Where:** app/Policies/SitePolicy.php
    - **Affects:** All CRUD on Site, Block, SiteMedia, Enquiry, SiteSubdomainAlias, LeadSubmission. The SiteMedia/SubdomainAlias ownership resolution path has a subtle spoofing-prevention check.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add `it('view: site owner can view', ...)` + `it('view: non-owner gets 404', ...)` for Site, Block, SiteMedia, SiteSubdomainAlias each.
        - Add `it('update: pending_deletion owner gets 423', ...)`.
        - Add `it('SiteMedia: resolves ownership via preloaded site relation', ...)`.
        - Add `it('SiteMedia: blocks spoofed site relation where site_id does not match', ...)` — the setRelation injection defense.
        - Add `it('SiteSubdomainAlias: same ownership resolution as SiteMedia', ...)`.
    - **Technical:** SitePolicy's `resolveOwnerId` has a two-layer defense for SiteMedia and SiteSubdomainAlias: it requires the caller to `setRelation('site', $site)` to avoid N+1, AND it cross-checks that the resource's `site_id` matches the preloaded site's `id`. This prevents an attacker from injecting a site they own to spoof access to another owner's resource. This cross-check is non-obvious and has zero test coverage — if a refactor drops the `site_id` comparison, the ownership check silently degrades to trusting whatever site is preloaded.
    - **Plain English:** The Site policy handles access to a brand's website content — pages, blocks, images, subdomains. For some of these (images and subdomains), ownership is determined indirectly through the parent site record, and there's a double-check to prevent a clever attack where someone pretends to own a resource by injecting a fake parent. None of this is tested. If someone refactors the image-upload code and accidentally removes the double-check, one brand could potentially see another brand's uploaded images.
    - **Evidence:**
        ```php
        // app/Policies/SitePolicy.php — untested spoofing defense
        if ($resource instanceof SiteMedia || $resource instanceof SiteSubdomainAlias) {
            $site = $resource->getRelation('site');
            if (! $site) {
                return null;
            }

            // Confirm the resource's site_id matches the preloaded site's id
            $resourceSiteId = $resource->getAttributes()['site_id'] ?? null;
            if ($resourceSiteId === null || (string) $resourceSiteId !== (string) $site->id) {
                return null;
            }

            return (string) $site->professional_id;
        }
        ```
    - `[DRAFT, confidence: 0.90]`

- [ ] **#TEST-8** · P2 — BrandPartnerLinkPolicy has zero test coverage
    - **Where:** app/Policies/BrandPartnerLinkPolicy.php
    - **Affects:** BrandPartnerLink, BrandPartnerLinkEvent (immutable audit log), BrandAffiliateInvite — the link/invite system connecting brands and affiliates.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add `it('view: brand owner can view their link', ...)` + `it('view: linked affiliate can view', ...)` + `it('view: unrelated actor gets 404', ...)`.
        - Add `it('create: only brand owner can create a link skeleton', ...)` + `it('create: pending_deletion brand gets 423', ...)`.
        - Add `it('update: brand owner can update', ...)` + `it('update: BrandPartnerLinkEvent is immutable (denyAsNotFound)', ...)`.
        - Add `it('view: BrandAffiliateInvite uses claimed_professional_id for affiliate side', ...)`.
    - **Technical:** This policy covers three models: BrandPartnerLink (brand writes, both sides read), BrandPartnerLinkEvent (append-only, no writes), and BrandAffiliateInvite (uses `claimed_professional_id` instead of `affiliate_professional_id`). The `resolveAffiliateId` private method handles the field-name difference — a regression that breaks the invite read path for claimed professionals would have no test to catch it. The audit-log immutability check (`$record instanceof BrandPartnerLinkEvent → denyAsNotFound`) is also untested.
    - **Plain English:** When a brand sends an affiliate invite or establishes a partnership link, this policy controls who can see and modify those records. The rules are: brand can write, both sides can read, and the audit trail is read-only forever. None of these rules have automated tests. If an invite recipient can't see their own invite after claiming it, that's a broken onboarding flow — and no test would flag it.
    - **Evidence:**
        ```php
        // app/Policies/BrandPartnerLinkPolicy.php — all untested
        public function view(Professional $actor, Model $record): bool|Response { ... }
        public function create(Professional $actor, BrandPartnerLink $skeleton): bool|Response { ... }
        public function update(Professional $actor, Model $record): bool|Response { ... }
        public function delete(Professional $actor, Model $record): bool|Response { ... }

        private function resolveAffiliateId(Model $record): string
        {
            return (string) ($record->affiliate_professional_id
                ?? $record->claimed_professional_id
                ?? '');
        }
        ```
    - `[DRAFT, confidence: 0.90]`

- [ ] **#TEST-9** · P2 — NotificationPolicy has zero test coverage including global-notification broadcast logic
    - **Where:** app/Policies/NotificationPolicy.php
    - **Affects:** All notification CRUD — Notification, NotificationEmailPreference, NotificationEmailPolicy, NotificationReceipt, EmailSubscription. The global-notification path (professional_id = null) has special semantics.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add `it('view: owner can view their targeted notification', ...)` + `it('view: non-owner gets 404', ...)`.
        - Add `it('view: global notification (null professional_id) is visible to all', ...)`.
        - Add `it('update: global notification is immutable (denyAsNotFound)', ...)` — no owner to authorize writes.
        - Add `it('update: pending_deletion actor gets 423', ...)`.
        - Add `it('view: NotificationEmailPreference follows standard ownership', ...)`.
    - **Technical:** The `view` method has a special early-return for global notifications: `if ($resource instanceof Notification && $resource->professional_id === null) return true;`. The `update` method explicitly denies mutations on global notifications with `denyAsNotFound()`. If someone adds a new notification subtype in the future and the `instanceof Notification` check stops matching, global notifications would become invisible to everyone — or writable by anyone. Neither regression would be caught.
    - **Plain English:** Notifications can be personal (targeted to one user) or global (broadcast to everyone, like a platform announcement). The rules say: everyone can see global notifications, nobody can edit them, and personal notifications are private. None of this is tested. If a code change accidentally makes global notifications behave like personal ones, they'd disappear from everyone's inbox.
    - **Evidence:**
        ```php
        // app/Policies/NotificationPolicy.php
        public function view(Professional $actor, Model $resource): bool|Response
        {
            // Global notifications (null professional_id) are visible to all.
            if ($resource instanceof Notification && $resource->professional_id === null) {
                return true;  // UNTESTED
            }
            // ...
        }

        public function update(Professional $actor, Model $resource): bool|Response
        {
            // Global notifications have no single owner — deny all mutations.
            if ($resource instanceof Notification && $resource->professional_id === null) {
                return $this->denyAsNotFound();  // UNTESTED
            }
            // ...
        }
        ```
    - `[DRAFT, confidence: 0.90]`

- [ ] **#TEST-10** · P2 — CustomerPolicy has zero test coverage
    - **Where:** app/Policies/CustomerPolicy.php
    - **Affects:** All CRUD on Customer records — the professional's client/customer list.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `it('view: owner can view their customer', ...)` + `it('view: non-owner gets 404', ...)`.
        - Add `it('create: owner can create for self', ...)` + `it('create: cannot create for another professional', ...)`.
        - Add `it('update: pending_deletion owner gets 423', ...)`.
        - Add `it('delete: pending_deletion owner gets 423', ...)`.
    - **Technical:** Standard direct-ownership policy pattern (Shape A in the codebase doctrine). While simple, it still needs tests to enforce the denyAsNotFound contract — the CLAUDE.md spec is that denied-because-not-yours must 404, not 403. Without a test asserting the 404 status code, a refactor to BasePolicy or the Response helper could accidentally change the status and leak resource existence across tenants.
    - **Plain English:** Customer records belong to one professional. The policy says: if you don't own the customer, the API should say "not found" (404) rather than "forbidden" (403) — that way someone can't probe whether a customer ID exists in another account. There are no tests for this policy, so a code change could accidentally switch 404 to 403 and create an information leak.
    - **Evidence:**
        ```php
        // app/Policies/CustomerPolicy.php
        public function view(Professional $actor, Customer $customer): bool|Response
        {
            if ((string) $customer->professional_id !== (string) $actor->id) {
                return $this->denyAsNotFound();  // UNTESTED — 404 contract
            }
            return true;
        }
        // create, update, delete — all untested
        ```
    - `[DRAFT, confidence: 0.90]`

- [ ] **#TEST-11** · P2 — IntegrationPolicy has zero test coverage
    - **Where:** app/Policies/IntegrationPolicy.php
    - **Affects:** Shopify/Fresha/Square OAuth credential management. Team members with `canManageShopify` capability, and the pending_deletion guard.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `it('view: integration owner can view', ...)` + `it('view: team member with Shopify manage can view', ...)` + `it('view: unrelated actor gets 404', ...)`.
        - Add `it('manage: pending_deletion owner gets 423', ...)`.
        - Add `it('manage: team member can manage (disconnect/sync)', ...)`.
        - Add `it('manage: non-owner, non-team gets denyAsNotFound', ...)`.
    - **Technical:** Uses BrandAccessService delegation similar to CommissionPolicy, but for the `canManageShopify` capability. The `actorCanReachOwner` private method handles both direct ownership and team delegation. Without tests, a capability-name refactor (e.g., renaming `CAPABILITY_SHOPIFY_MANAGE`) or a change to BrandAccessService would silently break integration access for team members — and because integrations are infrequently managed (connect once, rarely touch), the break would go unnoticed for weeks.
    - **Plain English:** When a brand connects their Shopify store, both the brand owner and any team members with Shopify management permissions should be able to view and manage that connection. These permissions are enforced by this policy, and there are zero tests. If someone renames the "manage Shopify" permission key, team members would silently lose access to the integration settings — and since integrations are set up once and rarely touched, nobody would notice for a long time.
    - **Evidence:**
        ```php
        // app/Policies/IntegrationPolicy.php
        private function actorCanReachOwner(Professional $actor, ProfessionalIntegration $integration): bool|Response
        {
            // ...
            return $this->brandAccess->canManageShopify($actor, $ownerId);  // UNTESTED
        }
        ```
    - `[DRAFT, confidence: 0.90]`

- [ ] **#TEST-12** · P2 — ServicePolicy has zero test coverage
    - **Where:** app/Policies/ServicePolicy.php
    - **Affects:** All CRUD on Service and ServiceCategory records (the booking/product catalog).
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `it('view: owner can view', ...)` + `it('view: non-owner gets 404', ...)`.
        - Add `it('create: owner can create for self', ...)` + `it('create: pending_deletion gets 423', ...)`.
        - Add `it('update: pending_deletion owner gets 423', ...)`.
        - Add `it('delete: pending_deletion owner gets 423', ...)`.
    - **Technical:** Standard direct-ownership Shape A policy. Uses `Model` type-hint to cover both Service and ServiceCategory with one policy class. The comment in the file notes "Narrowing to concrete types would require separate policies." If someone splits this into two policies without migrating tests, the gap wouldn't be visible. A test that exercises both model types through the same policy would catch this.
    - **Plain English:** Services and service categories (the booking catalog) are owned by a professional. The ownership rules are straightforward — you can see and edit your own, nobody else's. But there are no tests confirming this. A future refactor that splits services and categories into separate policies could accidentally drop authorization on one of them.
    - **Evidence:**
        ```php
        // app/Policies/ServicePolicy.php — covers Service + ServiceCategory with Model type-hint
        public function view(Professional $actor, Model $resource): bool|Response { ... }
        public function create(Professional $actor, Model $skeleton): bool|Response { ... }
        public function update(Professional $actor, Model $resource): bool|Response { ... }
        public function delete(Professional $actor, Model $resource): bool|Response { ... }
        ```
    - `[DRAFT, confidence: 0.90]`

- [ ] **#TEST-13** · P2 — EnforcePendingDeletionReadOnly middleware has zero test coverage
    - **Where:** app/Http/Middleware/Context/EnforcePendingDeletionReadOnly.php
    - **Affects:** Every write request from a user whose account is pending deletion. The 423 response payload drives the frontend cancellation prompt.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `it('allows GET/HEAD/OPTIONS through for pending_deletion account', ...)`.
        - Add `it('blocks POST/PATCH/PUT/DELETE with 423 for pending_deletion', ...)`.
        - Add `it('returns deletes_at timestamp in the 423 response', ...)`.
        - Add `it('passes through all methods for active account', ...)`.
    - **Technical:** The middleware reads `deletion_confirmed_at` from the professional model, adds `soft_delete_retention_days` (default 30), and returns the ISO 8601 `deletes_at` timestamp. The frontend uses this to render a "your account will be deleted on [date]" prompt with a cancel button. If the `deletes_at` field format changes (e.g., from ISO 8601 to Unix timestamp), the frontend date parser breaks silently. The `confirmedAt instanceof \DateTimeInterface` and `is_string` branches both need coverage.
    - **Plain English:** When someone requests account deletion, there's a 30-day grace period where they can still read their data but can't make changes. The frontend shows them exactly when deletion will happen. There are no tests for the middleware that enforces this. If the date format changes, the frontend would show a broken date — or worse, block the cancel button.
    - **Evidence:**
        ```php
        // app/Http/Middleware/Context/EnforcePendingDeletionReadOnly.php
        return response()->json([
            'message' => 'Account is pending deletion.',
            'pending_deletion' => true,
            'deletes_at' => $deletesAt,  // UNTESTED — frontend depends on this format
        ], 423);
        ```
    - `[DRAFT, confidence: 0.90]`

- [ ] **#TEST-14** · P3 — JWKS-success path in VerifySupabaseJwt not tested for claims exposure
    - **Where:** app/Http/Middleware/Auth/VerifySupabaseJwt.php, tests/Feature/Auth/VerifySupabaseJwtClaimsExposureTest.php
    - **Affects:** AAL step-up, fresh-MFA checks, session-ID tracking — all of which depend on claims being set on the request attributes by the JWKS path.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Generate a test RSA/EC key pair and sign a JWT to exercise the JWKS path end-to-end, or mock `resolveSigningKey` to return a known Key so claims flow through `setSupabaseContext`.
        - Add `it('exposes aal, amr, and session_id from JWKS-decoded claims', ...)` to replace the current self-documented gap.
    - **Technical:** The existing test acknowledges this gap explicitly: "Because our test JWT uses HS256, the JWKS path will throw and we fall through to the auth-server path. To test the JWKS-path attribute-setting we call handle() with a real asymmetric JWT instead — but that requires a key pair." The test then only covers the auth-server fallback (which defaults aal to aal1 and amr to []). The production JWKS path is the primary path — all AAL2/MFA enforcement depends on claims being correctly promoted to request attributes by `setSupabaseContext`. A regression in the claims-to-attributes mapping would go undetected.
    - **Plain English:** The JWT verification has two code paths: one for production (using cryptographic keys) and one for legacy fallback. Only the legacy fallback is tested. The production path is the one that actually reads multi-factor-authentication status from the token. If a code change accidentally stops reading the MFA claims, all the "require MFA" checks would silently treat everyone as unverified — and no test would catch it because the test only exercises the fallback path.
    - **Evidence:**
        ```php
        // tests/Feature/Auth/VerifySupabaseJwtClaimsExposureTest.php — self-documented gap
        it('exposes aal, amr, and session_id on the request attributes when the JWKS path sets claims', function () {
            // On the JWKS-success path the claims array is passed to setSupabaseContext.
            // We simulate this by calling the middleware and inspecting what it sets on
            // the request after a successful JWKS decode. Because our test JWT uses HS256,
            // the JWKS path will throw and we fall through to the auth-server path. To
            // test the JWKS-path attribute-setting we call handle() with a real
            // asymmetric JWT instead — but that requires a key pair.
            //
            // [falls through to test the auth-server fallback instead]
        });
        ```
    - `[DRAFT, confidence: 0.85]`
