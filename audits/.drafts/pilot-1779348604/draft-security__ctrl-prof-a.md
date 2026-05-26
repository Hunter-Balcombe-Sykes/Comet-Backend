- [ ] **#SEC-1** · P2 — BrandAffiliateController index() exposes affiliate PII (email, phone) in list response
    - **Where:** app/Http/Controllers/Api/Professional/Brand/BrandAffiliateController.php:89-90
    - **Affects:** Brand dashboard users viewing the connected-affiliates list. Email and phone are returned for every affiliate regardless of whether the list view needs them.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Split response shape: the list endpoint should omit `email` and `phone`; add a dedicated detail endpoint or optional `?include=contact` param gated by a Policy ability.
        - Ensure the `snapshot()` method already avoids exposing email/phone — confirm caller-side parity.
    - **Technical:** The index() method maps every BrandPartnerLink into an array that includes `primary_email ?? public_contact_email` and `phone ?? public_contact_number`. These are PII fields returned in a list endpoint with no opt-in mechanism. The snapshot() endpoint correctly omits them. The canonical fix is a Resource class split (`BrandAffiliateListResource` vs `BrandAffiliateDetailResource`) or a conditional include.
    - **Plain English:** The "list of your affiliates" page returns everyone's email and phone number, even though the dashboard only needs names and handles to render the list. It's like printing every employee's personal contact details on the company directory posted in the lobby — convenient but unnecessary exposure. The fix is to show contact info only when someone clicks into a specific affiliate's detail card.
    - **Evidence:**
        ```php
        'email' => $connectedProfessional?->primary_email ?? $connectedProfessional?->public_contact_email,
        'phone' => $connectedProfessional?->phone ?? $connectedProfessional?->public_contact_number,
        ```

- [ ] **#SEC-2** · P1 — StatsController uses inline role-gating instead of a Policy ability
    - **Where:** app/Http/Controllers/Api/Professional/Analytics/StatsController.php:35-40
    - **Affects:** Cross-role data access — a partner calling `?role=brand` or a brand calling `?role=affiliate` is blocked inline but without a Policy that can be tested, reused, or audited centrally.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a Policy ability (e.g. `viewStatsAsRole`) on the Professional model that checks `account_type` against the requested role.
        - Replace the inline `if/else` + `response()->json(['error' => 'cross_role'], 403)` with `$this->authorizeForUser($pro, 'viewStatsAsRole', $pro)`.
    - **Technical:** Per the Partna Authorization Doctrine, authorization decisions must live in Policies, never inline. The current inline check produces the correct 403 outcome but is invisible to static analysis, can't be unit-tested in isolation, and sets a pattern that encourages copy-paste inline gating in future controllers. The `role` parameter is user-supplied via query string — a Policy gates it server-side against the resolved actor's account_type.
    - **Plain English:** There's a guard at the analytics door that checks "are you a brand checking brand stats, or an affiliate checking affiliate stats?" It works, but the guard is a handwritten note taped to the door instead of being part of the building's security system. If someone copies this pattern to a new endpoint and forgets one branch, the door is unlocked. Move the check into the central security registry.
    - **Evidence:**
        ```php
        $role = (string) $request->input('role');
        $isBrand = $pro->isBrand();
        if ($role === 'brand' && ! $isBrand) {
            return response()->json(['error' => 'cross_role'], 403);
        }
        if ($role === 'affiliate' && $isBrand) {
            return response()->json(['error' => 'cross_role'], 403);
        }
        ```

- [ ] **#SEC-3** · P2 — ProfessionalLinkBlockController uses inline abort_unless for capability authorization
    - **Where:** app/Http/Controllers/Api/Professional/SiteManagement/ProfessionalLinkBlockController.php:72-76
    - **Affects:** Partners/individuals on account types where custom_links_allowed is false — the check works but bypasses the Policy system.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Move the `custom_links_allowed` check into a Policy ability on `Block` (e.g. `createCustomLink`) that reads the config per account type.
        - Replace the `abort_unless(config(...), 403, ...)` in `authorizeCustomLinks()` with `$this->authorizeForUser($pro, 'createCustomLink', Block::class)`.
    - **Technical:** The Partna Authorization Doctrine requires all authorization through Policies. The current `abort_unless` gate checks a config value keyed by account_type — it's a capability check, not an ownership check, but it's still an authorization decision made outside the Policy layer. Moving it into a Policy makes it centrally testable, prevents copy-paste drift, and ensures the same gate applies if this capability is exposed via other controllers or queued jobs.
    - **Plain English:** The "custom links" feature has a config toggle that says which account types can use it. Right now that toggle is checked by a one-off line of code inside the link controller. If a second controller ever needs to check the same thing (e.g., a bulk-import endpoint), someone has to remember to copy that line. Putting it in the Policy means every door that leads to custom links checks the same lock automatically.
    - **Evidence:**
        ```php
        abort_unless(
            (bool) config("partna.account_type_defaults.{$type}.custom_links_allowed", false),
            403,
            'Custom links are not available on your account type.'
        );
        ```

- [ ] **#SEC-4** · P2 — ShopifyIntegrationController::resolveShop makes outbound HTTP requests to user-supplied domains (SSRF surface)
    - **Where:** app/Http/Controllers/Api/Professional/Brand/ShopifyIntegrationController.php:1181-1264 (discoverShopifyHandle method chain)
    - **Affects:** Any authenticated brand or staff member — the endpoint fetches the homepage HTML of an arbitrary user-supplied domain.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add a configurable allow-list of permitted TLD suffixes (e.g. `.com`, `.com.au`, `.co.uk`) and reject domains outside that list before DNS resolution.
        - Rate-limit the endpoint (`throttle:10,1` — 10 requests per minute per professional) to prevent it being used as a port-scan oracle or DoS amplifier.
        - Log every resolution attempt with the actor professional ID for auditability.
    - **Technical:** While the method has strong SSRF hardening — `isPrivateHost()` blocks RFC1918/loopback/link-local, `CURLOPT_RESOLVE` pins DNS to prevent TOCTOU rebinding, and `allow_redirects => false` prevents redirect-based SSRF — it still makes an authenticated outbound HTTP GET to any public-IP host the caller supplies. An attacker with valid brand credentials could use this to probe public services behind Cloudflare, scan ports by timing response differences, or map internal infrastructure if any public-facing endpoint reflects internal state. The hardening is excellent defense-in-depth but doesn't close the "authenticated user → arbitrary external HTTP" trust boundary.
    - **Plain English:** The "find my Shopify store" feature lets a logged-in brand type any web address and our server will go fetch that page. We've added strong locks — it won't fetch internal company servers and won't follow redirects — but it will still fetch any public website on the internet. A malicious user who has a brand account (or steals one) could use this to probe other services. Adding a rate limit and restricting to common domain endings closes that gap.
    - **Evidence:**
        ```php
        $url = "https://{$host}/";
        // ...
        $response = Http::timeout(6)
            ->connectTimeout(4)
            ->withOptions(array_filter([
                'allow_redirects' => false,
                'curl' => $curlOptions !== [] ? $curlOptions : null,
            ]))
            ->get($url);
        ```

- [ ] **#SEC-5** · P2 — Shopify connect/disconnect endpoints lack rate limiting
    - **Where:** app/Http/Controllers/Api/Professional/Brand/ShopifyIntegrationController.php:234 (connect) and :378 (disconnect)
    - **Affects:** Brand accounts — connect/disconnect can be called repeatedly with no throttle, enabling token-stuffing attacks on connect and DoS-style disconnect cycles that trigger Shopify API quota consumption.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `throttle:5,1` middleware (5 attempts per minute) to the connect and disconnect routes.
        - Add `throttle:10,1` to the resolveShop route.
        - Consider per-professional rate limiting via `RateLimiter` for the Shopify webhook re-registration endpoint (`registerWebhooks`).
    - **Technical:** The connect endpoint accepts an `access_token` parameter and upserts a ProfessionalIntegration row — a malicious actor could stuff tokens to probe validity or exhaust the Shopify API rate limit through the cascade of post-connect jobs (RegisterShopifyWebhooksJob, CreateStorefrontAccessTokenJob, etc.). The disconnect endpoint triggers a Shopify API teardown sweep that consumes quota. Neither endpoint has throttle middleware. The ResyncController already implements `RateLimiter::tooManyAttempts` — the same pattern should apply here.
    - **Plain English:** Connecting or disconnecting a Shopify store triggers a cascade of API calls to Shopify — creating webhooks, generating tokens, setting up collections. Right now there's no speed limit on these actions. A buggy frontend (or a malicious script) could hammer the connect button and burn through our Shopify API quota for that store, degrading service for other brands. Adding a simple "5 tries per minute" cap prevents that.
    - **Evidence:**
        ```php
        // No throttle middleware reference in connect() or disconnect() method bodies
        // or their route definitions (not shown, but no rate-limiter calls present).
        public function connect(Request $request): JsonResponse
        {
            // ...
            $integration = ProfessionalIntegration::query()->updateOrCreate(
                // stores access_token, triggers post-connect jobs
            );
        ```

- [ ] **#SEC-6** · P3 — BrandAffiliateInviteController has redundant inline isBrand() checks alongside brand.only middleware
    - **Where:** app/Http/Controllers/Api/Professional/Brand/BrandAffiliateInviteController.php:56, 120, 177, 210, 240, 290
    - **Affects:** No user impact — defense-in-depth that adds noise but doesn't change authorization outcome.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Remove the inline `if (! $professional->isBrand()) { return $this->error(..., 403); }` blocks from `index()`, `store()`, `availability()`, `bulk()`, `importCsv()`, and `destroy()`.
        - The `brand.only` middleware already guarantees the actor is a brand — the inline checks are dead code on the happy path.
    - **Technical:** Every method in this controller (except `claim` and `decline`, which are affiliate-facing) performs an inline `isBrand()` check after the `brand.only` middleware has already rejected non-brand requests. This is harmless but violates the "brand-only routes use brand.only middleware, not inline professional_type checks" clause of the Partna Authorization Doctrine. It also creates a maintenance hazard: if the middleware is ever changed, the inline checks might diverge in behavior, producing confusing double-gating.
    - **Plain English:** This controller has two bouncers at the door checking the same ID. The first bouncer (middleware) already turns away anyone who isn't a brand. The second bouncer (inline code) checks again. It's not harmful — nobody gets in who shouldn't — but it's confusing for the next person who reads the code and wonders which bouncer is the real one. Remove the duplicate.
    - **Evidence:**
        ```php
        // Occurring in index(), store(), availability(), bulk(), importCsv(), destroy():
        if (! $professional->isBrand()) {
            return $this->error('Only brand accounts can view affiliate invites.', 403);
        }
        // ... while class docblock states:
        // All routes are gated by `brand.only` middleware — non-brand accounts never reach these methods.
        ```

- [ ] **#SEC-7** · P3 — ShopifyEmbeddedConnectionController generate() has no rate limit on connection-code creation
    - **Where:** app/Http/Controllers/Api/Professional/Brand/ShopifyEmbeddedConnectionController.php:32-35
    - **Affects:** Brand accounts — a single brand could generate thousands of 32-char codes, filling the Redis cache with unused entries (30-min TTL each).
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `throttle:10,5` middleware (10 connection codes per 5 minutes) to the generate route.
        - Alternatively, use `RateLimiter` with a per-professional key and a 5-minute decay.
    - **Technical:** Each call to `generate()` writes a `shopify:embed:connect:{code}` key to Redis with a 30-minute TTL. With no rate limiting, a malicious or buggy client could fill the cache with millions of dead entries. The 30-minute TTL provides natural cleanup, but a flood of writes within a short window could still pressure Redis memory. This is a self-DoS vector, not a cross-tenant issue — no other brand is affected since the codes are keyed by random value, not tenant.
    - **Plain English:** Every time a brand clicks "connect my Shopify store," we generate a random one-time code and store it for 30 minutes. There's nothing stopping someone from clicking that button a thousand times and filling up the temporary storage with useless codes. Adding a simple rate limit — "10 codes per 5 minutes" — keeps the storage tidy without affecting real usage.
    - **Evidence:**
        ```php
        $code = Str::random(32);
        Cache::put("shopify:embed:connect:{$code}", (string) $professional->id, now()->addMinutes(30));
        // No throttle or rate-limit check precedes this write.
        ```
