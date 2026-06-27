`★ Insight ─────────────────────────────────────`
The `HandlesShopifyWebhook` trait suffers the **same** header-trust pattern as the inline controllers — line 65 reads shop domain from `X-Shopify-Shop-Domain` and line 93 resolves the integration from it. So SHOP-1 affects all Shopify webhook controllers, not just uninstall/GDPR. This is a systemic pattern, not an isolated bug.
`─────────────────────────────────────────────────`

# Security / Auth / Injection / Feature Flags Audit — 2026-05-20

**Branch:** development
**Lens:** security auth injection feature flags
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
- app/Http/Middleware/Auth/VerifySupabaseJwt.php
- app/Http/Middleware/Auth/VerifyHydrogenApiKey.php
- app/Http/Middleware/Auth/VerifyShopifySessionToken.php
- app/Http/Middleware/Auth/VerifySupabaseEmailHookSignature.php
- app/Http/Middleware/Auth/EnsurePartnaStaff.php
- app/Http/Middleware/Auth/EnsurePartnaAdmin.php
- app/Http/Middleware/Auth/RequireAal2.php
- app/Http/Middleware/Auth/RequireEmailVerified.php
- app/Http/Controllers/Api/Webhooks/Shopify/ShopifyAppUninstalledWebhookController.php
- app/Http/Controllers/Api/Webhooks/Shopify/ShopifyGdprWebhookController.php
- app/Http/Controllers/Api/Webhooks/Shopify/ShopifyOrderWebhookController.php
- app/Http/Controllers/Api/Webhooks/Shopify/ShopifyOrdersCancelledWebhookController.php
- app/Http/Controllers/Api/Webhooks/Shopify/ShopifyOrdersEditedWebhookController.php
- app/Http/Controllers/Api/Webhooks/Shopify/ShopifyOrdersUpdatedWebhookController.php
- app/Http/Controllers/Api/Webhooks/Shopify/ShopifyRefundsCreateWebhookController.php
- app/Http/Controllers/Api/Webhooks/Shopify/ShopifyShopUpdateWebhookController.php
- app/Http/Controllers/Api/Webhooks/Shopify/ShopifyThemePublishedWebhookController.php
- app/Http/Controllers/Concerns/HandlesShopifyWebhook.php
- app/Http/Controllers/Api/Webhooks/StripeWebhookController.php
- app/Http/Controllers/Api/Webhooks/StripeConnectWebhookController.php
- app/Http/Controllers/Api/Webhooks/StripePlatformWebhookController.php
- app/Http/Controllers/Api/Webhooks/SquareCatalogWebhookController.php
- app/Http/Controllers/Api/Webhooks/FreshaCatalogWebhookController.php
- app/Http/Controllers/Api/Webhooks/SupabaseAuthHookController.php
- app/Policies/*.php

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 2 complete
- P2 Medium: 0 of 1 complete
- P3 Low: 0 of 0 complete

---

## P1 — Fix before pilot launch

- [ ] **AUTH-1** · P1 — `claimsMatchConfig` skips issuer/audience checks when env vars are absent, allowing cross-project tokens on the auth-server fallback path
    - **Where:** app/Http/Middleware/Auth/VerifySupabaseJwt.php — `claimsMatchConfig()` and `verifyWithAuthServer()`
    - **Affects:** All authenticated API routes when JWKS verification fails and the auth-server fallback is triggered. A mis-deployed instance missing `SUPABASE_JWT_ISSUER` / `SUPABASE_JWT_AUDIENCE` would accept valid JWTs from any Supabase project — including an attacker's own project — as legitimate user sessions.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a boot-time assertion in `AppServiceProvider::boot()` (non-local, non-testing environments) that `config('supabase.jwt_issuer')` and `config('supabase.jwt_audience')` are non-empty strings; throw a `RuntimeException` if either is absent, the same pattern already applied in `VerifyShopifySessionToken`.
        - Alternatively, make `claimsMatchConfig` return `false` immediately when either expected value is an empty string, so a misconfigured deploy fails closed at the claim-check layer rather than silently passing.
        - Add a test asserting that a JWT bearing a foreign `iss` is rejected even when the JWKS path throws.
    - **Technical:** `claimsMatchConfig` casts both config values to `(string)` and guards the checks with `if ($issExpected && ...)` / `if ($audExpected)`. When the env vars are absent, `(string) config(...)` returns `""`, which is falsy, causing both checks to be skipped entirely and the method to return `true`. This guard is called inside `verifyWithAuthServer()` (the fallback path) on an unverified payload decoded without signature checking — `extractJwtPayloadClaims` decodes the JWT body without any crypto. An attacker who controls a separate Supabase project can mint a JWT whose payload looks correct, and if JWKS is down AND the env vars are missing, it passes all checks. The JWKS primary path is unaffected (signature verification catches this independently), but the fallback path has no other protection when the config is absent.
    - **Plain English:** Imagine a nightclub's front door has two bouncers. The first bouncer (primary) checks a cryptographic wristband — very hard to fake. The second bouncer (backup, used when the wristband scanner breaks) is supposed to check a guest list showing which party this wristband belongs to. Right now, if someone forgot to print the guest list, the backup bouncer just waves everyone through — including guests from completely different clubs. We need the backup bouncer to refuse entry entirely rather than defaulting to "let them in" when the list is missing.
    - **Evidence:**
        ```php
        private function claimsMatchConfig(array $claims): bool
        {
            $issExpected = (string) config('supabase.jwt_issuer');
            $audExpected = (string) config('supabase.jwt_audience');

            if ($issExpected && (($claims['iss'] ?? null) !== $issExpected)) {
                return false;
            }

            $aud = $claims['aud'] ?? null;
            if ($audExpected) {
                // ...
            }

            return true;
        }
        ```

- [ ] **SHOP-1** · P1 — All Shopify webhook controllers trust the unsigned `X-Shopify-Shop-Domain` header to resolve the target tenant after HMAC passes, enabling any Partna-connected brand to trigger disconnect or GDPR redaction against another brand
    - **Where:** app/Http/Controllers/Concerns/HandlesShopifyWebhook.php:65,93 — app/Http/Controllers/Api/Webhooks/Shopify/ShopifyAppUninstalledWebhookController.php:22 — app/Http/Controllers/Api/Webhooks/Shopify/ShopifyGdprWebhookController.php:36 — app/Http/Controllers/Api/Webhooks/Shopify/ShopifyThemePublishedWebhookController.php
    - **Affects:** All Shopify webhook endpoints. The highest-impact paths are `app/uninstalled` (nulls the target brand's access token, sets them to `BrandStatus::Disconnected`) and `shop/redact` GDPR (triggers a `RedactShopJob` for the wrong shop). An attacker who owns any Shopify store connected to Partna can trigger a legitimate app/uninstalled webhook on their own store — receiving a valid HMAC body — then replay it with `X-Shopify-Shop-Domain` changed to any other brand's `.myshopify.com` domain.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - In `HandlesShopifyWebhook::__invoke()`, after JSON decoding the body, extract the authoritative shop identity from the HMAC-protected payload: `$payloadDomain = strtolower(trim((string) ($payload['myshopify_domain'] ?? $payload['domain'] ?? '')))`. If `$payloadDomain` is non-empty and does not match `$shopDomain`, return `$this->error('shop_domain_mismatch', 400)` and release the cache key.
        - Apply the same cross-check inline in `ShopifyAppUninstalledWebhookController` (which does not use the trait) and `ShopifyGdprWebhookController`.
        - Add a test: sign a webhook body for Shop A, set the header to Shop B's domain, assert a 400 rejection.
    - **Technical:** Shopify's HMAC signature covers the raw request body only — headers are unsigned. Every controller that reads `X-Shopify-Shop-Domain` to resolve a `ProfessionalIntegration` row is trusting an unsigned envelope label. The Shopify app/uninstalled payload body includes `myshopify_domain` and `domain` fields that identify the actual originating store — these are covered by the HMAC. Cross-referencing body vs header is therefore the correct fix; the header can still be used as a fast-path for logging, but tenant resolution must derive from the signed body. The `HandlesShopifyWebhook` trait is shared by all six order webhook controllers in addition to the GDPR and uninstall paths, so the fix in the trait covers the broadest surface.
    - **Plain English:** When Shopify sends a signed letter to Partna, it stamps the contents of the letter — but not the "To:" address on the envelope. Right now the system reads the envelope address to decide which store to act on, and ignores the store name written inside the letter. An attacker who receives a legitimately signed letter about their own store can scratch out the "To:" address, write another brand's name, and have Partna disconnect that brand's store or trigger a data-deletion job against them. The fix is to always read the store name from inside the signed letter and reject any delivery where the envelope address doesn't match.
    - **Evidence:**
        ```php
        // HandlesShopifyWebhook.php:65,93 — trait used by all order + update + shop webhook controllers
        $shopDomain = mb_strtolower(trim((string) $request->header('X-Shopify-Shop-Domain', '')));
        // ...
        $integration = ProfessionalIntegration::query()
            ->where('shopify_shop_domain', $shopDomain)
            ->where('provider', ProfessionalIntegration::PROVIDER_SHOPIFY)
            ->first();

        // ShopifyAppUninstalledWebhookController.php:22 — inline, same pattern
        $shopDomain = strtolower(trim((string) $request->header('X-Shopify-Shop-Domain', '')));
        // ...
        $integration = ProfessionalIntegration::query()
            ->where('shopify_shop_domain', $shopDomain)
            ->where('provider', ProfessionalIntegration::PROVIDER_SHOPIFY)
            ->lockForUpdate()
            ->first();
        ```

---

## P2 — Should fix

- [ ] **AUTH-2** · P2 — `SUPABASE_JWKS_FAIL_CLOSED` defaults to `false`, silently degrading to the auth-server fallback path during JWKS outages without operator awareness
    - **Where:** app/Http/Middleware/Auth/VerifySupabaseJwt.php — `handle()` — `if (config('supabase.jwks_fail_closed', false))`
    - **Affects:** All authenticated API traffic during a JWKS endpoint outage. The auth-server fallback still validates tokens, but it shifts trust from a stateless cryptographic check to a synchronous Supabase HTTP call, and the degradation is invisible unless operators watch for the `JWT JWKS verification failed` warning log.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Set `SUPABASE_JWKS_FAIL_CLOSED=true` in production via the Laravel Cloud environment config and document it in `.env.example` with a comment explaining the tradeoff.
        - Alternatively, flip the default in the config to `true` and require an explicit `SUPABASE_JWKS_FAIL_CLOSED=false` to enable the fallback, making the insecure path opt-in rather than opt-out.
        - Ensure the `JWT JWKS verification failed` warning emits a Nightwatch alert so ops know when the system is running on the fallback path.
    - **Technical:** The second argument to `config('supabase.jwks_fail_closed', false)` is the PHP-side default — it takes effect when the key is absent from the config entirely. A production deployment that never sets this env var therefore falls back silently. The auth-server fallback still calls `claimsMatchConfig` first (and is independently protected once AUTH-1 is fixed), but it introduces a per-request HTTP dependency on Supabase's `/auth/v1/user` endpoint and broadens the trust boundary. The intent documented in the code comment ("Set SUPABASE_JWKS_FAIL_CLOSED=true in production if you prefer hard failures") implies this is already understood; the issue is that the safe behavior is opt-in rather than the default.
    - **Plain English:** Think of JWKS verification as the fast, self-contained ID scanner at the door. The auth-server fallback is calling Supabase's own office to ask "is this person real?" — it works, but it's slower, depends on a phone call going through, and is a step down in security rigour. Right now, if the ID scanner breaks and no one has set the "refuse entry when the scanner is broken" flag, the system quietly switches to phone calls without telling anyone. We should make the system refuse entry by default when the scanner is broken, and require an explicit opt-in to allow the fallback — rather than the other way around.
    - **Evidence:**
        ```php
        // Fail-closed mode: refuse to fall back to Auth-Server during JWKS outage.
        // Set SUPABASE_JWKS_FAIL_CLOSED=true in production if you prefer hard failures
        // over the reduced-security fallback path.
        if (config('supabase.jwks_fail_closed', false)) {
            return response()->json(['message' => 'Service unavailable'], 503);
        }
        ```
