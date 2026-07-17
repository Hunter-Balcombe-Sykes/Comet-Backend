# Job/Queue Correctness Audit — 2026-07-17

**Branch:** audit/full-work-sweep-2026-07-17
**Lens:** Job/Queue Correctness — idempotency, retry safety, ShouldBeUnique, missing `$this->fail()`, retry storms
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4.5`
**Source files audited:**
- app/Jobs/Cloudflare/SyncSubdomainToKvJob.php
- app/Jobs/Platforms/InstagramConnectJob.php
- app/Jobs/Platforms/MenuFetchJob.php
- app/Console/Commands/CleanupOrphanedLifestyleConnections.php

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 3 complete
- P2 Medium: 0 of 0 complete
- P3 Low: 0 of 0 complete

---

## P1 — Fix before pilot launch

- [ ] **JOB-1** · P1 — Swallowed exception in `SyncSubdomainToKvJob::handle()` bypasses the moderation-hide gate on a transient DB error
    - **Where:** app/Jobs/Cloudflare/SyncSubdomainToKvJob.php:105-118
    - **Affects:** Any site whose owner is active but whose SITE has just been hidden by moderation (`site.sites.moderation_state = 'hidden'`) — including CSAM-triggered hides (`SuspendSiteJob::resolveSiteId` resolves both `Site` and `SiteMedia` reportable types to the same hide). This job is the exact job `PurgeModerationCacheJob` dispatches right after a hide to retire the route (`app/Jobs/Moderation/PurgeModerationCacheJob.php:51`), so a transient failure reading the just-hidden site's relationship (deadlock, connection blip, replica lag) causes this same sync to silently re-publish the handle instead.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Stop swallowing the exception: remove the `try/catch` around `$pro->site` (or catch, call `$this->fail($e); return;`) so a genuine read failure propagates to Horizon's normal retry path instead of falling through to the publish branch.
        - `SyncSubdomainToKvJob` already carries `$tries = 3` / `$backoff = [10, 30, 60]` / `$maxExceptions = 2` via `HasCloudflareRetryPolicy` — letting the exception surface uses infrastructure that already exists; no new retry policy is needed.
        - Add a regression test that mocks `User::site()` to throw and asserts the job does NOT call `$kv->put($current, ['type' => 'individual'], ...)` when the site read fails.
    - **Technical:** `User::isActive()` (app/Models/Core/User/User.php:128-131) only checks the user-level `status` column — it has no knowledge of `site.sites.moderation_state`, which is the ONLY gate stopping a moderation-hidden site from resolving once the user account itself is still `active`. The `try { $site = $pro->site; } catch (Throwable $e) { report($e); }` block at handle():105-110 leaves `$site = null` on any exception, which makes the subsequent `if ($site && ... === 'hidden')` check false-negative, and execution falls through to `$kv->put($current, ['type' => 'individual'], null)` at line 122 — republishing the handle to the edge. Because `report()` alone doesn't raise a Nightwatch alert (exceptions only alert when they actually propagate), this is invisible to on-call. This job is dispatched precisely in the moderation-hide flow (`PurgeModerationCacheJob::handle()`), so the trigger condition (hide event + a DB blip on the very next relation read) is a real, documented lifecycle path, not a hypothetical.
    - **Plain English:** Imagine a bouncer checking a banned list at the door. If the bouncer's tablet glitches while loading the list, instead of stopping and getting a manager, the bouncer just shrugs and waves everyone through — including people who were supposed to be turned away. That's what happens here: if reading a site's moderation status fails for any reason right after a moderator hides it, the background job republishes the page to the public internet instead of keeping it hidden, and nobody gets notified that it happened.
    - **Evidence:**
        ```php
        $site = null;
        try {
            $site = $pro->site;
        } catch (Throwable $e) {
            report($e);
        }

        // Moderation has hidden the site — retire the route so a hide_site
        // takedown (which hides the SITE, not the user) also stops resolving.
        if ($site && ($site->moderation_state ?? 'active') === 'hidden') {
            $this->retire($kv, $pro);

            return;
        }
        ```

- [ ] **JOB-2** · P1 — Swallowed exception in `SyncSubdomainToKvJob::retire()` can leave a taken-down user's custom domain fully serving their page
    - **Where:** app/Jobs/Cloudflare/SyncSubdomainToKvJob.php:173-185
    - **Affects:** Users who are soft-deleted, suspended, or moderation-hidden AND have an active custom domain (`custom_domain_status = 'active'`). Per the Cloudflare Worker's own routing contract (`cloudflare-worker/src/index.js:427-441`), a `domain:<host>` KV entry carries `{type:'individual', handle:<handle>}` and is resolved **independently** of whether the plain `<handle>` KV entry exists — the Worker forwards straight to `partna-pages` using the handle embedded in the domain entry. This means if `retire()`'s custom-domain delete is skipped, the takedown/hide is INCOMPLETE for anyone visiting via their custom domain: the page stays fully live, even though the handle-based `<handle>.partna.au` route was correctly retired.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Stop swallowing the exception here too: let it propagate (or `$this->fail($e); return;`) instead of `report($e); $site = null;`.
        - The unconditional `$kv->delete($handle)` earlier in `retire()` already ran and is idempotent on retry (a missing-key delete is a no-op at Cloudflare, per the method's own docblock), so retrying the whole `retire()` call is safe.
        - Add a regression test asserting that when the site-relation read throws inside `retire()`, the job does not silently succeed while `domain:<host>` remains undeleted.
    - **Technical:** This shares the exact swallow pattern as JOB-1 (`try { $site = $pro?->site; } catch (Throwable $e) { report($e); $site = null; }`), applied to the takedown path instead of the active-publish path. Because the Worker's custom-domain branch (`index.js:430-442`) never checks for a corresponding `<handle>` KV key — it serves directly off the `domain:<host>` value's own `handle` field — a failed domain delete is not a cosmetic "stale KV slot" as originally assessed; it is a live route that keeps rendering the taken-down page. Re-tiered from the draft's P2 to match JOB-1: same root cause (swallowed `Throwable` on a site-relation read during a takedown-adjacent code path), same class of consequence (a moderation/deletion/suspension action is left incomplete for a real subset of users — anyone on a custom domain).
    - **Plain English:** When an account gets taken down, the system needs to remove two "street signs" pointing at it: the `handle.partna.au` address and, if the user has one, their own custom domain. This bug means that if reading the domain details crashes at the wrong moment, only the first sign comes down — visitors using the person's own custom domain (e.g. `janedoe.com`) still land on the fully live page, defeating the whole point of the takedown.
    - **Evidence:**
        ```php
        try {
            $site = $pro?->site;
        } catch (Throwable $e) {
            report($e);
            $site = null;
        }

        if ($site) {
            $customDomain = strtolower(trim((string) ($site->custom_domain ?? '')));
            if ($customDomain !== '' && ($site->custom_domain_status ?? null) === 'active') {
                $kv->delete("domain:{$customDomain}");
            }
        }
        ```

- [ ] **JOB-3** · P1 — `MenuFetchJob`'s 600s `$timeout` exceeds the `redis` connection's 360s `retry_after`, risking a duplicate concurrent scrape/rebuild
    - **Where:** app/Jobs/Platforms/MenuFetchJob.php:48 (cross-referenced against config/queue.php:70-79)
    - **Affects:** Any user whose menu fetch genuinely runs long — the exact scenario the job's own 600s timeout exists to cover ("Up to two real store scrapes (UE + DD), each retried on empty"). `MenuFetchJob` is dispatched on `config('partna.queues.scraping', 'scraping')`, which runs on Horizon's `supervisor-scraping` using `'connection' => 'redis'` (config/horizon.php:219-231) — the default `redis` queue connection, whose `retry_after` is 360s. Laravel's worker uses the JOB's own `$timeout` (600s) to decide when to `pcntl_alarm`-kill the process, which is correctly *longer* than Horizon's per-supervisor `timeout: 180`, but that same 600s figure is what the job is actually allowed to run for — well past the 360s point at which Redis considers the job's reservation abandoned and hands the identical queued entry to a second free worker (2 processes configured on `supervisor-scraping`). Two workers then run `handle()` concurrently for the same user: two Apify scrapes are billed, and `persist()`'s delete-then-reinsert transaction (app/Jobs/Platforms/MenuFetchJob.php:263-363) is not safe against a second concurrent execution — each worker computes its own `rebuildableCategoryIds()` snapshot before either commits, so both transactions insert their own full set of categories/items, leaving duplicate menu content live on the user's sitepage.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Give `MenuFetchJob` (and the `scraping` queue generally) a connection whose `retry_after` comfortably exceeds 600s, mirroring the pattern this codebase already uses elsewhere: `redis_gdpr` sets `retry_after = 660` specifically because `RedactShopJob::$timeout = 600` (config/queue.php:94-101), and `redis_video` uses `3600` for long ffmpeg encodes. Either route `scraping` onto a comparable dedicated connection, or raise the shared `redis` connection's `REDIS_QUEUE_RETRY_AFTER` default past 600s after confirming no other job sharing that connection depends on faster abandoned-job recovery.
        - Add a test (or extend the existing `JobHygienePolicyTest` family) asserting every job's `$timeout` stays below its resolved connection's `retry_after` — this exact invariant is already documented in three separate places in `config/queue.php` but isn't enforced anywhere.
    - **Technical:** `config/queue.php`'s own comments state the rule explicitly ("Must exceed the longest job $timeout... so a slow job is never re-queued while still running") and the codebase has applied it correctly for every other long-running job (GDPR, video, image aggregates) — `MenuFetchJob` is the one instance where a 600s job timeout was added without also adjusting (or isolating from) the connection's `retry_after`. This is a genuine cross-file invariant violation the exhaustiveness pass is meant to catch, not a duplicate of an existing finding: `ShouldBeUnique` is present on `MenuFetchJob` and correctly prevents a second *dispatch* from being enqueued, but it does nothing to prevent the underlying Redis queue driver from redelivering the *same already-reserved* job payload to a second worker once its invisibility window lapses — that failure mode bypasses the Bus-level uniqueness lock entirely.
    - **Plain English:** Picture a food order that gets handed to a second cook if the first cook hasn't checked in within a fixed time — even though the first cook is still actively cooking it, just taking a bit longer than usual. Now two cooks are making the same dish at once, and both get plated. For this job, "plating" means writing a user's menu to the database — if two copies run at the same time, the customer's live menu page can end up with duplicate dishes.
    - **Evidence:**
        ```php
        // Up to two real store scrapes (UE + DD), each retried on empty; allow
        // headroom for MAX_ATTEMPTS × ATTEMPT_TIMEOUT per platform in MenuApifyScraper.
        public int $timeout = 600;
        ```
        ```php
        'redis' => [
            'driver' => 'redis',
            'connection' => env('REDIS_QUEUE_CONNECTION', 'default'),
            'queue' => env('REDIS_QUEUE', 'default'),
            // Must exceed the longest job $timeout. RebuildProfessionalHourlyAggregatesJob
            // and RebuildBrandHourlyAggregatesJob have $timeout = 300; use 360 for a
            // 60-second safety margin so a slow job is never re-queued while still running.
            'retry_after' => (int) env('REDIS_QUEUE_RETRY_AFTER', 360),
            'block_for' => null,
            'after_commit' => false,
        ],
        ```

## Suggested Bundled Sessions

- **Bundle 1 — Cloudflare KV sync fail-open exceptions:** JOB-1, JOB-2
    - **Why grouped:** Same file (`SyncSubdomainToKvJob.php`), same root-cause pattern (swallowed `Throwable` on a `$pro->site`/`$pro?->site` read during a moderation/takedown-adjacent path), same fix shape (stop catching, let Horizon's existing 3-try backoff handle it).
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

## Standalone — do NOT bundle

- **JOB-3 — `MenuFetchJob` timeout vs. `redis` connection `retry_after`** · standalone because the fix touches shared queue-connection configuration (`config/queue.php` `redis` connection and/or a new dedicated connection wired into `config/horizon.php`) used by every other queue on that connection — a distinct subsystem from the Cloudflare KV findings above, with a blast radius wide enough to warrant its own plan and sign-off rather than bundling.
