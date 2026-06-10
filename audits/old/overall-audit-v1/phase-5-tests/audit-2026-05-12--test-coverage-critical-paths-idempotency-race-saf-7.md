# Test Coverage Audit — 2026-05-12

**Branch:** development
**Lens:** Test coverage: critical paths, idempotency, race-safety, policy abilities, mock-vs-integration discipline
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
- `tests/Feature/Security/TenantIsolation/WebhookCrossTenantTest.php`
- `tests/Feature/Stripe/StripeWebhookControllerTest.php`
- `tests/Feature/Stripe/StripeConnectWebhookDedupeTest.php`
- `tests/Feature/Stripe/WalletMovementsLedgerTest.php`
- `tests/Feature/Stripe/CommissionPayoutServiceTest.php`
- `tests/Feature/Stripe/CommissionVoidServiceTest.php`
- `tests/Feature/Stripe/VoidExpiredPayoutsJobTest.php`
- `tests/Feature/Stripe/ReconcileStuckTransferringPayoutsJobTest.php`
- `tests/Feature/Stripe/RetryPendingFundsPayoutsJobTest.php`
- `tests/Feature/Stripe/StripeConnectControllerAuthorizationTest.php`
- `tests/Feature/Stripe/StripeConnectPayoutsControllerTest.php`
- `tests/Feature/Security/PolicyCoverageTest.php`
- `tests/Feature/Affiliate/AffiliatePayoutsListTest.php`
- `app/Http/Resources/AffiliatePayoutResource.php`
- `app/Http/Resources/ProfessionalPublicResource.php`
- `app/Http/Resources/BrandStoreSettingsResource.php`
- `app/Http/Requests/Api/Professional/Site/StoreLinkBlockRequest.php`
- `app/Http/Requests/Api/Professional/Site/UpdateSiteRequest.php`
- `app/Http/Requests/Api/Professional/Site/UpsertSectionBlockRequest.php`

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 1 complete
- P2 Medium: 0 of 5 complete

---

## P1 — Fix before pilot launch

- [ ] **#TEST-1** · P1 — Shopify order webhook has no re-delivery dedup test
    - **Where:** `tests/Feature/Security/TenantIsolation/WebhookCrossTenantTest.php`
    - **Affects:** All brands receiving Shopify order webhooks; Shopify guarantees at-least-once delivery, so duplicate `X-Shopify-Webhook-Id` values are a documented, expected scenario.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add a test that calls `ShopifyOrderWebhookController` twice with the same `X-Shopify-Webhook-Id` header value and asserts `ProcessShopifyOrderWebhookJob` is dispatched only once.
        - Confirm the dedup guard lives in `ProcessShopifyOrderWebhookJob` itself (check against `commerce.order_events.shopify_event_id` UNIQUE) and that the controller or job actually reads the header; if not, add that guard.
        - Once the test exists, run `composer test` to lock the behaviour.
    - **Technical:** The helper `buildShopifyWebhookRequest()` already seeds `HTTP_X_SHOPIFY_WEBHOOK_ID` with a random UUID on every call, but no existing test ever sends the same ID twice. The three tests in this file cover HMAC rejection, unknown domain, and cross-tenant isolation — all protection against forged or misrouted events. None cover the at-least-once delivery guarantee Shopify documents. The idempotency story for Shopify orders relies on `commerce.order_events.shopify_event_id` having a UNIQUE constraint, but without a test exercising the re-delivery path, a regression (e.g., job code path that never writes the event row before enqueueing downstream work) would go undetected. This is P1 by the calibration anchor: Shopify documents at-least-once delivery explicitly, so double-processing is a *known* scenario, not a theoretical one.
    - **Plain English:** Shopify's own documentation says it may send the same webhook twice. The app has a mechanism to detect and ignore the duplicate, but no test ever tries it. Right now, if someone changed the code and accidentally removed that guard, we'd have no automated warning — every duplicate Shopify order would be processed twice, potentially creating duplicate commission records.
    - **Evidence:**
        ```php
        function buildShopifyWebhookRequest(array $payload, string $shopDomain, string $secret): Request
        {
            // ...
            return Request::create('/api/webhooks/shopify/orders', 'POST', [], [], [], [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_SHOPIFY_SHOP_DOMAIN' => $shopDomain,
                'HTTP_X_SHOPIFY_HMAC_SHA256' => $hmac,
                'HTTP_X_SHOPIFY_WEBHOOK_ID' => (string) Str::uuid(), // new random ID every call
            ], $body);
        }

        // Three tests cover: unknown domain, cross-tenant, invalid HMAC.
        // No test: same Webhook-Id delivered twice → second is a no-op.
        ```

---

## P2 — Should fix

- [ ] **#TEST-2** · P2 — Stripe billing webhook (`/api/webhooks/stripe`) has no dedup test
    - **Where:** `tests/Feature/Stripe/StripeWebhookControllerTest.php`
    - **Affects:** Stripe billing events (subscription created/updated, payment success/failure); Stripe also guarantees at-least-once delivery.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a test that posts the same `stripe_event_id` to `/api/webhooks/stripe` twice (with a valid signature) and asserts idempotent handling — either that `billing.webhook_events` has exactly one row, or that the second call returns 200 without re-triggering business logic.
        - Note: `StripeConnectWebhookDedupeTest.php` already covers the Connect webhook path — use the same pattern here for the billing path.
    - **Technical:** `StripeWebhookControllerTest.php` contains exactly three tests, all scoped to signature verification. The `billing.webhook_events` table has a UNIQUE on `stripe_event_id`; the test in `StripeConnectWebhookDedupeTest.php` proves this works for the Connect webhook (`/api/webhooks/stripe/connect`). The billing webhook controller (`/api/webhooks/stripe`) is structurally parallel but has no equivalent coverage. This is P2 (not P1) because, unlike the Shopify path where double-processing could produce duplicate commission rows with real money impact, the billing webhook handles subscription state changes — harmful but recoverable within a pilot scale. Hardening the test parity between the two webhook surfaces is the right call before real subscribers arrive.
    - **Plain English:** The app has two Stripe webhook endpoints — one for Connect (affiliate payouts) and one for billing (subscriptions). The Connect one is tested to handle duplicate delivery correctly. The billing one is not. Given that Stripe also delivers webhooks at least once, this is a gap worth closing before real subscribers join.
    - **Evidence:**
        ```php
        it('returns 400 when Stripe-Signature header is missing', function () { ... });
        it('returns 400 when webhook secret is not configured', function () { ... });
        it('returns 400 when signature does not match', function () { ... });

        // Only signature-gate tests exist.
        // No test: same Stripe event ID posted twice → second is a no-op.
        ```

- [ ] **#TEST-3** · P2 — `BrandStoreSettingsResource` `oxygen_token_set` invariant is untested
    - **Where:** `app/Http/Resources/BrandStoreSettingsResource.php:23`
    - **Affects:** Any API consumer that reads brand store settings; the `oxygen_token_set` field must never leak a raw token — it must always be a boolean.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a unit or feature test that constructs a `BrandStoreSettingsResource` with a non-empty `oxygen_token_set` value (e.g., `'tok_abc123'`) and asserts the serialised response contains `true`, not the token string.
        - Also assert that when `oxygen_token_set` is `null` or `''` the response contains `false`.
        - Pair with a test that the `BrandStoreSettingsResource` never includes any key containing the raw token string regardless of what is passed in.
    - **Technical:** The resource converts the raw `oxygen_token_set` value to a boolean with `(bool) ($this->resource['oxygen_token_set'] ?? false)`, so the token is never returned in plain text. This is the correct behaviour. The problem is there is no test file for `BrandStoreSettingsResource` at all — a grep for the class name across `tests/` returns zero hits. A refactor that accidentally changes this line (e.g., removing the cast, returning the raw value for debugging) would go undetected. The `AffiliatePayoutResource` has a directly analogous redaction invariant (`brand_funding` → `null`) and that IS tested in `AffiliatePayoutsListTest.php` (lines 127–146) — demonstrating the pattern exists in the codebase. Apply the same discipline here.
    - **Plain English:** The settings endpoint deliberately hides the actual Oxygen API token and only tells the frontend "yes, a token is set" (true/false). There's no test verifying this — so if a developer accidentally removed the boolean-cast during a refactor, the raw token would start leaking in API responses and we'd have no automated warning. The sister resource for payout data already has this kind of test; this one just needs the same treatment.
    - **Evidence:**
        ```php
        class BrandStoreSettingsResource extends JsonResource
        {
            public function toArray(Request $request): array
            {
                return [
                    // ...
                    // Oxygen: token is never returned — only whether one is saved
                    'oxygen_token_set' => (bool) ($this->resource['oxygen_token_set'] ?? false),
                    // ...
                ];
            }
        }
        ```

- [ ] **#TEST-4** · P2 — `UpdateSiteRequest` has no dedicated test despite complex validation
    - **Where:** `app/Http/Requests/Api/Professional/Site/UpdateSiteRequest.php:57–128`
    - **Affects:** Any brand updating site settings; the request guards against deprecated key injection, enforces design enum contracts, and runs subdomain uniqueness across three tables.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add a `UpdateSiteRequestTest.php` with at least: (a) a test that passing a `prohibited` legacy key (e.g., `settings.design.border_color`, `settings.design.typography.heading_font`) returns 422; (b) a test that each 3-bucket enum (`corner_radius`, `border_thickness`, `section_spacing`) rejects out-of-range values; (c) a test that `font_family` rejects values not in the allowlist; (d) a test that subdomain uniqueness blocks a taken subdomain but allows the brand's own current subdomain; (e) a test that `is_published: true` with no `display_name` returns a validation error.
        - Compare against `LinkBlockSocialValidationTest.php`, `LinkBlockPlatformCapTest.php`, and `LinkBlockCategoryValidationTest.php` — `StoreLinkBlockRequest` has equivalent dedicated coverage; follow the same pattern.
    - **Technical:** `UpdateSiteRequest` carries 15+ `prohibited` rules (the retired design key blocklist), three `Rule::in()` enum constraints for the 3-bucket design system, a `font_family` allowlist, and a multi-table subdomain closure (checks `sites`, `site.site_subdomain_aliases`, and `site.professional_handle_aliases`). A grep across `tests/` for `UpdateSiteRequest` returns no test file. The only coverage pathway is integration tests that happen to hit the PATCH /site endpoint — those tests assert on HTTP response shapes, not on the validation contract itself. A regression in any `prohibited` rule or enum would be invisible until a brand managed to write a deprecated key into the JSONB column.
    - **Plain English:** This form validator is the gatekeeper for all brand site settings. It blocks brands from writing old field names that were retired in a recent design system migration. There are no dedicated tests for it — so if a rule was accidentally removed or an enum value added without updating both here and the frontend, nobody would know until a brand reported weird behavior. The equivalent validator for link blocks (a simpler form) has three dedicated test files; this one has zero.
    - **Evidence:**
        ```php
        'settings.design.border_color' => ['prohibited'],
        'settings.design.white_color' => ['prohibited'],
        'settings.design.dark_color' => ['prohibited'],
        'settings.design.background_color' => ['prohibited'],
        'settings.design.text_color' => ['prohibited'],
        // ...
        'settings.design.corner_radius' => ['sometimes', 'nullable', 'string', Rule::in(['square', 'default', 'pill'])],
        'settings.design.border_thickness' => ['sometimes', 'nullable', 'string', Rule::in(['hairline', 'default', 'bold'])],
        'settings.design.section_spacing' => ['sometimes', 'nullable', 'string', Rule::in(['tight', 'default', 'spacious'])],
        'settings.design.font_family' => ['sometimes', 'nullable', 'string', Rule::in([
            'neue_haas_grotesk',
            'helvetica_neue',
            'forma_djr',
            'nb_architekt',
            'swiss_721',
        ])],
        ```

- [ ] **#TEST-5** · P2 — Wallet credit idempotency test is sequential-only; concurrent path untested
    - **Where:** `tests/Feature/Stripe/WalletMovementsLedgerTest.php:100`
    - **Affects:** Brand wallet top-up credits; two `checkout.session.completed` webhooks for the same session could arrive concurrently during a Stripe retry.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add a concurrent idempotency test using PHP `pcntl_fork()` or parallel HTTP requests that call `creditWalletFromCheckoutSession` with the same session ID simultaneously, then assert exactly one `WalletMovement` row exists and the balance reflects exactly one credit.
        - Alternatively, if forking in SQLite-backed test processes is impractical, document that the UNIQUE constraint on `idempotency_key` is the sole guard and add a comment to the test noting the sequential-only coverage limitation.
    - **Technical:** The current test calls `creditWalletFromCheckoutSession` twice sequentially with the same `'cs_topup_dup'` session ID and asserts only one `WalletMovement` row is created. This proves the UNIQUE constraint on `idempotency_key` fires correctly — the DB-level guard is real and works. What the test doesn't prove is that the application-level lock (`lockForUpdate` or similar) prevents a race between the constraint check and the insert from leaving the wallet balance in a torn state. The UNIQUE constraint prevents duplicate rows, but if two concurrent calls both read balance=0, both attempt `increment('stripe_manual_balance_cents', 5000)`, and one then gets a unique constraint violation on insert, the balance could end up at 10000 with only one movement row. This is worth testing concurrently given the wallet is real money. Re-tiered from P1: the UNIQUE constraint does make production functionally safe (double row impossible), so this is hardening not a correctness bug.
    - **Plain English:** We have a test that calls the wallet top-up twice in a row and confirms only one credit goes through. That's good. But Stripe sometimes delivers two webhooks for the same event at the *exact same moment*. The current test doesn't simulate that simultaneous scenario — it only tests calling them one-after-the-other. The database constraint prevents double-recording, but a concurrent test would give us much stronger confidence that the account balance doesn't get out of sync even under a real-world Stripe retry storm.
    - **Evidence:**
        ```php
        it('credits wallet exactly once when called twice (idempotent via UNIQUE key)', function () {
            $brand = walletLedger_makeBrand();
            $session = walletLedger_makeSession($brand->id, ['id' => 'cs_topup_dup']);

            $service = walletLedger_makeService();
            $service->creditWalletFromCheckoutSession($brand->id, $session);
            $service->creditWalletFromCheckoutSession($brand->id, $session); // sequential, not concurrent

            expect($brand->fresh()->stripe_manual_balance_cents)->toBe(5000);
            expect(WalletMovement::where('related_session_id', 'cs_topup_dup')->count())->toBe(1);
        });
        ```

- [ ] **#TEST-6** · P2 — No migration-level constraint test sweep; structural schema invariants rely on code alone
    - **Where:** `tests/Feature/Security/PolicyCoverageTest.php:45`
    - **Affects:** All tenant data; DB-level constraints (UNIQUE, CHECK, FK) are the last line of defence against bad writes. No automated test verifies they're present.
    - **Effort:** L (~1–2d)
    - **What to do:**
        - Add a `SchemaConstraintCoverageTest.php` (parallel to `PolicyCoverageTest.php`) that connects to the real Postgres DB and asserts, for each critical table, the existence of its required UNIQUE, CHECK, and FK constraints by name.
        - Start with the highest-stakes constraints: `commerce.order_events.shopify_event_id` (UNIQUE), `billing.webhook_events.stripe_event_id` (UNIQUE), `commerce.wallet_movements.idempotency_key` (UNIQUE), and the `status` CHECK constraints on `commission_payouts`.
        - Use `information_schema.table_constraints` or `pg_constraint` — the test schema already runs against real Postgres so this is compatible with the existing test harness.
    - **Technical:** `PolicyCoverageTest.php` demonstrates a mature sweep pattern: enumerate the application surface (all Models), check each one against a registry (Gate policies), fail loudly on gaps. The codebase has no equivalent for database constraints. The UNIQUE on `commerce.order_events.shopify_event_id` is what makes Shopify webhook idempotency work; the UNIQUE on `billing.webhook_events.stripe_event_id` is what makes Stripe webhook idempotency work; the UNIQUE on `commerce.wallet_movements.idempotency_key` is what prevents double wallet credits. All three of these invariants are exercised in application tests, but none of those tests would catch if the constraint was accidentally dropped in a migration, column rename, or Supabase schema reset. A sweep test that queries `pg_constraint` for these constraints by name would catch that class of regression at CI time, before it reaches production.
    - **Plain English:** The app's protection against processing the same Shopify or Stripe event twice ultimately depends on database-level uniqueness rules. We test that the application code respects those rules, but we don't test that the rules themselves are actually installed in the database. It's like testing that your door lock works without ever checking the door has a lock slot. A similar sweep test already exists for a different kind of rule (authorization policies) and has caught real gaps — the same pattern applied to database constraints would catch accidental schema regressions before they affect users.
    - **Evidence:**
        ```php
        it('every tenant-owned model has a registered policy', function () {
            $modelFiles = (new Finder)
                ->files()
                ->in(app_path('Models'))
                ->name('*.php')
                ->notName('BaseModel.php')
                ->notPath('Views')
                ->getIterator();

            $missing = [];
            foreach ($modelFiles as $file) {
                // ...
                $policy = Gate::getPolicyFor($relative);
                if ($policy === null) {
                    $missing[] = $relative;
                }
            }

            expect($missing)->toBe([], "Models without a registered Policy:\n  - " ...);
        });

        // No parallel test: critical UNIQUE/CHECK/FK constraints are actually installed
        // in the live schema. A migration drop or column rename would be invisible.
        ```

`★ Insight ─────────────────────────────────────`
Three adjudication patterns exercised in this audit worth internalising:

1. **Grep before accepting DeepSeek's coverage claims.** Two of the nine draft findings (TEST-1/commission_paid_cents and TEST-4/AffiliatePayoutResource) were invalidated outright because the tests *do* exist — DeepSeek hallucinated the gap. Grep `tests/` for the class name and the key literal before keeping a "missing test" finding.

2. **DB-level guards change the tier.** Draft TEST-6 (wallet credit) came in at P1. The UNIQUE constraint on `idempotency_key` makes the production scenario safe — the concurrent gap is hardening evidence, not a correctness bug, so it drops to P2. Always ask: "Does a DB constraint catch this even if application code doesn't?"

3. **The PolicyCoverageTest sweep pattern is reusable.** That test enumerates an application surface (Models), checks each against a registry (Gate), and fails loudly on gaps. The same structure applies to DB constraints (pg_constraint), API resource classes (Resources/), and Form Requests — any invariant you want CI to catch before it reaches production.
`─────────────────────────────────────────────────`
