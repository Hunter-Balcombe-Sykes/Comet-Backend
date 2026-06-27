# Audit: Stripe Payout Lifecycle — 2026-05-09

**Lens:** Stripe payout lifecycle — card-on-file gate, retry/reconcile jobs, grace warnings, refund service, wallet movements ledger, AUSTRAC audit trail, analytics tightening, policy auth refactor

**Scope:** app/Services/Stripe/, app/Jobs/Stripe/, app/Jobs/Shopify/ProcessShopify*WebhookJob.php, app/Notifications/, app/Models/Retail/CommissionPayout*, app/Models/Commerce/WalletMovement.php, app/Policies/CommissionPolicy.php, app/Policies/WalletMovementPolicy.php, app/Http/Controllers/Api/Webhooks/StripeConnectWebhookController.php, app/Http/Controllers/Api/Professional/Stripe/, app/Http/Controllers/Api/Professional/Brand/, app/Http/Controllers/Api/Professional/Affiliate/, app/Http/Requests/Stripe/, app/Services/Cache/AnalyticsCacheService.php, app/Services/Cache/CacheKeyGenerator.php, app/Providers/AppServiceProvider.php, routes/console.php, config/services.php, supabase/migrations/20260510*

**Scanner:** DeepSeek V4 Pro (scan only, no Claude adjudicator)

**Branch:** worktree-stripe-payout-lifecycle → merged to development 2026-05-09

---

## Findings

- [x] **#STRIPE-1** P2 — Effort: S — `StripeConnectController@payouts` uses inline role-scoping instead of policy
    - **Where:** `app/Http/Controllers/Api/Professional/Stripe/StripeConnectController.php:288-295`
    - **Affects:** `GET /stripe/payouts` — both brand and affiliate roles. Future staff/admin viewers would need to unsafely modify the controller to bypass or copy the pattern.
    - **What to do:**
        - Replace the inline `if ($role === 'brand') { ->where('brand_professional_id', $pro->id) } else { ->where('affiliate_professional_id', $pro->id) }` branch with a `Gate::forUser($pro)->authorize('viewOwnPayouts', $skeleton)` check where `$skeleton` carries the role-appropriate ID
        - `CommissionPolicy::viewOwnPayouts` is already registered and handles the filtering; the inline `->where()` clauses duplicate that logic
    - **Technical:** The Partna authorization doctrine mandates that all tenant-owned model access goes through policies, never inline checks. The current code scopes the query directly using `$pro->id`, which works for the two single-role cases but means a staff or admin endpoint would need to copy or bypass the logic. A policy keeps access rules testable in one place and prevents future drift.
    - **Plain English:** The payout list endpoint enforces "show only my records" by manually adding a database filter. If a staff member later needs to see all records, they'd have to remove that filter — which could accidentally expose other users' data. A policy is the one rulebook that applies the right filter automatically and can't be forgotten.
    - **Evidence:**
        ```php
        if ($role === 'brand') {
            $query->where('brand_professional_id', $pro->id);
        } else {
            $query->where('affiliate_professional_id', $pro->id);
        }
        ```

- [x] **#STRIPE-2** P3 — Effort: S — Misleading log when `revalidatePayoutOrders` cancels a payout batch
    - **Where:** `app/Services/Stripe/CommissionPayoutService.php` (`processPayoutBatch` null-return path) + `app/Jobs/Stripe/ExecuteCommissionPayoutJob.php:54-62`
    - **Affects:** Operations monitoring — a payout cancelled because all orders became ineligible is logged as "parked at transferring — awaiting webhook," which is factually wrong and wastes incident response time.
    - **What to do:**
        - After `processPayoutBatch` returns `null`, check `CommissionPayout::find($this->payoutId)->status` immediately; if `'cancelled'`, log `'payout.cancelled_by_revalidation'` instead of the transferring message
        - Or use a sentinel return value (e.g. `false` for cancelled vs `null` for in-flight) so the job can branch without a DB read
    - **Technical:** `processPayoutBatch` returns `null` in two distinct cases: (1) the Stripe Transfer was created but `status` wasn't `'paid'` yet — legitimately awaiting the `transfer.paid` webhook; (2) `revalidatePayoutOrders` found all linked orders had been refunded/cancelled and set the payout to `'cancelled'` — permanently done, no webhook will ever arrive. The job's handler logs the same "awaiting webhook" message for both, so a cancelled payout will forever appear in logs as "stuck in transferring" until the daily `ReconcileStuckTransferringPayoutsJob` finds it — and that job will itself log an `unexpected_status` warning. Not data loss, but the monitoring story is broken.
    - **Plain English:** When a payment is cancelled (because all the orders were refunded), the system still logs "waiting for bank confirmation." No bank confirmation is coming. It's like marking a cancelled parcel as "out for delivery" — the parcel won't arrive, and anyone checking the tracking will be confused.
    - **Evidence:**
        ```php
        // ExecuteCommissionPayoutJob::handle — both paths log the same message
        if ($result === null) {
            Log::info('ExecuteCommissionPayoutJob parked at transferring — awaiting webhook', [
                'payout_id' => $this->payoutId,
            ]);
            return;
        }
        // processPayoutBatch returns null for BOTH:
        //   a) Transfer created, status != 'paid' (awaiting webhook — correct)
        //   b) revalidatePayoutOrders cancelled the batch (no webhook coming — wrong log)
        ```

- [x] **#STRIPE-3** P3 — Effort: S — WalletMovement duplicate detection catches `QueryException` with fragile string matching
    - **Where:** `app/Services/Stripe/StripeConnectService.php:430-445` (`creditWalletFromCheckoutSession`)
    - **Affects:** Top-up idempotency — under a PostgreSQL error message change or constraint rename, a duplicate delivery silently crashes rather than being skipped.
    - **What to do:**
        - Replace `catch (\Illuminate\Database\QueryException $e)` + `str_contains($e->getMessage(), 'idempotency_key')` with `catch (\Illuminate\Database\UniqueConstraintViolationException $e)` — no string check needed, and this is the exception class used for the same pattern in `insertOrderEvent` elsewhere in the codebase
    - **Technical:** The code catches a generic `QueryException` and checks the message for `'idempotency_key'` or `'UNIQUE'`. In PostgreSQL the default constraint name includes the column name so it matches today, but this is fragile across Postgres versions and would break if the constraint were renamed. `UniqueConstraintViolationException` (available since Laravel 10) is the canonical, version-stable way to detect this and is already used elsewhere in this codebase.
    - **Plain English:** The idempotency guard catches a generic database error and looks for a keyword in the error text to decide if it's a "duplicate payment" error. If the wording changes slightly, a duplicate payment would crash instead of being silently skipped. Using the dedicated exception type (already used elsewhere in the app) makes this bulletproof.
    - **Evidence:**
        ```php
        } catch (\Illuminate\Database\QueryException $e) {
            if (str_contains($e->getMessage(), 'idempotency_key') || str_contains($e->getMessage(), 'UNIQUE')) {
                Log::info('stripe.topup.duplicate_session', ['session_id' => $sessionId]);
                return;
            }
            throw $e;
        }
        ```

- [x] **#STRIPE-4** P2 — Effort: M — Grace warning notifications never fire for payouts stuck in the retry loop
    - **Where:** `app/Services/Stripe/CommissionPayoutService.php` (`markPendingFunding` resets `void_at`) + `app/Jobs/Stripe/VoidExpiredPayoutsJob.php:74-102` (`fireGraceWarnings` queries `void_at` window)
    - **Affects:** Affiliate users at risk of losing commissions without the promised T-30/T-7/T-1 warning emails. A payout that is actively retrying will show "60 days remaining" forever.
    - **What to do:**
        - Option A (simplest): add a separate `grace_started_at` column stamped once on the *first* call to `markPendingFunding`; base all grace warning logic on `grace_started_at` rather than `void_at`. `void_at` continues to reset for the retry-safety reason; the warning clock doesn't.
        - Option B: keep `void_at` as the warning anchor but stop resetting it in `markPendingFunding`. Add a `protected_until` timestamp that `VoidExpiredPayoutsJob` respects before voiding, so retries get their protection without moving the warning clock.
        - Option C (minimal): fire warnings based on `funding_failure_count` thresholds (e.g. after 3 failures, after 6) rather than calendar windows. Simpler but less precise for the affiliate UX.
    - **Technical:** `markPendingFunding` deliberately resets `void_at = now() + 60d` to prevent `VoidExpiredPayoutsJob` from silently cancelling a payout that's mid-retry. This is correct. However, `fireGraceWarnings` uses `WHERE void_at BETWEEN now()+$daysOut AND now()+$daysOut+1d` to find payouts approaching expiry. Since `void_at` is always ~60 days in the future while retries run, the T-30 window (`void_at BETWEEN now()+30d AND now()+31d`) never matches — `void_at` is 60 days out, not 30. The same applies to T-7 and T-1. An affiliate whose payout has been failing for 50 days has never received a single warning.
    - **Plain English:** When a payout can't be charged, the system resets the countdown timer back to 60 days so the payout isn't deleted mid-retry. That's sensible. But the warning system fires at "30 days left", "7 days left", and "1 day left" — and since the timer is always reset to 60, it never reaches those thresholds while retries are in progress. The affiliate could be in danger for weeks without a single warning email.
    - **Evidence:**
        ```php
        // markPendingFunding — resets void_at on every retry
        'void_at' => now()->addDays($this->gracePeriodDays), // always 60d from now

        // fireGraceWarnings — warning window keyed on void_at proximity
        $windowStart = now()->addDays($daysOut)->startOfDay();
        $windowEnd   = now()->addDays($daysOut)->endOfDay();
        $candidates  = CommissionPayout::query()
            ->whereBetween('void_at', [$windowStart, $windowEnd]) // never matches while retrying
            ...
        ```

---

## Suggested Bundled Sessions

### Bundle 1 — Monitoring & logging hygiene (S+S = ~1.5h)
- #STRIPE-2 (misleading cancelled-payout log)
- #STRIPE-3 (fragile QueryException string matching)

Both are one-liner fixes in production code. No schema changes. Safe to batch.

### Bundle 2 — Grace warning architecture (M = ~3h, standalone)
- #STRIPE-4 (grace warnings never fire during retry loop)

Requires a design decision (Option A/B/C above) before implementation. Likely needs a migration for `grace_started_at` if Option A is chosen. Should be reviewed before starting.

### Standalone — do NOT bundle
- #STRIPE-1 (policy refactor on payouts endpoint) — Low risk but touches auth logic; test coverage required before merge.
