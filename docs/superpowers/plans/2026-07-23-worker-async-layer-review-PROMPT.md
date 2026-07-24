# PROMPT — Comprehensive review of the async/worker execution layer

> Paste this whole file as the opening message of a fresh session in the
> Comet-Backend repo (`/Users/joshuahunter/Herd/Side Street/backend`).
> Read-only review. Produce a report. Do not change code.

---

## Mission

Produce a single, evidence-backed report that answers four questions about
everything this backend runs **outside the request/response cycle**:

1. **How does it actually work?** — a complete, accurate map of the async layer
   as it exists today, not as the docs claim it exists.
2. **Is it correct?** — real defects: double-processing, lost jobs, stuck state,
   silent failure, unbounded growth, missing idempotency, lock bugs.
3. **Is the right work in the right place?** — what is done synchronously that
   should be a job, what is a job that shouldn't be, and what is in the wrong
   lane/queue/connection.
4. **How does it scale, and how do we make it faster?** — what breaks first at
   10× and 100× current load, ranked, with concrete remediation.

You must verify your claims against **current upstream documentation via web
research**, not from memory. Version-specific behaviour (Horizon balance
strategies, Redis reservation semantics, Laravel 12 queue internals, Laravel
Cloud worker model) has changed across releases and your training data may be
stale or wrong on the details.

---

## Non-negotiable rules

Read `CLAUDE.md` at the repo root first, then obey these:

- **Read-only.** No edits, no commits, no branches, no migrations. The output is
  a markdown report plus whatever the audit pipeline generates.
- **Findings come from the audit pipeline, not from your keyboard.** This repo's
  standing rule: any "audit / review / find bugs" task runs
  `scripts/audit/audit.sh`. Never hand-write a findings list. Phase 2 below tells
  you exactly which runs to issue. Architecture description, research, and the
  enhancement roadmap ARE hand-written — those are analysis, not findings.
- **Never run two `audit.sh` invocations at once.** Scans parallelise internally;
  adjudications are sequential and will corrupt each other.
- **Narrow beats broad.** Recall degrades past roughly 100K tokens of payload.
  Several tightly-scoped runs find strictly more than one wide sweep. Do not
  reach for `--codebase --bundle full-sweep`.
- **Logs come from the Cloud CLI only.** Local log files and the laravel-boost
  log tools return stale test-suite output.
  `cloud env:logs partna development --minutes 30`. The tools
  `mcp__laravel-boost__read-log-entries` and `mcp__laravel-boost__last-error`
  are forbidden.
- **Tests run on SQLite; production is Postgres.** A green suite does not prove a
  constraint-bound write works. Verify against the DDL in `supabase/migrations/`.
- **Verify every premise before you report it.** Grep for the symbol. Read the
  file. Check the migration. Generated and remembered claims routinely reference
  columns, flags, and classes that no longer exist. If you cannot cite
  `path/to/file.php:LINE`, you do not have a finding.

---

## Scope — what "worker" means here

**In scope (primary):**

| Layer | Where |
|---|---|
| Job classes | `app/Jobs/**` (~49 files incl. `Concerns/` traits) |
| Job middleware & traits | `app/Jobs/Concerns/`, `app/Jobs/Platforms/ThrottledByProvider.php`, `app/Jobs/Moderation/Concerns/` |
| Queue connections | `config/queue.php` (`redis`, `redis_video`, `redis_gdpr`, `redis_scraping`) |
| Worker topology | `config/horizon.php` (3 supervisors, `balance => false`, per-env `maxProcesses`) |
| Redis layout | `config/database.php` — DB 0 queue+Horizon, 1 cache, 2 sessions, 3 dormant, 4 cache locks |
| Scheduler | `routes/console.php` (~20 scheduled entries) |
| Console commands | `app/Console/Commands/**` (~34) — the reconcilers, pruners and sweepers that compensate for job failure |
| Services invoked from jobs | `app/Services/Media/`, `app/Services/Platforms/`, `app/Services/Streaming/`, `app/Services/Analytics/`, `app/Services/Cache/`, `app/Services/Notifications/` |
| Guard tests | `tests/Unit/Jobs/HorizonQueueCoverageTest.php`, `tests/Feature/Queue/JobHygienePolicyTest.php`, `tests/Feature/Console/HorizonScheduleTest.php`, `tests/Unit/MediaJobReliabilityTest.php`, `tests/Feature/Security/HorizonDashboardAuthTest.php` |
| Deployment | Laravel Cloud (`~/.composer/vendor/bin/cloud`), Horizon process, scheduler process |
| Docs to check for drift | `docs/deploy/queue-worker-cutover.md`, `docs/runbooks/drills/01-worker-kill.md`, `docs/runbooks/drills/03-redis-down.md` |

**In scope (secondary — flag drift only, don't audit deeply):**
`cloudflare-worker/src/index.js` — the *edge* Worker. It is a different thing
that shares the word "worker". The only questions that matter here: does
`SyncSubdomainToKvJob` remain the sole KV writer, do the KV key shapes and TTLs
written by the backend match what the edge Worker reads, and does the Worker
populate the Cache API explicitly (it must — `Cache-Control` alone does not
populate it). One short section; no lens run.

**Out of scope:** HTTP controllers except where they dispatch or *should*
dispatch; frontend; anything under `audits/`.

---

## Phase 0 — Orient (do this before anything else)

1. Read `CLAUDE.md`, then `AI_CONTEXT.md`.
2. Read `config/queue.php`, `config/horizon.php`, `config/database.php` (redis
   block), `routes/console.php` in full. These four files are the spine.
3. Read the header comments in `config/horizon.php` — there is real history
   encoded there (a 2026-07-22 dev OOM caused by supervisor-union semantics, and
   the reason `balance` must stay `false`). Understand it before you propose
   changing it.
4. Pull recent runtime reality:
   ```bash
   cloud env:logs partna development --minutes 60
   cloud environment:get development --json --fields=environmentVariables
   ```
   Compare the *configured* env vars against what `config/queue.php`,
   `config/horizon.php` and `config/database.php` actually read. Missing or
   mismatched `REDIS_*_DB`, `QUEUE_CONNECTION`, `REDIS_QUEUE_CONNECTION` values
   are a known historic source of trouble here.
5. Check the deployed process list — is Horizon actually running, is the
   scheduler actually running, on which env, at what memory ceiling.

Use subagents for the reading fan-out. Keep the synthesis in your own context.

---

## Phase 1 — Build the map (hand-written, this is documentation)

Produce these tables. They are the foundation for every later judgement, and
they are the deliverable Josh will re-read six months from now.

### 1a. Job inventory

One row per job class in `app/Jobs/**`:

| Job | Queue | Connection | `$tries` | `$timeout` | `$backoff` | `retryUntil` | `failOnTimeout` | Middleware (`WithoutOverlapping` / `RateLimited` / custom) | `ShouldBeUnique`? | `failed()` handler? | Dispatched from | Idempotent on replay? |

Resolve queue and connection **as actually resolved at runtime**, not as
declared — some are set by `$queue`/`$connection` properties, some by
`->onQueue()`/`->onConnection()` at the dispatch site, some by config lookup.
Where a job's lane depends on config, say which key.

### 1b. Lane topology

For each of the four Redis connections (`redis`, `redis_video`, `redis_gdpr`,
`redis_scraping`): its `retry_after`, which Horizon supervisor consumes it,
which queues ride on it, and the **maximum `$timeout` of any job that lands
there**. This table is where the single most dangerous class of queue bug shows
up (see Phase 2 invariants).

### 1c. Scheduler inventory

One row per `Schedule::` entry in `routes/console.php`:

| Entry | Cadence | `onOneServer` | `withoutOverlapping` TTL | Runs inline or dispatches to a queue? | Realistic runtime | What happens if it's skipped for a week |

Then a **wall-clock timeline** of the 03:00–04:00 block. Almost every daily
prune/purge is scheduled in that hour against the same Postgres instance. Show
the overlap.

### 1d. Failure & recovery map

For each job that mutates persistent state, trace what happens on failure:
which sweeper/reconciler command (if any) cleans up the orphaned state, and how
long the state stays wrong. `ReconcileStuckPreAccountBuilds`,
`CleanupStuckMediaProcessingCommand`, `SweepStaleExportsCommand`,
`SweepPurgedVideoArtifactsCommand`, `GcOrphanedVideoArtifactsCommand`,
`RetryUnavailableMenusCommand` exist for exactly this reason — map which failure
each one covers, and find the failures **nothing** covers.

### 1e. Dispatch-graph

Which jobs dispatch other jobs, which are chained, which fan out. Call out any
fan-out that creates an unbounded number of child jobs from a single parent
(`SendStaffBroadcastEmailsJob` → per-subscriber, `GeneratePreAccountSiteJob` →
platform seeders, enquiry dispatch → per-recipient). Note the fan-out factor.

---

## Phase 2 — Correctness findings (audit pipeline — do NOT hand-write these)

Run these **sequentially**, one at a time, waiting for each to finish. Each is
deliberately narrow.

```bash
# 1. Job/queue correctness over the job layer itself
scripts/audit/audit.sh \
  --category workers --name 2026-07-23-jobs-correctness \
  --lens "job & queue correctness: retry_after vs timeout invariants, idempotency on replay, lock-key correctness, failed() cleanup, serialized-model staleness, dispatch-inside-transaction races" \
  --scope app/Jobs/ --scope config/queue.php --scope config/horizon.php

# 2. Scheduler + reconciler correctness
scripts/audit/audit.sh \
  --category workers --name 2026-07-23-scheduler-correctness \
  --lens "scheduled-task correctness: withoutOverlapping TTL vs real runtime, onOneServer lock-store requirements, batch-unbounded prunes, missed-run recovery, timezone and cadence drift" \
  --scope routes/console.php --scope app/Console/Commands/

# 3. Scaling of the async layer (graded against a 10k-user target)
scripts/audit/audit.sh \
  --category workers --name 2026-07-23-worker-scaling \
  --bundle scale-health \
  --scope app/Jobs/ --scope config/queue.php --scope config/horizon.php --scope routes/console.php

# 4. The heavy lanes — media + scraping services the jobs call into
scripts/audit/audit.sh \
  --category workers --name 2026-07-23-media-scraping-lanes \
  --lens "resource-bound worker paths: memory and wall-clock ceilings in image/video/scrape processing, unbounded downloads, missing streaming, temp-file leaks, vendor-timeout propagation, retry amplification against third-party APIs" \
  --scope app/Services/Media/ --scope app/Services/Platforms/ --scope app/Jobs/Platforms/ \
  --scope app/Jobs/ProcessImageVariantsJob.php --scope app/Jobs/ProcessVideoVariantsJob.php \
  --scope app/Jobs/ProcessLogoVariantsJob.php --scope app/Jobs/DeleteMediaArtifactsJob.php
```

Output lands in `audits/workers/<date>-<name>/CONSOLIDATED.md` (targeted) and
`audits/sweeps/<date>-<name>/CONSOLIDATED.md` (bundle). Reconcile the **total
finding count against the union of IDs** before you call a run complete — counts
and bundles have disagreed before.

### Invariants to confirm are actually checked

The lenses are good but generic. After the runs complete, personally verify
these repo-specific invariants and note in your report whether the pipeline
caught each one. If it missed one, that is itself a finding about the guard
tests:

1. **`retry_after` > max `$timeout`, per lane.** For every connection: the
   Redis reservation window must exceed the longest job that rides it, plus
   margin. If it doesn't, Redis hands a still-running job to a second worker and
   it executes **twice**. Check `redis` (360s) against every job on
   `default`/`notifications`/`mail`/`images`/`cloudflare`/`cache-warm`/
   `analytics`/`streaming`/`platform_refresh`/`platform_connect`/`moderation_high`;
   `redis_video` (3600s) against `ProcessVideoVariantsJob`; `redis_gdpr` (660s)
   against `ExportUserDataJob`; `redis_scraping` (660s) against every scrape job.
2. **Horizon supervisor `timeout` > job `$timeout`.** Horizon SIGKILLs on its own
   clock. If the supervisor timeout is shorter, jobs die mid-write with no
   `failed()` call.
3. **Every dispatched queue name is consumed by a supervisor.** A typo or a
   config-derived queue name that no supervisor lists means jobs enqueue and are
   never run — silently, forever. `HorizonQueueCoverageTest` claims to prove
   this; verify it handles *config-derived and dynamic* queue names, not just
   literals.
4. **`after_commit` is `false` on every connection.** So any dispatch inside a
   `DB::transaction()` can execute before the commit lands and read stale/absent
   rows. Find every such dispatch and confirm it uses `->afterCommit()` at the
   dispatch site. Note: a job must **never** declare `public bool $afterCommit`
   as a typed property — that is a silent fatal in this codebase.
5. **`WithoutOverlapping` keys match across producer and consumer.** A key
   built one way in a job and another way in a scheduler command means the lock
   never overlaps and the guard is decorative. This class of bug has shipped here
   before (`ScheduledRefresh`).
6. **`onOneServer` and `WithoutOverlapping` need a shared, atomic lock store.**
   Redis DB 4 (`REDIS_CACHE_LOCKS_DB`). Confirm the deployed env actually sets
   it and that the lock store isn't falling back to `array`/`file`, which makes
   every one of those guards a no-op across processes.
7. **`RateLimited` release consumes an attempt.** Any job using it needs
   `tries = 0` plus `retryUntil()`, or throttling silently exhausts retries and
   the work is dropped. The pre-account scraping jobs got this right — check
   every other rate-limited job.
8. **`SerializesModels` + soft/hard deletes.** A queued job holding a model that
   is deleted before execution throws `ModelNotFoundException` and burns all
   tries. Check `deleteWhenMissingModels` on jobs whose subject can be deleted
   mid-flight (deletion, moderation, pre-account expiry paths).
9. **`Cache::flush()` must never touch DB 0.** It issues a raw `FLUSHDB`, which
   would wipe live Horizon/queue state. Confirm the cache store is pinned to
   DB 1 in every deployed env, and that no code path flushes on the default
   connection.

---

## Phase 3 — Online research (this is a hard requirement, not optional)

Do not answer version-specific questions from memory. Fetch current docs and
cite URLs in the report. At minimum:

**Framework & package behaviour**
- Laravel 12 queue documentation — `retry_after` vs `--timeout` semantics, job
  middleware, `Bus::batch`, unique jobs, `afterCommit`, backoff arrays,
  `failOnTimeout`, encrypted payloads.
- Laravel Horizon 5.x (repo pins `^5.45`) — the exact semantics of
  `balance => false | simple | auto`, `autoScalingStrategy` (`time` vs `size`),
  `maxProcesses` / `minProcesses` / `balanceMaxShift` / `balanceCooldown`,
  `memory`, `nice`, `trim`/`snapshot` retention, `waits` thresholds. Confirm or
  correct the claims encoded in this repo's `config/horizon.php` comments —
  specifically the claim that `balance => false` is the only strategy that
  respects `maxProcesses`.
- **predis vs phpredis.** This repo runs `predis/predis ^3.3` (pure PHP). Find
  current guidance and benchmark data on the throughput/latency/CPU difference
  under Horizon, whether Laravel Cloud provides the `phpredis` extension, and
  what migrating would cost. This is probably the single highest-leverage
  performance question in the review — treat it seriously and get numbers.
- Check `laravel/horizon ^5.45`, `laravel/nightwatch ^1.24`, `predis/predis ^3.3`
  for known bugs, advisories, or behaviour changes in releases newer than the
  pinned range.

**Redis operational reality**
- Redis reservation/visibility semantics for Laravel's Redis driver: how
  `retry_after` interacts with the reserved ZSET, and the documented failure mode
  when it's too low.
- Redis persistence (RDB vs AOF) and what job loss looks like on restart or
  failover. What does the managed Redis behind Laravel Cloud actually guarantee?
  Find the documented durability model.
- `block_for` (currently `null` on all four connections) — what it does to poll
  latency and Redis command volume, and its known interaction with Horizon.
- Redis `maxmemory-policy` — an eviction policy other than `noeviction` on a
  Redis holding queue data silently **deletes jobs**. Find what Laravel Cloud's
  Redis defaults to and what's recommended.

**Platform**
- Laravel Cloud worker/Horizon deployment model: process sizing, memory limits,
  graceful restarts on deploy (`horizon:terminate`), SIGTERM handling for
  long-running jobs, whether scheduler and worker share a container, autoscaling
  behaviour and its cost model.
- Laravel Nightwatch job/queue monitoring: exactly which queue signals it
  auto-detects (slow jobs, failures, backlog?), and what it does **not** see.
  Alerts here fire on issues, not on log queries — establish precisely what's
  covered so the observability gaps are real gaps.

**Domain-specific**
- Current best practice for **video transcoding in a PHP worker** vs offloading
  to a dedicated service (Cloudflare Stream, Mux, ffmpeg-in-Lambda). Get real
  cost and operational comparisons — this repo runs ffmpeg inside the queue
  worker today.
- Current best practice for **image variant generation** at scale: in-worker
  (GD/Imagick) vs on-the-fly CDN transforms (Cloudflare Images / Image
  Resizing). Note that this stack already fronts everything with Cloudflare.
- Scraper-fleet patterns: politeness/backoff, per-vendor concurrency caps,
  circuit breakers, and the resilience implications of running scrapes in the
  same Redis-backed queue as user-facing notifications.

Where research **contradicts** something in this repo's config comments, docs,
or `CLAUDE.md`, say so explicitly and cite both sides. Where it **confirms** a
deliberate choice, say that too — Josh needs to know which constraints are real.

---

## Phase 4 — The four judgement calls

This is the part a lens can't do for you. Answer each concretely, with named
files and line numbers.

### 4a. What is synchronous that should be a job?

Walk the request-path code for work that blocks a user's HTTP response and
doesn't need to. Prime suspects, all confirmed to make outbound HTTP or heavy
work inside controllers:

- `app/Http/Controllers/Api/Platforms/*` — Fresha, Google Business, Instagram,
  Menu, Shop, Booking, Reservations, Apple, Refresh, Generic. Which of these
  call a scraper or vendor API **inline** during a dashboard request? What is
  the p99 when the vendor is slow? What does the user see when it times out?
- `app/Http/Controllers/Api/User/Uploads/UserUploadController.php` — what
  happens inline vs queued on upload.
- `app/Http/Controllers/Api/User/Customers/UserEnquiryController.php` — enquiry
  submission is a public, unauthenticated path; anything synchronous there is
  both a latency and an abuse-surface problem.
- `app/Http/Controllers/Api/User/SiteManagement/CustomDomainController.php`,
  `SubdomainAvailabilityController.php` — DNS/Cloudflare calls in-request.
- `app/Http/Controllers/Api/User/Account/UserAccountDeletionController.php`,
  `UserSelfController.php`, `Content/ContentController.php`.

For each: is the synchronous call load-bearing for the response (the user needs
the result *now*), or is it fire-and-forget that should be a job? Where the user
does need a result, is there a better shape — cached read, optimistic response +
async reconcile, poll endpoint (the media upload path already uses a
`processing_state` poll — is that pattern reused where it should be)?

### 4b. What is a job that shouldn't be?

The inverse. Look for:
- Jobs whose entire body is a couple of cheap DB writes — queue overhead
  (serialize → Redis round-trip → deserialize → DB) exceeds the work. Doing it
  inline would be faster *and* remove a failure mode.
- Jobs dispatched and then immediately awaited, or `dispatchSync`'d in
  production paths. Confirmed sites: `MediaUploadService.php:443-476,549`
  (documented conditional-sync fallback — assess whether the fallback can fire in
  production and what that does to request latency),
  `BackfillUserKvEntries.php:55`, `BackfillSubdomainKvCommand.php:45` (backfill
  commands — probably fine, confirm).
- Scheduled entries using `Schedule::job()` that dispatch to a queue when they'd
  be better run inline in the scheduler process, or vice versa
  (`AggregateCacheMetricsJob`, `CheckStreamingLiveStatusJob`).
- Anything queued that is genuinely time-critical, where queue depth introduces
  unacceptable latency (live-status polling every two minutes behind a queue
  that may be backed up — what's the real staleness ceiling?).

### 4c. What is in the wrong lane?

`supervisor-1` consumes **eleven** queues in a single process at
`maxProcesses` 2 (dev/prod) / 3 (staging tier), with `balance => false`, which
means strict listed-priority draining. Interrogate that:

- One slow job on `moderation_high` or `platform_connect` blocks **every** other
  queue in that supervisor, including `mail` and `notifications`. Quantify: what
  is the longest-running job in `supervisor-1`'s list, and what is the resulting
  worst-case delay for a transactional email?
- Strict priority order + a saturated high-priority queue = **starvation** of
  everything below it. `platform_refresh` and `platform_connect` sit last. Under
  an hourly `integrations:refresh` fan-out, do they ever drain?
- Is the queue **order** in `config/horizon.php:125` actually the intended
  priority order? Justify each position.
- Should user-facing transactional mail have its own supervisor/process so it is
  never behind bulk work? Weigh that against the documented memory ceiling and
  the 2026-07-22 OOM history — this box cannot afford unlimited processes, and a
  supervisor defined in `defaults` runs in **every** environment.
- Are the four connections carved along the right axis? The config comments say
  lanes map 1:1 to `retry_after` tiers, not to workloads. Is that still the right
  decomposition given the actual job mix?

### 4d. Performance — per avenue

Go through each processing avenue and answer: where does the time go, where does
the memory go, what is the ceiling, and what's the highest-leverage improvement.

**Video** (`ProcessVideoVariantsJob`, `VideoVariantService`, `redis_video`,
`supervisor-videos`, `retry_after` 3600)
- ffmpeg invocation: how many passes, what presets, is it CPU-bound in the same
  container as everything else? Does one encode starve the whole box?
- Memory ceiling vs Horizon `memory` setting vs the container limit.
- Temp-file lifecycle — are intermediates always cleaned up, including on
  timeout/SIGKILL? (`GcOrphanedVideoArtifactsCommand` and
  `SweepPurgedVideoArtifactsCommand` exist — what do they tell you about the
  failure rate?)
- Is transcoding in a PHP queue worker the right architecture at all, or should
  this be Cloudflare Stream / Mux / a dedicated encoder? Cost it with Phase 3
  research. `maxProcesses 1` for videos means a **serial** encode queue — model
  the throughput ceiling in videos/hour and say when it breaks.

**Image / logo** (`ProcessImageVariantsJob`, `ProcessLogoVariantsJob`,
`ImageVariantService`, `ImagePaletteExtractor`, `LogoProcessorClient`, queue
`images`, riding `supervisor-1`)
- Variant count per upload × cost per variant = the real per-upload cost. Is it
  fanned out or serial?
- Decompression-bomb protection: is there a pixel-dimension ceiling before
  decode, not just a byte-size ceiling? (`GuardsMediaProcessing` — check what it
  actually guards.)
- `ImagePaletteExtractor` — full decode just to get colours? Can it sample?
- Would Cloudflare Images / on-the-fly resizing eliminate this lane entirely?
  What would that cost, and what would it break?
- `LogoProcessorClient` calls an external service — timeout, retry, and what
  happens to the upload when it's down.

**Scraping** (`app/Jobs/Platforms/*`, `app/Services/Platforms/*`,
`redis_scraping`, `supervisor-long`, `ThrottledByProvider`,
`ThrottlesPreAccountScraping`)
- Per-vendor concurrency and rate limits: is every vendor covered, or only the
  two pre-account jobs? Enumerate the vendors (Instagram/Apify, Google
  Business/Places, Fresha, DoorDash, Bandcamp, BigCartel, Eventbrite, Humanitix,
  Apple, generic shop/link-card/link-in-bio, menu HTML/PDF/photo) and map each to
  its throttle — or to nothing.
- **Google Places is the only uncapped paid API in this stack.** Trace every path
  that can call it from a job and confirm there's a spend ceiling, not just a
  rate limit. A retry storm here costs real money.
- Retry amplification: a vendor 500 that triggers N retries × M jobs × K users.
  Model the worst case.
- Circuit breaker: when a vendor is down, does the system back off globally or
  keep hammering per-job?
- HTML/PDF parsing memory ceilings; unbounded download sizes; SSRF surface on
  user-supplied URLs (`ScanPreviousWebsiteContentJob`, `LinkInBioScanJob`,
  `WebsiteMenuHtmlScanJob`, `WebsiteMenuPdfScanJob`, `EnrichLinkCardJob` all take
  URLs that may originate from user input — confirm the SSRF guard and whether it
  survives redirects).
- `MenuAiExtractor` — is an LLM call in the job path? Token cost, timeout, and
  failure mode.
- `supervisor-long` runs `scraping` and `gdpr` on **one** process. A long GDPR
  export blocks all scraping and vice versa. Quantify.

**Notifications / mail** (`app/Jobs/Notifications/*`, queues `mail` +
`notifications`)
- Fan-out shape for broadcasts: `SendStaffBroadcastEmailsJob` →
  `SendStaffBroadcastEmailToSubscriberJob` per subscriber. Bounded? Batched?
  Should it be `Bus::batch` for progress/cancellation?
- Resend rate limits and the suppression gate — does a suppressed/bounced
  address burn a job attempt?
- Provisional (pre-account) users have **no email**;
  `routeNotificationForMail()` is nullable. Every notification path must tolerate
  null. Verify across all of them.
- Is transactional mail (enquiry confirmation, claim invite) ever queued behind
  bulk broadcast? See 4c.

**Analytics** (`RecordAnalyticsEventJob`, queue `analytics`, `QueuedIngestor`)
- Per-event job, or batched? A job per pageview is a scaling wall. What is the
  events/sec ceiling before the queue outruns the worker?
- `site_metrics_daily`/`_hourly` rollup tables are **never populated** — all
  reads compute from raw events. Confirm this is still true and state the
  consequence for read latency as raw event volume grows.
- Raw-event purge is a single daily partition-scoped DELETE at 03:00 — does it
  stay bounded?

**Cache & edge** (`WarmPublicSiteCacheJob`, `AggregateCacheMetricsJob`,
`CloudflareCachePurgeJob`, `SyncSubdomainToKvJob`, `HasCloudflareRetryPolicy`)
- Stampede protection on warm; uniqueness (`WarmPublicSiteCacheJobUniquenessTest`
  exists — does it cover the real key?).
- Cloudflare API rate limits vs purge/KV-write volume; per-account limits are
  global, so a bulk operation can throttle unrelated purges.
- Ordering: KV write vs cache purge vs DB commit. A purge that lands before the
  commit re-caches stale content.
- `SyncSubdomainToKvJob` is the **only** permitted KV writer — verify no other
  path writes KV.

**GDPR / deletion** (`ExportUserDataJob`, `redis_gdpr`, deletion cascade)
- Export streams or buffers? Memory ceiling on a large account.
- Deletion never soft-deletes; both `forceDelete` site cascades run **before**
  the KV job, so a custom domain must be captured first. Verify that ordering
  still holds and that a mid-cascade job failure can't leave a site live in KV
  after the DB row is gone.

**Moderation** (`app/Jobs/Moderation/*`, queue `moderation_high`)
- It's first in `supervisor-1`'s priority list — so it can starve everything
  else. Is the volume bounded enough that this is safe?
- `HasActionLogLifecycle` — is the action log written before or after the effect?
  What does a crash between them leave behind?

**Pre-account** (`GeneratePreAccountSiteJob`, `ApproveEarlyAccessBuildJob`)
- Multi-vendor fan-out per build. Partial-failure semantics: if 2 of 5 sources
  fail, is the build usable, retried, or stuck? (`builds:reconcile-stuck` runs
  hourly — what's the real stuck window?)
- One LIVE build per source is enforced by a partial unique index. What happens
  to the job when that constraint fires?

---

## Phase 5 — Enhancement & scalability roadmap

Model the async layer at **current load**, **10×**, and **100×**. State your
load assumptions explicitly (users, uploads/day, enquiries/day, events/sec,
scrape refreshes/hour) and derive them from the code and config, not from
imagination — say where each number came from and mark the guesses as guesses.

For each tier, identify the **first thing that breaks** and why. Then produce a
prioritised table:

| # | Change | Problem it solves | Tier (pilot / launch / scale) | Effort (S/M/L/XL) | Risk | Blast radius | Reversible? |

Cover at minimum:
- Redis driver (predis → phpredis) — with the numbers from Phase 3.
- `block_for` tuning and its effect on poll latency and Redis command volume.
- Supervisor/lane restructuring, weighed honestly against the memory ceiling and
  the OOM history.
- Batching (`Bus::batch`) for fan-outs that currently have no progress,
  cancellation, or completion signal.
- Offloading video and/or image processing off the PHP worker entirely.
- Analytics event batching.
- Backpressure: what happens when a queue's depth exceeds what workers can drain,
  and whether anything currently notices.
- Observability gaps: what would page someone at 3am today, and what would fail
  silently. Be specific about which Nightwatch/Horizon signal covers which
  failure, and which failures nothing covers.
- Redis as a single point of failure — durability, failover, and what job loss
  actually looks like operationally.
- Multi-server readiness: `onOneServer` and lock-store assumptions if the app
  ever runs more than one box.

Every recommendation must state what it would break or cost, not just what it
buys. Recommendations that contradict a documented deliberate choice in
`config/horizon.php`, `CLAUDE.md`, or `docs/deploy/queue-worker-cutover.md` must
say so and argue the case explicitly.

---

## Deliverable

Write to `docs/reviews/2026-07-23-worker-async-layer-review.md`:

1. **Executive summary** — max 15 lines. The three things that matter most.
2. **Verdict table** — each area (video / image / scraping / notifications /
   analytics / cache-edge / gdpr / moderation / pre-account / scheduler /
   topology) rated Healthy / Watch / At-risk / Broken, one line of justification
   each.
3. **The map** — Phase 1 tables in full.
4. **Findings** — the pipeline output, deduplicated across the four runs, ranked
   P0→P3, each with: Plain English, Technical, `file.php:LINE` evidence, and the
   concrete failure scenario (inputs/state → wrong outcome). Link to the
   `audits/` folders rather than pasting everything. **Reconcile the total count
   against the union of IDs.**
5. **Right-work-right-place** — Phase 4a/4b/4c, as tables.
6. **Performance per avenue** — Phase 4d.
7. **Research findings** — Phase 3, with URLs, and a clearly marked subsection:
   "Where current docs contradict this repo's assumptions".
8. **Roadmap** — Phase 5 table.
9. **Open questions for Josh** — decisions you can't make from the code.

### Evidence discipline

- Every claim about behaviour cites a file and line, a log excerpt, or a URL.
- Every claim about upstream behaviour cites a fetched doc, not memory.
- Anything you could not verify goes in an explicit **"Unverified — needs a
  runtime check"** list. Do not pad the report with plausible-sounding findings.
  A short report of confirmed problems is worth far more than a long one of
  maybes.
- If a guard test *claims* to cover an invariant, read the test and confirm it
  actually does. A passing test that asserts the wrong thing is itself a P1.
