# Job/Queue Correctness Audit — 2026-07-11

**Branch:** HEAD
**Lens:** Job/Queue Correctness — idempotency, retry safety, ShouldBeUnique, missing `$this->fail()`, retry storms
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4.5`
**Source files audited:**
- app/Jobs/Analytics/RecordAnalyticsEventJob.php
- app/Jobs/Cloudflare/CloudflareCachePurgeJob.php
- app/Jobs/Design/AnalyzeConnectionWebsitesJob.php
- app/Jobs/Notifications/DispatchEnquiryNotificationsJob.php
- app/Jobs/Notifications/SendTransactionalNotificationEmailJob.php
- app/Jobs/Platforms/GoogleBusinessEnrichJob.php
- app/Jobs/Platforms/InstagramConnectJob.php
- app/Jobs/Platforms/MenuFetchJob.php
- app/Jobs/ProcessLogoVariantsJob.php

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 1 complete
- P2 Medium: 0 of 3 complete
- P3 Low: 0 of 1 complete

---

## P1 — Fix before pilot launch

- [ ] **#JOB-1** · P1 — CloudflareCachePurgeJob silently succeeds on an empty handle instead of failing loudly
    - **Where:** app/Jobs/Cloudflare/CloudflareCachePurgeJob.php:76-79
    - **Affects:** Edge cache coherence for every professional's public sitepage — `CloudflareCachePurgeJob` is the only job responsible for busting the router's cache after a visible site mutation
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace the bare `return;` in the empty-handle branch with `$this->fail(new \RuntimeException('Empty handle dispatched to CloudflareCachePurgeJob'));` then `return;`.
        - No other change needed — `failed()` already calls `report($e)` + `Log::error(...)`, which is what makes the failure visible to Nightwatch (Nightwatch alerts on exceptions, not log lines).
    - **Technical:** `handle()` trims/lowercases `$this->handle` and returns immediately when the result is empty. Horizon records an empty-handle dispatch as a *succeeded* job, so the failed-jobs counter never increments and no exception reaches Nightwatch. Because this job is the sole edge-purge writer for the platform (dispatched from `SiteObserver::saved`, account-type transitions, and other mutation paths), a caller bug that ever produces an empty/blank handle means the affected site's public page keeps serving a stale render with no signal that a purge was dropped — exactly the "consequential job with a silent no-fail path" pattern this lens calls out for the named highest-stakes jobs.
    - **Plain English:** If someone hands this job a work order with no address on it, right now it just quietly closes the ticket as "done" instead of raising a hand and saying "I can't do this, something's wrong upstream." That means a bug that produces a blank site handle would never get noticed — the affected person's public page could keep showing old content indefinitely with nobody alerted.
    - **Evidence:**
        ```php
        $h = strtolower(trim($this->handle));
        if ($h === '') {
            return;
        }
        ```

## P2 — Should fix

- [ ] **#JOB-2** · P2 — GoogleBusinessEnrichJob can re-run the paid Apify scrape on a retry after a partial success
    - **Where:** app/Jobs/Platforms/GoogleBusinessEnrichJob.php:106-166
    - **Affects:** Google Business enrichment flow; duplicate Apify actor billing and duplicate scrape traffic against the same place on a DB-write failure
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Before invoking `$scraper->fetch()`, write an interim `apify_status = 'processing'` (or equivalent) marker to the connection so a retried attempt can detect that the paid step already ran.
        - At the top of `handle()`, short-circuit (treat as already complete / re-fetch nothing) when `apify_status` is already `'ok'` for this run's inputs.
        - This mirrors the pending → processing → ready|failed state machine already used elsewhere in this file (`apify_status: pending/ok/unavailable`) and in `ProcessLogoVariantsJob` (`PROCESSING_STATE_PROCESSING`).
    - **Technical:** `ShouldBeUnique`/`uniqueId()` on this job (scoped to `userId:placeId`, `uniqueFor = 900`) only coalesces *concurrent* duplicate dispatches — Laravel releases a `ShouldBeUnique` lock once a job attempt finishes processing (success or failure), so it does not protect a job's own retry from re-entering. With `$tries = 0` (unlimited, bounded by `retryUntil()`/`$maxExceptions = 2`), an exception thrown between the `$scraper->fetch($this->placeId, $this->userId)` call and the final `saveQuietly()` (e.g. the `forceFill(...)->saveQuietly()` failing) causes a retry that re-evaluates `needsApify()` against the same harvest/category and re-invokes the paid Apify actor, since no status marker distinguishes "scrape already ran" from "not yet attempted."
    - **Plain English:** This job pays a third party to fetch extra business details, then saves the result. If the save step glitches after the paid fetch already succeeded, the job tries the whole thing again from scratch — paying for the same fetch a second time. Leaving a "still working on it" note before the paid step would let a retry skip straight past the expensive part.
    - **Evidence:**
        ```php
        $enrichment = null;
        if ($this->needsApify($harvest, $gbp->category())) {
            $enrichment = $scraper->fetch($this->placeId, $this->userId);
        }
        ```
        ```php
        $connection->forceFill([
            'payload' => [
                ...$businessInfo,
                'apifyFetchedAt' => now()->toIso8601String(),
                'syncFindings' => $findings,
            ],
            'apify_status' => 'ok',
        ])->saveQuietly();
        ```

- [ ] **#JOB-3** · P2 — InstagramConnectJob can re-run the paid Apify scrape on a retry after a partial success
    - **Where:** app/Jobs/Platforms/InstagramConnectJob.php:132-237
    - **Affects:** Instagram auto-connect flow; duplicate Apify actor billing on a DB-write failure after the scrape/media-mirror already completed
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Write an interim `last_refresh_status = 'processing'` marker to the connection before `$scraper->fetchProfile()`, mirroring the `pending → ok/unavailable` state machine already used by this job's own `last_refresh_status` column.
        - At the top of `handle()`, if `last_refresh_status` is already `'ok'` for this connection/username pair, skip straight past the scrape.
    - **Technical:** Same root cause as JOB-2: `ShouldBeUnique`/`uniqueId()` (`connectionId:username`, `uniqueFor = 900`) coalesces concurrent duplicate dispatches only, not this job's own retries (`$tries = 0`, bounded by `retryUntil()`/`$maxExceptions = 2`). If the final `$connection->update([...])` throws (DB error, connection pool exhaustion, etc.) after `$scraper->fetchProfile()` has already billed and returned data and media has already been mirrored to R2, the retry re-enters `handle()` with `last_refresh_status` still `'pending'` and re-runs the paid scrape. (Media mirroring itself is idempotent — filenames are fixed per connection folder and overwrite in place — so only the Apify call is at risk of duplicate billing.)
    - **Plain English:** Same pattern as the Google Business job: fetching an Instagram profile costs money, and if the final save step fails afterward, the system redoes the whole expensive fetch instead of picking up where it left off.
    - **Evidence:**
        ```php
        $profile = $scraper->fetchProfile($this->username, $this->userId);
        ```
        ```php
        $connection->update([
            'payload' => $selection,
            'is_active' => true,
            'last_refreshed_at' => now(),
            'last_refresh_status' => 'ok',
            'last_refresh_error' => null,
            'consecutive_failures' => 0,
        ]);
        ```

- [ ] **#JOB-4** · P2 — MenuFetchJob can re-run the paid Uber Eats/DoorDash scrape on a retry after a partial success
    - **Where:** app/Jobs/Platforms/MenuFetchJob.php:130-204
    - **Affects:** Menu sync flow for connected online-ordering platforms; duplicate Apify scrape billing when a retry happens between the scrape and the transactional persist
    - **Effort:** M (~2–4h)
    - **What to do:**
        - The skip-check already reads `$existing->fetch_status === 'ok'` as its "nothing to do" gate — reuse this: flip `fetch_status` to a distinct `'processing'` marker (not `'pending'`) immediately before `$scraper->fetchStores(...)`, so a retry that lands before `persist()` commits can be distinguished from a fresh dispatch.
        - On retry, if `fetch_status === 'processing'` and no forced refresh was requested, either re-check freshness before re-scraping or accept the state and skip straight to a re-scrape only if the interim marker is stale beyond a TTL.
    - **Technical:** `MenuFetchJob` already carries `ShouldBeUnique` (`uniqueId() = userId`, `uniqueFor = 1800`), which — as with JOB-2/JOB-3 — only prevents concurrent duplicate dispatch, not the job's own exception-triggered retry (`$tries = 0`, bounded by `retryUntil()`/`$maxExceptions = 2`). `handle()` sets `fetch_status = 'pending'` via `Menu::updateOrCreate(...)` *before* the paid `$scraper->fetchStores(...)` call, and only flips to `'ok'` inside the `persist()` DB transaction that runs afterward (merge logic + `persist()` execute outside any lock, per the file's own `SEM-2` comment convention elsewhere in this codebase). If an exception occurs between the scrape returning and `persist()` committing, the retry's top-of-`handle()` skip check (`$existing->fetch_status === 'ok'`) sees `'pending'`, fails the skip, and re-enters the scrape path, re-billing Uber Eats/DoorDash Apify actors for the same user.
    - **Plain English:** This job fetches menu data from delivery apps, which costs money each time. If something goes wrong saving the result after the expensive fetch already succeeded, the job currently starts over and pays for the fetch again instead of remembering it already got the data.
    - **Evidence:**
        ```php
        $menu = Menu::updateOrCreate(
            ['user_id' => $this->userId],
            [
                'content_source' => $plan['contentSource'],
                'pickup_platform' => $plan['pickupPlatform'],
                'delivery_platform' => $plan['deliveryPlatform'],
                'fetch_status' => 'pending',
            ],
        );
        ```
        ```php
        $menus = $scraper->fetchStores($storeLinks, $this->userId, $plan['address']);
        ```
        ```php
        if (! $this->force
            && $existing
            && $existing->fetch_status === 'ok'
            && $allSettled) {
            return;
        }
        ```

## P3 — Nice to have

- [ ] **#JOB-5** · P3 — SendTransactionalNotificationEmailJob has no `ShouldBeUnique` guard, so duplicate dispatches contend for its row lock instead of coalescing
    - **Where:** app/Jobs/Notifications/SendTransactionalNotificationEmailJob.php:23-25
    - **Affects:** Transactional email dispatch; wasted worker/DB time when the same notification is dispatched more than once, no data-correctness impact
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `ShouldBeUnique` to the `implements` clause.
        - Add `public function uniqueId(): string { return $this->notificationId; }`.
        - Set a short `$uniqueFor` (e.g. 60–120s, comfortably under the job's own `$timeout = 30`) so a hung worker can't wedge the lock past the job's own execution budget.
    - **Technical:** `handle()` already wraps the read-and-check of `email_sent_at` in `DB::transaction(fn () => Notification::query()->lockForUpdate()->find(...))`, so correctness (no duplicate send) is already guaranteed at the data layer. Without `ShouldBeUnique`, however, duplicate dispatches for the same `notificationId` both get pulled off the queue and one blocks on the pessimistic lock until the other completes, burning a worker slot and a DB round-trip for no benefit. `ShouldBeUnique` would coalesce the duplicate at the queue layer before it ever reaches the lock.
    - **Plain English:** Two workers can currently both pick up the job to send the same notification email — the database lock stops a duplicate email from actually going out, but the second worker still wastes time waiting its turn instead of never being sent at all. Telling the queue "only run one of these per notification" avoids that wasted work.
    - **Evidence:**
        ```php
        class SendTransactionalNotificationEmailJob implements ShouldQueue
        {
            use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
        ```

## Suggested Bundled Sessions

- **Bundle 1 — Platforms paid-scrape retry idempotency:** #JOB-2, #JOB-3, #JOB-4
    - **Why grouped:** Identical root cause (paid vendor scrape re-run on a job's own retry because no pre-call "processing" marker exists) across three sibling files in `app/Jobs/Platforms/`; the fix pattern is the same state-machine addition in each.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet (per file's Execution policy).

- **Bundle 2 — Notification uniqueness hygiene:** #JOB-5
    - **Why grouped:** Single small hygiene fix, no shared file/pattern with other findings.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet (combine plan+implement — S effort).

## Standalone — do NOT bundle

- **#JOB-1 — CloudflareCachePurgeJob silent success on empty handle** · standalone: distinct subsystem (edge-cache job hygiene) from the Platforms-scrape bundle and the notification bundle; small enough to land independently without waiting on either.
