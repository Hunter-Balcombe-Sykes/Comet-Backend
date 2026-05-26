All five findings are confirmed against source. The TRNX-1 comment at line 90 even self-documents the problem ("callers do not see the after-commit dispatch in this branch") but doesn't prevent it — the comment describes the *intent*, the code doesn't enforce it. TRNX-2's crash window (after `markChunkCompleted` commits, before `ExportChunkJob::dispatch` enqueues) is real. TRNX-3/4/5 all verified.

`★ Insight ─────────────────────────────────────`
- For TRNX-1, the fix pattern is a `$transitioned` boolean returned from the closure via `use (&$transitioned)` — closures in PHP don't return values usefully when called by `DB::transaction`, so a reference variable captured by the closure is the idiomatic approach.
- TRNX-2's deepest fix is to make `markChunkCompleted` store the cursor *keyed by chunk index* rather than as a single advancing pointer — this makes any chunk retry deterministic regardless of which step it crashed on.
- TRNX-3 is the inverse of TRNX-4: the Resume action wraps Stripe inside the transaction (too tight), while Cancel has no transaction at all (too loose). Same root cause, same tier.
`─────────────────────────────────────────────────`

# Transaction Boundaries & Atomicity Audit — 2026-05-20

**Branch:** development
**Lens:** transaction boundaries atomicity rollback correctness
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
- app/Services/Accounts/AccountTypeTransitionService.php
- app/Jobs/Exports/ExportChunkJob.php
- app/Jobs/Exports/ExportFinalizerJob.php
- app/Services/Billing/ResumeProfessionalSubscriptionAction.php
- app/Services/Billing/CancelProfessionalSubscriptionAction.php
- app/Services/Billing/ChangeProfessionalPlanAction.php
- app/Services/Billing/CreateProfessionalSubscriptionAction.php
- app/Services/Billing/Entitlements.php
- app/Services/Accounts/AccountCapabilities.php
- app/Services/Accounts/AccountCapabilitySet.php

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 2 complete
- P2 Medium: 0 of 3 complete

---

## P1 — Fix before pilot launch

- [ ] **#TRNX-1** · P1 — Post-commit jobs dispatched unconditionally when inner-lock race detects no-op
    - **Where:** app/Services/Accounts/AccountTypeTransitionService.php:87-119
    - **Affects:** Any concurrent callers of `AccountTypeTransitionService::transition()` racing on the same Professional — spurious KV syncs, Cloudflare cache purges, and `AccountTypeTransitionEvent` firings for a transition that never happened, potentially with stale `$from`/`$to` values.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Capture a `$transitioned = false` reference variable and pass it into the closure with `use (&$transitioned)`. Set it to `true` only on the branch where `$locked->save()` is called.
        - Guard all three post-commit dispatch statements (`SyncSubdomainToKvJob`, `CloudflareCachePurgeJob`, `AccountTypeTransitionEvent`) behind `if ($transitioned)`.
        - Note: the outer pre-lock guard (`if ($from === $to) return;`) already handles the non-racing same-state case cleanly; this fix targets only the race branch inside the transaction closure.
    - **Technical:** `DB::transaction(callable $callback)` executes the callback and discards its return value. When the inner lock re-check at line 87 (`$currentType === $to`) fires, the closure returns early — but PHP's call stack unwinds back to `transition()` and execution continues past the transaction call. The source comment at line 90 even acknowledges this: *"The outer `$from` variable retains the pre-lock value; callers do not see the after-commit dispatch in this branch"* — but no code enforces it. A reference variable captured via `use (&$transitioned)` is the correct idiom; the transaction's return value cannot be used because `DB::transaction` doesn't thread it through. The consequence is `AccountTypeTransitionEvent` fires with the caller's pre-lock `$from`/`$to`, which are stale if the race occurred, and `SyncSubdomainToKvJob` + `CloudflareCachePurgeJob` run for a no-op mutation.
    - **Plain English:** Two requests arrive at the same moment, both trying to flip the same account type. The second one checks inside the filing cabinet, sees the first already did it, and correctly stops — but then keeps going and sends three follow-up notifications and clears the cached website anyway, even though nothing changed. The fix is to raise a flag ("we actually changed something") inside the filing cabinet check and only send the follow-ups if the flag is raised.
    - **Evidence:**
        ```php
        DB::transaction(function () use ($pro, $to): void {
            $locked = Professional::query()
                ->whereKey($pro->id)
                ->lockForUpdate()
                ->firstOrFail();

            $currentType = $locked->account_type;
            if ($currentType === $to) {
                // Already at the desired state — bail out of the transaction.
                // The outer $from variable retains the pre-lock value; callers
                // do not see the after-commit dispatch in this branch.
                return;
            }

            $locked->account_type = $to;
            $locked->save();

            $pro->setRawAttributes($locked->getAttributes(), true);
            $pro->syncOriginal();
        });

        // ----------------------------------------------------------------
        // Post-commit dispatches — NEVER move these inside DB::transaction.
        // ----------------------------------------------------------------

        SyncSubdomainToKvJob::dispatch((string) $pro->id);

        $handle = strtolower(trim((string) ($pro->handle ?? '')));
        if ($handle !== '') {
            CloudflareCachePurgeJob::dispatch($handle);
        }

        AccountTypeTransitionEvent::dispatch($pro, $from, $to);
        ```

- [ ] **#TRNX-2** · P1 — ExportChunkJob cursor advances before next-chunk dispatch, causing data loss on retry
    - **Where:** app/Jobs/Exports/ExportChunkJob.php:90-107
    - **Affects:** Commission export users; when a job crashes or is killed after `markChunkCompleted` commits but before `ExportChunkJob::dispatch(...)` enqueues, Laravel retries the same chunk index — which now fetches the *next* batch of payouts via the already-advanced cursor and overwrites the current part file. The original batch is silently missing from the final export.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Make `fetchChunkPayouts` deterministic per chunk index by recording `last_processed_payout_id` keyed to the chunk index (e.g., `chunk_cursors JSONB` column on `CommissionExportAudit`), not as a single advancing pointer. Chunk 0 always starts from the top; chunk N reads `chunk_cursors[N-1]`.
        - Alternatively, advance the cursor only when dispatching the *next* chunk, not in `markChunkCompleted` for the *current* chunk — but this requires restructuring the completion bookkeeping so it doesn't conflate "this chunk is written" with "advance the global cursor."
        - As belt-and-suspenders: in `fetchChunkPayouts` for chunk index > 0, assert that the returned payout IDs don't overlap with any IDs listed in the previous chunk's cursor entry; log a `critical` alert if they do.
        - Add a test: mock a crash between `markChunkCompleted` and `ExportChunkJob::dispatch`, re-run the same chunk index, and assert the part file contains the original batch.
    - **Technical:** The job docstring claims "Cursor is advanced only after a successful part upload, so a crash before upload replays the same payouts on retry" — this is true for the pre-`writePart` crash case. But the crash window between `markChunkCompleted` (line 90, commits cursor advance to DB) and `ExportChunkJob::dispatch` (line 107, enqueues next chunk) is not handled. On retry with the same `$chunkIndex`, `fetchChunkPayouts` reads the now-advanced `last_processed_payout_id` and applies the cursor filter, fetching the payouts that belong to chunk N+1. These are then written as part file `chunk-N.jsonl`, overwriting the previously-written correct data (or writing into an empty file if the first attempt hadn't uploaded yet). The finalizer concatenates all parts in natural sort order — `chunk-N` now contains N+1's data, and N+1's part file (if it runs at all) duplicates or skips depending on whether the next dispatch re-ran.
    - **Plain English:** You're copying a book by hand, chapter by chapter. You finish chapter 3 and write a bookmark saying "I finished through chapter 3." Before you file chapter 3 away, your hand cramps and you have to stop. When you restart, you look at the bookmark, see you're "done through chapter 3," and start copying from chapter 4 — but you label it as chapter 3. The final book has chapter 4's content under the chapter 3 heading, and chapter 3 is gone. The fix is to not advance the bookmark until after the chapter is safely filed away, or to use per-chapter bookmarks so a restart always goes back to the right starting page.
    - **Evidence:**
        ```php
        $partWriter->writePart(
            disk: config('partna.media_disk'),
            remotePath: $remotePath,
            rows: $generator->forPayouts($payouts, $audit->role),
        );

        $audit->markChunkCompleted(
            payoutsInChunk: $payouts->count(),
            lastPayoutId: $payouts->last()->id,   // advances cursor in DB
            nextIndex: $this->chunkIndex + 1,
        );

        // ... logging ...

        $fresh = $audit->fresh();
        if ($fresh->chunks_completed >= $fresh->chunks_total) {
            ExportFinalizerJob::dispatch($audit->id);
        } else {
            ExportChunkJob::dispatch($audit->id, $this->chunkIndex + 1); // ← crash here = retry fetches wrong batch
        }
        ```

---

## P2 — Should fix

- [ ] **#TRNX-3** · P2 — Stripe API call inside `DB::transaction` holds row lock for network round-trip duration
    - **Where:** app/Services/Billing/ResumeProfessionalSubscriptionAction.php:47-53
    - **Affects:** Professionals resuming subscriptions under any Stripe latency spike; the PostgreSQL row lock and connection are held for the full Stripe HTTP round-trip, risking connection pool exhaustion under concurrent resume requests.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Move `$this->billing->resumeSubscription(...)` outside the `DB::transaction` closure. Update the local row inside the transaction alone, then call Stripe after commit.
        - Use `DB::afterCommit(fn() => $this->billing->resumeSubscription(...))` to keep the sequencing explicit and readable.
        - If the Stripe call fails after local commit, the `customer.subscription.updated` webhook already reconciles state — add a `Log::warning` on Stripe failure so ops can detect any drift.
    - **Technical:** `AccountTypeTransitionService` carries an explicit class-level comment: *"Do NOT use `::dispatchSync()` inside the `DB::transaction()` closure — Cloudflare HTTP I/O under a row lock starves the connection pool."* The same principle applies equally to Stripe HTTP calls. `ResumeProfessionalSubscriptionAction` wraps both `$subscription->update()` and `$this->billing->resumeSubscription()` in a single `DB::transaction`, meaning any Stripe latency spike (p99 can be 5–10s) holds a `FOR UPDATE`-locked row and a pgbouncer connection for that duration. Laravel Cloud's connection pool is finite; under concurrent resume attempts this cascades to connection timeouts. The webhook reconciliation path already exists for Stripe → local sync, making the Stripe call safe to execute outside the transaction.
    - **Plain English:** The codebase has a documented rule: don't make a phone call while holding the filing cabinet open. This service breaks it — it grabs a lock on the subscription record, calls Stripe, and only closes the filing cabinet after Stripe responds. If Stripe is having a slow moment, every other request that needs that filing cabinet is stuck waiting. The fix moves the Stripe call to after the cabinet is closed.
    - **Evidence:**
        ```php
        DB::transaction(function () use ($subscription) {
            $subscription->update(['cancel_at_period_end' => false]);

            if ($subscription->isStripeManaged() && $subscription->stripe_subscription_id) {
                $this->billing->resumeSubscription($subscription->stripe_subscription_id);
            }
        });
        ```

- [ ] **#TRNX-4** · P2 — No transaction boundary between Stripe cancel and local `cancel_at_period_end` update
    - **Where:** app/Services/Billing/CancelProfessionalSubscriptionAction.php:36-39
    - **Affects:** Professionals canceling subscriptions; if the local `update` throws after Stripe has already scheduled the cancellation, the local row retains `cancel_at_period_end = false` until the `customer.subscription.updated` webhook reconciles it, creating a stale-data window.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Reverse the order: perform the local `$subscription->update(['cancel_at_period_end' => true])` first inside a `DB::transaction`, then call `$this->billing->cancelSubscriptionAtPeriodEnd(...)` outside. If Stripe fails, the local update rolls back and no divergence occurs; the user can retry.
        - Add a `Log::warning` on Stripe failure (after local commit) for the inverse case where local commits but Stripe call fails — the webhook will reconcile, but ops should be alerted.
    - **Technical:** Currently the Stripe call mutates remote state and returns successfully, then `$subscription->update()` mutates local state. There is no transaction spanning them. A DB exception after the Stripe call (model observer throwing, constraint violation, transient DB connection error) leaves Stripe with `cancel_at_period_end = true` and the local row with `cancel_at_period_end = false`. The API caller receives a 500 and retries — re-calling `cancelSubscriptionAtPeriodEnd` is idempotent on Stripe's side, but the local row still lags until the webhook fires. The safer order is local-first: if the local write fails, Stripe was never called; if Stripe fails after local commit, the webhook reconciles.
    - **Plain English:** You call the bank to cancel a recurring payment, the bank confirms it, then your accounting software crashes before it can record the change. For the next few minutes your system thinks the payment is still active. The fix is to record it locally first, then call the bank — if your software crashes before calling the bank, nothing happened on either side, so you just try again.
    - **Evidence:**
        ```php
        // Cancel on Stripe side at period end
        $this->billing->cancelSubscriptionAtPeriodEnd($subscription->stripe_subscription_id);

        $subscription->update([
            'cancel_at_period_end' => true,
        ]);
        ```

- [ ] **#TRNX-5** · P2 — Paid→paid plan switch relies solely on webhook for local `plan_id` reconciliation
    - **Where:** app/Services/Billing/ChangeProfessionalPlanAction.php:60-66
    - **Affects:** Professionals switching between paid tiers; the local `Subscription` row retains the old `plan_id` until the async `customer.subscription.updated` webhook fires. Entitlement checks (`Entitlements::hasPlan`, `::hasEntitlement`) in the interim window return the old tier's capabilities.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - After `$this->billing->updateSubscriptionPlan(...)` returns successfully, immediately call `$subscription->update(['plan_id' => $newPlan->id, 'cancel_at_period_end' => false])` so the synchronous API response reflects the new plan.
        - Keep the webhook reconciliation path as-is — it becomes defense-in-depth rather than the sole correctness path.
        - Clear the `Entitlements` per-request cache after the update so subsequent checks in the same request see the new tier.
    - **Technical:** The inline comment documents the design choice: *"customer.subscription.updated webhook reconciles plan_id and cancel_at_period_end locally."* This is correct as an eventual-consistency strategy but means `$subscription->fresh()` at line 66 returns the old `plan_id` — the API response the client receives immediately after the upgrade shows the old plan. Any entitlement gate (`Entitlements::hasEntitlement`, `hasPlan`) called in the same request or in the milliseconds before the webhook arrives gates on the old tier. For an upgrade (basic → premium), this means a feature the user just paid to unlock is temporarily inaccessible. The `Entitlements` service has a `clearCache()` method for exactly this purpose; it should be called after the inline update.
    - **Plain English:** You upgrade your phone plan from basic to premium. The phone company confirms the upgrade, but your account page still says "basic" for the next 30 seconds while a background sync catches up. If you immediately try to use a premium feature, it's blocked — even though you just paid for it. The fix is to update your own account page at the same time you confirm the upgrade, not wait for the slow background process.
    - **Evidence:**
        ```php
        // Paid -> Paid: update price on Stripe; customer.subscription.updated webhook
        // reconciles plan_id and cancel_at_period_end locally (same as paid->free path).
        $this->billing->updateSubscriptionPlan(
            $subscription->stripe_subscription_id,
            $newPlan,
        );

        return $subscription->fresh();
        ```
