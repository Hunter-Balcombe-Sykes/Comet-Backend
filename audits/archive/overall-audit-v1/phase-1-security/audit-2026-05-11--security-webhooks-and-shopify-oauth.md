`★ Insight ─────────────────────────────────────`
The dedup-before-HMAC pattern in 6 Shopify webhook controllers is revealing — `ShopifyOrderWebhookController` has an intentional comment calling it a "cheap Redis upfront dedup," making it a deliberate performance tradeoff. Meanwhile `ShopifyThemePublishedWebhookController` uses the correct HMAC-first pattern, and the GDPR controller (the gold standard) does too. Three divergent patterns in the same family of controllers is a strong signal that the ordering wasn't reviewed against a single canonical reference during Phase 3 development.
`─────────────────────────────────────────────────`

Now I have everything I need. Let me produce the final audit.

---

**Adjudication decisions:**
- **SEC-1** (dedup before HMAC): Evidence confirmed in all 6 controllers. Re-tier **P1→P2** — HMAC is always verified for non-cached requests; `Cache::add()` runs only after HMAC passes, so cache poisoning is impossible. The actual risk is minor information leakage (can a UUID been processed?). Not "ships bad behavior in known scenarios" → P2.
- **SEC-2** (Fresha placeholder signature): **DROPPED** — Fresha integration is explicitly out of scope (dropped 2026-05-11, CLAUDE.md `project_booking_dropped.md`).
- **SEC-3** (no rate limiting on OAuth): **DROPPED** — `routes/api.php` shows `throttle:60,1` on install/callback and `throttle:10,15` on setupPrefill. Finding is factually wrong.
- **SEC-4** (setupPrefill PII / rate limiting): **DROPPED** — same reason as SEC-3 + setup token is `bin2hex(random_bytes(32))` (256-bit random), brute-force is infeasible.
- **SEC-5** (Square URL validation): **DROPPED** — Square integration is out of scope (dropped).
- **SEC-6** (malformed JSON → 200): Evidence confirmed in 6 controllers. Keep at **P2**.
- **SEC-7** (email-based account linking): Evidence confirmed. Confidence 0.6 but this IS a real account-takeover path → per "always-drop" rule 4 exception, keep if it is a real security/data issue. Keep at **P2** — requires owning a Shopify store + knowing target email.
- **SEC-8** (IP logging in Fresha/Square): **DROPPED** — both code paths out of scope.

**New findings added:** None confirmed. Auth middleware (`VerifyHydrogenApiKey`, `VerifyEmbeddedApiKey`, `VerifyShopifySessionToken`, `VerifySupabaseJwt`) all verified clean: algorithm allowlist enforced, hash_equals used, fail-closed in production, kid-based JWKS caching, aud/dest validated. CORS `allowed_origins: ['*']` is intentional and safe (`supports_credentials: false`, JWT Bearer auth). Policy coverage enforced by CI test.

# Security Audit — 2026-05-11

**Branch:** development
**Lens:** Security: auth boundaries, tenant isolation, webhooks, secrets, injection, SSRF, PII exposure
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
- app/Http/Controllers/Api/Webhooks/ShopifyOrderWebhookController.php
- app/Http/Controllers/Api/Webhooks/ShopifyOrdersCancelledWebhookController.php
- app/Http/Controllers/Api/Webhooks/ShopifyOrdersEditedWebhookController.php
- app/Http/Controllers/Api/Webhooks/ShopifyOrdersUpdatedWebhookController.php
- app/Http/Controllers/Api/Webhooks/ShopifyRefundsCreateWebhookController.php
- app/Http/Controllers/Api/Webhooks/ShopifyShopUpdateWebhookController.php
- app/Http/Controllers/Api/Webhooks/ShopifyThemePublishedWebhookController.php
- app/Http/Controllers/Api/Webhooks/ShopifyGdprWebhookController.php
- app/Http/Controllers/Api/Webhooks/ShopifyAppUninstalledWebhookController.php
- app/Http/Controllers/Api/Webhooks/SquareCatalogWebhookController.php
- app/Http/Controllers/Api/Webhooks/FreshaCatalogWebhookController.php
- app/Http/Controllers/Api/Webhooks/StripeConnectWebhookController.php
- app/Http/Controllers/Api/Webhooks/StripeWebhookController.php
- app/Http/Controllers/Api/Shopify/ShopifyAppOAuthController.php
- app/Http/Middleware/Auth/VerifyHydrogenApiKey.php
- app/Http/Middleware/Auth/VerifyEmbeddedApiKey.php
- app/Http/Middleware/Auth/VerifyShopifySessionToken.php
- app/Http/Middleware/Auth/VerifySupabaseJwt.php
- app/Services/Shopify/ShopifySetupTokenService.php
- app/Services/Shopify/BrandSignupService.php
- app/Providers/AppServiceProvider.php
- config/cors.php
- routes/api.php
- routes/api/professional.php

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 3 complete
- P3 Low: 0 of 0 complete

---

## P2 — Should fix

- [ ] **#SEC-1** · P2 — Six Shopify webhook controllers perform Redis dedup probe before HMAC verification
    - **Where:** app/Http/Controllers/Api/Webhooks/ShopifyOrderWebhookController.php:28–32; ShopifyOrdersCancelledWebhookController.php:36–38; ShopifyOrdersEditedWebhookController.php:36–38; ShopifyOrdersUpdatedWebhookController.php:36–38; ShopifyRefundsCreateWebhookController.php:36–38; ShopifyShopUpdateWebhookController.php:34–36
    - **Affects:** All Shopify order, refund, and shop-update webhook topics — unauthenticated callers can observe whether a specific `X-Shopify-Webhook-Id` UUID has been processed (response body contains `duplicate:true` vs `invalid signature`). Violates defence-in-depth principle; `ShopifyThemePublishedWebhookController` and `ShopifyGdprWebhookController` already use the correct HMAC-first pattern.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Move the `Cache::has($dedupeKey)` early-return block to *after* `isValidShopifyHmac()` in all six controllers.
        - Adopt the pattern from `ShopifyThemePublishedWebhookController`: verify HMAC, then call `Cache::add()` atomically (no prior `Cache::has` needed — `Cache::add` returns false on duplicate, so the two-step `has`+`add` is unnecessary).
        - Remove the `Cache::has()` call entirely; keep only the post-HMAC `Cache::add()` check.
    - **Technical:** Cache poisoning is not possible here — `Cache::add()` is already gated behind a successful HMAC, so an unauthenticated caller cannot inject a dedup entry. The real issue is information leakage: by sending a POST with an arbitrary `X-Shopify-Webhook-Id` header, an attacker can distinguish `duplicate:true` (cache hit — that UUID was legitimately processed) from `401 invalid signature` (cache miss), enabling inference about which webhook events the platform has received. `ShopifyOrderWebhookController` even carries an explicit comment calling this a deliberate performance tradeoff ("Cheap Redis upfront dedup on webhook-id before recomputing HMAC"), which signals the pattern was consciously chosen without considering the observability side-channel. The correct fix removes `Cache::has()` entirely; `Cache::add()` is already atomic and serves as both the dedup write and the duplicate-detection read in a single operation.
    - **Plain English:** Six of the doors into the building check whether a visitor's name is on the "already visited" clipboard before asking to see their ID. Someone with no ID can still walk up, say a name, and see whether the clipboard reads "yep, already here" or "go back, show us your ID first" — which tells them something they shouldn't know. The fix is: check ID first, then check the clipboard. Seven lines of code across six files.
    - **Evidence:**
        ```php
        // ShopifyOrderWebhookController.php
        // Cheap Redis upfront dedup on webhook-id before recomputing HMAC.
        $dedupeKey = $webhookId !== '' ? "shopify:webhook:order:{$webhookId}" : null;
        if ($dedupeKey && Cache::has($dedupeKey)) {
            return $this->success(['received' => true, 'duplicate' => true]);
        }

        if (! $this->isValidShopifyHmac($rawBody, $signature)) {
        ```
        ```php
        // ShopifyOrdersCancelledWebhookController.php (identical shape in 4 other siblings)
        $dedupeKey = $webhookId !== '' ? "shopify:webhook:order-cancelled:{$webhookId}" : null;
        if ($dedupeKey && Cache::has($dedupeKey)) {
            return $this->success(['received' => true, 'duplicate' => true]);
        }

        if (! $this->isValidShopifyHmac($rawBody, $signature)) {
            Log::warning('Shopify orders/cancelled webhook: invalid HMAC signature', [...]);
            return $this->error('invalid signature', 401);
        }
        ```
        ```php
        // ShopifyThemePublishedWebhookController.php — correct reference pattern
        if (! $this->isValidShopifyHmac($rawBody, $signature)) {
            return $this->error('invalid signature', 401);
        }
        if ($webhookId !== '') {
            $dedupeKey = "shopify:webhook:themes-publish:{$webhookId}";
            if (! Cache::add($dedupeKey, true, ...)) {
                return $this->success(['received' => true, 'duplicate' => true]);
            }
        }
        ```

- [ ] **#SEC-2** · P2 — Six Shopify webhook controllers acknowledge malformed JSON payloads with HTTP 200, permanently losing the event
    - **Where:** app/Http/Controllers/Api/Webhooks/ShopifyOrdersCancelledWebhookController.php:67–69; ShopifyOrdersEditedWebhookController.php:67–69; ShopifyOrdersUpdatedWebhookController.php:67–69; ShopifyRefundsCreateWebhookController.php:67–69; ShopifyShopUpdateWebhookController.php:65–67; ShopifyOrderWebhookController.php:68–74
    - **Affects:** All Shopify order lifecycle and refund events — if Shopify delivers a malformed payload (network truncation, encoding bug), the controller ACKs with 200 and Shopify stops retrying. The commission event is permanently lost. `ShopifyGdprWebhookController` already uses the correct `422` pattern; the GDPR controller's comment explicitly documents why 200 on bad JSON is dangerous.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Return `$this->error('malformed payload', 422)` when `json_decode` returns a non-array, in all six controllers.
        - Add a `Log::warning` with `shop_domain` so ops can trace malformed deliveries — `ShopifyOrderWebhookController` already logs but still returns 200; flip it to 422.
        - Reference `ShopifyGdprWebhookController::handleGdprWebhook()` as the canonical pattern.
    - **Technical:** Shopify's webhook delivery system retries on any non-2xx response. Returning `200` on a parse failure tells Shopify the event was successfully consumed, ending the retry cycle. The event is then permanently unprocessable. `ShopifyGdprWebhookController` documents this exact failure mode in its source comment: "Validate BEFORE computing hash — if a malformed payload gets cached as RECEIVED, every Shopify retry is silently deduplicated and the compliance action never runs." The same correctness principle applies to order and refund events — a malformed `orders/paid` webhook could silently drop a commission accrual. The GDPR controller is the only one in the family that returns 422 on parse failure.
    - **Plain English:** Shopify delivers a package with a smudged label. Six of the seven receiving windows stamp it "delivered OK" and shred it — Shopify never tries again. The seventh window (handling privacy requests) correctly stamps it "unreadable, try again" so Shopify resends a clean copy. All six windows should behave like the seventh.
    - **Evidence:**
        ```php
        // ShopifyOrdersCancelledWebhookController.php (same in 4 siblings)
        $payload = json_decode($rawBody, true);
        if (! is_array($payload)) {
            return $this->success(['received' => true]);
        }
        ```
        ```php
        // ShopifyOrderWebhookController.php — logs but still returns 200
        $payload = json_decode($rawBody, true);
        if (! is_array($payload)) {
            Log::warning('Shopify order webhook: invalid JSON payload', [
                'shop_domain' => $shopDomain,
            ]);

            return $this->success(['received' => true]);
        }
        ```
        ```php
        // ShopifyGdprWebhookController.php — correct reference pattern
        $payload = json_decode($rawBody, true);
        if (! is_array($payload)) {
            Log::warning("Shopify GDPR webhook ({$topic}): malformed JSON body", [...]);

            return $this->error('malformed payload', 422);
        }
        ```

- [ ] **#SEC-3** · P2 — Shopify OAuth callback links a shop to an existing Professional account by email match alone, with no additional verification
    - **Where:** app/Http/Controllers/Api/Shopify/ShopifyAppOAuthController.php:134–146; app/Services/Shopify/BrandSignupService.php:handleExistingBrandConnect
    - **Affects:** Any existing brand Professional — an attacker who controls a Shopify store and knows a target's primary email can change their store's contact email and install the Partna app to overwrite the target's Shopify integration with their own shop. `handleExistingBrandConnect` calls `ProfessionalIntegration::updateOrCreate` keyed on `professional_id + provider`, replacing the access token, shop domain, and metadata with the attacker's store.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - For Path B (email match → existing account), require the Professional to be actively logged in via a Supabase JWT at install time rather than relying on email coincidence alone. The cleanest approach: if an email match is found, redirect to a dedicated confirmation page inside the Shopify admin that requires the Professional's Supabase session token; only proceed with `handleExistingBrandConnect` after that token is verified.
        - As a short-term mitigation pending the logged-in flow: only auto-link via email when the matched Professional has no existing Shopify integration (i.e., `ProfessionalIntegration::where('professional_id', ...)->where('provider', SHOPIFY)->doesntExist()`). If one already exists, redirect the installer to the Partna login page rather than overwriting a live integration silently.
        - Add an audit log entry whenever Path B executes (shop domain, email matched, professional ID, timestamp) so any malicious link is detectable post-hoc.
    - **Technical:** The OAuth callback fetches the shop's email from Shopify's API (`shopData['email']`) and does a case-insensitive `whereRaw('lower(primary_email) = ?', [$shopEmail])` lookup. If matched, `handleExistingBrandConnect` is called immediately, which runs `ProfessionalIntegration::updateOrCreate(['professional_id' => ..., 'provider' => SHOPIFY], ['access_token' => $attackersToken, 'external_account_id' => $attackersShop, ...])`. Shopify store contact emails are changeable by any store admin with no additional verification from Shopify. The HMAC and nonce checks correctly authenticate that the OAuth flow originated from Shopify, but they do not prove that the Shopify store owner is the same person as the matched Partna Professional. The result is that the attacker's Shopify store is now cryptographically linked to the victim's Partna account: future webhook deliveries (`X-Shopify-Shop-Domain: attackers-store.myshopify.com`) will be attributed to the victim's `professional_id`, injecting fraudulent commission data.
    - **Plain English:** When someone installs the app, the system looks up whether any existing account shares the same email as the Shopify store. If one exists, it immediately links them — assuming it's the same person — without asking the account owner to confirm. A bad actor who controls any Shopify store can change its contact email to match someone else's account, install the app, and walk into that person's Partna brand profile. It's like a coat-check that hands over your coat whenever someone says your name, without checking their ticket.
    - **Evidence:**
        ```php
        // ShopifyAppOAuthController.php:134-146
        if ($shopEmail !== '') {
            $existingProfessional = Professional::whereRaw('lower(primary_email) = ?', [$shopEmail])->first();

            if ($existingProfessional) {
                $result = $this->brandSignup->handleExistingBrandConnect(
                    $existingProfessional, $shop, $accessToken, $shopData, $scopes
                );

                Log::info('Shopify OAuth: existing account connect', [
                    'professional_id' => (string) $result->professional->id,
                    'shop_domain' => $shop,
                ]);

                return redirect()->away($basePath);
            }
        }
        ```
        ```php
        // BrandSignupService::handleExistingBrandConnect — overwrites the integration unconditionally
        $integration = ProfessionalIntegration::updateOrCreate(
            [
                'professional_id' => (string) $professional->id,
                'provider' => ProfessionalIntegration::PROVIDER_SHOPIFY,
            ],
            [
                'external_account_id' => $shopDomain,
                'access_token' => $accessToken,
                // ...
            ]
        );
        ```

`★ Insight ─────────────────────────────────────`
The Shopify OAuth `callback` uses three distinct install paths (reinstall, email-match, fresh install). Path A (reinstall) is safe because it matches on `shopify_shop_domain` — a value Shopify controls and signs via HMAC. Path B (email match) is the weak link because it relies on a Shopify-controlled email field that the store owner can freely change. Path C (fresh install) correctly defers trust by generating a random 256-bit token and requiring the frontend to complete setup. The fix for Path B should bring it closer to Path C's trust model: only proceed after the human confirms ownership via an active session.
`─────────────────────────────────────────────────`
