Now I have all the facts. Let me compile the final audit.

`★ Insight ─────────────────────────────────────`
Key adjudication corrections this audit required:
- DeepSeek claimed three endpoints had "no rate limiting" — all three actually have `throttle:public-site` (60 req/min) already defined in `AppServiceProvider`. Verifying route files before accepting "no throttle" claims is essential.
- `EmailSubscription::newUnsubscribeToken()` uses `Str::random(48)` = 285 bits of entropy, making the brute-force premise behind SEC-5 computationally impossible.
- SEC-3 (booking PII) is out of scope — per `project_booking_dropped.md`, the booking/Square path is dropped and should not appear in any audit.
- The `VerifyHydrogenApiKey` bypass-on-empty bug cited in the lens was already fixed (RuntimeException in non-local/test environments) — no finding needed.
`─────────────────────────────────────────────────`

# Security Audit — 2026-05-11

**Branch:** development
**Lens:** Security: auth boundaries, tenant isolation, webhooks, secrets, injection, SSRF, PII exposure
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
- app/Http/Controllers/Api/PublicSite/PublicConfigController.php
- app/Http/Controllers/Api/PublicSite/SiteVisibilityController.php
- app/Http/Controllers/Api/PublicSite/PublicSignupAvailabilityController.php
- app/Http/Controllers/Api/PublicSite/PublicShopifyStorefrontController.php
- app/Http/Controllers/Api/PublicSite/PublicMarketingPreferenceController.php
- app/Http/Controllers/Api/PublicSite/PublicEmailUnsubscribeController.php
- app/Http/Controllers/Api/PublicSite/QrCodeController.php
- app/Http/Controllers/Api/PublicSite/AnalyticsController.php
- app/Http/Controllers/Api/PublicSite/BootstrapController.php
- app/Http/Controllers/Api/PublicSite/PublicBrandAffiliateInviteController.php
- app/Http/Controllers/Api/PublicSite/PublicDocumentDownloadController.php
- app/Http/Controllers/Api/PublicSite/PublicEmailSubscriptionController.php
- app/Http/Controllers/Api/PublicSite/PublicEnquiryController.php
- app/Http/Controllers/Api/PublicSite/PublicOpenInviteController.php
- app/Http/Controllers/Api/PublicSite/PublicWaitlistController.php
- app/Http/Resources/ProfessionalDashboardResource.php
- app/Http/Resources/ProfessionalPublicResource.php
- app/Http/Resources/ProfessionalStaffResource.php
- app/Http/Resources/CustomerResource.php
- app/Http/Resources/BrandStoreSettingsResource.php
- app/Http/Resources/AffiliatePayoutResource.php
- app/Http/Resources/BrandPayoutResource.php
- app/Http/Middleware/Auth/VerifyHydrogenApiKey.php
- app/Http/Middleware/Auth/VerifyEmbeddedApiKey.php
- app/Http/Middleware/Auth/VerifyShopifySessionToken.php
- app/Policies/SitePolicy.php
- app/Providers/AppServiceProvider.php
- routes/api.php
- routes/api/publicSite.php
- routes/api/professional.php
- routes/web.php

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 2 complete
- P3 Low: 0 of 2 complete

---

## P2 — Should fix

- [ ] **#SEC-1** · P2 — Google Maps API key publicly served with no deploy-time enforcement of referrer restriction
    - **Where:** app/Http/Controllers/Api/PublicSite/PublicConfigController.php:39–47
    - **Affects:** Any visitor to `GET /api/public/config/integrations` — the key is served with `Cache-Control: public, max-age=3600` so CDNs cache it indefinitely. If a deploy ever ships without the HTTP referrer restriction configured in Google Cloud Console, the key is billable by anyone.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a boot-time assertion in `AppServiceProvider::boot()` (or a Laravel `CheckEnvironment` command run during deploy) that verifies `GOOGLE_MAPS_API_KEY` is set and — on `app()->isProduction()` — that a companion `GOOGLE_MAPS_API_KEY_REFERRER_RESTRICTION_VERIFIED=true` env var is present. This forces the operator to consciously confirm restriction is active before each deploy.
        - Add a note to `docs/deploy-checklist.md` (or create one) listing this key alongside the required Google Cloud Console verification step.
        - Do not add runtime HTTP validation of the referrer restriction — that would require calling the Google Maps API on every boot and is fragile. An env var flag is sufficient friction.
    - **Technical:** The docblock on `integrations()` states "Each key here must be HTTP-referrer-restricted" but the code trusts the operator to have acted on that comment. The key is served via a `public, max-age=3600` response that CDNs will cache globally. A Google Maps API key without HTTP referrer restrictions is billable for any domain that uses it — Places Autocomplete requests are priced per call. The missing protection is a process gap at deploy time, not a code bug, so the correct fix is a deploy-gate assertion rather than a runtime check.
    - **Plain English:** You've left a comment on the key that says "make sure the lock is on", but no alarm goes off if someone forgets to install the lock. The fix is a pre-flight checklist that the deploy process must tick off before the door opens. One missed step doesn't get the lock for free, but at least the deploy will fail loudly rather than silently.
    - **Evidence:**
        ```php
        public function integrations(): JsonResponse
        {
            return response()
                ->json([
                    'googleMapsApiKey' => config('services.google_maps.api_key'),
                ])
                ->header('Cache-Control', 'public, max-age=3600');
        }
        ```

- [ ] **#SEC-2** · P2 — SiteVisibilityController uses inline tenant scope instead of `authorizeForUser` + `SitePolicy`
    - **Where:** app/Http/Controllers/Api/PublicSite/SiteVisibilityController.php:20–32
    - **Affects:** Authenticated professionals toggling site visibility — the inline pattern bypasses `SitePolicy::update`, which additionally enforces `denyIfPendingDeletion()` (returning HTTP 423 instead of 403) and provides the single testable surface for site ownership checks.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace the inline `where('professional_id', $professional->id)->firstOrFail()` with an unconditional lookup followed by `$this->authorizeForUser($professional, 'update', $site)`.
        - Remove the manual `status !== 'active'` guard — `SitePolicy` should own that rule. Verify `BasePolicy::denyIfPendingDeletion` covers `disabled` and `suspended` statuses in addition to `pending_deletion`, or add a dedicated `denyIfRestricted()` method that the site policy calls.
        - The corrected controller block:
          ```php
          $site = Site::query()->where('professional_id', $professional->id)->firstOrFail();
          $this->authorizeForUser($professional, 'update', $site);
          $site->published = (bool) $request->validated('published');
          $site->save();
          ```
    - **Technical:** Per Partna Authorization Doctrine #2, all resource authorization must flow through registered Policies via `authorizeForUser`. `SitePolicy::update` is registered in `AppServiceProvider::boot()` and includes a `denyIfPendingDeletion` check that returns HTTP 423 — the protocol-correct response for accounts in the deletion grace window. The current controller returns HTTP 403 for all non-active statuses including `pending_deletion`, which breaks the expected 423 contract the frontend depends on. The inline `where` clause correctly enforces ownership (returns 404 for non-owned sites), but skips the policy's pending-deletion gate and cannot be unit-tested in isolation. Additionally, the controller is placed in the `PublicSite` namespace despite being an authenticated route in `routes/api/professional.php`, which adds confusion when sweeping for policy coverage.
    - **Plain English:** The platform has a central security guard desk (the Policy system) that every door request is supposed to be checked against. This controller is checking IDs at the door itself instead of sending people to the desk. The desk does one extra check the door doesn't: it knows when an account is being closed and gives a specific "please wait, account closing" signal rather than a generic "not allowed" signal. The fix is to route traffic through the desk, which also moves the logic to a place that can be automatically tested.
    - **Evidence:**
        ```php
        $site = Site::query()
            ->where('professional_id', $professional->id)
            ->firstOrFail();

        $site->published = (bool) $request->validated('published');
        $site->save();
        ```

---

## P3 — Nice to have

- [ ] **#SEC-3** · P3 — Signup availability response redundantly confirms email/phone registration via `exists: true`
    - **Where:** app/Http/Controllers/Api/PublicSite/PublicSignupAvailabilityController.php:35–60
    - **Affects:** The `POST /api/public/signup/availability` endpoint — the `exists: true` field in the response explicitly confirms a value is registered, marginally beyond what `available: false` already communicates. The route carries `throttle:public-site` (60/min/IP), which limits but does not eliminate slow-rate enumeration.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Remove the redundant `exists` key from the response object. Return only `available: true/false`. The caller gets the same actionable information, and the response no longer explicitly confirms a user record exists.
        - No change to the query logic is required.
    - **Technical:** `available: false` is logically equivalent to `exists: true` from the caller's perspective — both mean "you cannot register this value". Returning both is redundant and explicitly labels the negative case as "an existing user lives here". The distinction matters for a patient attacker who can cycle through email lists at 60/min/IP (or faster with IP rotation) to build a Partna user directory. Eliminating `exists` from the payload removes the semantic confirmation without changing UX: the frontend only needs to know whether the value is available.
    - **Plain English:** The front desk currently says "sorry, that email address belongs to another guest here" when it only needs to say "sorry, that name isn't available". The first version confirms someone exists; the second just says the slot is taken. They're functionally the same for someone trying to register, but the first version is more useful to someone trying to compile a list of guests.
    - **Evidence:**
        ```php
        return $this->success([
            'email' => [
                'available' => ! $emailExists,
                'exists' => $emailExists,
            ],
            'phone' => [
                'available' => ! $phoneExists,
                'exists' => $phoneExists,
            ],
            'handle_lc' => [
                'available' => ! $handleExists,
                'exists' => $handleExists,
            ],
            'signups_open' => $signupsOpen,
            'waitlist_only' => ! $signupsOpen,
        ]);
        ```

- [ ] **#SEC-4** · P3 — Public storefront-config endpoint confirms Shopify integration existence to unauthenticated callers
    - **Where:** app/Http/Controllers/Api/PublicSite/PublicShopifyStorefrontController.php:29–90
    - **Affects:** Any caller to `GET /api/public/shopify/storefront-config?shop_domain=…` — the 404 vs 200/202 response distinction reveals which Shopify stores have Partna installed. The route carries `throttle:public-site` (60/min/IP).
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a `brand_status` gate: if the resolved brand's status is not `active` (or the equivalent production-ready statuses), return 404 rather than 202. This ensures only fully live integrations are discoverable.
        - Consider returning a uniform 202 for both "token pending" and "not found" when the caller is not a trusted Hydrogen server — see `VerifyHydrogenApiKey` for the pattern. The Hydrogen server already goes through the `internal/hydrogen/*` routes with server-to-server auth, so the public endpoint can afford to be less informative.
    - **Technical:** `NormalizesShopDomain` already enforces a strict `*.myshopify.com` suffix check (verified in `app/Http/Controllers/Concerns/NormalizesShopDomain.php:25`), so the domain-injection risk DeepSeek cited is already closed. The remaining concern is competitive intelligence: a competitor or journalist can systematically probe `shop_domain` values to identify which Shopify merchants use Partna. The `storefront_access_token` returned is a Shopify Storefront API token (read-only, designed to be embedded in client-side code), so its exposure is not itself a secret-leakage issue. The concern is the 404 vs 200 signal on the brand-existence plane. At 60/min/IP this is slow-rate enumeration — a real but low-urgency concern pre-pilot.
    - **Plain English:** There's a public lookup page that will tell you whether any given Shopify store is a Partna customer — you just have to look it up. Right now the page says "yes" or "no" (or "almost ready"). Making the "no" and "almost ready" answers identical means you can't tell the difference between a store that isn't on Partna and one that's still setting up. Either way is not a crisis, but it limits what a competitor can learn by browsing through the directory.
    - **Evidence:**
        ```php
        $integration = ! empty($validated['shop_domain'])
            ? $this->resolveByShopDomain($validated['shop_domain'])
            : $this->resolveByBrandSlug($validated['brand_slug']);

        if (! $integration) {
            return $this->error('Not found.', 404);
        }
        // ...
        if ($storefrontToken === '') {
            // ...
            return response()->json([
                'status' => 'pending',
                'message' => 'Storefront token is being created. Try again in a few seconds.',
            ], 202);
        }

        return $this->success([
            'shop_domain' => $shopDomain,
            'storefront_access_token' => $storefrontToken,
            'default_collection_handle' => Arr::get($metadata, 'default_collection_handle', 'sidest-default-products'),
            'brand_status' => $brandProfile?->brand_status ?? BrandStatus::Onboarding->value,
            'business_website' => $brandProfile?->business_website,
        ]);
        ```
