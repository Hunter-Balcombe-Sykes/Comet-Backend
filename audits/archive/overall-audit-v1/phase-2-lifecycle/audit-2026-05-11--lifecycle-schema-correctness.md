`★ Insight ─────────────────────────────────────`
`withoutOverlapping(600)` is a scheduler-side guard — it prevents the cron from dispatching a second instance, but **queue retries** (`$tries = 3`) are a separate path that bypass it entirely. This is a common Laravel scheduling misconception: the overlap guard only applies at dispatch time, not at retry time.
`─────────────────────────────────────────────────`

Now I have everything I need. Let me synthesize:

- **LIFE-1 (DeepSeek)**: Evidence verified — no UNIQUE constraint on `commission_payouts`. However, `createPayoutBatch` uses `lockForUpdate` on orders and stamps `payout_id` atomically inside the same transaction, which correctly handles concurrent sweeps and job retries. The UNIQUE constraint is a missing schema-level backstop, not a gap in the current hot path. Re-tier P1 → **P2** (defense-in-depth).
- **LIFE-2 (missed by DeepSeek)**: `fireGraceWarnings()` in `VoidExpiredPayoutsJob` — PHP-side `in_array` dedup check is non-atomic with the subsequent `forceFill->save()`. With `$tries = 3`, a process crash between lines 92–98 causes re-notification on queue retry. `withoutOverlapping(600)` only protects against scheduler overlap, not retries. **P2**.

---

# Lifecycle Correctness Audit — 2026-05-11

**Branch:** development
**Lens:** Lifecycle correctness: race-safety, idempotency, anchor decoupling, reconcile loops, vendor resilience, observability discipline — Group F (Schema) + Group A cross-verify
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
- `supabase/migrations/20260403000000_v2_baseline.sql`
- `supabase/migrations/20260428000000_payout_grace_and_app_fee.sql`
- `supabase/migrations/20260510000000_add_commission_payouts_lifecycle_columns.sql`
- `supabase/migrations/20260511000000_add_commission_payouts_grace_started_at.sql`
- `app/Jobs/Stripe/VoidExpiredPayoutsJob.php`
- `app/Models/Retail/CommissionPayout.php` (cross-verify)
- `routes/console.php` (cross-verify)

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 2 complete

---

## P2 — Should fix

- [ ] **LIFE-1** · P2 — `commission_payouts` has no schema-level UNIQUE constraint; DB-layer backstop is absent
    - **Where:** `supabase/migrations/20260403000000_v2_baseline.sql` (CREATE TABLE commerce.commission_payouts + index block)
    - **Affects:** Financial integrity for all brands and affiliates. Any code path that bypasses `createPayoutBatch`'s `lockForUpdate` guard — a future admin endpoint, a staff one-off, or a queue job forked with different parameters — can silently create two `commission_payouts` rows for the same brand-affiliate-period, each independently triggering a Stripe Transfer and double-paying the affiliate. At ~10K daily payout-related job invocations across 200 brands × 50 affiliates, even a single plausible bypass path matters.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a partial unique index: `CREATE UNIQUE INDEX commission_payouts_natural_key_uq ON commerce.commission_payouts (brand_professional_id, affiliate_professional_id, eligible_after) WHERE status NOT IN ('cancelled', 'failed', 'reversed');`
        - In `createPayoutBatch`, wrap the `forceCreate` call in a `try/catch (UniqueConstraintViolationException $e)` block — log a warning and return `null` (treat as "already created by a concurrent path"). This is the `UniqueConstraintViolationException` typed-catch pattern from `#STRIPE-3`.
        - Note: `brand_professional_id` and `affiliate_professional_id` are nullable after `20260419000002_nullable_commission_fks.sql`; PostgreSQL NULL-valued rows do not participate in unique index conflicts, so soft-deleted professional rows are safely excluded.
    - **Technical:** The application-level guard in `createPayoutBatch` (`lockForUpdate()` on the orders table within a transaction, followed by an immediate `payout_id` stamp on those orders) correctly blocks concurrent sweeps and job retries for the primary creation path. Once the first transaction commits with stamped `payout_id`, any concurrent or retried call sees an empty order set and exits early. The schema gap is that this guarantee is entirely application-side: a future staff tool, a bulk-repair script, or a second creation path introduced without the same discipline carries no DB-level check. The `#STRIPE-3` canonical pattern requires both layers: application-level idempotency key derivation AND a UNIQUE constraint so the database refuses the duplicate even if application code is wrong. The current four indexes on `commission_payouts` (`idx_cp_brand`, `idx_cp_affiliate`, `idx_cp_status_eligible`, `idx_cp_pending_eligible`) are all plain B-tree indexes with no uniqueness enforcement.
    - **Plain English:** Imagine a safety deposit box that only one person can open at a time — but that rule only applies to the front desk staff. If someone breaks in through a side door, there's no lock on the box itself stopping them from depositing the same cheque twice. Right now the database table that records "Alice owes Bob $200 this month" has a rule that says "only one person can look at this at a time," but no rule that says "you can only create one record per Alice-Bob-month." The schema fix is adding that second rule directly to the database so it enforces itself regardless of who's writing.
    - **Evidence:**
        ```sql
        CREATE TABLE IF NOT EXISTS commerce.commission_payouts (
            id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
            brand_professional_id uuid NOT NULL REFERENCES core.professionals(id) ON DELETE RESTRICT,
            affiliate_professional_id uuid NOT NULL REFERENCES core.professionals(id) ON DELETE RESTRICT,
            ...
            eligible_after timestamptz NOT NULL,
            ...
        );

        -- All four indexes are plain (non-unique):
        CREATE INDEX idx_cp_brand ON commerce.commission_payouts (brand_professional_id);
        CREATE INDEX idx_cp_affiliate ON commerce.commission_payouts (affiliate_professional_id);
        CREATE INDEX idx_cp_status_eligible ON commerce.commission_payouts (status, eligible_after) WHERE status = 'pending';
        CREATE INDEX idx_cp_pending_eligible ON commerce.commission_payouts (eligible_after) WHERE status = 'pending' AND processed_at IS NULL;
        ```

- [ ] **LIFE-2** · P2 — `fireGraceWarnings()` dedup check is non-atomic with the notification send; job retries can double-fire grace warnings
    - **Where:** `app/Jobs/Stripe/VoidExpiredPayoutsJob.php:89,96-98`
    - **Affects:** Affiliates at the T-30, T-7, and T-1 warning windows who already received a grace warning but whose payout row's `grace_notifications_sent` save was not committed before a job failure. On the next retry (up to 3 total), `in_array` sees the tag absent and fires the notification again. At ~40K daily notifications fan-out peak and 200 brands × 50 affiliates × 3 warning tags = 30K annual grace warning sends, the probability of at least one double-send per quarter is non-negligible. Duplicate grace warning emails erode affiliate trust in the platform during a financially sensitive period.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Wrap the per-payout block in a `DB::transaction` with `lockForUpdate` on the payout row. Re-read `grace_notifications_sent` inside the lock before deciding to send. Only dispatch the notification **after** the tag has been committed (or accept that email dispatch is inherently non-transactional and settle for a tighter window).
        - Alternatively — since notifications are inherently outside DB transactions — use a separate `notification_dedup_log` row (or make the tag write atomic via a Postgres-level `jsonb ||` UPDATE ... WHERE NOT (`grace_notifications_sent` @> `'["T-X"]'`) RETURNING id) and only send if the UPDATE returned a row. This eliminates the notify-then-save ordering risk entirely.
        - Note: `withoutOverlapping(600)` (line 71 of `routes/console.php`) prevents the scheduler from dispatching a second instance, but does **not** prevent queue retries triggered by `$tries = 3` — those are dispatched by the queue worker, not the scheduler.
    - **Technical:** The `fireGraceWarnings()` method loads all candidate payouts with `->get()` (no row lock), then filters in PHP (`in_array($tag, $p->grace_notifications_sent ?? [], true)` on line 89), sends the notification (line 92), reads the array again (line 96), appends the tag, and saves (line 98). This is a read-modify-write without `lockForUpdate`. The canonical `JSONB dedup` pattern (`af90b2e`) establishes that dedup records must be written atomically. The non-atomic failure mode: the job succeeds on `notify()` but the process dies before `forceFill->save()` commits. On retry, `grace_notifications_sent` still lacks the tag, so the candidate passes the PHP filter and the notification fires a second time. The `withoutOverlapping(600)` guard (`routes/console.php:71`) prevents scheduler-level double dispatch but is irrelevant to queue-managed retries. `$tries = 3` and `backoff: [60, 180]` mean retries are expected and documented.
    - **Plain English:** Imagine a to-do list that says "only call Alice once about her overdue payment." The current system checks the list, makes the call, then updates the list to say "called." If the phone call goes through but the pen runs out before you can cross it off the list, the next person who checks thinks Alice hasn't been called yet and calls her again. The fix is to cross it off the list before making the call — or to use a method where the list update and the call are confirmed together.
    - **Evidence:**
        ```php
        // app/Jobs/Stripe/VoidExpiredPayoutsJob.php
        $candidates = CommissionPayout::query()
            ->whereIn('status', ['pending', 'pending_funds'])
            ->whereNotNull('grace_started_at')
            ->whereBetween('grace_started_at', [$windowStart, $windowEnd])
            ->whereDoesntHave('affiliateProfessional', fn ($q) => $q->where('stripe_connect_status', 'active'))
            ->get()
            ->filter(fn ($p) => ! in_array($tag, $p->grace_notifications_sent ?? [], true)); // ← PHP-side check, no lock

        foreach ($candidates as $payout) {
            $payout->affiliateProfessional?->notify(
                new AffiliatePayoutGraceWarningNotification($payout, $daysOut)
            ); // ← notification fires here

            $sent = $payout->grace_notifications_sent ?? [];
            $sent[] = $tag;
            $payout->forceFill(['grace_notifications_sent' => array_values(array_unique($sent))])->save(); // ← save may not reach here on retry
        }
        ```
        ```php
        // app/Models/Retail/CommissionPayout.php (cast confirms array semantics)
        'grace_notifications_sent' => 'array',
        ```
        ```php
        // routes/console.php — withoutOverlapping guards scheduler only, not queue retries
        Schedule::job(new \App\Jobs\Stripe\VoidExpiredPayoutsJob)
            ->dailyAt('07:00')
            ->withoutOverlapping(600); // 10-min lock; does not protect against $tries = 3 queue retries
        ```
