# Partna Phase 1 Security — Consolidated Remediation Plan

> **FROZEN as of 2026-05-12.** This plan is no longer the source of truth for status, regressions, or post-baseline annotations. Live status — including all changes from PR #12 onwards and any new findings introduced after this date — lives in `audits/MASTER-REMEDIATION-PLAN.md`. This file is preserved as provenance for `Original ID: Phase 1 Pattern X` references in the master plan. Do not edit; if a status change is needed, update the master instead.

**Date:** 2026-05-11
**Branch:** development
**Source:** 7 audits across `audits/phase-1-security/`, adjudicated by `claude-sonnet-4-6` over `deepseek-v4-pro` drafts

## Summary

- **29 reported findings**, **26 unique** after deduplication (3 cross-audit duplicates)
- **Tier breakdown (reported):** 3 P0 · 9 P1 · 13 P2 · 4 P3
- **Tier breakdown (unique):** 3 P0 · 7 P1 · 12 P2 · 4 P3
- **Four foundational patterns close 13 of 26 unique findings** (3 P0 · 6 P1 · 4 P2)
- **13 standalone fixes** for the rest (1 P1 · 8 P2 · 4 P3)
- **Estimated total:** ~1.5–2 weeks of focused work to close all 26 unique findings

## Cross-audit duplicates (collapse on fix)

| Finding | Audits | Same root cause |
|---------|--------|-----------------|
| `EmbeddedConnectController` inline `if ($expected !== '')` bypass-on-empty | SEC-A#2 (P1) ≡ SEC-C#1 (P0) | Pattern A |
| `VerifyEmbeddedApiKey` resolves tenant from `X-Shopify-Shop` header | SEC-A#1 (P0) ≡ SEC-F#2 (P1) | Pattern A |
| Shopify OAuth Path B email-based account linking | SEC-B#3 (P2) ≡ SEC-F#1 (P0) | Pattern B |

When the foundational pattern lands, all dup checkboxes flip together. Take the higher tier as the canonical severity.

## Source audit files

- `audit-2026-05-11--security-auth-middleware-and-policies.md` (SEC-A: 1 P0, 1 P1, 2 P2)
- `audit-2026-05-11--security-webhooks-and-shopify-oauth.md` (SEC-B: 3 P2)
- `audit-2026-05-11--security-internal-and-pro-shopify-integration.md` (SEC-C: 1 P0, 3 P1, 1 P2)
- `audit-2026-05-11--security-pro-stripe-brand-affiliate-and-requests.md` (SEC-D: 3 P1)
- `audit-2026-05-11--security-public-site-and-resources.md` (SEC-E: 2 P2, 2 P3)
- `audit-2026-05-11--security-services-and-cloudflare-worker.md` (SEC-F: 1 P0, 2 P1, 4 P2, 1 P3)
- `audit-2026-05-11--security-config-and-wrangler.md` (SEC-G: 1 P2, 1 P3)

---

## Post-baseline annotations (2026-05-12)

The commits on `origin/development` (`60f231c..feeab29`, 17 commits across PR #12–#25) landed between audit generation (2026-05-11) and this plan. They were Shopify-feature-correctness work (namespace rename, Storefront→Admin API switch, embedded-setup reliability), not remediation. **No Phase 1 finding closed cleanly.**

**Findings re-classified after the May 11-12 window:**

- `#SEC-C-4` (P1) — **Partial.** PR #23 (`1c03040`) added `validateShopifyAccessToken()` probing `GET /admin/api/{ver}/shop.json` and refusing on HTTP 401. Two gaps remain — see updated Pattern A Step 6 below.

**New audit-worthy concerns introduced by these commits** — captured in the appendix at the bottom of this file. Treat them as Phase 1.5 ops-safety follow-ups, not original-tier findings.

---

# Part 1 — Foundational fixes

These four patterns are sequenced by fix-leverage (findings closed per day of work). Land them in this order: Pattern D first (smallest, fastest, biggest tier impact per hour), then C, then A, then B.

## Pattern A — Unify the embedded Shopify auth model

**Closes 4 unique findings (2 P0 · 1 P1 · 1 P2):** SEC-A#1 (≡ SEC-F#2), SEC-A#2 (≡ SEC-C#1), SEC-A#3, SEC-C#4

**Effort:** ~2–3 days

### Root cause

Two parallel auth mechanisms protect the embedded Shopify-admin surface:

| Mechanism | Tenant identity from | Risk |
|-----------|---------------------|------|
| `embedded.key` middleware | `X-Shopify-Shop` request header (client-controlled) | Tenant decoupled from auth — key compromise = all-tenant compromise |
| `shopify.session` middleware | Shopify-signed JWT `dest` claim (cryptographic) | Tenant cryptographically bound to token |

`shopify.session` already exists and is used for the UI extension routes at `routes/api.php:199–206`. The setup-wizard routes (`routes/api.php:178–193`) were left on `embedded.key` because they were written first. The `EmbeddedConnectController` bootstrap endpoint is on neither and uses an inline auth check that fails open if the env var is empty.

### What to do

- [ ] **Step 1 — Migrate setup-wizard routes to `shopify.session`.** All 13 routes in the `embedded.key` group at `routes/api.php:178–193` become `shopify.session`-authenticated. Pre-condition: confirm with Shopify that App Bridge session tokens are available on every setup-wizard page (they should be — App Bridge is standard for embedded admin extensions).
- [ ] **Step 2 — `EmbeddedSetupController` reads tenant from JWT `dest`.** No controller code changes if the session-token middleware is set to populate the same `embedded_professional_id` request attribute the existing middleware uses.
- [ ] **Step 3 — Add JTI replay gate to `VerifyShopifySessionToken`** before relying on it for high-impact routes. After `JWT::decode`, `Cache::add("partna:jti:{$jti}", 1, 120)` — duplicate returns false → 401. Closes SEC-A#3.
- [ ] **Step 4 — Reduce `VerifyEmbeddedApiKey` to a single use.** Only `POST /internal/embedded/connect-account` keeps it (no shop is linked yet, session token unavailable). Confirm via grep that no other route group references `embedded.key` after migration.
- [ ] **Step 5 — Fix `EmbeddedConnectController` fail-open.** Replace inline `if ($expected !== '')` with the same `app()->environment(['local','testing'])` guard + `RuntimeException` pattern the middleware already uses. Closes SEC-A#2/SEC-C#1.
- [ ] **Step 6 — Verify access tokens before storing them.** **Partial fix already landed via PR #23 (`1c03040`).** `validateShopifyAccessToken()` calls `GET /admin/api/{ver}/shop.json` and refuses on HTTP 401, but only when `$tokenChanged` (existing integration with a new token). Two remaining gaps to close:
    1. **Run validation on every install, including first.** Currently `validateShopifyAccessToken()` runs only when `$existing !== null && $tokenChanged`. A brand's very first install still writes the token unverified. Fix: drop the `$tokenChanged` gate and validate on every persist.
    2. **Assert response domain matches header-derived shop domain.** The current implementation only treats `!= 401` as success and discards the body. Read `data.shop.myshopify_domain` from the response and assert equal (after `strtolower(trim($x, " /"))` on both sides) to the validated `$shopDomain`. Refuse with 422 on mismatch — closes the cross-shop-token-replay vector (token issued for shop A, submitted under header for shop B).
    - **Safe-sequencing note (behavior change ahead):** this changes the first-install flow from "always succeed" to "may refuse." To keep the working OAuth flow working: on **5xx or network timeout**, log a warning and proceed (transient Shopify outage shouldn't refuse a legitimate install). Only refuse on **401** (bad token) or **domain mismatch** (replay). Verify against `radiorufus` dev brand that normalised `myshopify_domain` values match across the header and `/shop.json` body before merging.
    - **Logging hygiene piggyback:** the existing implementation logs `'error' => $e->getMessage()` on the network-failure path. `$e->getMessage()` from cURL can include the resolved URL/IP — marginally adjacent to `#SEC-F-3` SSRF. Replace with `'error_class' => class_basename($e)` to avoid leaking resolved hosts into Nightwatch.
    - Closes SEC-C#4.

### Plain English

The setup wizard currently uses a single shared password for all brands and reads which brand to act on from a header in the request — meaning anyone who finds the password can act on any brand. Shopify's session tokens are the same idea but with the brand identity cryptographically baked into the token, so each brand can only act on themselves. The work is to retire the shared password for the wizard, move everything to session tokens, and harden the one route that still has to use the shared password (because it runs before any brand is connected) so it can never silently fall open.

### Why this is the highest-leverage fix

Three independent audits flagged `VerifyEmbeddedApiKey`'s tenant-from-header pattern as a P0 or P1. When the same architectural decision is visible from three angles (middleware, controller, service), the fix isn't to patch each angle — it's to remove the decision. Migrating to `shopify.session` closes the entire embedded-auth P0/P1 spine.

---

## Pattern B — `ShopDomain` value object + kill OAuth Path B

**Closes 5 unique findings (1 P0 · 2 P1 · 2 P2):** SEC-B#3 (≡ SEC-F#1), SEC-C#3, SEC-C#5, SEC-F#3, SEC-F#5

**Effort:** ~2–3 days

### Root cause

The codebase passes `string $shopDomain` around as a plain primitive. Some call sites validate it (`BrandDesignImporter`, `ShopifyDataResyncService` both run a `*.myshopify.com` regex); others don't (`ShopifyTeardownService`, `BrandSignupService::revokeStorefrontToken`). The OAuth Path B email-match path is a parallel form of the same trust ambiguity: a Shopify-controlled string (the shop's contact email) is treated as if it proved Partna account ownership.

### What to do

- [ ] **Step 1 — Introduce `App\Services\Shopify\ShopDomain` value object.** Readonly PHP 8 class, constructor enforces `/^[a-z0-9][a-z0-9\-]*\.myshopify\.com$/`, throws `InvalidShopDomainException` on bad input. One named constructor `ShopDomain::fromUntrusted(string)` for boundary parsing.
- [ ] **Step 2 — Change `ShopifyAdminClient::rest()` and `graphql()` signatures.** Replace `string $shopDomain` parameter with `ShopDomain $shop`. Compiler enforces validation at every call site. Closes SEC-F#3.
- [ ] **Step 3 — Add path-prefix enforcement to `rest()`.** Guard at top: `if (!str_starts_with($path, '/admin/')) throw new \InvalidArgumentException(...)`. Closes SEC-F#5.
- [ ] **Step 4 — Pin DNS in `discoverShopifyHandle()`.** After validating the host's IP via `gethostbynamel()`, thread the resolved IP into the Guzzle call via `CURLOPT_RESOLVE` so DNS only resolves once. Closes SEC-C#3.
- [ ] **Step 5 — Remove the third regex pattern in `discoverShopifyHandle()`.** The catch-all `/([a-z0-9][a-z0-9-]*\.myshopify\.com)/i` matches anywhere in scraped HTML including UGC. The two structured patterns (`Shopify.shop = "..."`, `"shop":"..."`) above it cover well-formed themes. Closes SEC-C#5.
- [ ] **Step 6 — Kill OAuth callback Path B (email auto-match).** `ShopifyAppOAuthController::callback` lines 134–146 + `BrandSignupService::handleExistingBrandConnect`. Replace with: if no `ProfessionalIntegration` exists for the shop domain, store a setup token (already implemented for Path C) and redirect to the dashboard. Brand must log in via Supabase JWT and explicitly authorize the connection. Closes SEC-B#3 / SEC-F#1.

### Plain English

The codebase passes around the name of a Shopify store as plain text. Most code that uses that name double-checks it looks right; some code doesn't. We can't make every individual caller remember to check — instead we replace "plain text store name" with a typed object whose constructor refuses to be created from invalid input. Then the type system stops anyone from forgetting. Separately, the OAuth flow currently auto-links a Shopify store to an existing Partna account if the store's email matches — but that email is editable by any store owner with no verification. We replace that with a dashboard-confirmation flow.

### Why this matters beyond closing today's findings

A `ShopDomain` value object makes a whole class of *future* SSRF/injection bugs structurally impossible. Every new caller is forced to construct a `ShopDomain` (which validates), so they can't forget the regex check the way `ShopifyTeardownService` did. Same for the path-prefix enforcement on `rest()`. This is the "primitive obsession" antipattern: strings carry no validation contract.

---

## Pattern C — Canonical Shopify webhook controller base class

**Closes 2 unique findings across 6 files (2 P2):** SEC-B#1, SEC-B#2

**Effort:** ~2–4 hours

### Root cause

Seven Shopify webhook controllers were written one at a time, each subtly different. `ShopifyGdprWebhookController` is the canonical correct pattern (HMAC-first, 422-on-malformed); the other six drift in two ways:

1. **Dedup before HMAC:** `ShopifyOrderWebhookController` + 5 siblings call `Cache::has($dedupKey)` before `isValidShopifyHmac()`. Cache poisoning isn't possible (the cache write is post-HMAC), but unauthenticated callers can probe `X-Shopify-Webhook-Id` UUIDs and observe `duplicate:true` vs `401 invalid signature` responses.
2. **Malformed JSON returns HTTP 200:** the same six controllers treat `json_decode` failure as success and return 200, which tells Shopify to stop retrying. The event is permanently lost. `ShopifyGdprWebhookController` correctly returns 422; the comment in that file explicitly documents why.

### What to do

- [ ] **Step 1 — Extract `App\Http\Controllers\Concerns\HandlesShopifyWebhook` trait** (or `AbstractShopifyWebhookController` base class). Enforces canonical sequence: `verifyHmac() → cacheAddDedup() → decodePayload(or 422) → dispatchJob() → 200`.
- [ ] **Step 2 — Subclasses provide only:** `topic()`, `dedupKeyPrefix()`, `dispatchJob(array $payload)`. No HMAC, no dedup, no JSON decode in subclass code.
- [ ] **Step 3 — Convert all 6 drifting controllers** (`ShopifyOrderWebhookController`, `ShopifyOrdersCancelledWebhookController`, `ShopifyOrdersEditedWebhookController`, `ShopifyOrdersUpdatedWebhookController`, `ShopifyRefundsCreateWebhookController`, `ShopifyShopUpdateWebhookController`) to use the trait.
- [ ] **Step 4 — Verify** `ShopifyGdprWebhookController` and `ShopifyThemePublishedWebhookController` still pass tests — they should be no-op converts since they already follow the canonical pattern.

### Plain English

Seven of these doors were built by different people on different days, and most of them have the lock and the stamp in the wrong order. We're going to write one canonical "how to receive a Shopify webhook" pattern and convert all seven doors to use it. Six of them already mostly work — they just need to be updated.

---

## Pattern D — Shared form-request safety primitives

**Closes 3 unique findings (3 P1):** SEC-D#1, SEC-D#2, SEC-D#3

**Effort:** ~30 minutes
**Status:** Done — 2026-05-13 (commit `d4b03ee`)

### Root cause

`StorePlanSubscriptionRequest::allowedRedirectRule()` already exists and validates that `success_url` / `cancel_url` point to the application's own origin (host allow-list against `config('app.frontend_url')`, `config('app.url')`, `localhost`, `127.0.0.1`). Three Stripe Connect form requests (`OnboardRequest`, `CreatePaymentMethodSetupRequest`, `CreateTopUpCheckoutRequest`) accept the same fields with only the `url` rule — open redirect via Stripe's hosted page after the user completes a real payment flow.

### What to do

- [x] **Step 1 — Move `allowedRedirectRule()` from `StorePlanSubscriptionRequest` to `BaseFormRequest`** as a `protected` method. Single source of truth.
- [x] **Step 2 — Apply to `OnboardRequest::rules()`** for `return_url` and `refresh_url`.
- [x] **Step 3 — Apply to `CreatePaymentMethodSetupRequest::rules()`** for `success_url` and `cancel_url`.
- [x] ~~**Step 4 — Apply to `CreateTopUpCheckoutRequest::rules()`** for `success_url` and `cancel_url`.~~ **N/A — `CreateTopUpCheckoutRequest` does not exist.** Wallet top-up endpoints were removed (see `StripeConnectWebhookController.php:165`: legacy in-flight top-ups are logged and dropped). No surface left to harden.

### Plain English

Three Stripe redirect-URL form-request classes are missing the host-validation rule that the billing subscription requests already use. Move that rule to the base class so all four classes share one implementation, then apply it in three places. Less than an hour.

### Why this matters more than the tier suggests

Open redirects via Stripe are unusually effective social engineering targets because the user just completed a real, branded Stripe flow ("I just paid", "I just connected my bank") and is in a high-trust visual state. Bouncing them to `attacker.example.com/login.html` exactly at that moment is much more convincing than a cold redirect.

---

# Part 2 — Standalone fixes

These 13 findings don't cluster into architectural patterns. Each is a discrete, localized fix.

## Cluster: Multi-tenant fan-out (needs its own design)

- [ ] **#SEC-C-2 · P1** — `HydrogenDeploymentController::targets` returns *all* brands' decrypted Oxygen deployment tokens behind a single `HYDROGEN_API_KEY`. CI secret leak → one request → every brand's storefront is overwriteable.
    - **Effort:** M (~2–4h)
    - **Where:** `app/Http/Controllers/Api/Internal/HydrogenDeploymentController.php` (`targets` method)
    - **What to do:** Layer GitHub Actions OIDC verification on top of the static key. Workflow requests a short-lived OIDC token, backend verifies against GitHub's JWKS before returning tokens. Longer term: separate "single brand deploy" path from "all brands deploy" path with different scoping so a leaked key compresses blast radius from "all brands" to one.
    - **Plain English:** The deployment pipeline has one master key that, if leaked, unlocks every brand's storefront at once. Layer GitHub's per-workflow temporary tokens on top so a leaked static key alone is no longer sufficient.
    - **Source:** SEC-C audit

## Cluster: Logging hygiene (one convention + 4 sweeps)

A shared rule: **never log raw response bodies; only structured error fields.** Could be enforced via a Pint custom rule or a sweeping codemod.

- [ ] **#SEC-F-4 · P2** — `SupabaseAdminService::createUser` failure log includes raw email. GDPR concern at log-aggregator retention scale.
    - **Effort:** S (~0.5–1h)
    - **Where:** `app/Services/Auth/SupabaseAdminService.php:96–103`
    - **What to do:** Replace `'email' => $email` with `'email_hash' => hash_hmac('sha256', $email, config('app.key'))`. Correlatable for support without raw PII storage.

- [ ] **#SEC-F-6 · P2** — `CloudflareDnsService` logs full Cloudflare response body on every error (zone IDs, internal diagnostics).
    - **Effort:** S (~0.5–1h)
    - **Where:** `app/Services/Cloudflare/CloudflareDnsService.php` — `ensureCname`, `upsertCname`, `upsertTxt`, `deleteRecord`
    - **What to do:** Replace `'body' => $response->body()` with `'cf_errors' => $response->json('errors', [])` everywhere. Cloudflare structures errors as `[{"code": N, "message": "..."}]` — the structured field has everything needed for debugging.

- [ ] **#SEC-F-7 · P2** — `HydrogenDeploymentService` logs full GitHub API response body on non-2xx (repo metadata, workflow paths).
    - **Effort:** S (~0.5–1h)
    - **Where:** `app/Services/Hydrogen/HydrogenDeploymentService.php:42–46`
    - **What to do:** Replace `'body' => $response->body()` with `'github_message' => $response->json('message')`.

- [ ] **#SEC-F-8 · P3** — `ShopifySetupTokenService` encrypts the access token but stores `shop_email` plaintext in Redis next to it for 60 minutes. Internal-access risk only (Horizon, redis-cli).
    - **Effort:** S (~0.5–1h)
    - **Where:** `app/Services/Shopify/ShopifySetupTokenService.php:22–30`
    - **What to do:** Wrap `$shopEmail` with `encrypt()` to match `$accessToken`'s treatment. Update `decryptPayload` to decrypt it on the way out. Or omit it entirely (it's already in `shop_data['email']`).

## Cluster: Production env var flips (one PR, two lines)

- [ ] **#SEC-G-1 · P2** — `SUPABASE_JWKS_FAIL_CLOSED` defaults `false` in production. JWKS outage → falls through to `verifyWithAuthServer()`, which still verifies signatures but bypasses the `RS256/ES256` algorithm allowlist. Config comment itself recommends flipping it.
    - **Effort:** S (~15min)
    - **Where:** `.env` (production) + `config/supabase.php:18–20`
    - **What to do:** Set `SUPABASE_JWKS_FAIL_CLOSED=true` in production env. Optionally add a boot-time assertion in `AppServiceProvider::boot()` that throws when `APP_ENV=production && !jwks_fail_closed` to fail the deploy rather than silently degrade.

- [ ] **#SEC-G-2 · P3** — `SESSION_ENCRYPT=false` — session payloads (CSRF tokens, Horizon dashboard state) stored unencrypted. Low blast radius (Supabase JWT is the real auth path; sessions only used for Horizon basic-auth).
    - **Effort:** S (~15min)
    - **Where:** `.env` (production) + `config/session.php:22`
    - **What to do:** Set `SESSION_ENCRYPT=true`. Verify `APP_KEY` is a 32-byte random value. Truncate the sessions table or accept that existing sessions will fail to decrypt (Horizon dashboard sessions only — no end-user impact).

## Cluster: Public-surface enumeration (convention + small fixes)

A shared rule: **public endpoints return uniform "not available" semantics; never confirm existence.**

- [ ] **#SEC-E-3 · P3** — `PublicSignupAvailabilityController` returns `{available: false, exists: true}`. The `exists` flag explicitly confirms a user is registered, marginally beyond what `available: false` already says.
    - **Effort:** S (~0.5–1h)
    - **Where:** `app/Http/Controllers/Api/PublicSite/PublicSignupAvailabilityController.php:35–60`
    - **What to do:** Remove the `exists` field from the response. Caller still gets `available: true/false`.

- [ ] **#SEC-E-4 · P3** — `PublicShopifyStorefrontController` 404 vs 202/200 distinction reveals which Shopify merchants are Partna customers. Slow-rate enumeration via `throttle:public-site` (60/min/IP).
    - **Effort:** S (~0.5–1h)
    - **Where:** `app/Http/Controllers/Api/PublicSite/PublicShopifyStorefrontController.php:29–90`
    - **What to do:** Return 404 if brand status is not `active` (instead of 202). Optionally return uniform 202 for both "token pending" and "not found" when the caller is not the trusted Hydrogen server.

## Cluster: Independent fixes (no shared theme)

- [ ] **#SEC-A-4 · P2** — `X-Site-Subdomain` header accepted on public analytics endpoints with no CDN-origin validation. Anyone can attribute fake pageviews / lead submissions to any brand's site.
    - **Effort:** S (~0.5–1h)
    - **Where:** `app/Http/Controllers/Concerns/ResolvesSubdomainFromHost.php:16–19`
    - **What to do:** Remove the `X-Site-Subdomain` header path entirely (host-based extraction is sufficient and harder to forge). If a CDN does set this header, gate it behind a trusted-proxy IP allow-list.

- [ ] **#SEC-E-1 · P2** — `PublicConfigController::integrations()` serves `GOOGLE_MAPS_API_KEY` with `Cache-Control: public, max-age=3600`. Docblock says "must be HTTP-referrer-restricted" but no deploy-gate enforces it.
    - **Effort:** S (~0.5–1h)
    - **Where:** `app/Http/Controllers/Api/PublicSite/PublicConfigController.php:39–47`
    - **What to do:** Add a boot-time assertion that requires a companion `GOOGLE_MAPS_API_KEY_REFERRER_RESTRICTION_VERIFIED=true` env var when `app()->isProduction()`. Forces operator to consciously confirm referrer restriction is configured in Google Cloud Console before each deploy.

- [ ] **#SEC-E-2 · P2** — `SiteVisibilityController` uses inline `where('professional_id', $pro->id)->firstOrFail()` instead of `authorizeForUser($pro, 'update', $site)`. Bypasses `SitePolicy::denyIfPendingDeletion` (which returns 423, not 403 — the protocol-correct response for accounts in deletion grace window).
    - **Effort:** S (~0.5–1h)
    - **Where:** `app/Http/Controllers/Api/PublicSite/SiteVisibilityController.php:20–32`
    - **What to do:** Replace with unconditional `Site::query()->where('professional_id', $pro->id)->firstOrFail()` followed by `$this->authorizeForUser($pro, 'update', $site)`. Remove the manual `status !== 'active'` guard — let the policy own that rule.

---

# Suggested merge order

| Day | Work | Findings closed |
|-----|------|-----------------|
| 0.5 | **Pattern D** — Form-request safety primitives | 3 P1 |
| 1   | **Pattern C** — Webhook controller base class | 2 P2 (across 6 files) |
| 2–4 | **Pattern A** — Embedded Shopify auth unification | 2 P0, 1 P1, 1 P2 |
| 5–7 | **Pattern B** — `ShopDomain` value object + kill OAuth Path B | 1 P0, 2 P1, 2 P2 |
| 8   | Env var flips (SEC-G-1, SEC-G-2) + logging convention sweep (SEC-F-4, F-6, F-7, F-8) | 2 P2, 4 sundry |
| 9   | `HydrogenDeploymentController` OIDC layering (SEC-C-2) | 1 P1 |
| 10  | Remaining standalone fixes (SEC-A-4, SEC-E-1, SEC-E-2, SEC-E-3, SEC-E-4) | 5 sundry |

**Why this order:** Pattern D first because it's 30 minutes and closes 3 P1s — highest tier impact per hour. Pattern C next because the orchestrator can fan it out across 6 files in one ~half-day session. Patterns A and B are the architectural backbone — doing them last would mean fixing point bugs that the architectural change would have erased anyway. Doing them in the middle means the standalone fixes (week 2) are uncontaminated by the larger change.

# Appendix — New audit-worthy concerns surfaced 2026-05-12

These items emerged from cross-referencing PR #12–#25 against this plan. They are not yet in the audit ledger; folding them into the next Phase 1 sweep is recommended. None block the existing Part 1/Part 2 work.

## New artisan commands with destructive flags (P3 — ops-safety cluster)

Three artisan commands landed without `--confirm-in-production` guards or `php artisan down` preconditions:

- `MigrateMetafieldNamespaceCommand` (`8327d1f`) — supports `--delete-old`, removes Shopify metafield definitions across **every connected brand** in one invocation.
- `ReconcileSmartCollectionRulesCommand` (`1c03040`, PR #23) — rewrites smart-collection rules across every brand's Shopify store.
- `BackfillSubdomainKvCommand` (`a118f62`, PR #12) — writes Cloudflare KV entries for any/every professional. Now wired to a weekly cron (`fedcb66`, Sunday 04:00 UTC).

All three iterate the fleet via plain `->get()` / `->pluck()` — no `chunkById`, no progress checkpointing. A misfire fans out across the entire tenant base before anyone can intervene.

**What to do:** add a `ConfirmInProduction` trait to `App\Console\Commands\Concerns` that prompts on `app()->environment('production')` unless `--force` is passed (mirrors Laravel's `db:wipe` pattern). Apply to the three commands above. Cross-references `#SEC-C-2` (HydrogenDeploymentController) — same "single command, all brands" blast-radius theme.

## `public_dev` filesystem disk alias (P3 — defense-in-depth)

Commit `6e0d9c1` adds a second `s3` disk at `config/filesystems.php:88–106` reading the same `MEDIA_DISK_*` env vars with `throw=>false, report=>false`. Functionally a duplicate of the `media` disk for legacy variant URLs.

**Concern:** doubles the surface for a misconfigured-bucket leak (a misset env var would mount the wrong bucket twice, with errors suppressed on both). Low risk; worth documenting which disk is canonical and adding a boot-time assertion that both resolve to the same `MEDIA_DISK_BUCKET`.

## Cloudflare KV namespace ID committed to repo (P3 — informational)

PR #19 (`bf79e67`) committed the production KV namespace ID at `cloudflare-worker/wrangler.toml:17`. KV namespace IDs are not secrets by Cloudflare's threat model — they're operational identifiers. Flagging only because: anyone with a leaked Cloudflare API token + zone ID can now target the namespace directly without recon. No finding worsened; record as known.

## Affiliate catalog new Admin-API call site — Pattern B follow-on

PR #17 (`bef81ef`) switched `AffiliateProductCatalogService` from Storefront API to Admin API. The pre-existing `$shopDomain = trim((string) Arr::get($metadata, 'shop_domain', ''))` pattern carries forward with no `myshopify.com` regex check. Same root cause as `#SEC-F-3`, now reachable from one more code path.

**What to do:** when Pattern B (ShopDomain value object) lands, this call site joins the migration list. Update `app/Services/Store/AffiliateProductCatalogService.php` to construct via `ShopDomain::fromUntrusted($metadata['shop_domain'])`. Not a new finding; an additional call site for the existing Pattern B sweep.

## `validateShopifyAccessToken` outbound HTTP — Pattern B follow-on

PR #23 (`1c03040`) added a `Http::timeout(8)` call inside the request path. The current implementation logs the raw `$e->getMessage()` on failure (see Pattern A Step 6 above for the recommended sanitisation). When Pattern B lands, also route this call's `$shopDomain` parameter through the `ShopDomain` value object so the URL construction can't be subverted.

---

# What this plan does NOT cover

- **Phase 2–6 audits** (Lifecycle correctness, Scaling antipatterns, DB/queue scaling, Test coverage, Data integrity) — these run after Phase 1 closure per `audit-checklist.md`.
- **External audits** (`composer audit`, `npm audit`, Supabase RLS review, backup drill) — separately tracked in `audit-checklist-external.md`.
- **Pentests** (deferred to STAGE 3 per the staged checklist).
- **Things the audits explicitly verified clean** — including: `Gate::forUser($pro)->authorize(...)` is the safe pattern, no raw SQL injection vectors, all webhook controllers HMAC-verify before processing, `VerifyHydrogenApiKey` and (post-fix) `VerifyEmbeddedApiKey` are correctly fail-closed via `RuntimeException`, and `EmailSubscription::newUnsubscribeToken()` uses 285-bit entropy (brute-force infeasible).
