I now have everything I need. My verification confirms:
- **SEC-4 drops**: `CommissionPolicy::viewOwnPayouts` explicitly checks `$isBrand` (derived from `professional_type`) on the policy side — an affiliate passing `?role=brand` returns `false` at line 94. Not a vulnerability.
- **SEC-1/2/3 confirmed**: Three Stripe redirect-URL form requests lack the domain allow-list that `StorePlanSubscriptionRequest` and `UpdatePlanSubscriptionRequest` already carry.
- Auth middleware, Shopify OAuth, and all webhook controllers are properly implemented (fail-closed, `hash_equals`, Stripe `constructEvent`, Shopify HMAC trait with `hash_equals`, event-ID dedup). No additional findings from tool investigation.

`★ Insight ─────────────────────────────────────`
- The codebase shows a clear split in redirect-URL hygiene: billing plan requests (StorePlanSubscriptionRequest/UpdatePlanSubscriptionRequest) have domain allow-list enforcement, while Stripe Connect Express requests (OnboardRequest, CreatePaymentMethodSetupRequest, CreateTopUpCheckoutRequest) don't — a "same team, different day" inconsistency that's easy to miss in review.
- `CommissionPolicy::viewOwnPayouts` has an elegant two-axis gate: it checks both the ID equality AND the `professional_type` (`isBrand`), so neither side can impersonate the other by toggling the `?role=` parameter. This is the correct pattern for dual-role entities sharing one endpoint.
- The `VerifyHydrogenApiKey` and `VerifyEmbeddedApiKey` middleware both use `throw new \RuntimeException(...)` instead of silent allow-all when the env var is missing in non-local environments — the exact lesson from the cautionary tale referenced in the audit lens.
`─────────────────────────────────────────────────`

---

# Security Audit — 2026-05-11

**Branch:** development
**Lens:** Security: auth boundaries, tenant isolation, webhooks, secrets, injection, SSRF, PII exposure
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
- app/Http/Requests/Stripe/OnboardRequest.php
- app/Http/Requests/Stripe/CreatePaymentMethodSetupRequest.php
- app/Http/Requests/Stripe/CreateTopUpCheckoutRequest.php
- app/Http/Requests/Api/Professional/StorePlanSubscriptionRequest.php
- app/Http/Requests/Api/Professional/UpdatePlanSubscriptionRequest.php
- app/Http/Controllers/Api/Professional/Stripe/StripeConnectController.php
- app/Http/Controllers/Api/Professional/Stripe/AffiliateStripeOnboardingController.php
- app/Http/Controllers/Api/Professional/Brand/BrandBillingSummaryController.php
- app/Http/Controllers/Api/Professional/Brand/BrandPayoutsController.php
- app/Http/Controllers/Api/Professional/Affiliate/AffiliatePayoutsController.php
- app/Http/Middleware/Auth/VerifyHydrogenApiKey.php
- app/Http/Middleware/Auth/VerifyEmbeddedApiKey.php
- app/Http/Middleware/Auth/VerifyShopifySessionToken.php
- app/Http/Controllers/Api/Shopify/ShopifyAppOAuthController.php
- app/Http/Controllers/Api/Webhooks/StripeWebhookController.php
- app/Http/Controllers/Api/Webhooks/StripeConnectWebhookController.php
- app/Http/Controllers/Api/Webhooks/ShopifyOrderWebhookController.php
- app/Http/Controllers/Concerns/ValidatesShopifyWebhookHmac.php
- app/Policies/CommissionPolicy.php
- app/Models/Retail/BrandStoreSettings.php
- app/Http/Requests/BaseFormRequest.php
- config/cors.php

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 3 complete

---

## P1 — Fix before pilot launch

- [ ] **#SEC-3** · P1 — Top-up checkout redirect URLs accept any domain (open redirect via Stripe)
    - **Where:** app/Http/Requests/Stripe/CreateTopUpCheckoutRequest.php (rules method)
    - **Affects:** Brands using `/stripe/topups/checkout`. After topping up the commission wallet, Stripe redirects the user's browser to the `success_url` or `cancel_url` supplied in the request. A manipulated URL redirects the brand to an attacker-controlled page immediately after a real Stripe session.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Extract the `allowedRedirectRule()` closure from `StorePlanSubscriptionRequest` into `BaseFormRequest` as a `protected` helper (or a shared trait) so all Stripe redirect endpoints can share it without duplication.
        - Add that rule to `success_url` and `cancel_url` in `CreateTopUpCheckoutRequest::rules()`.
    - **Technical:** The `rules()` method constrains `success_url` and `cancel_url` only with the `url` rule, which validates URL format but not the host. After a brand completes the Stripe Checkout session, Stripe's hosted page issues a browser redirect to the supplied URL. Any origin is accepted. By contrast, `StorePlanSubscriptionRequest` (commit history, same codebase) already implements `allowedRedirectRule()`, which parses the host and rejects anything outside `config('app.frontend_url')`, `config('app.url')`, `localhost`, and `127.0.0.1`. The fix for all three Stripe Connect request classes (SEC-1, SEC-2, SEC-3) is to share that same rule.
    - **Plain English:** When a brand adds money to their commission wallet, they tell us two web addresses: "here's where to send me if it worked, here's where to send me if I cancel." Right now we only check that those addresses look like web links — we don't check that they point to our app. A malicious actor could craft a top-up link that redirects someone to a convincing fake login page the moment they finish adding funds. We already block this on the subscription billing screen; we just need to apply the same check here.
    - **Evidence:**
        ```php
        // app/Http/Requests/Stripe/CreateTopUpCheckoutRequest.php
        public function rules(): array
        {
            return [
                'amount_cents'  => ['required', 'integer', 'min:1000', 'max:10000000'],
                'currency_code' => ['nullable', 'string', 'size:3'],
                'success_url'   => ['required', 'url'],
                'cancel_url'    => ['required', 'url'],
            ];
        }
        ```
        Compare to the existing protection in `StorePlanSubscriptionRequest`:
        ```php
        // app/Http/Requests/Api/Professional/StorePlanSubscriptionRequest.php
        'success_url' => ['required_unless:plan_id,'.$freePlanId, 'nullable', 'url', $this->allowedRedirectRule()],
        'cancel_url'  => ['required_unless:plan_id,'.$freePlanId, 'nullable', 'url', $this->allowedRedirectRule()],
        ```

- [ ] **#SEC-2** · P1 — Payment method setup redirect URLs accept any domain (open redirect via Stripe)
    - **Where:** app/Http/Requests/Stripe/CreatePaymentMethodSetupRequest.php (rules method)
    - **Affects:** Brands using `/stripe/payment-method/setup-checkout`. Stripe's hosted Setup Checkout page redirects the user's browser to `success_url` or `cancel_url` after the brand saves (or abandons) their payment method. A manipulated URL redirects the brand to an attacker-controlled page immediately after a real Stripe session.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Apply the same `allowedRedirectRule()` already used by `StorePlanSubscriptionRequest` to `success_url` and `cancel_url` in `CreatePaymentMethodSetupRequest::rules()`.
        - Share the rule via `BaseFormRequest` or a trait (see SEC-3 note on extraction).
    - **Technical:** Identical root cause to SEC-3. The `rules()` array uses only the `url` rule on both redirect fields, imposing no host restriction. `StorePlanSubscriptionRequest::allowedRedirectRule()` and `UpdatePlanSubscriptionRequest::allowedRedirectRule()` already solve this; the fix is consistency.
    - **Plain English:** Same problem as SEC-3, but on the "add a payment method" flow. After a brand finishes adding their card through Stripe's payment page, we redirect them based on addresses they sent us. We only check that the addresses look like web links — not that they lead back to our app. The fix is the same: check the destination matches our allowed domains, just as we already do on the billing subscription screens.
    - **Evidence:**
        ```php
        // app/Http/Requests/Stripe/CreatePaymentMethodSetupRequest.php
        public function rules(): array
        {
            return [
                'success_url' => ['required', 'url'],
                'cancel_url'  => ['required', 'url'],
            ];
        }
        ```

- [ ] **#SEC-1** · P1 — Stripe Connect onboarding redirect URLs accept any domain (open redirect via Stripe)
    - **Where:** app/Http/Requests/Stripe/OnboardRequest.php (rules method)
    - **Affects:** All professionals calling `/stripe/connect/onboard` and affiliates calling `/affiliate/stripe/connect/start` (both resolve through `OnboardRequest`). After a professional completes Stripe Express onboarding, Stripe redirects their browser to the `return_url`; if they need to restart, Stripe uses `refresh_url`. Either field can be set to an attacker-controlled domain.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `allowedRedirectRule()` to both `return_url` and `refresh_url` in `OnboardRequest::rules()`.
        - Move the rule to `BaseFormRequest` as a shared protected method to keep SEC-1, SEC-2, and SEC-3 in sync (they all need identical host restrictions).
    - **Technical:** `OnboardRequest` is shared between `StripeConnectController::onboard()` (brand/affiliate self-service) and `AffiliateStripeOnboardingController::startConnect()` (affiliate-explicit path). Both pass `return_url` and `refresh_url` directly to `StripeConnectService::createOnboardingLink()`, which forwards them to Stripe's API as the post-onboarding redirect targets. Stripe then issues browser redirects to these URLs without further validation. The host is never verified against the application's own origin. The pattern to follow is already live in `StorePlanSubscriptionRequest::allowedRedirectRule()` and `UpdatePlanSubscriptionRequest::allowedRedirectRule()` — extracting it to `BaseFormRequest` closes the gap across all three Stripe redirect surfaces simultaneously.
    - **Plain English:** When someone sets up their Stripe account to receive payouts, they tell our API two web addresses: "where to send me when I finish" and "where to send me if I need to start again." We check that these addresses are formatted like web links, but we don't check that they point to our app. An attacker who has a valid account could craft a Stripe onboarding link with a fake destination, then use social engineering to get a target to complete the Stripe flow — after which they land on the attacker's page instead of the dashboard. We already block this on our billing subscription screens; we need to apply the same protection here.
    - **Evidence:**
        ```php
        // app/Http/Requests/Stripe/OnboardRequest.php
        public function rules(): array
        {
            return [
                'return_url'  => ['required', 'url'],
                'refresh_url' => ['required', 'url'],
            ];
        }
        ```
