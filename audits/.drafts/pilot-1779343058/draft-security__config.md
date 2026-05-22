
<!-- ═══ SUB-CHUNK: s1 (config) ═══ -->

- [ ] **#SEC-1** · P1 — Env-var dump endpoint gated by a single shared-secret token that, if leaked, exposes every API key in the system
    - **Where:** config/partna.php:4-9
    - **Affects:** All third-party service credentials (Stripe, Shopify, Cloudflare, Supabase, Hydrogen, Twitch, Kick, Square, Fresha, Turnstile, Slack, Resend, Google Maps, Postmark) — every secret consumed via `env()`.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Remove the `GET /api/internal/env-check` endpoint entirely. If an env-var report is needed for ops, ship it via an Artisan command (`php artisan env:check`) that runs on the server, never over HTTP.
        - If the endpoint must stay, ensure the controller uses `hash_equals` for token comparison, rate-limit to single-digit requests per minute per IP, and never include values for keys matching `*_KEY`, `*_SECRET`, `*_TOKEN`, `*_PASSWORD` — return only key names + redacted placeholders.
    - **Technical:** An HTTP endpoint that returns `$_ENV` or `config()` values for every env var is a single-point-of-failure for secret management. A single leaked `INTERNAL_ENV_CHECK_TOKEN` (commit to source, Slack paste, log line) hands an attacker every Stripe, Shopify, Cloudflare, and Supabase credential in one request. Even with `hash_equals`, the blast radius of this endpoint dwarfs any other secret-storage concern in the codebase. The fail-closed default (503 when unset) is good hygiene but doesn't reduce the endpoint's danger when enabled.
    - **Plain English:** Imagine a master key that opens every safe in the building — the office safe, the cash drawer, the server room, the filing cabinets. That's this endpoint. It's protected by a single password. If that password ever leaks (someone pastes it in Slack, commits it to a repo, or an attacker guesses it), every other lock in the building becomes irrelevant. The fix is to either remove the master-key door entirely, or make sure it never hands out the actual contents of the safes — just tells you which safes exist.
    - **Evidence:**
        ```php
        // Shared-secret token for GET /api/internal/env-check. Required to enable
        // the endpoint. When unset, the endpoint returns 503 — fail-closed by default
        // so a fresh deploy never accidentally exposes the env-var report.
        'internal_env_check_token' => env('INTERNAL_ENV_CHECK_TOKEN'),
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **#SEC-2** · P2 — Stripe API version pinned to two different defaults across config files; if `STRIPE_API_VERSION` is unset in `.env`, money-movement code and export code use incompatible API versions
    - **Where:** config/services.php:75 and config/partna.php (exports.commission.stripe_api_version)
    - **Affects:** All Stripe API calls — webhook processing, Connect onboarding, commission payouts, transaction exports. The export pipeline specifically would use `2025-02-24.acacia` while core Stripe SDK calls use `2026-02-25.clover`.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Set `STRIPE_API_VERSION` in `.env.example` and production `.env` to one canonical value (preferably `2026-02-25.clover` since it's the newer API).
        - Align the fallback default in `config/partna.php` exports section to match `config/services.php` stripe section, or — better — remove the duplicate default and have the export config read from `config('services.stripe.api_version')` directly.
    - **Technical:** `config/services.php` sets `stripe.api_version` default to `2026-02-25.clover`, which is what the Stripe SDK binding reads at boot. `config/partna.php` exports.commission.stripe_api_version defaults to `2025-02-24.acacia` — a full year older. If `STRIPE_API_VERSION` is missing from `.env` (common on fresh deploys), the export pipeline pins an older API version. Stripe API versions are immutable: a field available in `2026-02-25.clover` may be absent or differently shaped in `2025-02-24.acacia`, causing silent data mismatches or hard failures in payout calculations. The comment claims the export key is "Shared with the global Stripe SDK binding so the whole app pins one version" — the differing defaults contradict that claim.
    - **Plain English:** Think of the Stripe API version like the edition of a legal contract. Two different parts of your app are signing two different editions of the contract. If the env variable that picks the edition isn't set, the core app signs the 2026 edition while the export pipeline signs the 2025 edition. Most of the time the clauses match, but when they don't, you get unexpected results — wrong payout amounts, missing fields — and it's extremely hard to debug because everything looks fine until one specific field goes missing.
    - **Evidence:**
        ```php
        // config/services.php
        'stripe' => [
            'api_version' => env('STRIPE_API_VERSION', '2026-02-25.clover'),
        ],

        // config/partna.php — exports section
        'stripe_api_version' => env('STRIPE_API_VERSION', '2025-02-24.acacia'),
        ```
    - `[DRAFT, confidence: 0.9]`

- [ ] **#SEC-3** · P2 — Hydrogen GitHub PAT (`actions:write` scope) stored in runtime-accessible config, reachable via `config('partna.hydrogen.github_token')` from any code path
    - **Where:** config/partna.php (hydrogen.github_token)
    - **Affects:** The `sidest-storefront` GitHub repository — a leaked token gives an attacker `actions:write` (trigger workflows, modify CI, potentially exfiltrate secrets embedded in workflow runs).
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Audit all `Log::*`, `dd()`, `dump()`, exception handlers, and Nightwatch payloads to confirm none emit `config('partna.hydrogen.github_token')` or the full `config('partna.hydrogen')` array.
        - Add `hydrogen.github_token` (and ideally the whole `partna.hydrogen` key) to Nightwatch's `redact_payload_fields` in `config/nightwatch.php` as defence-in-depth.
        - Consider moving the token out of `config/` and into a dedicated `GitHubService` that reads `env()` directly at call time and never stores it in a global config array accessible to debug tooling.
    - **Technical:** Laravel's `config()` helper makes every value in `config/partna.php` globally accessible. Any code that logs request context, dumps config for debugging, or serialises config values in error payloads could inadvertently include this token. A GitHub PAT with `actions:write` can trigger workflows, modify repository dispatch inputs, and potentially inspect workflow-run logs that contain other secrets. The token lives alongside user-facing config (link block settings, social platforms) in the same file, making it easy to overlook during a broad `Log::debug('config', config('partna'))` call.
    - **Plain English:** You've put the key to your factory inside a publicly-accessible filing cabinet drawer labelled "miscellaneous settings." Anyone with access to the filing cabinet — including the maintenance crew who logs what's in each drawer for inventory — can see the key. The key doesn't just open the door; it lets someone reprogram the factory machines. The fix is to keep the key in a locked safe that only the machine operator can open, not in a shared drawer anyone can peek into.
    - **Evidence:**
        ```php
        // config/partna.php
        'hydrogen' => [
            // GitHub PAT with actions:write scope on the sidest-storefront repo.
            // Used by HydrogenDeploymentService to trigger single-brand Oxygen
            // deployments when a brand saves credentials in the wizard.
            'github_token' => env('PARTNA_HYDROGEN_GITHUB_TOKEN', env('SIDEST_HYDROGEN_GITHUB_TOKEN')),
        ```
    - `[DRAFT, confidence: 0.8]`

- [ ] **#SEC-4** · P3 — Placeholder PII in account-type defaults seeds new professional profiles with test contact data showing a real-looking email and phone number
    - **Where:** config/partna.php (account_type_defaults.influencer, individual, partner)
    - **Affects:** Every newly registered professional, influencer, individual, or partner account that hasn't yet customised their contact section — their public site displays "Charlie" with email `charlie@ai.com` and phone `1234 567 890` until they edit it.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace the hardcoded values with empty strings or `null`, and have the frontend render a "set your contact info" prompt for the contact section block until the professional fills it in.
        - If the placeholder is needed for visual preview during onboarding, use clearly synthetic values (`'Your Name'`, `'you@example.com'`, `'+61 0000 0000'`) that cannot be confused with real PII.
    - **Technical:** The `default_contact` arrays in `account_type_defaults` for `influencer`, `individual`, and `partner` each contain `'full_name' => 'Charlie'`, `'email' => 'charlie@ai.com'`, `'phone' => '1234 567 890'`. These are written into new professionals' contact section blocks on registration. Until the professional edits their site, these values render on their public-facing mini-site. While these look like test data, `charlie@ai.com` could be a real inbox, and publishing it on public profiles creates both a PII exposure and a spam magnet for whoever owns that address. The `source => 'system_default'` field suggests awareness that these are defaults, but that doesn't prevent them from being served publicly.
    - **Plain English:** When a new user signs up, we pre-fill their public contact card with "Charlie" at a real-looking email address. Think of it like printing business cards for every new customer with someone else's name and phone number on them — until the customer notices and swaps the card out, everyone who picks it up is calling Charlie. Even if Charlie is a test account, we're putting their details on every new user's public page. Replace it with blank fields or obviously fake placeholders.
    - **Evidence:**
        ```php
        'influencer' => [
            'default_contact' => [
                'full_name' => 'Charlie',
                'email' => 'charlie@ai.com',
                'phone' => '1234 567 890',
                'source' => 'system_default',
                'subscribed' => true,
            ],
        ],
        // Repeated identically in 'individual' and 'partner' defaults
        ```
    - `[DRAFT, confidence: 0.7]`

- [ ] **#SEC-5** · P3 — CORS `paths` include `sanctum/csrf-cookie` alongside wildcard `allowed_origins: *`; the cookie endpoint is non-functional cross-origin but the configuration doesn't document this constraint explicitly
    - **Where:** config/cors.php:2,8
    - **Affects:** Any future developer who assumes `sanctum/csrf-cookie` works cross-origin — it silently fails because `supports_credentials: false` prevents browsers from including cookies on wildcard-origin requests. If the app later adds a cookie-based auth path under `api/*`, the wildcard origin would need to be locked down.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a comment in `config/cors.php` noting that `sanctum/csrf-cookie` is included in paths for local/SSR use but is non-functional cross-origin due to `supports_credentials: false`.
        - If Sanctum CSRF is genuinely unused (likely, given Supabase JWT auth), remove `sanctum/csrf-cookie` from the paths array to eliminate the ambiguity.
        - Document in the file that if `supports_credentials` is ever changed to `true`, `allowed_origins` must be locked to an explicit allow-list (as the existing comment already partially covers).
    - **Technical:** Browsers enforce a hard rule: when `Access-Control-Allow-Origin: *` is sent, the response cannot include `Access-Control-Allow-Credentials: true`, and cookies/HTTP-auth are never sent cross-origin. The current config sets `supports_credentials: false`, so the wildcard origin is safe for the Bearer-token API. However, `sanctum/csrf-cookie` is listed in `paths` — this is a cookie-setting endpoint. It will only work same-origin (where CORS doesn't apply) or from SSR/localhost. Listing it in the CORS paths alongside a wildcard origin is not a vulnerability but creates a foot-gun: a developer seeing Sanctum in the paths might assume cookie auth works cross-origin and build a feature on that assumption.
    - **Plain English:** We've got a sign on the door that says "everyone welcome" (wildcard origins) and another sign that says "please show ID at the cookie counter" (Sanctum CSRF path). The door policy explicitly says "no ID checks at this door" (no credentials), so the cookie counter is effectively closed to anyone coming through that door. That's fine right now because everyone uses the keycard lane (Bearer token). But a future builder might see the cookie counter sign, assume it works, and build a whole new entrance that relies on it — only to find out it was never open. The fix is a sticky note on the sign: "Cookie counter is for local traffic only — do not build cross-origin features that depend on it."
    - **Evidence:**
        ```php
        'paths' => ['api/*', 'sanctum/csrf-cookie'],
        'allowed_origins' => ['*'],
        'supports_credentials' => false,
        ```
    - `[DRAFT, confidence: 0.6]`

<!-- ═══ SUB-CHUNK: s2 (routes .env.example) ═══ -->

- [ ] **SEC-1** · P0 — Shopify webhooks rely entirely on per-controller HMAC verification with no middleware-level enforcement
    - **Where:** routes/api.php (Shopify webhook group)
    - **Affects:** All 10+ Shopify webhook endpoints — orders, refunds, app-uninstall, GDPR, themes, shop-update. A single controller missing HMAC verification = wide-open webhook.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add a `shopify.webhook` middleware that verifies `x-shopify-hmac-sha256` using `hash_equals` before the controller executes.
        - Alternatively, add a `ShopifyWebhookRequest` Form Request with an `authorize()` method that performs HMAC verification, and apply it to every webhook route definition.
        - Audit every Shopify webhook controller to confirm individual HMAC checks exist, as an interim safeguard.
    - **Technical:** Category 4 — Webhook signature verification. All Shopify webhooks sit under a single `throttle:webhooks` group with the comment "no auth middleware — signature validated in controller." Ten distinct controllers handle these routes. Under Laravel's middleware architecture, a single forgotten `abort_unless($this->hmacValid($request), 401)` in any one of them means that webhook path accepts unverified payloads — orders can be fabricated, app-uninstall can be spoofed, GDPR requests can be injected. Middleware-level enforcement is defense-in-depth: it guarantees no code path reaches the controller without a valid signature. The Stripe webhooks in the same group use `Webhook::constructEvent()` which is similarly per-controller, but Stripe ships a signed-request helper that hard-fails — Shopify's HMAC is a hand-rolled check with no framework guardrail.
    - **Plain English:** Imagine a warehouse with ten delivery doors. Each door has its own guard who's supposed to check IDs. If one guard calls in sick and there's no backup check at the gate, that door is wide open. All ten Shopify webhook doors rely on the guard inside each room to check the signature. A single missed check means fake orders, fake uninstalls, or fake GDPR deletion requests can walk right in.
    - **Evidence:**
        ```php
        // Webhooks (no auth middleware — signature validated in controller)
        Route::middleware('throttle:webhooks')->group(function () {
            // ... Stripe, Square, Supabase webhooks ...
            Route::post('/webhooks/shopify/orders', ShopifyOrderWebhookController::class)
                ->middleware('throttle:shopify-webhooks');
            Route::post('/webhooks/shopify/orders-paid', ShopifyOrderWebhookController::class)
                ->middleware('throttle:shopify-webhooks');
            Route::post('/webhooks/shopify/orders-updated', ShopifyOrdersUpdatedWebhookController::class)
                ->middleware('throttle:shopify-webhooks');
            Route::post('/webhooks/shopify/orders-edited', ShopifyOrdersEditedWebhookController::class)
                ->middleware('throttle:shopify-webhooks');
            Route::post('/webhooks/shopify/orders-cancelled', ShopifyOrdersCancelledWebhookController::class)
                ->middleware('throttle:shopify-webhooks');
            Route::post('/webhooks/shopify/refunds-create', ShopifyRefundsCreateWebhookController::class)
                ->middleware('throttle:shopify-webhooks');
            Route::post('/webhooks/shopify/app-uninstalled', ShopifyAppUninstalledWebhookController::class)
                ->middleware('throttle:shopify-webhooks');
            Route::post('/webhooks/shopify/shop-update', ShopifyShopUpdateWebhookController::class)
                ->middleware('throttle:shopify-webhooks');
            Route::post('/webhooks/shopify/themes-publish', ShopifyThemePublishedWebhookController::class)
                ->middleware('throttle:shopify-webhooks');
            Route::post('/webhooks/shopify/gdpr/customers-data-request', [ShopifyGdprWebhookController::class, 'customersDataRequest'])
                ->middleware('throttle:shopify-webhooks');
            Route::post('/webhooks/shopify/gdpr/customers-redact', [ShopifyGdprWebhookController::class, 'customersRedact'])
                ->middleware('throttle:shopify-webhooks');
            Route::post('/webhooks/shopify/gdpr/shop-redact', [ShopifyGdprWebhookController::class, 'shopRedact'])
                ->middleware('throttle:shopify-webhooks');
        });
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **SEC-2** · P1 — Hydrogen affiliate data endpoint is intentionally unauthenticated, relying solely on controller-level link verification
    - **Where:** routes/api.php (line comment before the route)
    - **Affects:** Affiliate services/products data exposed at `/internal/hydrogen/affiliate`. If the controller's `BrandPartnerLink` check is bypassed or has an edge case, an unauthenticated caller enumerates affiliate product selections and pricing.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Audit `HydrogenAffiliateController::show()` to confirm the 404-on-no-verified-link gate is watertight for all input combinations (missing `shop_domain`, invalid `slug`, deleted brand, disconnected partnership).
        - Consider adding a lightweight shared-secret HMAC or short-lived signed token to this endpoint. The comment justifies omitting `hydrogen.key` to avoid server load, but a signed URL with a 60s TTL eliminates the tenant-boundary risk entirely at negligible cost.
    - **Technical:** Category 1 — Authentication boundary correctness. The route is explicitly declared "INTENTIONALLY UNAUTHENTICATED" with the rationale that `HydrogenAffiliateController::show()` returns 404 when no verified `BrandPartnerLink` exists. This makes tenant isolation a controller-internal concern rather than a middleware-enforced one. The adjacent endpoints (`/affiliate-services`, `/affiliate-products`) are protected by `hydrogen.key` middleware — the asymmetry means `/affiliate` is the only Hydrogen endpoint where tenant resolution is decoupled from cryptographic identity. Any logic error in the link-verification path (e.g., a soft-deleted partnership that still resolves, a race condition on disconnect) would leak affiliate commerce data to an unauthenticated caller.
    - **Plain English:** There's a reception desk that's supposed to check visitor badges, but one entrance has a sign that says "no badge needed — the room inside will check if you're supposed to be there." If the person in that room makes a mistake, someone without a badge gets access to affiliate sales data. The fix is to put a badge check at the entrance, same as every other door.
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
    - `[DRAFT, confidence: 0.70]`

- [ ] **SEC-3** · P1 — Internal env-check diagnostic endpoint has no rate limiting
    - **Where:** routes/api.php (near end of file, before `/ready` routes)
    - **Affects:** The self-diagnostic endpoint at `GET /api/internal/env-check`. An attacker can brute-force the `X-Internal-Token` header or flood the endpoint to degrade service — it's the only internal endpoint with zero throttle middleware.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `->middleware('throttle:10,1')` or a named throttle like `throttle:env-check` to the route.
        - Confirm inside `EnvCheckController` that the shared-secret comparison uses `hash_equals` (timing-safe) and that no secret value or env-var output is logged.
    - **Technical:** Category 9 — Rate limiting on auth & sensitive endpoints. Every other internal/admin endpoint in the route file carries a throttle: `throttle:hydrogen-internal`, `throttle:embedded-by-shop`, `throttle:webhooks`, `throttle:60,1` on Shopify OAuth. The env-check route stands alone with no middleware at all. The controller gates access behind a single `X-Internal-Token` header — without rate limiting, that token is brute-forceable (even with a strong random value, an unthrottled endpoint allows high-speed guessing). Additionally, a simple connection-flood DoS against this endpoint consumes PHP-FPM workers since there is no request-per-minute cap.
    - **Plain English:** Every entrance to the building has a security guard counting how many people come through. This one diagnostic entrance has no guard at all — just a keypad. Someone can stand there trying combinations as fast as the keypad lets them, and nobody's watching.
    - **Evidence:**
        ```php
        // Self-diagnostic env-var check. Independent of every other auth subsystem on
        // purpose — this is the endpoint you hit when something else is misconfigured.
        // Auth is a single shared-secret header inside the controller.
        Route::get('/internal/env-check', EnvCheckController::class);
        ```
    - `[DRAFT, confidence: 0.90]`

- [ ] **SEC-4** · P2 — `productGid` route parameter regex `.*` accepts arbitrary strings instead of constraining to valid Shopify GIDs
    - **Where:** routes/api/professional.php (multiple brand catalog routes: `updateMetafields`, `toggleActive`, `updateCommission`, `updateDiscount`; affiliate product photo routes)
    - **Affects:** Brand catalog write endpoints and affiliate product photo routes. Overly permissive parameter matching allows malformed or malicious GID strings to reach the controller and potentially downstream Shopify API calls.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `->where('productGid', '.*')` with a constrained regex like `->where('productGid', 'gid://shopify/Product/[0-9]+')` matching the pattern used in `routes/api/staff.php` for the same parameter.
        - Apply the same constraint to the `gid` parameter on affiliate product routes in `professional.php`.
    - **Technical:** Category 6 — Input validation & injection. The parameter `{productGid}` appears on multiple brand-catalog mutation endpoints and is passed through to Shopify Admin API calls. A regex of `.*` matches any string including empty, injection payloads, or characters that could cause unexpected behavior in downstream HTTP clients (e.g., newlines in header injection, path traversal sequences). Notably, `routes/api/staff.php` already constrains the same parameter correctly for staff-side catalog routes: `->where('productGid', '.*')` on the staff write routes but `->where('gid', 'gid://shopify/Product/[0-9]+')` on the staff affiliate photo route — the inconsistency confirms the tighter pattern is both feasible and expected.
    - **Plain English:** The system accepts product IDs in a field that should look like `gid://shopify/Product/12345`, but the validation rule is set to "accept anything at all." It's like a parking garage that requires a ticket but the gate accepts any piece of paper — a gum wrapper works. The staff-facing side already has the correct gate; the professional-facing side needs the same one.
    - **Evidence:**
        ```php
        // From routes/api/professional.php — permissive regex
        Route::patch('/brand/catalog/{productGid}/metafields', [BrandCatalogController::class, 'updateMetafields'])
            ->middleware('throttle:brand-catalog-writes')
            ->where('productGid', '.*');
        Route::patch('/brand/catalog/{productGid}/active', [BrandCatalogController::class, 'toggleActive'])
            ->middleware('throttle:brand-catalog-writes')
            ->where('productGid', '.*');
        Route::patch('/brand/catalog/{productGid}/commission', [BrandCatalogController::class, 'updateCommission'])
            ->middleware('throttle:brand-catalog-writes')
            ->where('productGid', '.*');
        Route::patch('/brand/catalog/{productGid}/discount', [BrandCatalogController::class, 'updateDiscount'])
            ->middleware('throttle:brand-catalog-writes')
            ->where('productGid', '.*');
        ```
        ```php
        // From routes/api/staff.php — constrained regex for the same concept
        Route::get('/professionals/{professional}/affiliate/products/{gid}/photos', [...], 'index'])
            ->where('gid', 'gid://shopify/Product/[0-9]+');
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **SEC-5** · P2 — Staff read-only mirror routes expose PII (customer data, email subscribers, enquiries) to any staff member without role-scoped filtering
    - **Where:** routes/api/staff.php (B2 read-only inspector bundle: `StaffEmailSubscriberController`, `StaffEnquiryController`, `StaffCustomerManagementController`, `StaffBookingController`, multiple analytics/catalog mirrors)
    - **Affects:** Customer PII — email addresses, phone numbers, enquiry messages, purchase history — is visible to every authenticated staff member. There is no apparent tiering (e.g., "support can see metadata but only admin can see full PII").
    - **Effort:** L (~1–2d)
    - **What to do:**
        - Define a staff role hierarchy with granular permissions (e.g., `support:read_pii`, `admin:full_access`).
        - Add PII-redacted Resource classes for non-admin staff roles — return last-4 of phone, masked email, enquiry body trimmed to first 80 chars unless the staff member has elevated permissions.
        - Audit what `StaffMeController::show()` returns about the staff member's own permissions so the frontend can hide PII-exposing UI components.
    - **Technical:** Category 10 — PII exposure in responses & logs. The staff route file defines a two-tier system: regular `staff` (read-only mirrors) and `staff.admin` (write operations). The B2 read-only inspector bundle — documented as "any-staff read, no admin gate" — mounts ~15 controllers that mirror brand-facing data, including `StaffEmailSubscriberController::export()` (full CSV of subscriber emails), `StaffEnquiryController::index()` (contact-form messages), and `StaffCustomerManagementController::index/show()` (customer records). None of these routes have additional middleware that distinguishes between "support agent helping with a billing question" and "staff member browsing customer data." Under GDPR, access to PII must be proportionate and auditable — a flat any-staff-read posture is disproportionate for a support agent who only needs to verify subscription status.
    - **Plain English:** Every staff member — whether they're debugging a CSS issue or handling a GDPR deletion request — can see every customer's email, phone number, enquiry messages, and purchase history. It's like giving every hotel employee a master key to every guest room, including the front-desk trainee on their first day. Support staff should have access to what they need for their specific job, not the entire guest roster.
    - **Evidence:**
        ```php
        // #GDPR-1 — email subscribers list + CSV export. Compliance: Article 15/20
        // requests routed to Partna support need a way to answer without the brand.
        Route::get('/professionals/{professional}/email-subscribers', [StaffEmailSubscriberController::class, 'index']);
        Route::get('/professionals/{professional}/email-subscribers/export', [StaffEmailSubscriberController::class, 'export']);

        // #ENQUIRY-1 — contact-form enquiries inbox (read).
        Route::get('/professionals/{professional}/enquiries', [StaffEnquiryController::class, 'index']);
        ```
        ```php
        // B2: Read-only inspector mirrors — any-staff read, no admin gate
        Route::get('/professionals/{professional}/customers', [StaffCustomerManagementController::class, 'index']);
        Route::get('/professionals/{professional}/customers/{customer}', [StaffCustomerManagementController::class, 'show'])
            ->whereUuid('customer');
        ```
    - `[DRAFT, confidence: 0.80]`

- [ ] **SEC-6** · P2 — `shopify_order_id` route parameter regex permits special characters (`/`, `.`, `:`) that could cause path traversal or injection in downstream API calls
    - **Where:** routes/api.php (embedded order analytics route)
    - **Affects:** The embedded order analytics endpoint `GET /internal/embedded/orders/{shopify_order_id}`. If the controller interpolates this parameter directly into a Shopify Admin API URL or a filesystem path, the permissive regex allows injection.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Tighten the regex to `[0-9]+` if Shopify order IDs are purely numeric, or to a known format like `gid://shopify/Order/[0-9]+` if GIDs are used.
        - Regardless of regex, validate the parameter against a known format inside the controller before any outbound HTTP call or DB query.
    - **Technical:** Category 6 — Input validation & injection. The route definition uses `->where('shopify_order_id', '[A-Za-z0-9_/.:-]+')`. Characters like `/` and `..` in a URL path parameter can enable path traversal if the value is concatenated into a REST API URL (e.g., `https://{shop}.myshopify.com/admin/api/{version}/orders/{shopify_order_id}.json` where `shopify_order_id` = `../../products`). The same applies to `shopify_product_id` on the product analytics route. Shopify's own GID format is `gid://shopify/Order/123456` but some endpoints accept numeric IDs — the regex should match only the expected format, not a superset that includes traversal characters.
    - **Plain English:** The system asks for an order number but accepts slashes, dots, and colons in the answer. If someone types `../../products` instead of a real order number, and the system blindly stitches that into a web address, they could be sent somewhere they shouldn't go. The fix is to only accept digits — because that's what a real order number looks like.
    - **Evidence:**
        ```php
        Route::get('/orders/{shopify_order_id}', [EmbeddedOrderAnalyticsController::class, 'show'])
            ->where('shopify_order_id', '[A-Za-z0-9_/.:-]+');
        Route::get('/products/{shopify_product_id}/analytics', [EmbeddedProductAnalyticsController::class, 'show'])
            ->where('shopify_product_id', '[A-Za-z0-9_/.:-]+');
        ```
    - `[DRAFT, confidence: 0.75]`

- [ ] **SEC-7** · P3 — `.env.example` comment recommends a "Full access" Resend API key when a "Sending access" scoped key is sufficient
    - **Where:** .env.example (RESEND_API_KEY comment block)
    - **Affects:** Principle of least privilege for the Resend mail API integration. A full-access key can manage domains, API keys, and audience contacts — none of which the Laravel mailer needs.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Update the comment to recommend a "Sending access" API key instead of "Full access."
        - If bounce/complaint webhooks are needed later, document that those require a separate webhook configuration in Resend, not a broader API key scope.
    - **Technical:** Category 5 — Secrets handling & log hygiene. The `.env.example` guidance reads: "Use a 'Full access' key so bounce/complaint webhooks can be wired up later." Resend supports scoped API keys: "Sending access" permits only the `send` endpoint, which is all Laravel's `resend` mail transport needs. "Full access" additionally permits domain management, API key rotation, and audience operations. If the key were compromised, a scoped key limits blast radius to sending email; a full-access key allows an attacker to delete the verified domain, rotate keys, or export contact lists. Bounce/complaint webhooks in Resend are configured per-domain in the dashboard and authenticated via a separate webhook signing secret — they do not require a full-access API key.
    - **Plain English:** The setup instructions tell developers to use a master key that opens every door in the building, when a key that only opens the mailroom door would work fine. If someone steals the master key, they can change the locks on every door — not just read the mail.
    - **Evidence:**
        ```
        # Resend HTTP API key — required when MAIL_MAILER=resend. Get from
        # https://resend.com/api-keys. Use a "Full access" key so bounce/complaint
        # webhooks can be wired up later.
        RESEND_API_KEY=
        ```
    - `[DRAFT, confidence: 0.95]`

- [ ] **SEC-8** · P3 — Public email subscription endpoint has no CAPTCHA protection, unlike other lead-capture endpoints
    - **Where:** routes/api.php and routes/api/publicSite.php (subscribe routes)
    - **Affects:** `POST /api/public/subscribe` and `POST /api/public/{subdomain}/subscribe` — both accept email subscriptions with only `throttle:public-site` as protection. Other lead-capture endpoints (`/public/customers`, `/public/enquiry`, `/public/waitlist`) carry `captcha` middleware.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `captcha` middleware to both subscribe routes so they match the protection level of the customer-lead and enquiry endpoints.
        - Ensure the `PARTNA_CAPTCHA_ENABLED` feature flag gates the middleware behavior consistently.
    - **Technical:** Category 9 — Rate limiting on sensitive endpoints. The subscribe endpoint accepts an email address and creates/updates an `EmailSubscription` record. Without CAPTCHA, a script can programmatically subscribe thousands of addresses — inflating the subscriber list, triggering welcome emails, and degrading the brand's email reputation. The `throttle:public-site` rate limit slows this but doesn't prevent it at scale (a distributed botnet can stay under per-IP limits). The customer-lead, enquiry, and waitlist endpoints all carry `captcha` middleware — the subscribe route is an unexplained gap in the same public surface.
    - **Plain English:** The public signup form for "email me updates" has no bot check, but the "contact us" form on the same site does. A spammer can write a script that subscribes ten thousand fake emails to the newsletter. The other forms are locked — this one was left unlocked.
    - **Evidence:**
        ```php
        // No captcha middleware
        Route::post('/public/subscribe', [PublicEmailSubscriptionController::class, 'subscribe'])
            ->middleware('throttle:public-site');
        ```
        ```php
        // Captcha present on peer endpoints
        Route::post('/public/customers', [PublicCustomerLeadController::class, 'store'])
            ->middleware(['lead.log', 'throttle:leads', 'captcha']);
        Route::post('/public/enquiry', [PublicEnquiryController::class, 'submit'])
            ->middleware(['lead.log', 'throttle:leads', 'captcha']);
        ```
    - `[DRAFT, confidence: 0.90]`

- [ ] **SEC-9** · P3 — Brand catalog debug endpoint exposes raw Shopify API response without apparent sanitization or scoping restrictions
    - **Where:** routes/api/professional.php (brand catalog debug route)
    - **Affects:** `GET /brand/catalog/debug` — returns raw Shopify responses including shop info, product samples, cost data, errors, and granted OAuth scopes. Available to any authenticated brand professional.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Confirm the controller strips sensitive fields (cost data, scopes, access token metadata) before returning the debug payload.
        - If this endpoint is genuinely temporary, add a hard deprecation deadline in the route comment and a `trigger_error` in the controller after that date.
        - Consider gating behind a staff-only route or a feature flag that defaults to off.
    - **Technical:** Category 10 — PII exposure in responses & logs. The route comment describes this as a "temporary diagnostic probe" that returns "raw Shopify response for a minimal products query." It is auth-gated (behind `supabase.jwt` + `brand.only`) so tenant isolation is enforced, but the response includes "shop info, products sample, cost, errors, granted scopes" — data points Shopify considers sensitive. Exposing OAuth scopes tells an attacker exactly which API surfaces are available. Exposing raw cost data may violate Shopify's API terms. The comment says it is "safe to leave in place" but this was written during development — pre-launch is the right time to remove or harden it.
    - **Plain English:** There's a "debug mode" button in the dashboard that shows the brand owner everything the system knows about their Shopify store — including what permissions the app has and what the raw product data looks like. The note says "this is temporary, safe to leave." Before real brands use the system, that button should either be removed or locked behind a staff-only key.
    - **Evidence:**
        ```php
        // Temporary diagnostic probe — returns raw Shopify response for a
        // minimal products query so we can see exactly what Shopify returns
        // (shop info, products sample, cost, errors, granted scopes). Safe
        // to leave in place; auth-gated, read-only, no mutations.
        Route::get('/brand/catalog/debug', [BrandCatalogController::class, 'debug']);
        ```
    - `[DRAFT, confidence: 0.80]`
