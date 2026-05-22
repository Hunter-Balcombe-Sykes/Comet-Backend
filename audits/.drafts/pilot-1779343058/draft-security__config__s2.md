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
