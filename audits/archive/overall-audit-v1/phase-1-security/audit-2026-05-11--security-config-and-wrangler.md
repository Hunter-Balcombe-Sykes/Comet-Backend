`★ Insight ─────────────────────────────────────`
**What I found during verification:**
- `Gate::forUser($pro)->authorize(...)` is used correctly throughout the Stripe controllers — this IS the safe pattern (not the silent-pass `$this->authorize()`)
- No raw SQL injection vectors (`DB::raw($input)`, `orderByRaw($request->input(...))`) exist in the controllers
- All Shopify, Stripe, and GDPR webhook controllers use HMAC verification before processing; deduplication is correctly implemented
- `VerifyHydrogenApiKey` and `VerifyEmbeddedApiKey` are both fail-closed via `RuntimeException` in non-local/testing environments — the bypass-on-empty cautionary tale is fixed
`─────────────────────────────────────────────────`

# Security Audit — 2026-05-11

**Branch:** development
**Lens:** Security: auth boundaries, tenant isolation, webhooks, secrets, injection, SSRF, PII exposure
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
- config/supabase.php
- config/cors.php
- config/session.php
- config/services.php
- config/partna.php
- config/app.php
- cloudflare-worker/wrangler.toml
- app/Http/Middleware/Auth/VerifySupabaseJwt.php
- app/Http/Middleware/Auth/VerifyHydrogenApiKey.php
- app/Http/Middleware/Auth/VerifyEmbeddedApiKey.php
- app/Http/Middleware/Auth/VerifyShopifySessionToken.php
- app/Http/Controllers/Api/Webhooks/StripeWebhookController.php
- app/Http/Controllers/Api/Webhooks/StripeConnectWebhookController.php
- app/Http/Controllers/Api/Webhooks/ShopifyOrderWebhookController.php
- app/Http/Controllers/Api/Webhooks/ShopifyGdprWebhookController.php
- app/Http/Controllers/Concerns/ValidatesShopifyWebhookHmac.php
- app/Http/Controllers/Api/Shopify/ShopifyAppOAuthController.php
- app/Providers/AppServiceProvider.php (Gate::policy registrations)
- tests/Feature/Security/PolicyCoverageTest.php

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 1 complete
- P3 Low: 0 of 1 complete

---

## P2 — Should fix

- [ ] **#SEC-1** · P2 — JWKS outage degrades to Auth-server fallback path; `jwks_fail_closed` defaults off in production
    - **Where:** config/supabase.php:18–20
    - **Affects:** Every Supabase JWT-authenticated request during a JWKS fetch failure — the system falls through to a secondary verification path (Supabase Auth `/auth/v1/user`) rather than refusing service. The config comment itself marks `jwks_fail_closed=true` as "recommended for production once JWKS is stable."
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Set `SUPABASE_JWKS_FAIL_CLOSED=true` in the production `.env` (and staging); the code at `VerifySupabaseJwt:83–85` already handles this correctly — no code change needed.
        - Optionally add a boot-time assertion in `AppServiceProvider::boot()` that throws when `APP_ENV=production` and `jwks_fail_closed=false`, so a missed env var fails the deploy rather than silently degrading.
    - **Technical:** Category 1 — Authentication boundary correctness. The `VerifySupabaseJwt` middleware enforces two important constraints in its JWKS path: (a) algorithm must be `RS256` or `ES256` (algorithm-confusion guard), and (b) `kid` must be present in the fetched key set. When JWKS fetch fails for any reason — network blip, Supabase infra issue, misconfigured `SUPABASE_JWKS_URL` — the exception propagates out of `verifyWithJwks`, is caught at line 72, and the middleware falls through to `verifyWithAuthServer`. The Auth-server path does perform real signature verification (it sends the token to Supabase's `/auth/v1/user`), so this is not an authentication bypass. However, the Auth-server path bypasses the algorithm allowlist: a Supabase project still issuing HS256 tokens (legacy symmetric mode) would be accepted via the fallback but rejected via JWKS. More importantly, `jwks_fail_closed=false` means a partial infra failure during JWKS fetch produces a degraded-but-live auth path, which makes incidents harder to detect and diagnose. The config comment already calls out the recommended posture; the env default simply hasn't been set in production yet. Recent commit history shows no change to this default.
    - **Plain English:** Your front-door security check normally works by verifying visitor badges against a local list. If that local list becomes temporarily unavailable, the system currently waves visitors through by calling a central registry instead — which still checks validity, just via a different (slightly less strict) method. The comment in the code itself says this alternative path should be turned off in production. The fix is flipping a single setting so that if the badge list goes down, the door locks instead of staying open on the backup check. No customer is affected during normal operation; this only matters during an outage, which is exactly when you want the safest behavior.
    - **Evidence:**
        ```php
        // When true, a JWKS outage returns 503 instead of falling back to Auth-Server.
        // Recommended for production once JWKS is stable.
        'jwks_fail_closed' => (bool) env('SUPABASE_JWKS_FAIL_CLOSED', false),
        ```

---

## P3 — Nice to have

- [ ] **#SEC-2** · P3 — Session payloads stored unencrypted at rest in the database
    - **Where:** config/session.php:22
    - **Affects:** Session table in the application database — if the sessions table is ever read by an unauthorized party, all stored payloads (CSRF tokens, Horizon dashboard state, flash messages) are readable as plaintext.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Set `SESSION_ENCRYPT=true` in production `.env`.
        - Verify `APP_KEY` is set to a 32-byte random value (required for AES-256-CBC encryption; `config/app.php` already reads it correctly).
        - After enabling encryption, flush the sessions table (`TRUNCATE sessions`) or accept that existing sessions will fail to decrypt and log out active users (Horizon dashboard sessions only — no end-user impact).
    - **Technical:** Category 5 — Secrets handling. Laravel encrypts session payloads via `APP_KEY`-derived AES-256-CBC when `encrypt => true`. In this architecture, Supabase JWT is the exclusive auth mechanism and `Auth::user()` always returns `null`; sessions are not used for customer auth. The practical exposure is limited to the Horizon dashboard (HTTP Basic Auth via `AppServiceProvider`) and any CSRF token state. However, the sessions table is in the same PostgreSQL database as application data; a DB-level credential leak (e.g., via a compromised `DB_PASSWORD`) would expose session contents alongside application data. `config/session.php` already sets `secure => true`, `http_only => true`, and `same_site => lax` for the cookie transport — encryption is the one remaining at-rest gap. The fix is a single env var; Laravel handles the encryption/decryption transparently.
    - **Plain English:** Your app keeps temporary notes about browser sessions in a database table. Those notes don't contain anything highly sensitive (there's no password or payment data in there — real auth is done via a separate token system), but they're written in plain text. It's a bit like leaving sticky notes on your desk rather than in a locked drawer. The fix is a one-line setting that makes the app scramble those notes before storing them, so even if someone got into the database they'd see gibberish.
    - **Evidence:**
        ```php
        'encrypt' => env('SESSION_ENCRYPT', false),
        ```
