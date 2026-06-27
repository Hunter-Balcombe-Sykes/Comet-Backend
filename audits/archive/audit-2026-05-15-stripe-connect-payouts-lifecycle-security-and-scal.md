# Stripe Connect Payouts Lifecycle Security and Scaling Audit — 2026-05-15

**Branch:** development
**Lens:** Stripe connect payouts lifecycle security and scaling
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
- app/Services/Stripe/ExportService.php
- app/Http/Controllers/Api/Professional/Stripe/StripeConnectController.php
- app/Services/Stripe/CommissionVoidService.php
- app/Policies/CommissionPolicy.php
- app/Providers/AppServiceProvider.php

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 1 complete
- P2 Medium: 0 of 2 complete
- P3 Low: 0 of 0 complete

---

## P1 — Fix before pilot launch

- [ ] **#STRP-1** · P1 — `cursor()` silently disables eager loading in export service, producing N+1 queries
    - **Where:** app/Services/Stripe/ExportService.php:77, :128
    - **Affects:** Any brand or affiliate who triggers the detailed-commissions, EOFY, or payouts export on a non-trivial payout history. At the 50-affiliates × 100-orders/year scale target, an EOFY export produces up to 5,000 order rows × 3 relation queries = 15,000 extra database round-trips per export request.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - In `exportDetailedCommissions()`: replace `->cursor()` with `chunkById(500, function ($chunk) use (&$rows) { ... })`, writing chunk rows into a shared buffer the generator yields from. The `->with([...])` clause is honoured by `chunkById`.
        - In `exportPayouts()`: apply the same `chunkById` replacement to the `$query->cursor()` call (line 77). The `scopedPayouts()` builder already carries `->with([...])` — it is silently discarded by `cursor()`.
        - Keep the streaming `streamDownload` / openspout outer layer unchanged; only the inner iteration changes.
    - **Technical:** Laravel's `cursor()` is a PDO-level row streamer that yields one model at a time from an open cursor. The eager-load mechanism (`->with([...]`) requires the builder to first collect all primary IDs via a full `get()`, then issue secondary `whereIn` queries in bulk. Those two behaviours are incompatible: `cursor()` never collects IDs, so the `with` clauses are silently ignored and every relation access (`.payout`, `.brandProfessional`, `.affiliateProfessional`) fires a fresh SELECT per row. `chunkById(500, ...)` chunks the result set in groups of 500, runs the secondary `whereIn` loads inside each chunk, then releases that chunk's memory — preserving both the relation loading and the bounded memory guarantee that motivated `cursor()` in the first place.
    - **Plain English:** The export code is supposed to fetch all the related info (payout details, brand name, affiliate name) for every order row in one efficient batch, not one at a time. But the "streaming" trick it uses to avoid loading everything into memory at once has a hidden catch: it breaks the batch-loading feature entirely. So instead of 3 database lookups total for 5,000 orders, it makes 3 lookups per order row — 15,000 database calls for a single tax export. On a real account, that export will either crawl for several minutes or hit a timeout before it finishes, leaving the user with a blank download.
    - **Evidence:**
        ```php
        // exportDetailedCommissions() — line 120–128
        $orders = Order::query()
            ->with([
                'payout:id,status,gross_commission_cents,platform_fee_cents,net_payout_cents,ledger_entry_count,payment_intent_id,charge_id,created_at',
                'brandProfessional:id,handle,display_name',
                'affiliateProfessional:id,handle,display_name',
            ])
            ->whereIn('payout_id', $payoutIds)
            ->orderBy('occurred_at')
            ->cursor(); // ← with() is silently ignored; every relation access fires a separate SELECT

        // exportPayouts() — line 76–87 (scopedPayouts() adds ->with([...]) which is also discarded)
        $generator = function () use ($query) {
            foreach ($query->cursor() as $p) {
                yield [
                    ...
                    $p->brandProfessional?->display_name ?? '',
                    $p->affiliateProfessional?->display_name ?? '',
        ```

---

## P2 — Should fix

- [ ] **#STRP-2** · P2 — Grace-period day-20/day-28 warnings permanently short-circuited with `return 0`
    - **Where:** app/Services/Stripe/CommissionVoidService.php:362–368
    - **Affects:** All new affiliates who haven't connected Stripe. They receive only the 5-day-before-void per-commission warning from `sendPerCommissionWarnings()`; the two earlier nudges at day 20 and day 28 are never sent. Affiliates first learn they have 5 days left to save their commissions — the earlier chance to act is gone.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Identify the v2 replacement for the dropped `stripe_grace_period_ends_at` column (the TODO references Phase 4; check whether `stripe_connect_status` transitions + `created_at` can serve as the proxy, or whether a new column is needed).
        - Remove the `return 0` short-circuit and rewire the `$warningWindows` scaffolding below it against the replacement column.
        - Add a Pest test asserting that a notification is dispatched for an affiliate at the day-20 and day-28 windows.
    - **Technical:** `sendSignupWarnings()` is called unconditionally from `sendGracePeriodWarnings()` but exits immediately via `return 0`, making the body unreachable. The original implementation (still present below the return) keyed off `stripe_grace_period_ends_at` to identify affiliates in their initial grace window, emitting a "10 days left" warning at day 20 and a "2 days left" warning at day 28. That column was dropped in the v2 schema migration. The scaffolding — the `$warningWindows` array, the query, the notification dispatch — is otherwise complete and only needs a column binding to reactivate. The per-payout (`sendPerPayoutWarnings`) and per-commission (`sendPerCommissionWarnings`) paths are unaffected and remain operational.
    - **Plain English:** The system is meant to send two early warning emails to affiliates who haven't connected their Stripe account — one at the 10-day-left mark and one at the 2-day-left mark. Both of those warning emails are silently skipped right now because a temporary "off switch" was added when the underlying database column they depended on was removed. The code to send them is still there, just unreachable. The only warning affiliates receive today is a 5-days-to-void notice, which is a much shorter runway to act on.
    - **Evidence:**
        ```php
        private function sendSignupWarnings(): int
        {
            $sent = 0;

            // TODO[stripe-v2]: stripe_grace_period_ends_at column dropped. Grace
            // period warnings (day 20 and day 28) need v2 re-implementation in Phase 4.
            // Short-circuited: always returns 0 until Phase 4 restores the grace period logic.
            return 0;

            $warningWindows = [
                'day20' => [
                    'range' => [now()->addDays(10)->startOfDay(), now()->addDays(10)->endOfDay()],
                    'title' => 'Connect Stripe — 10 days left',
                    'body' => 'Connect your Stripe account within 10 days or your %s in pending earnings will be forfeited.',
        ```

- [ ] **#STRP-3** · P2 — Inline `professional_type` guard in `balance()` and `upcomingPayouts()` bypasses `affiliate.only` middleware doctrine
    - **Where:** app/Http/Controllers/Api/Professional/Stripe/StripeConnectController.php:548–549, :573–574
    - **Affects:** `GET /stripe/balance` and `GET /stripe/upcoming-payouts` — affiliate-only endpoints. No current security bypass, but the pattern deviates from the architecture doctrine and is not covered by the CI capability-method check.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `affiliate.only` to the route definitions for `/stripe/balance` and `/stripe/upcoming-payouts` in `routes/api/professional.php`.
        - Remove both inline `if (($pro->professional_type ?? null) === 'brand')` guards from `balance()` and `upcomingPayouts()`.
    - **Technical:** The Partna doctrine states affiliate-only role restrictions must be enforced via the `affiliate.only` middleware, not inline `professional_type` checks. The inline form has a subtle gap: `($pro->professional_type ?? null) === 'brand'` evaluates to `false` when `professional_type` is null or any unexpected value, inadvertently allowing through a professional whose type column is unset. The `affiliate.only` middleware applies an explicit allowlist check, not an exclusion check. Aligning these routes with the middleware pattern also keeps role enforcement centrally auditable and consistent with the CI guard that already enforces this for `BrandAccessService` capability calls.
    - **Plain English:** Two endpoints that are only meant for affiliate users currently turn away brand users with a manual check coded directly inside the controller function. The house rule says this kind of "who's allowed in" check belongs at the door (a middleware layer applied to the route), not scattered inside individual rooms. The current approach isn't broken today, but it's inconsistent with every other role-restricted endpoint in the codebase — meaning it's easy to miss in a security review, and a future developer copying the pattern could get the logic subtly wrong.
    - **Evidence:**
        ```php
        // balance() — line 548–550
        if (($pro->professional_type ?? null) === 'brand') {
            return response()->json(['error' => 'affiliate_only'], 403);
        }

        // upcomingPayouts() — line 573–575
        if (($pro->professional_type ?? null) === 'brand') {
            return response()->json(['error' => 'affiliate_only'], 403);
        }
        ```

`★ Insight ─────────────────────────────────────`
**Laravel `cursor()` + `->with()` is a silent footgun.** The builder stores eager-load constraints in-memory and flushes them in a post-collection pass — `cursor()` never triggers that pass, so the constraints are compiled into SQL that is never executed. There is no warning, no exception, and no documentation banner on `cursor()` itself. The only safe patterns for streaming with relations are: `chunkById()` (batch + eager load per chunk), or a manual ID pre-fetch + lookup map (pre-load all related models keyed by ID, then reference the map in the loop).

**Inline `null`-coalescing on authorization is a smell.** `($pro->professional_type ?? null) === 'brand'` reads as defensive, but it inverts the authorization polarity — a null or unknown type passes through as affiliate-permitted. The `affiliate.only` middleware uses `=== 'affiliate'` (explicit allowlist), which is far safer because unknown types fail closed.

**`return 0` in the middle of a method body is a valid but risky short-circuit pattern.** It's better than commenting code out (no syntax rot), but it creates unreachable code that static analysers will flag and future developers may accidentally delete the scaffolding below it during a cleanup pass. A feature flag (`config('partna.features.signup_void_warnings')`) or a dedicated `gracePeriodWarningsEnabled()` method returning false makes the skip explicit, self-documenting, and easy to re-enable without touching the logic.
`─────────────────────────────────────────────────`
