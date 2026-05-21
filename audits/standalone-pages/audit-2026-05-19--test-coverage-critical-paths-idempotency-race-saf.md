`★ Insight ─────────────────────────────────────`
The critical adjudication insight here: DeepSeek scanned only the **planning document** (`PARTNA-STANDALONE-PAGES-NEW-DIRECTION-1.md`) and generated findings based on the plan saying "these need tests" — without checking the actual test suite. The codebase already has `CommissionPayoutServiceTest.php`, `VoidExpiredPayoutsJobTest.php`, `ReconcileStuckPayoutsJobTest.php`, `CommissionVoidServiceTest.php`, and `AffiliateCommercePaidGateTest.php`. That makes 8 of the 20 draft findings factually wrong. The remaining findings about future code (planned services that don't exist yet) are premature — they should be flagged when the code is written, not now. Only 4-5 findings survive verification.
`─────────────────────────────────────────────────`

# Test Coverage Audit — 2026-05-19

**Branch:** development
**Lens:** Test coverage: critical paths, idempotency, race-safety, policy abilities, mock-vs-integration discipline
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
- `app/Http/Controllers/Api/Webhooks/Shopify/` (15 files)
- `app/Http/Controllers/Api/Webhooks/Stripe/` (3 files)
- `app/Services/Stripe/CommissionPayoutService.php`
- `app/Services/Stripe/CommissionVoidService.php`
- `app/Jobs/Stripe/VoidExpiredPayoutsJob.php`
- `app/Jobs/Stripe/ReconcileStuckPayoutsJob.php`
- `app/Policies/*.php` (15 policies)
- `tests/Feature/Stripe/` (38 files)
- `tests/Feature/Webhooks/Shopify/` (8 files)
- `tests/Feature/Security/PolicyEnforcement/` (9 files)
- `tests/Feature/Analytics/AffiliateCommercePaidGateTest.php`
- `/Users/joshuahunter/Downloads/PARTNA-STANDALONE-PAGES-NEW-DIRECTION-1.md` (plan)

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 4 complete
- P3 Low: 0 of 1 complete

---

## P2 — Should fix

- [ ] **#TEST-1** · P2 — Shopify secondary webhook controllers missing HMAC signature-fail tests
    - **Where:** `app/Http/Controllers/Api/Webhooks/Shopify/ShopifyOrdersEditedWebhookController.php`, `ShopifyRefundsCreateWebhookController.php`, `ShopifyAppUninstalledWebhookController.php`, `ShopifyOrdersCancelledWebhookController.php`, `ShopifyThemePublishedWebhookController.php`
    - **Affects:** Webhook authentication for order-edited, refund, app-uninstall, order-cancel, and theme-published events. A silent break in HMAC middleware would not be caught by the existing functional tests for these controllers, meaning forged Shopify events could be accepted.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - For each of the five controllers listed, add `it('returns 401 when HMAC is invalid', ...)` and `it('accepts valid HMAC and dispatches', ...)` tests in the corresponding test file.
        - `ShopifyOrdersEditedWebhookController` → `tests/Feature/Webhooks/Shopify/OrderEditedSnapshotTest.php` (extend) or a new `ShopifyOrdersEditedWebhookControllerTest.php`.
        - `ShopifyRefundsCreateWebhookController` → extend `tests/Feature/Webhooks/Shopify/OrderRefundPathTest.php`.
        - `ShopifyAppUninstalledWebhookController`, `ShopifyOrdersCancelledWebhookController`, `ShopifyThemePublishedWebhookController` → create dedicated test files following the pattern in `ShopifyOrderWebhookControllerTest.php`.
    - **Technical:** `ShopifyOrderWebhookControllerTest.php` and `ShopifyOrdersUpdatedWebhookControllerTest.php` both cover the HMAC-fail path explicitly (`it('orders/paid — bad HMAC returns 401 and dispatches nothing', ...)` etc.) and serve as the template. `GdprWebhookControllerTest.php` also covers signature fail. The five controllers named above have test files that exercise business logic paths but contain zero HMAC-related assertions — verified by grepping for `hmac|signature` returning 0 matches. If the `VerifyShopifyWebhookSignature` middleware is ever refactored or its config key changes, the only catch point would be the controllers that already have HMAC tests.
    - **Plain English:** When Shopify sends a webhook, it includes a secret handshake to prove the message is genuine. The tests for your main order webhook correctly check that a fake handshake gets rejected. But five other webhooks — including the one for refunds and order edits — have tests that never try a bad handshake. If someone broke the secret-handshake check, those tests would still pass while accepting forged messages.
    - **Evidence:**
        ```
        // Verified: grep for 'hmac|HMAC|signature' in OrderEditedSnapshotTest.php → 0 matches
        // Verified: grep for 'hmac|HMAC|signature' in OrderRefundPathTest.php → 0 matches
        // Verified: no test file exists for ShopifyAppUninstalledWebhookController,
        //           ShopifyOrdersCancelledWebhookController, ShopifyThemePublishedWebhookController
        //
        // Contrast with ShopifyOrderWebhookControllerTest.php which has:
        it('orders/paid — bad HMAC returns 401 and dispatches nothing', function () {
            // ...
            'X-Shopify-Hmac-SHA256' => 'invalid-hmac',
            // ...
        });
        it('orders/paid — accepts valid HMAC and dispatches job with real-shape payload', function () {
        ```

- [ ] **#TEST-2** · P2 — Seven policies have no dedicated ability-coverage test (allowed + denied)
    - **Where:** `app/Policies/AffiliateProductPolicy.php`, `BrandResourcePolicy.php`, `GdprPolicy.php`, `ProfessionalSelfPolicy.php`, `SubscriptionPolicy.php`, `PartnaStaffPolicy.php`, `FeatureFlagPolicy.php`
    - **Affects:** Authorization surface for subscriptions, self-management, staff operations, feature flags, GDPR endpoints, brand resources, and affiliate products. A silent policy regression returns 200 where it should return 403/404.
    - **Effort:** L (~1–2d)
    - **What to do:**
        - For each policy, add tests in `tests/Feature/Security/PolicyEnforcement/` asserting: (a) the correct actor is allowed, (b) a wrong-actor request returns 404 (not 403) for ownership checks per the doctrine, and (c) a cross-tenant request is denied.
        - Verify 404-on-not-yours (not 403) for policies that use `denyAsNotFound()`.
        - Use existing `BrandPartnerLinkPolicyEnforcementTest.php` and `SitePolicyEnforcementTest.php` as templates.
    - **Technical:** `app/Policies/` contains 15 policy classes. `tests/Feature/Security/PolicyEnforcement/` contains 9 test files covering: `BrandPartnerLinkPolicy`, `NotificationPolicy`, `CommissionPolicy`, `IntegrationPolicy` (via `EmbeddedPolicyEnforcementTest`), `ServicePolicy`, `SitePolicy`, `CustomerPolicy`, and two link-block/document tests that map to `SitePolicy`. The seven listed above have no dedicated enforcement test. The `PolicyCoverageTest` referenced in CLAUDE.md asserts every model has a policy registered — it does NOT assert every policy method has an allowed + denied test. The MFA Foundation work (2026-05-18) and the `account_type` capability migration (plan §28.11) both touch `CommissionPolicy` and refactor policy gates — without allowed/denied tests for the remaining policies, regressions there go undetected.
    - **Plain English:** Every locked door in the app has a policy — a set of rules about who can open it. Nine doors already have tests that try the right key and a wrong key. Seven doors only have the lock installed; nobody has tested that the wrong key actually fails. During the account-type refactor, the policy rules are being changed — you want tests that will shout if someone accidentally unlocks one of those seven doors.
    - **Evidence:**
        ```
        // app/Policies/ — 15 policy files found:
        AffiliateProductPolicy.php  BrandPartnerLinkPolicy.php  BrandResourcePolicy.php
        CommissionPolicy.php        CustomerPolicy.php           FeatureFlagPolicy.php
        GdprPolicy.php              IntegrationPolicy.php        NotificationPolicy.php
        PartnaStaffPolicy.php       ProfessionalSelfPolicy.php   ServicePolicy.php
        SitePolicy.php              SubscriptionPolicy.php       BasePolicy.php

        // tests/Feature/Security/PolicyEnforcement/ — 9 files, covering only:
        BrandPartnerLinkPolicyEnforcementTest.php  CommissionPolicyEnforcementTest.php
        CustomerPolicyEnforcementTest.php           DocumentPolicyEnforcementTest.php
        EmbeddedPolicyEnforcementTest.php           LinkBlockPolicyEnforcementTest.php
        NotificationPolicyEnforcementTest.php       ServicePolicyEnforcementTest.php
        SitePolicyEnforcementTest.php

        // No enforcement test for: AffiliateProductPolicy, BrandResourcePolicy,
        // GdprPolicy, ProfessionalSelfPolicy, SubscriptionPolicy, PartnaStaffPolicy, FeatureFlagPolicy
        ```

- [ ] **#TEST-3** · P2 — Future plan migrations add CHECK / UNIQUE / FK constraints with no described constraint-rejection tests
    - **Where:** Plan §28.1, §28.16, §34, §36 — migrations to be created under `supabase/migrations/`
    - **Affects:** Data integrity for the `account_type` column, `brand_profiles.signup_code`, `brand.signup_code_audit`, and `brand.brand_partner_links` soft-delete. A subtly wrong CHECK constraint passes migration but accepts invalid values silently.
    - **Effort:** M (~2–4h) per migration, add alongside implementation
    - **What to do:**
        - Follow the existing pattern in `tests/Feature/Commerce/OrdersSchemaMigrationTest.php` and `LedgerRenameMigrationTest.php`.
        - For `_enforce_account_type_constraints.sql`: add test asserting INSERT with `account_type = 'invalid'` is rejected by the CHECK constraint.
        - For `_enforce_brand_signup_code_constraints.sql` (§36 step 3): add test asserting duplicate `signup_code` INSERT fails the UNIQUE constraint.
        - For `_create_brand_signup_code_audit.sql` (§34): add tests asserting (a) `event = 'invalid_event'` is rejected by CHECK, and (b) `event = 'claimed'` with `joined_professional_id = NULL` is rejected by the compound CHECK.
        - For `_add_soft_deletes_to_brand_partner_links.sql` (§28.16): add test asserting orphan `affiliate_professional_id` (pointing to non-existent professional) is rejected by FK.
    - **Technical:** The plan explicitly calls out these constraints in §28.1 step 3 (`CHECK (account_type IN ('brand', 'partner', 'individual'))`), §36 step 3 (`UNIQUE (signup_code)`, `NOT NULL`), and §34 (`CHECK (event IN (...))`, compound CHECK). `tests/Feature/Migrations/BackfillOrdersPayoutIdTest.php` and the Commerce migration tests show the established pattern. Adding constraint tests at implementation time is far cheaper than debugging a subtly-wrong constraint in production when bad data gets through.
    - **Plain English:** The plan is adding database rules like "account type must be one of three specific values." Each rule needs a test that tries to break it — insert a bad value and confirm the database says no. Without these tests, you can deploy a migration that looks correct but contains a typo in the rule, and it silently lets wrong data through for months until you notice something is off.
    - **Evidence:**
        ```sql
        -- From plan §28.1 step 3:
        ALTER COLUMN account_type SET NOT NULL,
        ADD CONSTRAINT ... CHECK (account_type IN ('brand', 'partner', 'individual'))

        -- From plan §36 step 3:
        ALTER COLUMN signup_code SET NOT NULL,
        ADD CONSTRAINT brand_profiles_signup_code_unique UNIQUE (signup_code)

        -- From plan §34:
        event text NOT NULL CHECK (event IN ('generated','rotated','deactivated',
            'reactivated','claimed','failed_claim')),
        CHECK ((event = 'claimed' AND joined_professional_id IS NOT NULL)
            OR (event <> 'claimed'))
        ```

- [ ] **#TEST-4** · P2 — Future `IndividualProfileResource` needs a snapshot / field-exclusion test enforcing plan rule #7 at implementation time
    - **Where:** `app/Http/Resources/PublicSite/IndividualProfileResource.php` (to be created per plan §28.8)
    - **Affects:** Public profile responses for individuals. An accidental inclusion of `placeholders`, `fallback_gallery`, `brand_logo`, or `brand_slogan` in the response leaks brand-specific data to a public unauthenticated endpoint.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - When `IndividualProfileResource` is implemented, add a feature test in `tests/Feature/PublicSite/` asserting the response from `GET /api/public/profiles/{handle}` for an individual professional:
            - **Contains** expected keys: bio, services, booking, links, newsletter status, analytics tracking IDs, `site.settings.design`.
            - **Does not contain** `placeholders`, `fallback_gallery`, `brand_logo`, `brand_slogan`.
        - Pair this with the existing `PublicSitePayloadShapeTest.php` pattern which already asserts response shape for brand/partner profiles.
    - **Technical:** Plan §28.8 specifies: "Excludes: brand placeholders, fallback_gallery, product/cart fields, commission/order data." Plan rule #7 (§50): "Brand-fallback content (placeholders, fallback_gallery, brand logo, brand slogan) stays in Hydrogen's data path. The Astro app for individuals never sees them." The enforcement mechanism for rule #7 is a feature test asserting key absence — without it, a refactor that accidentally includes those fields in the resource class would pass all existing tests. `tests/Feature/PublicSite/PublicSitePayloadShapeTest.php` is the nearest existing test host.
    - **Plain English:** The new public profile page for individual professionals gets its data from a new API endpoint. There's a hard rule that certain brand-specific fields must never appear in that response — they're only for the brand's own Shopify storefront. Without a test asserting those fields are absent, someone could accidentally add them during a refactor and leak brand data to a public page. A five-minute test would permanently enforce this rule.
    - **Evidence:**
        ```php
        // From plan §28.8 — explicit exclusion rule:
        // "Excludes: brand placeholders, fallback_gallery, product/cart fields, commission/order data"

        // From plan §50 rule #7:
        // "Brand-fallback content (placeholders, fallback_gallery, brand logo, brand slogan)
        // stays in Hydrogen's data path. The Astro app for individuals never sees them."

        // Enforcement mechanism described in §51:
        // "Feature test against IndividualProfileController response asserting absence of
        // `placeholders`, `fallback_gallery`, `brand_logo`, `brand_slogan` keys."
        ```

## P3 — Nice to have

- [ ] **#TEST-5** · P3 — Future `BrandProfile::creating` Eloquent hook for `signup_code` needs a model creation test confirming the hook fires and produces a valid code
    - **Where:** `app/Models/Core/Professional/BrandProfile.php` (creating hook to be added per plan §33)
    - **Affects:** Any test that creates a `BrandProfile` via factory and then relies on `signup_code` being non-null. If `createQuietly()` or `make()` is used instead of `create()`, the hook doesn't fire and tests operate on a model that would fail the NOT NULL constraint in production.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - When the `creating` hook is implemented, add `it('generates signup_code on factory create', ...)` asserting the result is non-null, 16 alphanumeric chars.
        - Add a negative test using `BrandProfile::create([... 'signup_code' => null ...])` — should produce a generated code, not a null.
        - Ensure factory defaults call `create()` (not `createQuietly()`) in test fixtures that rely on signup_code.
    - **Technical:** Plan §33 specifies the code is generated in the `BrandProfile::creating` Eloquent hook via `bin2hex(random_bytes(8))`. Plan §36 backfill explicitly notes: "The `creating` Eloquent hook does NOT fire when saving existing rows" — this asymmetry extends to test factories: `createQuietly()` skips model events, `make()` never persists. Future tests that call `BrandProfile::factory()->createQuietly()` will get a null `signup_code` that would fail the NOT NULL constraint added in §36 step 3.
    - **Plain English:** Every new brand is supposed to automatically get a unique signup code when it's created. But Laravel's test factory has two creation modes — one that triggers the automatic code generation and one that skips it. If a test accidentally uses the skip-mode, the brand has no code, which would cause an error in production (where the database rejects missing codes). A small test that creates a brand and confirms the code was generated prevents this subtle trap.
    - **Evidence:**
        ```php
        // From plan §33:
        // "signup_code: opaque alphanumeric string, 16 chars. Generated in PHP via the
        // BrandProfile::creating Eloquent hook using bin2hex(random_bytes(8))."

        // From plan §36:
        // "The creating Eloquent hook does NOT fire when saving existing rows, so the
        // backfill MUST call the code generator explicitly — relying on the hook would
        // silently leave codes blank."
        ```

`★ Insight ─────────────────────────────────────`
The key lesson from this adjudication pass: a coverage audit that reads only a *planning document* instead of the actual codebase will systematically over-report gaps. The Stripe financial flows (CommissionPayoutService, VoidExpiredPayoutsJob, ReconcileStuckPayoutsJob, CommissionVoidService, AffiliateCommercePaidGateTest) all had existing test files that the draft missed — dropping 8 of 20 findings. The surviving findings are concrete, quotable from actual file listings, and actionable at implementation time.
`─────────────────────────────────────────────────`
