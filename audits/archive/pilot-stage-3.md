# Partna — Stage 3 Pilot Readiness Audit (2026-05-07)

**Branch:** development-v2
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6` via `scripts/audit/audit.sh` (one run per lens, four lenses)
**Lenses run:**
1. Authorization surface (policies, middleware, route gates)
2. Money flow (Shopify/Stripe/Square webhooks, commerce models, payout services)
3. Background job reliability (jobs + observers)
4. Public/unauthenticated surface (PublicSite controllers, public routes)

**Findings tally:** 18 total — 2 P0, 6 P1, 8 P2, 2 P3.

> **Note on PUB-1:** the public-surface adjudication output was truncated at the start of the file; line 1 began mid-paragraph with the Technical/Plain English/Evidence sections of a CAPTCHA-related finding whose header was lost. PUB-1 below has been reconstructed from the visible content. Treat tier as my best inference (`P2`); rerun `scripts/audit/audit.sh` with `--keep-drafts` if you want to verify the original DeepSeek classification.

---

## Progress

- P0 Blockers: 0 of 2 complete
- P1 High: 0 of 6 complete
- P2 Medium: 0 of 8 complete
- P3 Low: 0 of 2 complete

---

## Suggested Bundled Sessions

### Bundle: missing failed() handlers across financial / onboarding jobs
- [ ] **B1 — Missing failed() handlers across financial / onboarding jobs.** #JOB-3, #JOB-4, #JOB-5, #JOB-6, #JOB-7. ~2–3h. Identical fix shape — add `public function failed(\Throwable $e): void { report($e); Log::error(...); }`. Same review concerns (Nightwatch routing, log fields). Touches five sibling job classes; one PR is cleaner than five.

### Bundle: media job terminal-state idempotency guards
- [ ] **B2 — Media job terminal-state idempotency guards.** #JOB-1, #JOB-2. ~1–2h. Both `ProcessVideoVariantsJob` and `ProcessImageVariantsJob` need the same idempotency guard at the top of `handle()`, plus JOB-1 needs the missing `ready` write. They're sister classes that share an architectural contract.

### Bundle: auth-surface mechanical cleanup
- [ ] **B3 — Auth-surface mechanical cleanup.** #AUTH-2, #AUTH-3, #AUTH-5. ~2h. All `routes/api*` and `AppServiceProvider` edits, all S-effort. Low risk, mechanical.

### Standalone — do NOT bundle

- **#AUTH-1 (P0)** — Security-critical fail-open on missing config. Needs dedicated test (`testing` env behaviour + production fail-closed assertion) and careful review.
- **#AUTH-4 (P2)** — Touches policy contract / `bool|Response` return type. Will likely surface a `PolicyCoverageTest` adjustment.
- **#PAY-1 (P1)** — Financial state-machine fix; needs unit test for grace-window reset + the `failure_code` skip guard. Don't merge with anything else touching `CommissionPayoutService`.
- **#PAY-2 (P2)** — Introduces a new `reversed` payout state and a new `handleTransferReversed` method. State-machine + admin-tooling implications; do alone.
- **#PAY-3 (P2)** — Concurrency-sensitive `lockForUpdate` change in `processPayoutBatch`. Do alone with explicit race-test coverage.
- **#WEB-4 (P2)** — Webhook contract change (200 → 401 on bad HMAC). Coordinate with monitoring expectations; do alone.
- **#PUB-1 (P2 — reconstructed)** — CAPTCHA introduction is a product/UX decision, not just code. Pick a vendor (Turnstile / hCaptcha / reCAPTCHA) before scoping the implementation.
- **#PUB-8 (P3)** — String fix + a potential one-off `UPDATE` for any pre-existing rows with the stale `firstOrCreate` lookup key.

### Dependencies between bundles / items

- **JOB-1 ↔ JOB-2:** the idempotency guard pattern in JOB-2 is the template for JOB-1's added guard; do JOB-2 first or do them together (recommended bundle).
- **AUTH-2 ↔ AUTH-1:** unrelated. Both auth, but different layers (route middleware vs. middleware fail-open).
- **PAY-1 ↔ PAY-2 ↔ PAY-3:** all touch `CommissionPayoutService` / `StripeConnectWebhookController`. Sequence them so the `reversed` state from PAY-2 lands before PAY-3's re-validation logic if you want PAY-3 to also re-validate against reversal-in-flight; otherwise independent.

---

## Category 1: Authorization & Auth Surface

**Source files:** `app/Policies/`, `app/Http/Middleware/`, `app/Services/Auth/`, `app/Providers/AppServiceProvider.php`, `routes/`

### P0

- [x] **#AUTH-1** · P0 — VerifyEmbeddedApiKey silently bypasses auth when `embedded.api_key` config is empty
    - **Where:** app/Http/Middleware/Auth/VerifyEmbeddedApiKey.php:20–28
    - **Affects:** All routes under `internal/embedded/*` — setup wizard, deployment token creation, deploy-now trigger, domain provisioning, brand config. Any of these can be called without a valid key if the env var is absent at deploy time.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Mirror `VerifyHydrogenApiKey` exactly: add an `if ($expected === '')` branch that allows pass-through only when `app()->environment(['local', 'testing'])`, and throws a `RuntimeException` otherwise.
        - Optionally add a boot-time assertion in `AppServiceProvider::boot()` that `services.embedded.api_key` is non-empty in production, as a belt-and-suspenders deploy guard.
    - **Technical:** `VerifyHydrogenApiKey` was hardened with an environment-gated fail-closed guard (the comment explicitly records the production-deploy scenario it protects against). `VerifyEmbeddedApiKey` was not updated in the same pass. Currently, if `SIDEST_EMBEDDED_API_KEY` is missing or blank in any environment — including production — the outer `if ($expected !== '')` block is skipped entirely and every `/internal/embedded/*` request passes through unauthenticated. The Hydrogen fix at `VerifyHydrogenApiKey:14–22` is the exact template: two lines of environment gate followed by a `RuntimeException`.
    - **Plain English:** Both the Shopify-embedded door and the Hydrogen door use a secret passcode to verify who's knocking. The Hydrogen door was patched so that if nobody remembered to set the passcode at deployment time, the door throws an alarm rather than swinging open. The embedded door has the same flaw the Hydrogen door used to have: no passcode set means anyone can walk straight in. A single missing environment variable in a production deployment silently opens the entire embedded setup wizard — including the endpoint that writes deployment tokens to the storefront — to anonymous traffic.
    - **Evidence:**
        ```php
        // VerifyEmbeddedApiKey.php:20-28
        $expected = (string) config('services.embedded.api_key');

        // Skip key validation in dev/test when no key is configured
        if ($expected !== '') {
            $provided = (string) str_replace('Bearer ', '', $request->header('Authorization', ''));

            if ($provided === '' || ! hash_equals($expected, $provided)) {
                return response()->json(['message' => 'Invalid or missing embedded API key.'], 403);
            }
        }
        ```
        ```php
        // VerifyHydrogenApiKey.php:14-22 — the correct pattern to replicate
        if ($expected === '') {
            if (app()->environment(['local', 'testing'])) {
                return $next($request);
            }

            throw new \RuntimeException(
                'services.hydrogen.api_key is not configured — refusing to fall through to bypass outside local/testing.'
            );
        }
        ```

### P1

- [x] **#AUTH-2** · P1 — BrandAffiliateInvite write and delete endpoints missing `brand.only` middleware — any authenticated professional can reach brand-only controllers
    - **Where:** routes/api/professional.php:105–111
    - **Affects:** POST `/brand-affiliate-invites`, `/brand-affiliate-invites/bulk`, `/brand-affiliate-invites/import-csv`, and DELETE `/brand-affiliate-invites/{invite}` — all are reachable by any authenticated professional (affiliate, influencer, or professional type), not just brands.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Wrap all four write routes in a `Route::middleware(['brand.only'])` group (inside which `brand-funding-gate` can nest for the three POSTs, or fold both into a single group).
        - The `DELETE /brand-affiliate-invites/{invite}` is not even inside `brand-funding-gate` currently — it has no role protection at all beyond the outer JWT + `current.pro` middleware. Add it to the same `brand.only` group.
        - Confirm `BrandAffiliateInviteController::store/bulk/importCsv` builds the skeleton with `brand_professional_id` set from the authenticated professional (not from request input), so `BrandPartnerLinkPolicy::create` provides a second line of defense even while this fix ships.
    - **Technical:** `BrandFundingGate` explicitly documents that it passes non-brand professionals through — its role is payment verification, not role gating. The comment on line 102 even says "the route's own role check… is the right place to 403 for non-brand traffic" — acknowledging that a separate role gate was supposed to exist here and does not. The sibling brand management routes (`/brand-affiliates`, all `/brand/catalog/*`, etc.) already live inside `Route::middleware(['brand.only'])` groups. A prior audit fix (#PH4-3, visible in the route file comment at line 83) moved brand-affiliate read endpoints to `brand.only` but left the invite write routes behind. The `BrandPartnerLinkPolicy::create` check (`$brandId !== '' && $actor->id === $brandId`) would block affiliates if the controller sets `brand_professional_id` from the authenticated actor — but this relies on controller implementation correctness and would silently break if a future change accepted `brand_professional_id` from user input. Doctrine mandates route-level middleware as the first gate.
    - **Plain English:** The platform has a VIP lounge (brand-only endpoints) with a bouncer at the front. Most brand doors have an ID-checker bouncer (`brand.only`) plus a payment-card scanner inside (`brand-funding-gate`). These invite creation doors only have the payment scanner — which is explicitly designed to wave non-brand people straight past. A previous security fix added the ID-checker to the adjacent brand management doors but missed these. Right now, any affiliate can walk up to the "create invite" door, the payment scanner says "you're not a brand, proceed," and the affiliate lands in the controller with no further gate in their way.
    - **Evidence:**
        ```php
        // routes/api/professional.php:100-111
        Route::get('/brand-affiliate-invites', [BrandAffiliateInviteController::class, 'index']);
        Route::post('/brand-affiliate-invites/availability', [BrandAffiliateInviteController::class, 'availability']);
        // Write endpoints are gated by BrandFundingGate — a brand can't
        // send invites without a payment method on file (the platform
        // would absorb commission float for any lapsed brand otherwise).
        Route::middleware('brand-funding-gate')->group(function (): void {
            Route::post('/brand-affiliate-invites', [BrandAffiliateInviteController::class, 'store']);
            Route::post('/brand-affiliate-invites/bulk', [BrandAffiliateInviteController::class, 'bulk']);
            Route::post('/brand-affiliate-invites/import-csv', [BrandAffiliateInviteController::class, 'importCsv']);
        });
        Route::delete('/brand-affiliate-invites/{invite}', [BrandAffiliateInviteController::class, 'destroy'])
            ->whereUuid('invite');
        ```
        ```php
        // BrandFundingGate.php:39-43 — confirms non-brands pass straight through
        // Non-brand requests pass through. The route's own role check (or
        // policy / controller guard) is the right place to 403 for
        // non-brand traffic; double-rejecting here would mask the real
        // reason and make the response shape misleading.
        if (mb_strtolower(trim((string) $professional->professional_type)) !== 'brand') {
            return $next($request);
        }
        ```

### P2

- [x] **#AUTH-3** · P2 — `/internal/hydrogen/affiliate` is the only Hydrogen route outside `hydrogen.key` middleware with no explicit unauthenticated annotation
    - **Where:** routes/api.php:115–117
    - **Affects:** The affiliate identity endpoint used by the Hydrogen storefront client-side. Security relies entirely on controller-level verification that a valid, active brand-affiliate link exists — not verifiable from route definitions alone.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Audit `HydrogenAffiliateController::show()` to confirm it only returns data when a verified, active `BrandPartnerLink` exists and returns 404 (not an empty object) for unknown inputs.
        - Add an explicit `// INTENTIONALLY UNAUTHENTICATED — enumeration mitigated by controller link verification` annotation directly on the route, distinct from the existing prose comment, so future engineers do not accidentally add leakage-prone response fields without recognising the security contract.
        - If `HydrogenAffiliateController` does not currently enforce a verified-link check, add `hydrogen.key` middleware and coordinate with the Hydrogen frontend team on a server-side proxy pattern to hold the key.
    - **Technical:** Every other route in the `/internal/hydrogen/` prefix is behind `hydrogen.key`. This single route was intentionally excluded because the Hydrogen storefront loads it client-side (browser → Laravel), not server-side, and cannot safely hold a shared secret. The route comment asserts enumeration is prevented by controller-level verification. That contract is invisible to route definitions and future maintainers, and nothing in the provided source confirms the controller enforces it. The asymmetry — one unlocked door in an otherwise fully locked hallway — creates a maintenance trap: any addition to the controller's response payload silently inherits the unauthenticated exposure.
    - **Plain English:** Every door in the Hydrogen server corridor requires a keycard except one, which is left open because customers need to walk through it from their browser. The sign on the door says "it's safe because the receptionist inside only tells you something if your name is on the list." That's a reasonable design, but it depends entirely on the receptionist's logic staying correct. Right now there's no way to verify that from the outside, and no "intentionally unlocked" warning sign for future engineers adding features to this door.
    - **Evidence:**
        ```php
        // routes/api.php:113-117
        // Public affiliate endpoint — no API key needed. The affiliate is only returned
        // when a verified brand-affiliate link exists, so there's no enumeration risk.
        // Accessory endpoints (services, products) remain behind the hydrogen.key
        // middleware since they add load with no client-side initiator.
        Route::get('/internal/hydrogen/affiliate', [HydrogenAffiliateController::class, 'show'])
            ->middleware('throttle:hydrogen-internal');
        ```
        ```php
        // routes/api.php:105-111 — all sibling routes are key-gated
        Route::middleware(['hydrogen.key', 'throttle:hydrogen-internal'])->prefix('internal/hydrogen')->group(function () {
            Route::get('/brand-config', [HydrogenBrandConfigController::class, 'show']);
            Route::get('/deployment-targets', [HydrogenDeploymentController::class, 'targets']);
            Route::get('/brand-design/{slug}', [HydrogenBrandDesignController::class, 'show'])
                ->where('slug', '[a-zA-Z0-9][a-zA-Z0-9_-]{0,62}');
            Route::get('/affiliate-services', [HydrogenAffiliateController::class, 'services']);
            Route::get('/affiliate-products', [HydrogenAffiliateProductsController::class, 'show']);
        });
        ```

- [x] **#AUTH-4** · P2 — IntegrationPolicy::view returns a bare `false` (yields HTTP 403) instead of `denyAsNotFound()` (HTTP 404) when integration has no owner
    - **Where:** app/Policies/IntegrationPolicy.php:44–51
    - **Affects:** Any authenticated professional testing a UUID for an integration record with a null or empty `professional_id` — they receive a 403 response instead of 404, leaking that the record exists.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Change `actorCanReachOwner` return type from `bool` to `bool|Response`.
        - Replace `return false;` in the empty-`$ownerId` branch with `return $this->denyAsNotFound();`.
        - Change `view`'s return type annotation from `bool` to `bool|Response` to match — `manage` already uses `bool|Response` and serves as the in-file template.
    - **Technical:** Per the Partna Authorization Doctrine, resource-level denials on route-bound endpoints must return 404 (`denyAsNotFound()`) to prevent non-owners from confirming a resource's existence. `IntegrationPolicy::view` delegates to `actorCanReachOwner`, which currently returns a plain `bool`. Laravel's Gate interprets `false` as HTTP 403 — confirming to the caller that a record with that UUID exists but they cannot see it. Every other policy in the codebase (`CustomerPolicy`, `ServicePolicy`, `SitePolicy`, `BrandPartnerLinkPolicy`, etc.) uses `denyAsNotFound()` for this case. `IntegrationPolicy` is the sole outlier. The empty-`ownerId` path is a data integrity edge case, but it is reachable at runtime and the status-code difference is an observable information leak. Note that `manage` in the same file already has the correct `bool|Response` return type and calls `denyIfPendingDeletion` — the `view` method just needs to match that pattern.
    - **Plain English:** When someone tries to access an integration record they don't own, every other locked door in the building says "never heard of it" (404 — no information given). This particular door says "that exists, but you can't have it" (403 — confirms the record is real). The difference is small but meaningful to someone probing the system: they now know that UUID belongs to a real record. The fix makes this door behave the same way every other door in the building does.
    - **Evidence:**
        ```php
        // IntegrationPolicy.php:37-51
        public function view(Professional $actor, ProfessionalIntegration $integration): bool
        {
            return $this->actorCanReachOwner($actor, $integration);
        }

        // ...

        private function actorCanReachOwner(Professional $actor, ProfessionalIntegration $integration): bool
        {
            $ownerId = trim((string) ($integration->professional_id ?? ''));
            if ($ownerId === '') {
                return false;  // ← yields 403; should be $this->denyAsNotFound() → 404
            }

            if ((string) $actor->id === $ownerId) {
                return true;
            }

            return $this->brandAccess->canManageShopify($actor, $ownerId);
        }
        ```

### P3

- [x] **#AUTH-5** · P3 — Duplicate `Gate::policy` registration for `CommissionMovement` in AppServiceProvider
    - **Where:** app/Providers/AppServiceProvider.php:46–47
    - **Affects:** No runtime behavior — `Gate::policy` registration is idempotent. Indicates a copy-paste error that may mask a missing registration for a different model.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Remove the duplicate line.
        - Check whether the duplicate was intended to register a different commission-adjacent model. Cross-reference `PolicyCoverageTest.php`'s exempt list to confirm no tenant-owned model is silently unregistered.
    - **Technical:** `Gate::policy(CommissionMovement, CommissionPolicy)` appears on consecutive lines. Idempotent at runtime, but copy-paste duplicates in a policy registration block are often the ghost of an intended-but-forgotten registration for a different model. The Phase 4 migration (`20260506600000_rename_ledger_to_movements.sql`) renamed `commission_ledger_entries` to `commission_movements`; if a `CommissionLedgerEntry` model class still exists anywhere in the codebase as a legacy alias, it would need its own registration or an explicit exemption in `PolicyCoverageTest`. The CI sweep-test (`PolicyCoverageTest`) is the safety net here.
    - **Plain English:** You have two identical keys on the keyring where one was supposed to be a different key. The duplicate doesn't cause any harm — it just doesn't unlock anything new — but it's worth checking whether you meant to add a second, different key and grabbed the wrong one by mistake.
    - **Evidence:**
        ```php
        // AppServiceProvider.php:46-47
        Gate::policy(\App\Models\Retail\CommissionMovement::class, \App\Policies\CommissionPolicy::class);
        Gate::policy(\App\Models\Retail\CommissionMovement::class, \App\Policies\CommissionPolicy::class);
        ```

---

## Category 2: Commerce / Money Flow

**Source files:** `app/Http/Controllers/Api/Webhooks/`, `app/Http/Controllers/Api/Shopify/`, `app/Jobs/Shopify|Stripe|Square/`, `app/Services/Shopify|Stripe|Square/`, `app/Models/Commerce/`

### P1

- [x] **#PAY-1** · P1 — Currency-mismatch payout loops forever; `void_at` not reset on `pending_funds`
    - **Where:** `app/Services/Stripe/CommissionPayoutService.php:339–361`, `:631–644`
    - **Affects:** Any brand whose Stripe manual wallet is denominated in a different currency than the commission being paid out — commissions for those affiliates stay permanently frozen in `pending_funds` while `processEligiblePayouts` re-dispatches them on every sweep, silently flooding Horizon.
    - **Effort:** S (~1h)
    - **What to do:**
        - In `markPendingFunding`, add `'void_at' => now()->addDays($this->gracePeriodDays)` to the `forceFill` array so the grace window resets each time the payout status is updated rather than expiring against the original creation timestamp.
        - Add a `failure_code` guard in `processEligiblePayouts`: if `failure_code = 'wallet_currency_mismatch'`, skip re-dispatching until an admin clears the flag — prevents infinite Horizon churn with no forward progress.
        - Add an admin endpoint / Nightwatch alert for payouts stuck in `pending_funds` with `wallet_currency_mismatch` for more than 48 hours.
    - **Technical:** `createPayoutBatch` stamps `void_at = now() + grace_period_days` (default 60 days). `markPendingFunding` then sets `status='pending_funds'` but leaves `void_at` untouched. Because `processEligiblePayouts` re-queues every `pending_funds` payout whose `eligible_after` is in the past, a currency-mismatch payout is dispatched on every scheduler tick, hits the mismatch check again, calls `markPendingFunding` again (a no-op on the already-`pending_funds` status), publishes a deduped notification, and returns `null` — in a tight loop until either the mismatch is manually resolved or `VoidExpiredPayoutsJob` voids the payout. If the void job reaches it, `orders.payout_id` is cleared, and the affiliate's accrued commission evaporates with no record-level audit trail of the root cause. Even if the void job only targets inactive affiliates, active-affiliate payouts simply loop in the queue forever.
    - **Plain English:** When a brand has their wallet funded in pounds but needs to pay an affiliate in dollars, the system correctly pauses the payout and notifies the brand. But it doesn't reset the expiry clock. The system also keeps retrying the payout on every automated sweep, which is like a postal worker attempting the same undeliverable letter thousands of times. After 60 days the letter can be automatically thrown out — and the affiliate's unpaid earnings go with it. The fix is to reset the clock each time the system decides to wait, and to stop the mail carrier from endlessly reattempting what it already knows will fail.
    - **Evidence:**
        ```php
        // CommissionPayoutService.php:339–361 — currency mismatch path
        if ($hasCurrencyMismatch) {
            $walletCurrency = strtoupper((string) ($brand->stripe_manual_balance_currency ?? 'unknown'));
            $this->markPendingFunding(
                $payout,
                'wallet_currency_mismatch',
                "Wallet balance is in {$walletCurrency} but payout requires {$currencyUpper}. Please contact support to resolve.",
            );

            $this->publisher->publish(
                professionalId: $payout->brand_professional_id,
                frontendType: 'Warning',
                category: 'commissions',
                title: 'Commission payout on hold',
                body: sprintf(
                    'A commission payout of %s could not be processed because your wallet balance is in %s. Please contact support to resolve the currency mismatch.',
                    $this->formatMoney($payout->gross_commission_cents, $payout->currency_code),
                    $walletCurrency,
                ),
                dedupeKey: "wallet_currency_mismatch.{$payout->id}",
                ctaUrl: '/account/settings?section=wallet',
            );

            return null;
        }

        // CommissionPayoutService.php:631–644 — markPendingFunding: void_at absent
        private function markPendingFunding(CommissionPayout $payout, string $code, string $reason): void
        {
            $payout->forceFill([
                'status' => 'pending_funds',
                'failure_code' => $code,
                'failure_reason' => $reason,
                'processed_at' => null,
            ])->save();
        ```

### P2

- [x] **#WEB-4** · P2 — `themes/publish` webhook returns 200 on invalid HMAC
    - **Where:** `app/Http/Controllers/Api/Webhooks/ShopifyThemePublishedWebhookController.php:27–34`
    - **Affects:** Shopify's webhook delivery health tracking; operators lose visibility when HMAC verification fails in production.
    - **Effort:** S (~0.5h)
    - **What to do:**
        - Return `$this->error('invalid signature', 401)` on bad HMAC, identical to the pattern in `ShopifyOrdersUpdatedWebhookController`.
        - Remove the suppression comment — if a legitimate Shopify retry storm is a concern, handle it at the infrastructure level (rate-limit the endpoint) rather than by accepting forged deliveries silently.
    - **Technical:** The inline comment justifies the 200 response by claiming Shopify would "flood logs" on retries. This reasoning is incorrect: Shopify retries with exponential backoff capped at a finite attempt count, then alerts the merchant via the Partners dashboard. Returning 200 has two concrete downsides: (1) it prevents Shopify's own retry + alert chain from surfacing a real HMAC misconfiguration; (2) any system monitoring delivery success rates will see 100% success even when keys are rotated out of sync. Because this endpoint dispatches `SyncShopifyBrandDesignJob` — which itself re-fetches from Shopify's API rather than trusting the webhook body — the blast radius of a forged delivery is limited to a spurious read-only job. The risk is observability, not data corruption. Still, the 200-on-bad-HMAC pattern is inconsistent with all other Shopify webhook controllers in this codebase and should be aligned.
    - **Plain English:** When someone sends a fake delivery to this webhook, our server tells them "thanks, got it!" instead of "you're not authorised." That means if our own security keys ever get out of sync with Shopify, nothing will alert us — every delivery looks successful. It's like a security guard saying "no problem, come in" to anyone who can't show ID, and then reporting to management that no one was ever turned away.
    - **Evidence:**
        ```php
        // ShopifyThemePublishedWebhookController.php:27–34
        if (! $this->isValidShopifyHmac($rawBody, $signature)) {
            Log::warning('Shopify themes/publish webhook: invalid HMAC signature', [
                'shop_domain' => $shopDomain,
            ]);

            // Return 200 regardless — Shopify retries on non-2xx, which would flood logs.
            return $this->success(['received' => true]);
        }
        ```

- [x] **#PAY-2** · P2 — `transfer.reversed` routed to `handleTransferFailed`; guard silently no-ops on completed payouts
    - **Where:** `app/Http/Controllers/Api/Webhooks/StripeConnectWebhookController.php:129`, `:250–257`
    - **Affects:** Affiliates whose Stripe Connect transfers are reversed after the payout completes — their commission record shows `completed` but their Stripe balance was clawed back with no system-level record of the reversal.
    - **Effort:** S (~1h)
    - **What to do:**
        - Add a dedicated `handleTransferReversed` handler that correctly marks the payout `status='reversed'` (add this to the state machine), logs the reversal with a distinct `failure_code`, and flags the payout for manual review.
        - Guard the existing `handleTransferFailed` so `transfer.reversed` events are not silently swallowed when the payout is already `completed` — `completed` payouts should be updatable to `reversed`.
        - Ensure `transfer_reversed` failure code is distinct from `transfer_failed_webhook` so admin tooling can differentiate failed-before-delivery from reversed-after-delivery.
    - **Technical:** The `match` dispatch at line 129 maps `transfer.reversed` to the same `handleTransferFailed` method used for `transfer.failed`. These events have meaningfully different semantics: `transfer.failed` means funds never left the platform; `transfer.reversed` means funds reached the affiliate and were subsequently clawed back by Stripe (compliance hold, account closure, etc.). The guard `! in_array($payout->status, ['failed', 'completed', 'cancelled'])` causes silent no-ops for `completed` payouts — the most common real-world reversal scenario (transfer confirmed → payout marked complete → Stripe later reverses). After the no-op, the payout record shows `completed`, the affiliate's Stripe balance is drained, and there is no `needs_manual_refund` flag, no notification, and no audit trail. The brand was not refunded. Unlike the synchronous failure path in `processPayoutBatch`, the webhook handler does not attempt auto-refund logic, so the brand ends up charged with no corresponding affiliate payment.
    - **Plain English:** When Stripe sends money to an affiliate and then takes it back (which they can do for legal or compliance reasons), our system currently treats that the same as if the money never left — and if it already recorded the payment as complete, it ignores the reversal entirely. The end result is the system believes the affiliate was paid, the affiliate's account was drained, and the business that funded the payout has no indication anything went wrong. It's like the bank reversing a wire transfer and the sender never getting notified — the accounting books stay wrong.
    - **Evidence:**
        ```php
        // StripeConnectWebhookController.php:128–129 — reversed routed to failed handler
        'transfer.failed'    => $this->handleTransferFailed($event->data->object),
        'transfer.reversed'  => $this->handleTransferFailed($event->data->object),

        // StripeConnectWebhookController.php:250–257 — guard skips completed payouts silently
        $payout = CommissionPayout::find($payoutId);
        if ($payout && ! in_array($payout->status, ['failed', 'completed', 'cancelled'], true)) {
            $payout->forceFill([
                'status' => 'failed',
                'failure_code' => 'transfer_failed_webhook',
                'failure_reason' => 'Transfer failed according to Stripe webhook',
            ])->save();
        }
        ```

- [x] **#PAY-3** · P2 — Post-creation refund window unguarded; `processPayoutBatch` doesn't re-validate order state after batch is in `collecting`
    - **Where:** `app/Services/Stripe/CommissionPayoutService.php:154–161`, `:302–306`
    - **Affects:** Any affiliate whose orders are refunded in the window between `createPayoutBatch` stamping `payout_id` on the orders and `processPayoutBatch` completing the Stripe transfer. The affiliate receives the full commission on a refunded order.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - At the start of `processPayoutBatch`, when the payout is still `pending` (before wallet debit), re-query for any orders whose `refund_cents > 0` or `status != 'approved'` since the batch was created. If any are found, either rebuild the batch (update `gross_commission_cents`, `net_payout_cents`) or abort and clear `payout_id` stamps to release orders back to the next sweep.
        - The re-check must be inside a `lockForUpdate` transaction to prevent a concurrent refund webhook from racing with the re-check itself.
        - Add a guard in the `collecting`/`transferring` resume path that short-circuits if `net_payout_cents` has become ≤ 0 after order adjustments.
    - **Technical:** `createPayoutBatch` runs inside a `lockForUpdate` transaction that atomically stamps `orders.payout_id` on all eligible orders. However, the eligibility check (`refund_cents = 0`, `status = 'approved'`) is a point-in-time snapshot; a `refunds/create` webhook arriving immediately after the transaction commits can flip an order's `refund_cents` and `status` before `processPayoutBatch` runs. Because `processPayoutBatch` only checks `payout.status` (not re-reading the underlying orders), it proceeds through wallet debit, card charge, and Stripe transfer using the stale `gross_commission_cents` from batch creation. The code's own docblock acknowledges this: "Refunds that arrive AFTER a payout is created are not reconciled in v1 — acceptable pre-beta; tracked for Phase 4." At pilot scale with real money this window is too wide to leave open — a 7-day hold period means there is a substantial window for refunds to arrive between batch creation and transfer.
    - **Plain English:** When our system decides which sales to pay out, it locks in the list and the dollar amounts at that moment. But between locking in the list and actually sending the money, a customer could return the item and get a refund. Right now the system doesn't look back to check — it pays out the original amount even if the sale was reversed. This is real money leaving the business for sales that no longer happened. The fix is a quick double-check immediately before sending funds to confirm nothing changed since the list was locked in.
    - **Evidence:**
        ```php
        // CommissionPayoutService.php:154–161 — acknowledged gap in docblock
        /**
         * Create a payout batch record and link all eligible orders.
         *
         * Phase 3.5+: reads from commerce.orders directly (not commission_movements).
         * Orders that are refunded after status='approved' have their status flipped to
         * 'partially_refunded'/'refunded', which the WHERE clause already excludes. Refunds
         * that arrive AFTER a payout is created are not reconciled in v1 — acceptable pre-beta;
         * tracked for Phase 4.
         */
        private function createPayoutBatch(

        // CommissionPayoutService.php:302–306 — resume path reads saved payout state only
        if (in_array($payout->status, ['collecting', 'collected', 'transferring'], true)) {
            // Wallet debit already committed in a previous run — read from DB, don't re-debit.
            $walletDebitCents = (int) ($payout->wallet_debit_cents ?? 0);
            $chargeAmountCents = (int) ($payout->charge_cents ?? $amountToCollect);
        }
        ```

---

## Category 3: Background Job Reliability

**Source files:** `app/Jobs/`, `app/Observers/`

### P0

- [x] **#JOB-1** · P0 — Video processing job never promotes SiteMedia to `ready` after successful transcode
    - **Where:** app/Jobs/ProcessVideoVariantsJob.php:117–120 (try block, successful path)
    - **Affects:** Every video uploaded to the platform. After FFmpeg transcoding and variant creation succeed, the `SiteMedia` row stays permanently in `processing_state = processing`. Videos are invisible on every page, and dashboards show perpetual loading spinners for all users. The job's own docblock documents the intended `processing → ready` transition; only the success-path write is absent.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Immediately after `$service->processVariants(...)` returns, add an Eloquent update setting `processing_state = SiteMedia::PROCESSING_STATE_READY` and `processing_error = null` — mirror the exact block used in `ProcessImageVariantsJob` (lines 109–115 of that file).
        - Add an idempotency guard at job entry, after the trashed-row check: load the row with `SiteMedia::withTrashed()->find($this->mediaId)` and return early if `processing_state` is already `PROCESSING_STATE_READY` or `PROCESSING_STATE_FAILED`. Without this guard a redelivered job overwrites a terminal state back to `processing`, and if the retry then throws, the row lands in `failed` even though transcoding was already successful.
    - **Technical:** The class has a complete `failed()` hook and `markFailed()` path, so the error branch is implemented correctly. Only the happy path is broken — `$service->processVariants()` completes, the log line fires, the `finally` block cleans up the temp file, and the method returns without ever calling the database again. The three-state machine (`pending → processing → ready/failed`) is documented in the class docblock but only two transitions are implemented. The idempotency gap is a secondary concern added here rather than as a separate finding because the same file requires both fixes and they should be committed together.
    - **Plain English:** Think of a photo-printing kiosk that processes your photo and prints it out perfectly, but then shreds the receipt and tells you it's "still printing." The website never stops showing a loading spinner because there's no "done" stamp being recorded, even though the video was actually processed successfully. This affects 100% of video uploads on the platform right now — not edge cases.
    - **Evidence:**
        ```php
        $service->processVariants(
            localOriginalPath: $localTmp,
            mediaId: $this->mediaId,
            basePath: $this->basePath,
        );

        Log::info('ProcessVideoVariantsJob: completed.', ['media_id' => $this->mediaId]);
        // try block ends here; no update to processing_state = PROCESSING_STATE_READY
        ```

### P1

- [x] **#JOB-2** · P1 — Image variant job has no terminal-state idempotency guard; retry after success can permanently flip the image to `failed`
    - **Where:** app/Jobs/ProcessImageVariantsJob.php:93–97
    - **Affects:** Any image upload where the queue redelivers a previously-successful job (Horizon worker restart, Redis ack loss, job timeout exceeded before broker acknowledgement). The row rewinds to `processing`, variants are regenerated, and if the retry fails for any transient reason (e.g., an OOM kill that killed the first run before ack, not before write) the row lands permanently in `failed` — even though the image and all its variants already exist on the media disk and are perfectly usable.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - After the existing trashed-row check, add: `if (in_array($siteMedia->processing_state, [SiteMedia::PROCESSING_STATE_READY, SiteMedia::PROCESSING_STATE_FAILED], true)) { return; }` — using the `$siteMedia` already loaded above via `SiteMedia::withTrashed()->find()`.
        - This is the same guard needed in the video job (JOB-1); both fixes are one-liners in their respective handle methods.
    - **Technical:** Laravel's at-least-once delivery guarantee makes this a production certainty rather than a theory. The job unconditionally writes `processing_state = processing` before any other work. If a previously-successful execution (state already `ready`) is redelivered — e.g., a Horizon worker is SIGKILL'd after writing `ready` but before the broker receives the ack — the redelivery overwrites `ready` with `processing` and begins reprocessing. On a fresh S3 read the retry would normally succeed and re-write `ready`. But a transient error (S3 rate limit, memory limit on large image) during that retry would call `markFailed()` and `$this->fail($e)`, leaving the row in `failed` with valid variants still on disk. The `failed()` handler then runs `cleanupR2Artifacts()`, deleting the variants that were actually fine.
    - **Plain English:** After a successful image upload, if the background-processing system glitches and hands the same job to a second worker without confirming the first one finished, the second worker starts over without checking "was this already done?" If that second attempt has a hiccup, the image is permanently marked as broken — even though the first attempt succeeded and the image was perfectly fine. The platform would then delete the already-processed image files.
    - **Evidence:**
        ```php
        SiteMedia::query()
            ->where('id', $this->imageId)
            ->whereNull('deleted_at')
            ->update([
                'processing_state' => SiteMedia::PROCESSING_STATE_PROCESSING,
                'processing_error' => null,
            ]);
        // No check against PROCESSING_STATE_READY or PROCESSING_STATE_FAILED before overwriting
        ```

- [x] **#JOB-3** · P1 — VoidPendingCommissionsForLinkJob has no `failed()` handler; terminal void failures leave commission in indeterminate state with no alert
    - **Where:** app/Jobs/Stripe/VoidPendingCommissionsForLinkJob.php (class-level omission)
    - **Affects:** Brand-affiliate disconnections where the pending commission void job exhausts its 3 retries. No Nightwatch alert fires, no audit trail is written, and the disconnected pair's stale commission entries remain active with no expiry mechanism. Operations cannot detect the gap without manually querying `commission_movements` for the disconnected pair.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `public function failed(\Throwable $e): void` that calls `report($e)` (Nightwatch visibility) and `Log::error(...)` with `affiliate_professional_id`, `brand_professional_id`, `reason`, and `$e->getMessage()`.
        - Optionally: write a sentinel flag to `BrandPartnerLinkAuditor` so support can detect unresolved voids via a query without trawling Horizon.
    - **Technical:** `ShouldQueue::failed()` is the framework's dead-letter hook — it fires only after all retry attempts are exhausted. The job has `tries = 3` and a 60s `backoff`, so three consecutive failures (DB deadlock, Stripe outage, OOM) silently terminate. Every other financially significant job in the codebase calls `report($e)` in `failed()`: `ExecuteCommissionPayoutJob`, `ProcessCommissionPayoutsJob`, and `VoidExpiredPayoutsJob` all implement this pattern explicitly. The inconsistency is not cosmetic — without `report($e)`, the exception is never forwarded to Nightwatch's named-exception grouping, making it indistinguishable from other generic queue noise.
    - **Plain English:** When a brand removes an affiliate, this job cancels any commissions that haven't been paid out yet. If it fails three times in a row and gives up, no one is told. The stale commissions just sit there indefinitely. A simple one-line alarm — the same alarm every other financial job already has — is all that's needed to close this gap.
    - **Evidence:**
        ```php
        class VoidPendingCommissionsForLinkJob implements ShouldQueue
        {
            use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

            public int $tries = 3;

            public int $backoff = 60;

            public function __construct(
                public readonly string $affiliateProfessionalId,
                public readonly string $brandProfessionalId,
                public readonly string $reason,
            ) {
                $this->onQueue('stripe');
            }

            public function handle(...): void { ... }

            public function loadProfessionals(): array { ... }
            // No failed() method
        }
        ```

- [x] **#JOB-4** · P1 — ProcessShopifyOrderWebhookJob has no `failed()` handler; terminal orders/paid failures silently drop commission records
    - **Where:** app/Jobs/Shopify/ProcessShopifyOrderWebhookJob.php (class-level omission)
    - **Affects:** Every Shopify `orders/paid` event. A terminal failure after 3 retries means the `commerce.orders` row is never written, no `order_events` row exists, no rollup trigger fires, no analytics cache invalidation occurs, and no Nightwatch alert surfaces. From the platform's perspective the sale never happened. The affiliate is never paid; the brand never sees the attributed sale.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `public function failed(\Throwable $e): void` that calls `report($e)` and `Log::error(...)` with `brand_professional_id`, `shopify_event_id` from `$this->shopifyEventId`, the Shopify order ID from `Arr::get($this->orderPayload, 'id', '')`, and `$e->getMessage()`.
    - **Technical:** This job is the entry point for the entire commission pipeline. It already contains production-readiness signals: an explicit `timeout = 30`, a slow-processing warning log at the 15s threshold with `$this->attempts()` context, and the LWW upsert SQL that guards against out-of-order delivery. The only missing piece is the dead-letter hook. A schema mismatch after a migration deploy, a DB connection pool exhaustion, or a BrandCatalogService Shopify API failure — any of these hitting all 3 attempts would silently drop the order. `ExecuteCommissionPayoutJob`, which runs downstream of this job's output, has a complete `failed()` handler including wallet-debit reversal. The job that feeds it has nothing.
    - **Plain English:** This is the job that records a completed sale and calculates the commission. If the database has a problem during all three attempts, the sale disappears from Partna's records entirely — the affiliate isn't paid, the brand doesn't see the revenue, and no alarm fires. You'd only discover the gap days later by noticing that Shopify shows more orders than Partna has commission records for.
    - **Evidence:**
        ```php
        class ProcessShopifyOrderWebhookJob implements ShouldQueue
        {
            use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

            public int $tries = 3;

            public int $timeout = 30;

            public function __construct(
                public string $brandProfessionalId,
                public array $orderPayload,
                public string $shopifyEventId = '',
                public string $source = 'webhook',
            ) {
                $this->onQueue('integrations');
            }

            public function handle(...): void { ... }

            // process(), upsertOrder(), insertOrderEvent(), buildEnrichedLineItems(),
            // extractProductGids(), buildSafeShopifyData(), captureAffiliateContact(),
            // parseMarketingOptInAttribute(), extractCartAttribute(), resolveCommissionRate()
            // all present — no failed() method
        }
        ```

- [x] **#JOB-5** · P1 — ProcessShopifyOrderUpdatedWebhookJob has no `failed()` handler; terminal refund/cancel failures cause silent commission drift
    - **Where:** app/Jobs/Shopify/ProcessShopifyOrderUpdatedWebhookJob.php (class-level omission)
    - **Affects:** Order lifecycle corrections: `orders/cancelled`, `refunds/create`, `orders/edited`. A terminal failure after 3 retries means `commerce.orders` drifts from Shopify — cancelled orders remain `approved`, refunds are not applied, and `brand_affiliate_rollup.reversed_commission_cents` is understated because the `trg_rollup_clawback` trigger never fires. Affiliates receive commission payouts for sales that were fully refunded or cancelled, with no alert that the correction failed.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `public function failed(\Throwable $e): void` that calls `report($e)` and `Log::error(...)` with `professional_id`, `topic`, the Shopify order ID extracted from `$this->payload` (`Arr::get($this->payload, 'id')` for most topics, `Arr::get($this->payload, 'order_id')` for `refunds/create`), and `$e->getMessage()`.
    - **Technical:** The `refunds/create` handler updates `refund_cents` via a raw SQL statement and relies on the `trg_rollup_clawback` trigger to maintain `brand_affiliate_rollup`. A silent drop on this topic means the rollup accrual is overstated by the full refund amount for every failed event. At pilot scale with a small affiliate base this may go undetected for weeks; at scale it produces systematic overpayment. The `orders/cancelled` handler flips `status = 'cancelled'` — silent failure means cancelled-order commissions flow into the payout pipeline when they should not. Per the CLAUDE.md architecture reminder, `commission_cents` is frozen at `orders/paid` time (Decision #3), so there is no duplicate-credit risk; the risk here is purely the missing clawback.
    - **Plain English:** This is the job that records cancellations and refunds. If a customer returns a purchase and this job fails three times and gives up, the commission record still shows the original sale. The affiliate eventually gets paid for a sale that was reversed. No alarm goes off — the only way to discover the discrepancy is to manually compare Shopify's refund list against Partna's payout records.
    - **Evidence:**
        ```php
        class ProcessShopifyOrderUpdatedWebhookJob implements ShouldQueue
        {
            use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

            public int $tries = 3;

            public int $timeout = 30;

            public function __construct(
                public string $professionalId,
                public array $payload,
                public string $topic,
                public string $shopifyEventId = '',
            ) {
                $this->onQueue('integrations');
            }

            public function handle(AnalyticsCacheService $analyticsCache): void { ... }

            // handleUpdated(), handleEdited(), handleCancelled(), handleRefund(),
            // snapshotUpdate(), insertEventIfNew(), insertStub(), findOrder(),
            // atomicStubInsert(), resolveShopDomain(), resolveAffiliateIdFromPayload(),
            // calculateGrossCents(), calculateRefundSubtotal(), ... all present
            // No failed() method
        }
        ```

### P2

- [x] **#JOB-6** · P2 — SeedAffiliateDefaultSelectionsJob has no `failed()` handler; terminal failure leaves new affiliates with an empty store and no alert
    - **Where:** app/Jobs/Store/SeedAffiliateDefaultSelectionsJob.php (class-level omission)
    - **Affects:** Any affiliate who connects to a brand for the first time. After 3 retries exhaust with no success, the affiliate's product catalog is empty — the brand's default collection is not seeded. There is no Nightwatch alert, no support ticket, and no retry path beyond the initial 3 attempts. The affiliate sees a blank store and has no indication of whether this is normal or broken.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `public function failed(\Throwable $e): void` that calls `report($e)` and `Log::error(...)` with `affiliate_professional_id`, `brand_professional_id`, and `$e->getMessage()`.
    - **Technical:** The `handle()` method already has the correct internal pattern — it catches `\Throwable`, logs it as `Log::error`, then re-throws for queue retry machinery. The missing piece is the terminal handler. Because this job is dispatched exactly once per link lifecycle event (new connection), there is no background reconciler that would re-seed a failed affiliate. A permanent failure is permanent until a support engineer manually dispatches the job, which they cannot do without an alert that it failed.
    - **Plain English:** When an affiliate joins a brand's program, this job automatically fills their store with the brand's default product list. If the job runs into a persistent problem and gives up, the affiliate's store stays empty — like showing up to a store where all the shelves have been stripped bare. No one is notified, and the affiliate has no way to know this wasn't intentional.
    - **Evidence:**
        ```php
        class SeedAffiliateDefaultSelectionsJob implements ShouldQueue
        {
            use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

            public int $tries = 3;

            public int $backoff = 30;

            public function __construct(
                public readonly string $affiliateProfessionalId,
                public readonly string $brandProfessionalId,
            ) {
                $this->onQueue('integrations');
            }

            public function handle(AffiliateProductCatalogService $catalogService): void
            {
                // ...
            }
            // No failed() method
        }
        ```

- [x] **#JOB-7** · P2 — SendWeeklyAnalyticsNotificationJob has `tries=1` with no `failed()` handler; a single exception silently drops the entire weekly fan-out for all users
    - **Where:** app/Jobs/Notifications/SendWeeklyAnalyticsNotificationJob.php:27–28
    - **Affects:** All active professionals on the platform. Any exception during the chunked DB scan — Redis unavailable, Postgres timeout, OOM kill mid-loop — terminates the job immediately with no retry and no Nightwatch alert. Every active user misses that week's analytics summary. There is no way to know this happened except by noticing the absence of notifications in a week's worth of user feedback.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `public function failed(\Throwable $e): void` that calls `report($e)` and `Log::error(...)` — even with `tries = 1`, Laravel fires `failed()` on terminal exit, giving Nightwatch full visibility.
        - Raise `$tries` to `2`. The per-professional dedupe key `"analytics.weekly.{$professional->id}.{$yearWeek}"` passed to `NotificationPublisher::publish()` uses `insertOrIgnore` semantics, so a second run of the same week is fully idempotent — each professional receives at most one notification regardless of how many times the job runs. `tries = 1` buys nothing and removes a free safety net.
    - **Technical:** `SendStaffBroadcastEmailsJob` — a structurally similar weekly fan-out job — has both `report($e)` in `failed()` and `Log::error` with the relevant ID for Nightwatch grouping. `SendWeeklyAnalyticsNotificationJob` covers a broader population (all active professionals vs. marketing subscribers) and fires on a fixed weekly schedule rather than on-demand, making silent failure harder to notice. The `yearWeek` dedupe key is the correct idempotency mechanism; `tries = 1` is an unnecessary restriction on top of it.
    - **Plain English:** Once a week, this job sends every user a summary of their sales and commissions. It only tries once. If the database hiccups or runs out of memory at the wrong moment, the entire batch is dropped silently — no second attempt, no alarm, no record that anything went wrong. Users just don't get their weekly update that week. The fix is to let it try twice and add a one-line alarm, which costs almost nothing.
    - **Evidence:**
        ```php
        public int $tries = 1;

        public int $timeout = 300;

        public function __construct()
        {
            $this->onQueue('notifications');
        }

        public function handle(NotificationPublisher $publisher): void
        {
            $yearWeek = now()->format('o-W');
            // ... chunked fan-out over all active professionals
        }
        // No failed() method in class
        ```

---

## Category 4: Public Surface Hardening

**Source files:** `app/Http/Controllers/Api/PublicSite/`, `app/Services/Public/`, `app/Services/Store/`, `app/Services/Site/`, `routes/api/publicSite.php`, `routes/api.php`

### P2

- [x] **#PUB-1** · P2 — Public lead-capture endpoints rely on weak passive bot defenses (reconstructed from orphaned adjudicator output)
    - **Where:** app/Http/Controllers/Api/PublicSite/PublicEnquiryController.php; app/Http/Controllers/Api/PublicSite/PublicWaitlistController.php; routes/api.php (`/public/customers`, `/public/enquiry`, `/public/waitlist`)
    - **Affects:** Public lead-capture endpoints visible to unauthenticated traffic. Without a server-side cryptographic challenge, an automated script can flood every professional's inbox with fake leads daily.
    - **Effort:** M (~2–4h once a CAPTCHA vendor is selected)
    - **What to do:**
        - Pick a CAPTCHA vendor (Cloudflare Turnstile, hCaptcha, or reCAPTCHA v3 — Turnstile is recommended for the lowest UX friction and free tier).
        - Add a CAPTCHA verification middleware that runs after `throttle:leads`/`throttle:waitlist` and before controller dispatch.
        - Apply to all three lead-capture routes. The throttle middleware alone is bypassable via IP rotation; CAPTCHA is the missing server-side challenge.
        - Honeypot + start-time timing checks on `PublicEnquiryController` are good defense-in-depth but are passive — they validate attacker-controlled values that are trivially forged. Keep them; do not rely on them as the primary gate.
    - **Technical:** `PublicEnquiryController` uses an `if (honeypot !== '')` check and a client-supplied `startedMs` timing check. Both are validated against attacker-controlled input. `PublicWaitlistController` has neither — only `throttle:waitlist`. None of the three routes (`/public/customers`, `/public/enquiry`, `/public/waitlist`) have CAPTCHA middleware. Throttle middleware alone is bypassable via IP rotation; CAPTCHA provides a server-side cryptographic challenge that cannot be forged without genuine user interaction and defeats the IP rotation vector entirely.
    - **Plain English:** The front door has a "no solicitors" sign and a hidden trip-wire — both visible to anyone who reads the blueprints. The waitlist door has nothing at all. A real bot ignores the sign, steps over the wire, and submits a plausible timestamp. What's missing is a lock that requires human interaction to open. Without it, one automated script can flood every professional's inbox with fake leads daily, and there is no technical barrier to stop it.
    - **Evidence:**
        ```php
        // app/Http/Controllers/Api/PublicSite/PublicEnquiryController.php — passive checks only
        $honeypot = $data['website'] ?? null;
        if (is_string($honeypot) && trim($honeypot) !== '') {
            $this->logLead($request, $subdomain, null, null, 'honeypot', $startedMs);
            return $this->success(['ok' => true]);
        }
        if (is_int($startedMs)) {
            $nowMs = (int) floor(microtime(true) * 1000);
            $delta = $nowMs - $startedMs;
            // validates attacker-controlled value — trivially forged
        }
        ```
        ```php
        // app/Http/Controllers/Api/PublicSite/PublicWaitlistController.php — no bot detection
        public function store(PublicWaitlistSignupRequest $request): JsonResponse
        {
            $data = $request->validated();
            $email = mb_strtolower(trim((string) $data['email']));
            // no honeypot, no timing check — only throttle:waitlist rate limit
        ```
        ```php
        // routes/api.php — no CAPTCHA middleware on any lead-capture route
        Route::post('/public/customers', [PublicCustomerLeadController::class, 'store'])
            ->middleware(['lead.log', 'throttle:leads']);
        Route::post('/public/enquiry', [PublicEnquiryController::class, 'submit'])
            ->middleware(['lead.log', 'throttle:leads']);
        Route::post('/public/waitlist', [PublicWaitlistController::class, 'store'])
            ->middleware('throttle:waitlist');
        ```

### P3

- [x] **#PUB-8** · P3 — Welcome notification hardcodes stale product name "Sight" after the Partna rebrand
    - **Where:** app/Http/Controllers/Api/PublicSite/BootstrapController.php (`createWelcomeNotification`)
    - **Affects:** Every new professional who signs up after pilot launch. Their first in-app notification reads "Welcome to Sight" — a name that does not match the product.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Update both the `title` and `body` strings to use "Partna" and replace the placeholder body with real onboarding copy before pilot launch.
        - Note: the `firstOrCreate` key includes the old `title` string. Existing rows (pre-pilot internal accounts) will not be updated by the code change alone — run a targeted `UPDATE` if any real accounts were created with the broken notification.
    - **Technical:** The recent rebrand commit `fddeec5 chore(rebrand): rename Side St → Partna` updated "Side St" references across the codebase but left the "Sight" placeholder in `createWelcomeNotification` untouched. Because `firstOrCreate` uses `title` as part of the lookup key, changing the title in code will not update existing rows — the old row will remain and new signups will correctly get the updated notification, but any pre-existing accounts get neither (the old title no longer matches, so a new row is created alongside the stale one on next bootstrap). A one-off migration is the clean fix if any real accounts exist.
    - **Plain English:** Every new member's first message says the wrong product name. It's a two-word fix, and the only reason it matters is that pilot users will see it on day one.
    - **Evidence:**
        ```php
        // app/Http/Controllers/Api/PublicSite/BootstrapController.php — createWelcomeNotification
        private function createWelcomeNotification(Professional $professional): void
        {
            Notification::query()->firstOrCreate(
                [
                    'professional_id' => $professional->id,
                    'type' => 'Info',
                    'title' => 'Welcome to Sight',
                ],
                [
                    'body' => 'Welcome to Sight. This is placeholder content for now.',
                    'cta_url' => null,
                    'severity' => 'info',
                    'starts_at' => now(),
                    'ends_at' => null,
                ]
            );
        }
        ```
