`★ Insight ─────────────────────────────────────`
Key verification takeaways from this adjudication:
1. CAPE-2 was a false positive — the routes file shows both `balance` and `upcomingPayouts` already carry `affiliate.only` middleware, exactly per the Partna Authorization Doctrine. Always check routes before flagging missing auth on controllers.
2. The `CommissionPolicy` still reads the legacy `professional_type` column directly (`=== 'brand'`) rather than using `isBrand()` — commit `ebc7ad43` migrated middleware but didn't sweep policies. Cross-file invariants like "migrate all professional_type reads" need a grep sweep.
3. CAPE-3 (sync export Stripe gate) was dropped because the `ExportService` docblock explicitly calls out the cross-tenant safety strategy, and access to one's own historical data after disconnect is a design intent question, not a correctness bug.
`─────────────────────────────────────────────────`

# Capability Gating — Commission Export & Stripe Surfaces Audit — 2026-05-19

**Branch:** feat/capability-gating-stripe-surface
**Lens:** capability gating completeness in commission export and Stripe surfaces
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
- app/Http/Controllers/Api/Professional/Stripe/CommissionExportController.php
- app/Http/Controllers/Api/Professional/Stripe/StripeConnectController.php
- app/Services/Stripe/CommissionExportService.php
- app/Services/Stripe/ExportService.php
- app/Services/Stripe/StripeBalanceService.php
- app/Services/Stripe/StripeRowGenerator.php
- app/Policies/CommissionPolicy.php
- routes/api/professional.php

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 1 complete
- P2 Medium: 0 of 0 complete
- P3 Low: 0 of 2 complete

---

## P1 — Fix before pilot launch

- [ ] **#CAPE-1** · P1 — Async commission export dispatches with no Stripe capability gate
    - **Where:** app/Http/Controllers/Api/Professional/Stripe/CommissionExportController.php:48–53; app/Services/Stripe/CommissionExportService.php:49–70
    - **Affects:** Any authenticated professional whose `stripe_connect_status` is not `active` — they can submit `POST /stripe/exports/transactions`, trigger the full chunk+finalizer Horizon pipeline, and receive an empty export email. At pilot scale this burns queue resources and produces confusing no-data emails.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - In `CommissionExportController::store()`, after resolving `$pro` and `$role`, check `AccountCapabilities::for($pro)->hasStripeConnect()` (or check `$pro->stripe_connect_status === 'active'` directly). Return `422` with a clear message like `"Complete Stripe Connect onboarding before exporting transactions."` if the check fails.
        - Use `AccountCapabilities` (commit `f7d2b75b`) rather than an inline column check so the gate stays consistent with the pattern established across the rest of the codebase.
        - The check belongs in the controller, not in `CommissionExportService::dispatch`, so the service stays a pure dispatcher.
    - **Technical:** `CommissionPolicy::viewOwnPayouts` (the gate applied at line 53) only verifies role and ownership — it does not inspect `stripe_connect_status` or payment-method presence. `CommissionExportService::dispatch` proceeds directly to counting payouts and dispatching jobs regardless of Stripe state. For a professional with no Stripe history, `$payoutsTotal === 0` at line 50, which causes the transaction to insert a `STATUS_QUEUED` audit row and then immediately dispatch `ExportFinalizerJob` (line 68), producing an empty-file email. The `AccountCapabilities` registry landed in commit `f7d2b75b` specifically to centralize gates like this but is not consulted on this path.
    - **Plain English:** Imagine clicking "download my bank statement" before you've ever linked a bank account. The system accepts the request, spins up a bunch of background workers, then emails you a blank spreadsheet. The fix is to check whether you have a linked account before accepting the request — not after all the work is done.
    - **Evidence:**
        ```php
        // CommissionExportController.php:48-53 — only role+ownership check, no Stripe capability gate
        $skeleton = new CommissionPayout;
        $skeleton->forceFill($role === 'brand'
            ? ['brand_professional_id' => $pro->id]
            : ['affiliate_professional_id' => $pro->id]);
        Gate::forUser($pro)->authorize('viewOwnPayouts', $skeleton);
        ```
        ```php
        // CommissionExportService.php:49-54 — dispatches regardless of Stripe state
        $payoutsTotal = $this->countPayouts($professional->id, $role, $filters);
        $chunksTotal = (int) ceil($payoutsTotal / $chunkSize);
        // ...
        if ($audit->payouts_total === 0) {
            ExportFinalizerJob::dispatch($audit->id);
        }
        ```

---

## P3 — Nice to have

- [ ] **#CAPE-2** · P3 — `CommissionExportController::show()` uses an inline ownership query instead of a policy gate
    - **Where:** app/Http/Controllers/Api/Professional/Stripe/CommissionExportController.php:91–99
    - **Affects:** Maintainability / future policy extensions. The check is functionally correct today, but if a `CommissionExportAuditPolicy` is ever added (e.g., to gate status polling on active Stripe capability), this endpoint would silently bypass it because `Gate::authorize` is never called.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Create `app/Policies/CommissionExportAuditPolicy.php` extending `BasePolicy` with a `view` method that checks `$audit->professional_id === $pro->id` (returning `denyAsNotFound()` on mismatch).
        - Register it in `AppServiceProvider::boot()`: `Gate::policy(CommissionExportAudit::class, CommissionExportAuditPolicy::class)`.
        - In `show()`, replace the inline `where('professional_id')` guard with `findOrFail($exportId)` followed by `Gate::forUser($pro)->authorize('view', $audit)`. Keep the query scope as defense-in-depth if desired.
    - **Technical:** The current inline pattern (`->where('id')->where('professional_id')->first()` + `abort_if(! $audit, 404)`) correctly returns 404 for cross-tenant access, matching the 403-vs-404 standard in CLAUDE.md. The gap is architectural: every other commission endpoint routes through `Gate::forUser($pro)->authorize(...)`. No `CommissionExportAuditPolicy` exists today (confirmed via `Glob app/Policies/*.php`). The `PolicyCoverageTest` will eventually catch `CommissionExportAudit` if it's a tenant-owned model registered in `AppServiceProvider`.
    - **Plain English:** The door lock works — it correctly checks whether the key matches the room. But every other door in the building uses the master key system, which means security staff can update access rules from one place. This door was installed with a standalone lock that won't pick up those updates automatically.
    - **Evidence:**
        ```php
        // CommissionExportController.php:91-99 — inline ownership check, no policy gate
        $audit = CommissionExportAudit::query()
            ->where('id', $exportId)
            ->where('professional_id', $pro->id)
            ->first();

        // 404 not 403 — cross-tenant per CLAUDE.md: never reveal whether a resource exists
        abort_if(! $audit, 404);

        return new CommissionExportAuditResource($audit);
        ```

- [ ] **#CAPE-3** · P3 — `CommissionPolicy` reads legacy `professional_type` column instead of `isBrand()` after middleware migration
    - **Where:** app/Policies/CommissionPolicy.php:87, 149, 159
    - **Affects:** Forward-compatibility. Commit `ebc7ad43` migrated `professional_type` reads to `isBrand()` / `account_type` in middleware but didn't sweep policies. During the dual-write transition period both columns stay consistent, but any future deprecation of the `professional_type` write path would silently break these checks.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `($pro->professional_type ?? null) === 'brand'` with `$pro->isBrand()` at lines 87, 149, and 159 of `CommissionPolicy`.
        - Confirm `Professional::isBrand()` exists (it's referenced in the commit message and in `CommissionExportController::inferRole()`).
    - **Technical:** Per CLAUDE.md: "Don't read `professional_type` in new code." The migration in commit `ebc7ad43` updated middleware but `CommissionPolicy` was left behind with three direct `professional_type` reads. The `viewOwnPayouts` method (line 87) is called on every payout list and export request, making it the highest-traffic of the three. `managePaymentMethod` and `manageWallet` (lines 149, 159) are called less often but follow the same pattern.
    - **Plain English:** The system got an update that standardized how it checks "is this user a brand?" The update was applied to one part of the security layer but missed this policy file. It works fine today because both the old and new ways agree, but leaving the old approach in place means if the old column is ever phased out, this file would quietly stop working.
    - **Evidence:**
        ```php
        // CommissionPolicy.php:87 — legacy professional_type read instead of isBrand()
        $isBrand = ($pro->professional_type ?? null) === 'brand';

        // CommissionPolicy.php:149, 159 — same pattern in managePaymentMethod / manageWallet
        && ($actor->professional_type ?? null) === 'brand';
        ```
