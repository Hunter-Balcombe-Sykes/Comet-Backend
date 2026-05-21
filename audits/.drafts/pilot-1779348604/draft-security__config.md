- [ ] **#SEC-1** · P1 — Horizon dashboard middleware uses `web` guard in a Supabase JWT app; auth may silently pass when Basic-auth creds are unset
    - **Where:** config/horizon.php:12
    - **Affects:** Horizon dashboard (`/horizon`) — exposes job payloads, failed-job retry button, queue metrics. Job payloads may contain PII (emails, order data, Stripe payment-method last4).
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Verify the `AppServiceProvider::authorizeHorizonRequest` gate fails-closed when `HORIZON_DASHBOARD_USERNAME` or `HORIZON_DASHBOARD_PASSWORD` is empty in production.
        - Add a deploy-time assertion in `AppServiceProvider::boot()` that refuses to boot in production when both Horizon dashboard creds are empty (fail-sealed, not fail-open).
        - Document that the `web` middleware group is vestigial here — the gate is the real protection.
    - **Technical:** The Horizon config uses `'middleware' => ['web']`, which boots the Laravel session cookie + CSRF stack. But this app runs Supabase JWT auth exclusively (`Auth::user()` always returns null). The `web` middleware will start a session but won't authenticate anyone. The real protection is the `Horizon::auth()` HTTP-Basic gate in `AppServiceProvider`, which compares against `HORIZON_DASHBOARD_USERNAME` / `HORIZON_DASHBOARD_PASSWORD`. If both are empty and the gate logic checks `!empty($username) && !empty($password)` → allows-through (the common pattern), production Horizon would be unauthenticated. If the gate logic checks `app()->environment('production')` → 403, it's sealed but the `web` middleware still runs unnecessarily.
    - **Plain English:** The Horizon dashboard (which shows job data, queue backlogs, and a "retry" button) is protected by a username/password check. But the app doesn't use Laravel's normal login system — it uses Supabase tokens. The config still loads the normal session system for the dashboard, which would let anyone through if those credentials are ever left blank. It's like having a door with a deadbolt but the frame is made of cardboard. The fix is to make the app refuse to start in production unless those credentials are set.
    - **Evidence:**
        ```php
        'middleware' => ['web'],
        ```
        ```php
        'dashboard' => [
            'username' => env('HORIZON_DASHBOARD_USERNAME'),
            'password' => env('HORIZON_DASHBOARD_PASSWORD'),
        ],
        ```
    - `[DRAFT, confidence: 0.7]`

- [ ] **#SEC-2** · P1 — Intentionally unauthenticated `/internal/hydrogen/affiliate` route relies entirely on controller-level mitigation
    - **Where:** routes/api.php:194-196
    - **Affects:** All Hydrogen storefront visitors — affiliate data (display name, avatar, site handle, shop domain) could leak if the controller's link-verification logic is bypassed or bugged.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a lightweight HMAC-signed token requirement to this route (a stateless `hydrogen.affiliate` middleware that validates a short-TTL signed token from the Hydrogen storefront).
        - Until the middleware is in place, audit `HydrogenAffiliateController::show()` to confirm it **always** 404s when no verified `BrandPartnerLink` exists, and that it never leaks `exists vs. not-yours` via different status codes.
        - Add a Nightwatch alert on any 2xx response from this endpoint where the resolved affiliate is null/empty.
    - **Technical:** This route lives outside the `hydrogen.key` middleware group and is the only internal Hydrogen endpoint with no auth at all. The route comment says "enumeration mitigated by controller link verification" — but without seeing the controller code, this is a single-point-of-failure trust boundary. If the controller's `BrandPartnerLink` lookup has a logic error (e.g., it resolves the brand but not the affiliate, and falls through to a default), affiliate PII could leak. The canonical fix under the Partna doctrine is a middleware that cryptographically verifies the caller, not a controller that trusts the query params.
    - **Plain English:** There's a door into the system that's intentionally left unlocked, with a sign saying "the security guard inside checks everyone's ID." If the guard gets distracted or the ID-check logic has a bug, anyone can walk in and see affiliate data. The fix is to put a proper lock on the door — a cryptographic token that proves the request came from our own storefront.
    - **Evidence:**
        ```php
        // INTENTIONALLY UNAUTHENTICATED — enumeration mitigated by controller link verification.
        // HydrogenAffiliateController::show() enforces a 404 when no verified BrandPartnerLink
        // exists; unknown shop_domain or slug values never return affiliate data.
        // Accessory endpoints (services, products) remain behind hydrogen.key since they
        // add server load with no client-side initiator.
        Route::get('/internal/hydrogen/affiliate', [HydrogenAffiliateController::class, 'show'])
            ->middleware('throttle:hydrogen-internal');
        ```
    - `[DRAFT, confidence: 0.65]`

- [ ] **#SEC-3** · P1 — `/shopify/resolve-shop` fires outbound HTTP request from user-supplied domain; SSRF risk if domain validation is lax
    - **Where:** routes/api/professional.php:245-247
    - **Affects:** Any authenticated professional who can call this endpoint — they might probe internal network hosts or metadata endpoints by supplying a malicious domain.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Audit `ShopifyIntegrationController::resolveShop()` to confirm it validates the resolved host against a strict allow-list (`*.myshopify.com` or a known Shopify custom-domain record).
        - Add a post-resolution check: after the HTTP call resolves the domain, verify the result is a `*.myshopify.com` host before returning it.
        - Consider caching successful resolutions so the endpoint can't be used to amplify request volume against arbitrary targets.
    - **Technical:** The route comment says "Resolve a custom primary domain (e.g. radiorufus.com) or bare handle to the canonical `<handle>.myshopify.com` used by the OAuth flow. Throttled because it fires an outbound HTTP request on every call." The user supplies a domain, the controller makes an HTTP request to it, and returns the canonical Shopify domain. If the controller uses `Http::get($userDomain)` without validating that `$userDomain` is a real Shopify store (e.g., by checking a known DNS CNAME or API endpoint pattern), an attacker could supply `169.254.169.254` (AWS metadata) or `127.0.0.1:5432` and exfiltrate internal data from the response. Throttling at 30/min limits the blast radius but doesn't close the vector.
    - **Plain English:** The app lets a user type in any domain name, then the server visits that domain on the user's behalf and brings back information. This is like giving someone a courier and saying "go to whatever address this person writes down and bring back a package." If they write down "the server's own internal control panel," the courier will go there and bring back secrets. The fix is to verify the address is really a Shopify store before sending the courier.
    - **Evidence:**
        ```php
        // Resolve a custom primary domain (e.g. radiorufus.com) or bare handle
        // to the canonical <handle>.myshopify.com used by the OAuth flow.
        // Throttled because it fires an outbound HTTP request on every call.
        Route::get('/shopify/resolve-shop', [ShopifyIntegrationController::class, 'resolveShop'])
            ->middleware('throttle:30,1');
        ```
    - `[DRAFT, confidence: 0.6]`

- [ ] **#SEC-4** · P1 — `/internal/env-check` protected by single shared secret; full env-var dump on compromise
    - **Where:** config/partna.php:31-34, routes/api.php:187
    - **Affects:** All secrets in the environment — Stripe keys, Shopify API secrets, Supabase service-role key, Cloudflare API tokens, database credentials, Resend API key, GitHub PAT. One token leak = total secret exposure.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a second factor: require the token AND require the request originate from a known IP range (Laravel Cloud private network or a VPN CIDR).
        - Emit a Nightwatch alert on every successful call so any unauthorized access is detected immediately.
        - Add rate limiting on this specific endpoint (`throttle:1,5` — one request per 5 minutes per IP) as defense-in-depth.
        - Document that the token value must be at least 32 random bytes (the `.env.example` doesn't specify minimum length).
    - **Technical:** The endpoint `GET /api/internal/env-check` is gated by a single `X-Internal-Token` header compared against `config('partna.internal_env_check_token')`. The config fails-closed when the token is unset (returns 503) — good. But when set, it's a single shared secret bearing the full weight of every credential in the environment. There's no IP restriction, no second factor, no anomaly alerting on the route itself. If the token appears in a log, a Slack message, or a developer's shell history, every secret in the deploy is exposed. The route also has no throttle middleware — a brute-force attack on the token could try many values.
    - **Plain English:** The app has a master key that unlocks a report showing every password and API key the system uses. If anyone ever sees that master key — in a log file, in a chat message, in a developer's command history — they get everything. The fix is to add a second lock (like only accepting the request from the company office IP) and to make the system scream loudly every time someone uses the key, so we know immediately if it's stolen.
    - **Evidence:**
        ```php
        // config/partna.php
        'internal_env_check_token' => env('INTERNAL_ENV_CHECK_TOKEN'),
        ```
        ```php
        // routes/api.php
        // Self-diagnostic env-var check. Independent of every other auth subsystem on
        // purpose — this is the endpoint you hit when something else is misconfigured.
        // Auth is a single shared-secret header inside the controller.
        Route::get('/internal/env-check', EnvCheckController::class);
        ```
        ```php
        // .env.example
        # Shared-secret token for the GET /api/internal/env-check self-diagnostic endpoint.
        # When unset, the endpoint returns 503 (fail-closed). Set this on Laravel Cloud
        # to enable remote env-var verification, then hit the URL with the matching
        # X-Internal-Token header. Use a strong random value, e.g. `openssl rand -hex 32`.
        INTERNAL_ENV_CHECK_TOKEN=
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **#SEC-5** · P2 — Shopify webhook secret falls back to API secret, conflating two separate security contexts
    - **Where:** config/services.php:125-127
    - **Affects:** All Shopify webhook routes — if `SHOPIFY_WEBHOOK_SECRET` is unset but `SHOPIFY_API_SECRET` is set, webhook HMAC verification uses the API secret. This means the API secret (used for OAuth and Admin API calls) doubles as the webhook verification key, weakening the blast-radius isolation between the two surfaces.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Remove the fallback: `'webhook_secret' => env('SHOPIFY_WEBHOOK_SECRET')` (no second argument).
        - Add a deploy-time assertion in `AppServiceProvider::boot()` that requires `SHOPIFY_WEBHOOK_SECRET` to be set in production when any Shopify routes are registered.
        - If zero-downtime rotation is needed, use the `SHOPIFY_FALLBACK_SECRET` pattern (already in config) — accept both current and previous secret during rotation, but never fall back to the API secret.
    - **Technical:** The config reads `env('SHOPIFY_WEBHOOK_SECRET', env('SHOPIFY_API_SECRET'))`. Shopify best practice is to use a dedicated webhook signing secret, separate from the API secret, so that a compromise of one doesn't grant the other. By falling back to the API secret, the blast radius of an API secret leak expands to include webhook forgery — an attacker could POST fake order-paid events and create commission rows. The fallback also masks a deployment misconfiguration: if ops forgets to set `SHOPIFY_WEBHOOK_SECRET`, the system silently degrades to using the API secret instead of failing loudly.
    - **Plain English:** The system has two keys — one for talking to Shopify's API, one for verifying that incoming webhooks really came from Shopify. But if the webhook key isn't set, the system silently uses the API key instead. This means if the API key ever leaks, the attacker can also send fake "order placed" messages and steal commissions. The fix is to remove the fallback and force the system to shout loudly if the webhook key is missing.
    - **Evidence:**
        ```php
        'webhook_secret' => env('SHOPIFY_WEBHOOK_SECRET', env('SHOPIFY_API_SECRET')),
        'fallback_secret' => env('SHOPIFY_FALLBACK_SECRET'),
        ```
    - `[DRAFT, confidence: 0.9]`

- [ ] **#SEC-6** · P2 — CORS config documents a future footgun; wildcard origins + future `supports_credentials: true` = credential theft
    - **Where:** config/cors.php:8-14
    - **Affects:** Every API endpoint if `supports_credentials` is ever flipped to `true` without also locking down `allowed_origins`.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a deploy-time assertion in `AppServiceProvider::boot()` that refuses to boot in any environment when `supports_credentials === true` AND `allowed_origins` contains `'*'`.
        - Add a CI lint rule that greps for `supports_credentials.*true` in `config/cors.php` and fails the build if `allowed_origins` still contains `'*'`.
        - Document the invariant in the CORS config comments as a hard rule: "DO NOT set supports_credentials to true without locking allowed_origins to explicit values."
    - **Technical:** The current config is safe: `allowed_origins: ['*']` with `supports_credentials: false`. Browsers reject credentialed requests to wildcard origins. But the config's own comments explicitly warn about the risk of flipping `supports_credentials` — and there's no automated guard preventing it. A well-meaning developer adding cookie-based auth for a new feature (e.g., a staff dashboard with session auth) could flip `supports_credentials` to `true` without realizing they've also opened CORS to every origin, enabling cross-origin credential theft. The fix is a boot-time assertion that treats this combination as a deploy-blocker.
    - **Plain English:** The CORS settings currently say "any website can call our API, but no cookies are sent." This is safe. But the config file itself warns that if someone ever changes "no cookies" to "cookies allowed," every website on earth would be able to steal those cookies. There's no automatic alarm that would go off if someone made that mistake. The fix is to install an alarm that refuses to let the system start if those two settings are ever combined.
    - **Evidence:**
        ```php
        // Wildcard origin is safe because supports_credentials => false — the
        // browser's wildcard+credentials restriction doesn't apply.
        'allowed_origins' => ['*'],
        ```
        ```php
        // If supports_credentials is ever set to true, both allowed_origins and
        // allowed_headers MUST be locked to explicit values.
        'supports_credentials' => false,
        ```
    - `[DRAFT, confidence: 1.0]`

- [ ] **#SEC-7** · P2 — Stripe platform webhook secrets (`STRIPE_PLATFORM_WEBHOOK_SECRET` and `STRIPE_PLATFORM_THIN_WEBHOOK_SECRET`) missing from `.env.example`
    - **Where:** config/services.php:61-65, .env.example:149-156
    - **Affects:** Production deploys where ops copies `.env.example` as a starting point — the two platform webhook routes (`/webhooks/stripe-platform` and `/webhooks/stripe-platform-thin`) would silently receive an empty signing secret, and signature verification would fail open or crash depending on the controller implementation.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `STRIPE_PLATFORM_WEBHOOK_SECRET=` and `STRIPE_PLATFORM_THIN_WEBHOOK_SECRET=` to `.env.example` with explanatory comments.
        - Audit both `StripePlatformWebhookController` methods (`__invoke` and `thin`) to confirm they fail-closed when the webhook secret is empty (return 503, not 200 with unverified payload).
        - Add a deploy-time assertion that all three Stripe webhook secrets are non-empty in production when the webhook routes are registered.
    - **Technical:** The `config/services.php` defines three Stripe webhook secrets: `connect_webhook_secret`, `platform_webhook_secret`, and `platform_thin_webhook_secret`. The `.env.example` only lists `STRIPE_WEBHOOK_SECRET` and `STRIPE_CONNECT_WEBHOOK_SECRET` (the latter being `connect_webhook_secret`). The two platform secrets are documented in the services config comments but absent from `.env.example`. An ops person deploying from the example file would miss them, leaving the platform webhook controllers with an empty secret. If the controllers use `Webhook::constructEvent()` with an empty secret, Stripe's SDK throws an exception — but if they have a fallback or try/catch that swallows it, the webhook would be processed unverified.
    - **Plain English:** The setup instructions list the keys needed for two of the three doorbells. The third doorbell (which handles payment confirmations) isn't mentioned. If someone sets up the system following the instructions, that third doorbell gets installed without a lock — anyone could ring it and pretend to be Stripe. The fix is to add the missing key to the instructions and make sure the system refuses to start if it's missing.
    - **Evidence:**
        ```php
        // config/services.php
        'connect_webhook_secret' => env('STRIPE_CONNECT_WEBHOOK_SECRET'),
        'platform_webhook_secret' => env('STRIPE_PLATFORM_WEBHOOK_SECRET'),
        'platform_thin_webhook_secret' => env('STRIPE_PLATFORM_THIN_WEBHOOK_SECRET'),
        ```
        ```php
        // .env.example — only these two Stripe webhook env vars appear:
        STRIPE_WEBHOOK_SECRET=
        STRIPE_CONNECT_WEBHOOK_SECRET=
        // STRIPE_PLATFORM_WEBHOOK_SECRET is absent
        // STRIPE_PLATFORM_THIN_WEBHOOK_SECRET is absent
        ```
    - `[DRAFT, confidence: 0.95]`

- [ ] **#SEC-8** · P2 — Webhook throttle names (`throttle:webhooks`, `throttle:shopify-webhooks`) have no visible rate-limit definitions in the provided scope
    - **Where:** routes/api.php:63-110
    - **Affects:** All webhook endpoints — if the named throttles are not registered or are too permissive, webhook endpoints are vulnerable to flooding DoS (malicious or accidental from a vendor retry storm).
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Verify that `throttle:webhooks` and `throttle:shopify-webhooks` are registered in `AppServiceProvider::boot()` or `app/Http/Kernel.php` with per-minute limits appropriate for each vendor's expected webhook volume.
        - Document the expected rate limits in route comments so future auditors can verify without tracing through the bootstrapper.
        - Add a CI assertion that every named throttle referenced in route files has a corresponding `RateLimiter::for()` call.
    - **Technical:** The webhook routes reference custom throttle names: `throttle:webhooks` (Stripe, Supabase), `throttle:shopify-webhooks` (Shopify), and `throttle:hydrogen-internal` (Hydrogen). These are Laravel named rate limiters, defined via `RateLimiter::for('webhooks', ...)` typically in `AppServiceProvider::boot()`. None of the files provided contain the rate-limiter definitions, so the actual limits are invisible. If `throttle:webhooks` is defined as 600 requests per minute (reasonable for a high-volume Shopify store) but `throttle:shopify-webhooks` was never registered, Laravel would throw a 500 on every Shopify webhook — or worse, if it falls through to a default, it might apply no limit at all.
    - **Plain English:** The webhook endpoints have speed-limit signs posted, but the actual speed limits are written in a different document we can't see. If one of those signs was never actually programmed into the system, the road has no speed limit at all — someone could flood it with traffic and knock the system offline. The fix is to verify every speed-limit sign is backed by a real limit and to write the limits down where future inspectors can see them.
    - **Evidence:**
        ```php
        // Multiple webhook routes using named throttles:
        Route::middleware('throttle:webhooks')->group(function () {
            Route::post('/webhooks/square', SquareCatalogWebhookController::class);
            Route::post('/webhooks/stripe-connect', StripeConnectWebhookController::class);
            Route::post('/webhooks/stripe-platform', StripePlatformWebhookController::class);
            Route::post('/webhooks/stripe-platform-thin', [StripePlatformWebhookController::class, 'thin']);
            Route::post('/webhooks/stripe', StripeWebhookController::class);
            // ...
        });
        Route::post('/webhooks/shopify/orders', ShopifyOrderWebhookController::class)
            ->middleware('throttle:shopify-webhooks');
        // ... multiple Shopify webhook routes with throttle:shopify-webhooks
        ```
    - `[DRAFT, confidence: 0.8]`

- [ ] **#SEC-9** · P3 — Hardcoded fake default contact data (`charlie@ai.com`, `1234 567 890`) seeded as system defaults for new accounts
    - **Where:** config/partna.php — `account_type_defaults.influencer.default_contact` (line ~860), `account_type_defaults.individual.default_contact` (line ~920), `account_type_defaults.partner.default_contact` (line ~935)
    - **Affects:** Every new influencer/individual/partner account created without explicit contact info — their site will display "Charlie" with email `charlie@ai.com` and phone `1234 567 890` as default contact. This could confuse real customers and appears in data exports, dashboard views, and public site renders.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace the fake defaults with empty strings or `null` values so the system reflects "not set" rather than seeding test data.
        - If the frontend needs a placeholder for the contact form, move those to a frontend-only constant — don't persist them to the database.
        - Run a one-off migration to null out any existing professional rows that still have `source: system_default` and the Charlie defaults (check with the team before touching production data).
    - **Technical:** The `default_contact` array in three `account_type_defaults` entries hardcodes a full name, email, and phone number. These are stored in the site's contact settings (likely JSONB in `site.settings`) at account creation time via the registration flow. While obviously fake, they represent a data-hygiene issue: they pollute exports, confuse any automated PII scanning, and could accidentally surface to real customers if a professional publishes their site before updating contact details. The values are clearly test data from early development that were never replaced with production-appropriate defaults.
    - **Plain English:** Every new account starts with a fake person named "Charlie" listed as their contact, with a fake email and phone number. If someone publishes their site before filling in their real contact info, their customers see Charlie's details instead. It's like buying a new phone and finding the previous owner's contact card still saved on it. The fix is to leave those fields blank for new accounts rather than pre-filling them with test data.
    - **Evidence:**
        ```php
        'default_contact' => [
            'full_name' => 'Charlie',
            'email' => 'charlie@ai.com',
            'phone' => '1234 567 890',
            'source' => 'system_default',
            'subscribed' => true,
        ],
        ```
    - `[DRAFT, confidence: 1.0]`

- [ ] **#SEC-10** · P3 — Stripe API version mismatch between `config/services.php` default (`2026-02-25.clover`) and `.env.example` pinned value (`2025-02-24.acacia`)
    - **Where:** config/services.php:66, .env.example:155
    - **Affects:** Development and CI environments that copy `.env.example` — they get `2025-02-24.acacia`, while production (or any env that doesn't set `STRIPE_API_VERSION`) gets `2026-02-25.clover`. Different Stripe API versions can change webhook payload shapes and SDK behavior between environments, leading to "works in dev, breaks in prod" bugs.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Align the `.env.example` default to `2026-02-25.clover` (the config default) so dev and prod use the same API version unless explicitly overridden.
        - Add a CI check that fails if `.env.example`'s `STRIPE_API_VERSION` differs from `config/services.php`'s default.
        - Document the upgrade process: test against the new version in a staging env, then bump both the config default and `.env.example` simultaneously.
    - **Technical:** The config file's fallback is `2026-02-25.clover`, but `.env.example` pins `2025-02-24.acacia`. A developer who copies `.env.example` to `.env` gets `2025-02-24.acacia`, while a production deploy that omits the env var (relying on the config default) gets `2026-02-25.clover`. These are a year apart in Stripe API versioning — webhook event shapes may differ, SDK method signatures may change. The `.env.example` also includes a comment about bumping to `2026-04-22.dahlia`, which is already past the config default of `2026-02-25.clover`. This is version drift, not a direct security vulnerability, but API version confusion has caused silent data corruption in payment systems before.
    - **Plain English:** The system talks to Stripe using a specific "language version." The main configuration says "use version 2026," but the setup instructions tell developers to use "version 2025." This means developers and the live site might be speaking slightly different dialects of Stripe's API — a payment webhook that works perfectly in testing could arrive in a different format in production and get dropped. The fix is to make the instructions match the actual configuration.
    - **Evidence:**
        ```php
        // config/services.php — default falls back to 2026
        'api_version' => env('STRIPE_API_VERSION', '2026-02-25.clover'),
        ```
        ```php
        // .env.example — pinned to 2025
        # Pin Stripe API version at the SDK client level so behaviour is independent of
        # dashboard settings. Bump intentionally after testing.
        STRIPE_API_VERSION=2025-02-24.acacia
        ```
    - `[DRAFT, confidence: 1.0]`
