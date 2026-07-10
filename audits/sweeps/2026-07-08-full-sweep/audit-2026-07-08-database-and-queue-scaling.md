# Database & Queue Scaling Audit — 2026-07-08

**Branch:** audit-fix/middleware-2026-07-06
**Lens:** Database & queue scaling — N+1, unbounded reads, connection scoping, queue shape, vendor budgets, migration safety, backpressure
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4.5`
**Source files audited:**
- app/Models
- app/Http/Resources
- database/factories
- app/Console
- routes/console.php
- config/horizon.php
- config/queue.php
- app/Jobs
- app/Services/Media
- app/Services/Streaming
- app/Services/Cloudflare
- app/Services/Analytics

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 1 complete
- P2 Medium: 0 of 2 complete
- P3 Low: 0 of 11 complete

---

## P1 — Fix before pilot launch

- [ ] **#SCALE-1** · P1 — ProcessVideoVariantsJob's `$tries` only consumes the first backoff tier, silencing the documented exponential retry
    - **Where:** app/Jobs/ProcessVideoVariantsJob.php:37-40
    - **Affects:** Video uploads platform-wide — any transient R2 storage or transcode-container blip longer than ~60s permanently fails the user's video instead of retrying with the documented widening gaps.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Set `public int $tries = 4;` so all three backoff entries (`60, 300, 900`) are actually consumed, matching the docblock's stated intent ("progressively longer retry gaps").
        - Alternatively, if 2 attempts total is the actual intended ceiling, trim `$backoff` to `[60]` and update the comment — but given the job already accounts for crash-recovery locking and a 720s timeout, the wider backoff is clearly the intended behavior.
    - **Technical:** `$tries = 2` means one initial attempt plus one retry, so only `$backoff[0]` (60s) is ever used — `$backoff[1]` (300s) and `$backoff[2]` (900s) are unreachable dead configuration. The adjacent comment explicitly documents "JOB-11: transient R2/transcode failures get progressively longer retry gaps," which the current `$tries` value contradicts. This is a values mismatch, not a missing-`$backoff` finding (which `JobHygienePolicyTest` already enforces), so it survives the CI gate silently.
    - **Plain English:** The video-processing job is designed to retry a failed upload three times with increasing patience — wait a minute, then five minutes, then fifteen. But a mismatched setting means it only gets one retry after one minute before giving up for good. A two-minute hiccup in the cloud storage service — the kind that resolves itself if you just wait — permanently kills the video instead of quietly recovering on the next attempt.
    - **Evidence:**
        ```php
        public int $tries = 2;

        // Exponential backoff (JOB-11): transient R2/transcode failures get progressively longer retry gaps.
        public array $backoff = [60, 300, 900];
        ```

## P2 — Should fix

- [ ] **#SCALE-2** · P2 — Staff broadcast email fan-out has no per-second send-rate awareness for the mail provider
    - **Where:** app/Jobs/Notifications/SendStaffBroadcastEmailToSubscriberJob.php:84-86 (leaf send); app/Jobs/Notifications/SendStaffBroadcastEmailsJob.php:92-97 (batch dispatch)
    - **Affects:** All platform email deliverability — a broadcast to the marketing list can exceed Resend/Postmark's per-second sending budget, which throttles or rejects sends across the *entire* mail queue (including transactional enquiry/subscription confirmations that share the same `mail` queue and provider account).
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add a Redis-backed `RateLimited` queue middleware to `SendStaffBroadcastEmailToSubscriberJob::middleware()`, keyed off a new `config('partna.notifications.mail_rate_limit_per_second')` value sized to the provider's plan.
        - Verify the `mail` supervisor's `maxProcesses` (3 in prod per `config/horizon.php`) doesn't itself become the bottleneck once the limiter is in place.
    - **Technical:** `SendStaffBroadcastEmailsJob` correctly bounds subscriber iteration (`chunkById(500)`) and batches dispatch (`Bus::batch()` in chunks of `partna.notifications.batch_chunk_size`, default 200) — both real prior fixes. But the leaf job, `SendStaffBroadcastEmailToSubscriberJob::handle()`, calls `Mail::to($sub->email)->send(...)` with no throttling middleware, and no `partna.*` config key for a provider send-rate exists (confirmed via grep of `config/partna.php`). Nothing in the pipeline caps concurrent sends to the provider's actual per-second ceiling — only the Horizon worker count (3) provides an implicit, unconfigured cap.
    - **Plain English:** When staff send an announcement to the mailing list, the system fires the emails at the delivery service as fast as its three workers allow, with no throttle tuned to what that service actually permits per second. It's like feeding letters into a mail slot faster than the postal worker can process them — some get rejected, and because the same delivery service also handles password resets and booking confirmations, everyone's email can get momentarily backed up during a broadcast.
    - **Evidence:**
        ```php
        Mail::to($sub->email)->send(
            new StaffBroadcastMail($notification, $unsubscribeUrl)
        );
        ```

- [ ] **#SCALE-3** · P2 — ResolveDesignPresetsJob is hardcoded to the shared `default` queue instead of an isolated lane
    - **Where:** app/Jobs/Design/ResolveDesignPresetsJob.php:42-45
    - **Affects:** All users sharing the `default` Horizon queue — dispatched on every connection connect/disconnect/refresh (via `IntegrationConnectionObserver`) plus by `AnalyzeConnectionWebsitesJob`/`AnalyzePreviousWebsiteJob` on completion, so an observer cascade (e.g. a multi-brand shop connect) can burst several of these onto the same lane as short user-facing default-queue work.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Route to `config('partna.queues.cache_warm', 'cache-warm')` — the existing "background best-effort" lane already isolated for exactly this class of non-urgent recompute-then-touch work (see `config/horizon.php`'s `supervisor-cache-warm` comment), rather than inventing a new `design` queue/supervisor.
    - **Technical:** Every other background-recompute job in the codebase (`WarmPublicSiteCacheJob`, etc.) routes to a dedicated queue via `config('partna.queues.*')`; `ResolveDesignPresetsJob` is the outlier, hardcoding `$this->onQueue('default')`. `config/horizon.php` has no queue named `design`, so the correct fix reuses the existing `cache-warm` lane rather than adding new infrastructure. The job does a DB read/write (`DesignPresetResolver::resolveForUser()`) plus a `$site->touch()` that cascades the full sitepage-cache purge chain — non-trivial work that shouldn't compete with the `default` supervisor's short, latency-sensitive jobs.
    - **Plain English:** A background job that recalculates a user's page styling shares the same waiting line as fast, everyday user actions. When several site connections are made at once, a handful of these recalculation jobs can briefly clog that line and slow down quick actions other users are waiting on. Moving it to the platform's existing "background work" lane keeps both fast.
    - **Evidence:**
        ```php
        public function __construct(public readonly string $userId)
        {
            $this->onQueue('default');
        }
        ```

## P3 — Nice to have

- [ ] **#SCALE-4** · P3 — EnrichLinkCardJob reads its queue name from config with no fallback default
    - **Where:** app/Jobs/Platforms/EnrichLinkCardJob.php:40
    - **Affects:** Link-card enrichment jobs — if the `partna.queues.scraping` config key is ever removed/misspelled, `config()` returns `null` and the job's queue routing becomes version-dependent/undefined instead of falling back to a sane default.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add the fallback string used by every sibling job on this queue: `config('partna.queues.scraping', 'scraping')`.
    - **Technical:** `DeleteMirroredMediaJob`, `GoogleBusinessEnrichJob`, `InstagramConnectJob`, and `MenuFetchJob` all call `config('partna.queues.scraping', 'scraping')` with an explicit fallback; `EnrichLinkCardJob` alone omits the second argument. This is a small inconsistency, not an active bug today (the config key exists), but it's a one-line fix that closes a silent-misroute footgun.
    - **Plain English:** One job trusts its queue-name setting will always be there, unlike every other job of its kind, which has a backup plan. If that setting were ever accidentally deleted, this job alone would silently misbehave instead of falling back gracefully like its siblings.
    - **Evidence:**
        ```php
        $this->onQueue(config('partna.queues.scraping'));
        ```

- [ ] **#SCALE-5** · P3 — AggregateCacheMetricsJob is the only cache-domain job left on the shared `default` queue
    - **Where:** app/Jobs/Cache/AggregateCacheMetricsJob.php:30
    - **Affects:** Low — runs hourly, does a single Redis hash read plus structured logging, so contention with `default`-queue user-facing work is brief.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Route to `config('partna.queues.cache_warm', 'cache-warm')` for consistency with the rest of the cache-domain job family.
    - **Technical:** Every other cache-domain job routes to a dedicated queue via `config('partna.queues.*')`; this job hardcodes `$this->onQueue('default')`. Impact is minimal (fast job, hourly cadence) but it's the sole exception to an otherwise-consistent isolation pattern.
    - **Plain English:** The hourly metrics-collection job sits in the general-purpose line instead of its own lane like its sibling cache jobs. It's quick enough that this rarely matters, but tidying it up removes a theoretical, if minor, source of contention.
    - **Evidence:**
        ```php
        $this->onQueue('default');
        ```

- [ ] **#SCALE-6** · P3 — RecordAnalyticsEventJob and SyncCustomerMarketingOptInJob use a flat scalar backoff instead of a stepped array
    - **Where:** app/Jobs/Analytics/RecordAnalyticsEventJob.php:28-31; app/Jobs/Notifications/SyncCustomerMarketingOptInJob.php:30-38
    - **Affects:** Retry pacing under transient failures — both jobs retry all attempts at the same fixed delay instead of progressively spacing them out.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Change `RecordAnalyticsEventJob::$backoff` from `10` to `[10, 30, 60]`.
        - Change `SyncCustomerMarketingOptInJob::$backoff` from `30` to `[30, 90, 180]`.
    - **Technical:** Both jobs declare `$tries = 3` with a single scalar `$backoff` value, so all three attempts retry at the identical interval. This is valid Laravel usage (not a bug), but it's inconsistent with the stepped-backoff convention used elsewhere in the codebase (e.g. `RefreshConnectionJob`, `ResolveDesignPresetsJob`) which gives upstream dependencies more room to recover across a short outage window.
    - **Plain English:** When these two jobs hit a temporary hiccup, they retry at the same speed every time instead of waiting progressively longer, like the rest of the platform's jobs do. It's a minor consistency gap, not a bug — during a short outage it just means slightly less breathing room before the retries pile up.
    - **Evidence:**
        ```php
        // RecordAnalyticsEventJob
        public int $tries = 3;
        public int $backoff = 10;
        ```
        ```php
        // SyncCustomerMarketingOptInJob
        public int $tries = 3;
        public int $backoff = 30;
        ```

- [ ] **#SCALE-7** · P3 — CheckStreamingLiveStatusJob accumulates every live-check handle in memory before any poll begins
    - **Where:** app/Jobs/Streaming/CheckStreamingLiveStatusJob.php:65-88
    - **Affects:** Streaming poll cycle (every 2 minutes) — all handles across all platforms are collected into one array before the first API call fires; harmless at current scale, a minor memory-efficiency gap at high growth.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Poll each platform's batch as its `chunkById` pass completes rather than accumulating a single cross-chunk `$handlesByPlatform` array, if/when block volume grows large enough to matter.
    - **Technical:** The job correctly streams DB rows via `chunkById(500)` (bounded memory per chunk), but builds one in-memory array (`$handlesByPlatform`) spanning every chunk before any `$poller->poll()` call executes. At current and near-term scale (thousands of blocks ≈ a few hundred KB of strings) this is immaterial; it only becomes worth addressing well beyond pre-beta volume.
    - **Plain English:** The streaming-status checker reads through all users' streaming links in efficient small batches, but then waits until it has collected every single one before checking any of them — like gathering every envelope before starting to mail them, rather than mailing each batch as it's ready. At today's scale this costs nothing noticeable; it's just slightly less efficient than it could be as the platform grows.
    - **Evidence:**
        ```php
        $handlesByPlatform = array_fill_keys($streamingPlatforms, []);

        Block::query()
            // ...
            ->chunkById(500, function ($blocks) use (&$handlesByPlatform, $streamingPlatforms): void {
                foreach ($blocks as $block) {
                    // ...
                    $handlesByPlatform[$platform][] = $handle;
                }
            });

        foreach ($handlesByPlatform as $platform => $handles) {
        ```

- [ ] **#SCALE-8** · P3 — ProcessLogoVariantsJob buffers the full original file into PHP memory instead of streaming
    - **Where:** app/Jobs/ProcessLogoVariantsJob.php:113-123
    - **Affects:** Logo uploads — if a user uploads an oversized image as their logo, the entire file is held in memory before being sent to the background-removal container; typical logos are small, so this is a latent guard gap rather than an active problem.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Stream via `$disk->readStream($this->originalPath)` and pass a stream/temp-file path to `LogoProcessorClient`, matching the pattern `ProcessImageVariantsJob`/`ProcessVideoVariantsJob` already use for their originals.
    - **Technical:** `$originalBytes = $disk->get($this->originalPath)` reads the entire file into a PHP string, then attaches it to an outbound multipart request. There is no size guard on this specific path, so an unusually large upload allocates its full size in the PHP worker's memory. Logos are typically small (a few hundred KB), which keeps this a low-severity hardening item rather than an active incident risk.
    - **Plain English:** The logo-processing job loads the whole image into memory in one gulp instead of sipping it in a stream the way the platform's other image jobs do. For normal-sized logos this is harmless; if someone uploaded an unusually large file, it would use more memory than necessary.
    - **Evidence:**
        ```php
        $originalBytes = $disk->get($this->originalPath);
        if ($originalBytes === null || $originalBytes === '') {
            throw new \RuntimeException('Original file is empty.');
        }
        ```

- [ ] **#SCALE-9** · P3 — ModerationSlaScanCommand hydrates the full at-risk case set every 15 minutes
    - **Where:** app/Console/Commands/Moderation/ModerationSlaScanCommand.php:27-31
    - **Affects:** SLA breach-warning scan — moderation cases are a small, actively-managed table by design (CSAM automated pipeline was deferred, moderation stays a light-touch foundation), so this is a latent-only concern.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - If the open-case count ever grows materially, switch to `chunk()`/`cursor()` rather than a single `get()`. Not urgent today.
    - **Technical:** `ModerationCase::query()->...->get(['id', 'severity', 'sla_due_at'])` already projects only 3 columns, keeping memory-per-row small; the finding is about unbounded row *count*, not column width. Given the moderation subsystem is intentionally kept minimal (per project history), this table is not expected to reach a size where a 15-minute `get()` becomes a real memory concern pre-pilot.
    - **Plain English:** Every 15 minutes the system loads every case that's close to missing its response deadline into memory to check on it. The moderation caseload today is small and expected to stay that way, so this isn't a real problem right now — just worth revisiting if that ever changes.
    - **Evidence:**
        ```php
        $atRisk = ModerationCase::query()
            ->whereIn('status', ['open', 'triaged', 'under_review'])
            ->whereNotNull('sla_due_at')
            ->where('sla_due_at', '<', $cutoff)
            ->get(['id', 'severity', 'sla_due_at']);
        ```

- [ ] **#SCALE-10** · P3 — PruneResolvedCaseSignalsPiiCommand plucks the full resolved-case-ID set into memory
    - **Where:** app/Console/Commands/PruneResolvedCaseSignalsPiiCommand.php:60-64
    - **Affects:** Weekly PII-retention sweep on `moderation.case_signals` — a T&S table explicitly expected to stay small.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - No action needed pre-pilot. If the resolved-case set grows large, chunk the `pluck` + `whereIn` loop as the command's own comment already anticipates.
    - **Technical:** The command's own docblock states: *"for a P3 weekly job on a T&S table expected to stay small, a single whereIn is acceptable. If the resolved-case set grows large, chunk the pluck + whereIn loop in future."* This is a pre-acknowledged, deliberately-deferred tradeoff rather than an oversight — kept here only as a forward-looking marker, not an action item.
    - **Plain English:** A weekly cleanup task that erases old reported-content data loads the full list of closed cases into memory first. The system's own documentation already flags this as fine for now because that list is expected to stay small, with a plan noted for if it ever doesn't.
    - **Evidence:**
        ```php
        $caseIds = DB::connection('pgsql')
            ->table('moderation.cases')
            ->whereIn('status', ['resolved', 'auto_actioned'])
            ->where('resolved_at', '<', $cutoff->toDateTimeString())
            ->pluck('id');
        ```

- [ ] **#SCALE-11** · P3 — AnalyzeConnectionWebsitesJob re-queries `shopBrands()->get()` per connection instead of eager-loading
    - **Where:** app/Jobs/Design/AnalyzeConnectionWebsitesJob.php:147-151, 189, 249-254
    - **Affects:** Users with shop-platform connections during design-analysis runs — extra per-connection queries, bounded by a per-user connection count that stays small (a handful of platforms) and a `MAX_ANALYSES_PER_RUN = 2` budget that already caps work per run.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `->with('shopBrands')` to the `IntegrationConnection::query()` calls in `handle()` and in the `failed()` requery, so `connectionNeedsAnalyses()`/`brandNeedsAnalysis()` iterate an eager-loaded collection instead of re-querying.
    - **Technical:** `$connection->shopBrands()->get()` is called once per connection inside `handle()`'s loop and again inside `connectionNeedsAnalyses()` (used in both the self-continue check and `failed()`'s kill-recovery check), issuing a fresh query each time rather than reusing an eager-loaded relation. This is a genuine N+1 pattern, but the practical blast radius is small: `OutsideWebsitesFactor::SOURCE_PLATFORMS` bounds the connection count per user to a handful, and this job runs on the isolated `scraping` queue, not the hot public-sitepage path.
    - **Plain English:** When the design engine checks a user's outside websites for missing style analysis, it asks the database for that connection's shop products separately, multiple times, instead of once. Because each user only has a few connections, this wastes a little bit of database capacity rather than a lot — worth tidying up, not urgent.
    - **Evidence:**
        ```php
        $connections = IntegrationConnection::query()
            ->where('user_id', $user->id)
            ->active()
            ->whereIn('platform', OutsideWebsitesFactor::SOURCE_PLATFORMS)
            ->get();
        // ...
        foreach ($connection->shopBrands()->get() as $brand) {
        ```

- [ ] **#SCALE-12** · P3 — BackfillWebsiteAnalysesCommand plucks all outside-connection user IDs into memory before dispatch
    - **Where:** app/Console/Commands/BackfillWebsiteAnalysesCommand.php:100-103
    - **Affects:** One-shot, developer-run design-analysis backfill — not an automated recurring path.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - If run against a large fleet, switch to `chunkById`/`lazyById` over `IntegrationConnection` and dispatch per-row instead of `distinct()->pluck('user_id')` into a single array.
    - **Technical:** `$outsideConnections()->distinct()->pluck('user_id')` already pushes deduplication to Postgres (the command's own comment notes this was a deliberate choice over hydrating full models), so this is a lighter-weight `pluck` of UUID strings, not full model rows. It's manually invoked by an operator, not scheduled, so the realistic blast radius is small.
    - **Plain English:** A one-time maintenance command loads every relevant user ID into a single list before starting work. It's only run by hand when needed, and the list is IDs only (not full records), so this is a low-risk, low-priority cleanup.
    - **Evidence:**
        ```php
        $outsideUserIds = $outsideConnections()->distinct()->pluck('user_id');
        foreach ($outsideUserIds as $userId) {
            AnalyzeConnectionWebsitesJob::dispatch((string) $userId);
        }
        ```

- [ ] **#SCALE-13** · P3 — BackfillSubdomainKvCommand's `--all` mode dispatches Cloudflare KV syncs with no inter-job pacing
    - **Where:** app/Console/Commands/BackfillSubdomainKvCommand.php:59-63
    - **Affects:** Cloudflare KV API during a full resync — a rare, manually-triggered operator command (run after raw-SQL fixes or alias rollouts), not a recurring user-facing path.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a small per-dispatch delay (e.g. via `Sleep::milliseconds(...)`) when `--all --queue` is used, or rely on the existing `supervisor-cloudflare` concurrency cap (`maxProcesses` 2 in production) as the de facto pacing mechanism and document that reliance.
    - **Technical:** The command already streams users via `chunkById(500)` (a prior fix, per its own `SCALE-2` comment) rather than materialising all IDs, so memory is bounded. The remaining gap is that `SyncSubdomainToKvJob` calls `CloudflareKvService::put()`/`bulkPut()` with no explicit rate-limiter — pacing is implicit, coming only from the `cloudflare` Horizon supervisor's `maxProcesses: 2` (production) cap, not a deliberate per-second budget. For a full-fleet `--all` backfill this is an infrequent, operator-controlled action, so the risk is real but low-frequency.
    - **Plain English:** A bulk resync of every user's page address fires off requests to Cloudflare with no explicit pacing, relying only on there being just two worker processes to naturally slow things down. This only runs when an operator deliberately triggers a full resync, so the risk window is small, but an explicit pause between batches would be safer.
    - **Evidence:**
        ```php
        $query->select('id')->chunkById(500, function ($users) use ($dispatch): void {
            foreach ($users as $user) {
                $dispatch((string) $user->id);
            }
        });
        ```

- [ ] **#SCALE-14** · P3 — NotifyReporterJob plucks all reporter emails into memory and sends synchronously in a loop
    - **Where:** app/Jobs/Moderation/NotifyReporterJob.php:53-62
    - **Affects:** Staff enforcement notifications on a moderation case with many reporters — bounded by Partna's actual traffic shape (individual-professional platform, not a mass-reporting social feed), so "thousands of reporters" is an unrealistic extreme, but the dedup-at-DB-level fix is essentially free.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `->pluck('reporter_email')->unique()` with `->distinct()->pluck('reporter_email')` to push deduplication to Postgres instead of PHP.
        - If report volume per case ever grows materially, chunk the notification sends so a single case can't approach the 60s `$timeout`.
    - **Technical:** `CaseSignal::query()->...->pluck('reporter_email')->unique()` hydrates every matching reporter email into a PHP collection before deduplicating in-process; `Notification::route('mail', $email)->notify(...)` then runs synchronously per unique reporter inside the job's own 60s timeout. At Partna's scale (individual professional sitepages, not a mass-reporting platform), realistic case sizes are small, making this a defense-in-depth cleanup rather than an active risk.
    - **Plain English:** When staff resolve a moderation case, the system pulls every reporter's email into memory and emails them all in a simple loop within one job. For the kinds of report volumes this platform actually sees, that's fine — but pushing the "get unique emails" step to the database instead of doing it in code is a free, low-effort improvement.
    - **Evidence:**
        ```php
        $reporters = CaseSignal::query()
            ->where('case_id', $case->id)
            ->whereNotNull('reporter_email')
            ->pluck('reporter_email')
            ->unique();

        foreach ($reporters as $email) {
            Notification::route('mail', $email)->notify(new ReportOutcomeNotification($decision));
        }
        ```

## Suggested Bundled Sessions

- **Bundle 1 — Media pipeline job reliability:** #SCALE-1, #SCALE-8
    - **Why grouped:** Both are `app/Jobs/Process*VariantsJob.php` siblings with mechanical, low-risk fixes (backoff/tries value, streamed vs buffered read).
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

- **Bundle 2 — Moderation ops-command / job unbounded reads:** #SCALE-9, #SCALE-10, #SCALE-14
    - **Why grouped:** Same subsystem (moderation), same root-cause pattern (result-set handling on tables documented/expected to stay small); fixes are independent one-liners.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

- **Bundle 3 — Job queue routing / config / backoff hygiene:** #SCALE-3, #SCALE-4, #SCALE-5, #SCALE-6, #SCALE-7
    - **Why grouped:** All are small, independent `app/Jobs/` config-hygiene fixes (queue routing consistency, config fallback, backoff arrays, memory accumulation) with no shared state — safe to land in one sweep.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

- **Bundle 4 — Outbound vendor pacing:** #SCALE-2, #SCALE-13
    - **Why grouped:** Both address missing explicit rate-limiting on an outbound vendor API (mail provider, Cloudflare KV) that currently relies only on implicit Horizon worker-count caps.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

- **Bundle 5 — Design-analysis backfill hygiene:** #SCALE-11, #SCALE-12
    - **Why grouped:** Same feature (outside-website design-analysis backfill) — `BackfillWebsiteAnalysesCommand` is the dispatcher for `AnalyzeConnectionWebsitesJob`; fixing both in one session avoids re-testing the same flow twice.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

## Standalone — do NOT bundle

None.
