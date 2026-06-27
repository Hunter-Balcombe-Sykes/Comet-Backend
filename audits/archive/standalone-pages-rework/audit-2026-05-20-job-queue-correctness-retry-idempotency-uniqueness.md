# Job Queue Correctness, Retry, Idempotency & Uniqueness Audit — 2026-05-20

**Branch:** development
**Lens:** job queue correctness retry idempotency uniqueness
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
- app/Jobs/Exports/ExportChunkJob.php
- app/Jobs/Exports/ExportFinalizerJob.php
- app/Jobs/Notifications/FanOutBrandStatusNotificationJob.php
- app/Jobs/Notifications/SendStaffBroadcastEmailsJob.php
- app/Jobs/ProcessImageVariantsJob.php
- app/Jobs/ProcessVideoVariantsJob.php
- app/Jobs/Store/SeedAffiliateDefaultSelectionsJob.php
- app/Services/Store/AffiliateProductCatalogService.php (verified via Read)

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 1 complete
- P2 Medium: 0 of 2 complete
- P3 Low: 0 of 1 complete

---

## P1 — Fix before pilot launch

- [ ] **#QUEUE-1** · P1 — Export chunk cursor advances before next-chunk dispatch; retry fetches wrong payouts and overwrites part file
    - **Where:** app/Jobs/Exports/ExportChunkJob.php — `markChunkCompleted` call followed by `ExportChunkJob::dispatch`
    - **Affects:** Commission export data integrity — if dispatch throws after the cursor commits, a retry of the same `chunkIndex` fetches the *next* chunk's payouts and writes them into the current chunk's part file, producing a gap-plus-overwrite in the final export.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Wrap `writePart`, `markChunkCompleted`, and the subsequent dispatch in a single try block where the dispatch is the final step. If dispatch throws, let the exception propagate so Horizon retries the job — the cursor is already committed so `fetchChunkPayouts` must be made re-entrant against the new cursor position. The simplest safe fix: store the `lastPayoutId` in the audit *after* writing but check `chunkIndex` vs `chunks_completed` on entry to detect "this chunk's work is already done" before re-fetching.
        - Alternatively, add an early-exit guard at the top of `handle()`: if `$audit->chunks_completed > $this->chunkIndex`, the chunk cursor has already passed this index — skip the fetch/write and jump straight to dispatching the next job or finalizer.
    - **Technical:** `markChunkCompleted` immediately commits `last_processed_payout_id` (the cursor) and increments `chunks_completed`. `fetchChunkPayouts` uses this cursor via `WHERE (created_at, id) < (cursor.created_at, cursor.id)`. If `ExportChunkJob::dispatch(…, $this->chunkIndex + 1)` throws (Redis connection blip, serialization error), Horizon retries the *current* job with `chunkIndex=0`. The retry calls `fetchChunkPayouts` with the advanced cursor, which now returns chunk-1 payouts. `writePart` overwrites `chunk-0.jsonl` with chunk-1 data. `chunks_completed` gets double-incremented. The final concatenation produces a corrupt export with a missing chunk and a duplicated chunk. The docstring says "Cursor is advanced only after a successful part upload" — this is true for the `writePart` → `markChunkCompleted` ordering but does not protect the `markChunkCompleted` → `dispatch` transition, which is the actual failure seam.
    - **Plain English:** Picture a factory filling boxes on a conveyor belt. After filling box 1, a worker stamps "Box 1: done" in the ledger, then tries to slide box 2 into position. If the conveyor jams on the slide, the worker's next shift starts by re-checking the ledger — which now shows "start at box 2 contents" — so they accidentally fill box 1's slot with box 2's items. Box 1's contents are gone and the shipment is wrong. The fix is to either record "done" only after the next box is safely in position, or to have the worker check on arrival "wait, is my box already filled?" before touching anything.
    - **Evidence:**
        ```php
        $partWriter->writePart(
            disk: config('partna.media_disk'),
            remotePath: $remotePath,
            rows: $generator->forPayouts($payouts, $audit->role),
        );

        $audit->markChunkCompleted(
            payoutsInChunk: $payouts->count(),
            lastPayoutId: $payouts->last()->id,
            nextIndex: $this->chunkIndex + 1,
        );

        // ...

        $fresh = $audit->fresh();
        if ($fresh->chunks_completed >= $fresh->chunks_total) {
            ExportFinalizerJob::dispatch($audit->id);
        } else {
            ExportChunkJob::dispatch($audit->id, $this->chunkIndex + 1);
        }
        ```

---

## P2 — Should fix

- [ ] **#QUEUE-2** · P2 — `FanOutBrandStatusNotificationJob` implements `ShouldBeUnique` without `$uniqueFor`; worker crash leaves permanent lock
    - **Where:** app/Jobs/Notifications/FanOutBrandStatusNotificationJob.php — class declaration
    - **Affects:** Brand status fan-out — if the Horizon worker process is killed (OOM, SIGKILL) mid-job, the `brandProfessionalId:brandStatus` uniqueness lock is never released, silently blocking future fan-outs for that brand-status pair until manual cache purge.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `public int $uniqueFor = 600;` to the class. This gives a 10-minute TTL on the lock — comfortably longer than the `$timeout = 120` but short enough that a killed worker doesn't block the next status transition for more than 10 minutes.
    - **Technical:** Laravel's `ShouldBeUnique` stores the lock in the cache driver (Redis). The lock is released by the `UniqueJobMiddleware` when the job completes normally. If the worker process is killed between job start and completion, the middleware never runs and the cache key persists indefinitely. `$uniqueFor` sets a TTL on the lock key itself, providing an automatic escape hatch. Without it, a rare but real worker-death scenario (OOM kill during the `chunkById` walk over brand_partner_links) leaves subsequent `FanOutBrandStatusNotificationJob` dispatches silently de-duped and dropped for the same brand+status pair.
    - **Plain English:** Imagine a padlock on a room where one team is working. Normally they unlock the door when they leave. But if there's a fire alarm and everyone evacuates suddenly, the room stays locked. Any future team that needs to use that room can't get in — and nobody gets an error message, the request just silently fails. Adding an automatic timer to the padlock ("unlock after 10 minutes regardless") prevents the permanent lockout.
    - **Evidence:**
        ```php
        class FanOutBrandStatusNotificationJob implements ShouldBeUnique, ShouldQueue
        {
            use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

            public int $tries = 3;
            public int $maxExceptions = 2;
            public int $backoff = 30;
            public int $timeout = 120;
            // no $uniqueFor property

            public function uniqueId(): string
            {
                return $this->brandProfessionalId.':'.$this->brandStatus;
            }
        ```

- [ ] **#QUEUE-3** · P2 — `SendStaffBroadcastEmailsJob` implements `ShouldBeUnique` without `$uniqueFor`; inline comment misstates the guarantee
    - **Where:** app/Jobs/Notifications/SendStaffBroadcastEmailsJob.php — class declaration and comment block
    - **Affects:** Staff broadcast fan-out — same permanent lock risk as QUEUE-2. Compounded by a misleading code comment that could cause future maintainers to confidently leave the gap in place.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `public int $uniqueFor = 600;` to the class.
        - Update the comment block: remove the claim "Lock auto-releases when the job finishes; no explicit uniqueFor needed beyond the default." — this is only true for graceful exits, not SIGKILL/OOM.
    - **Technical:** Same root cause as QUEUE-2. The comment "Lock auto-releases when the job finishes; no explicit uniqueFor needed beyond the default" is factually incorrect — `ShouldBeUnique` has no default TTL, only a condition-based release. The `$timeout = 120` only controls how long Horizon lets the job run before marking it failed; it does not release the lock. A large broadcast (thousands of subscribers) combined with a worker OOM kill during the `chunkById` walk would leave `staff-broadcast:{notificationId}` locked indefinitely, dropping any subsequent re-dispatch of the same notification.
    - **Plain English:** Same padlock problem as QUEUE-2 — this time on the staff broadcast room. The misleading comment makes it worse: a developer reading the code in the future would see the comment and trust it, never realising the lock can stick. Fix the comment as well as the code.
    - **Evidence:**
        ```php
        class SendStaffBroadcastEmailsJob implements ShouldBeUnique, ShouldQueue
        {
            use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

            public int $tries = 3;
            public int $maxExceptions = 2;
            public array $backoff = [10, 30, 60];
            public int $timeout = 120;

            // Prevent concurrent fan-out for the same notification. The leaf job's
            // broadcast_email_receipts PK already blocks duplicate sends, but without this
            // a concurrent dispatch doubles the per-subscriber queue work. Lock auto-releases
            // when the job finishes; no explicit uniqueFor needed beyond the default.
            public function uniqueId(): string
            {
                return 'staff-broadcast:'.$this->notificationId;
            }
        ```

---

## P3 — Nice to have

- [ ] **#QUEUE-4** · P3 — Media processing jobs document the stuck-PROCESSING cleanup cron but it doesn't exist yet
    - **Where:** app/Jobs/ProcessImageVariantsJob.php:handle, app/Jobs/ProcessVideoVariantsJob.php:handle
    - **Affects:** Media reliability — if a Horizon worker is OOM-killed while holding the `image:processing-lock` or `video:processing-lock`, the `SiteMedia` row stays in `PROCESSING` state for up to `timeout + 60` seconds (180s for images, 780s for video). Subsequent retry attempts within that window see the lock held, return silently (treated as success), and never fire `failed()`. The row is stuck until the TTL expires and the next retry acquires the lock — but with `$tries=3` and standard Horizon backoff, all three retries may land within the lock TTL and silently exhaust, leaving the row permanently stuck in `PROCESSING` with no terminal state written and no `failed()` invocation.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Implement the "separate cleanup story" documented in both jobs' comments: a scheduled command or cron job that scans for `SiteMedia` rows in `PROCESSING` state with `updated_at` older than `max(image_timeout, video_timeout) + buffer` and transitions them to `FAILED` with reason `processing_timed_out`. This closes the stuck-row gap without changing the lock or retry logic (which is correctly designed per the job comments).
        - Add a Nightwatch alert target on `SiteMedia` rows where `processing_state = 'processing'` AND `updated_at < now() - 15 minutes` as an interim observability signal until the cron is in place.
    - **Technical:** The code comments in both jobs explicitly acknowledge this trade-off and name the correct remediation ("A separate cleanup story (cron over rows in PROCESSING past their expected duration) is the right place to reconcile this"). The cron is the accepted fix — not `$this->release()` (explicitly rejected in comments) and not changing `$tries` (would trigger spurious `failed()` while a valid lock holder is still alive). This finding is purely a tracking reminder that the documented remediation hasn't been implemented yet.
    - **Plain English:** Two workers have a rule: "if someone else is already doing this task, just say 'OK done' and walk away." This is intentional — it stops two workers duplicating effort. But if the first worker collapses mid-task and never wakes up, the second worker keeps walking away each time it checks, because the "doing it" sign is still up. The sign eventually comes down on its own (after a timer), but if all three of the second worker's shifts finish before that timer runs out, the task never completes. The code already identifies the fix — a supervisor doing rounds to check "this task has been 'in progress' for 15+ minutes with no sign of life, mark it failed." That supervisor doesn't exist yet.
    - **Evidence:**
        ```php
        // app/Jobs/ProcessImageVariantsJob.php
        // Trade-off (same as ProcessVideoVariantsJob): a worker death leaves the
        // lock held until TTL expiry; retries during that window silently return
        // and Laravel marks the job complete, so the SiteMedia row can be left
        // stuck in PROCESSING for up to (timeout + 60)s after a worker death.
        // A separate cleanup story (cron over rows in PROCESSING past their
        // expected duration) is the right place to reconcile this — adding
        // release-with-delay here would consume `tries` and trigger a spurious
        // `failed()` if the lock holder is still alive when retries exhaust.
        $lockKey = "image:processing-lock:{$this->imageId}";
        $acquired = Redis::set($lockKey, '1', 'EX', $this->timeout + 60, 'NX');
        if (! $acquired) {
            Log::info('ProcessImageVariantsJob: another worker is processing this image, skipping.', [
                'image_id' => $this->imageId,
            ]);

            return;
        }
        ```
