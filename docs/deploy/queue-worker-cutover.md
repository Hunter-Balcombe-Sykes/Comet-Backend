# Queue worker cutover — sync → Horizon readiness

**Compiled:** 2026-07-20 · **Status:** pre-cutover · **Sibling doc:** [`production-cutover.md`](./production-cutover.md)
**Re-verified 2026-07-22** against live dev env + post-07-20 code delta — see §9 for item status and new findings.

## Why this document exists

The deployed dev environment runs `QUEUE_CONNECTION=sync` with zero Horizon masters, so
every `ShouldQueue` job executes inline in the web request. Real Redis-backed workers are
being provisioned. This document inventories everything that changes, breaks, or silently
starts working when that flip happens.

**Headline finding: the codebase is already built for this migration.** Across all 41 job
classes there are zero serialization violations, zero transaction races, and zero
request-scoped state reads in `handle()`. Two sweep tests already enforce the discipline
(`tests/Feature/Queue/JobHygienePolicyTest.php` for retry config,
`tests/Unit/Jobs/HorizonQueueCoverageTest.php` for supervisor coverage and
`uniqueFor > timeout`). Job comments reference prior incidents JOB-2/4/101/102/103 and
TXN-101 that map directly onto these hazard categories — this cutover was anticipated and
largely pre-fixed.

The real work is concentrated in three small buckets: two documentation/config items, two
minor code fixes, and four connect flows that were never job-ified in the first place.

---

## Two framings, both necessary

Most of this analysis answers *"what breaks when we flip?"* — but that framing alone misses
a whole class of problem. Sync mode does not merely defer async bugs; it **creates its own**.

- `SyncQueue::later()` discards the delay argument entirely, so `->delay()` is a no-op today
  (finding **A1**). The anti-burst guard on our only uncapped paid API has never functioned.
- The documented singleton-upload soft-delete race is *widened* by sync, because image
  processing runs inline inside the vulnerable window
  (`app/Services/Media/MediaUploadService.php:205`).

Both are cases where the code is correct **for workers** and wrong under sync. Turning
workers on will fix bugs we did not know we had. Do not read a disappeared symptom as a
fixed bug — in the singleton-upload case the window narrows but does not close.

### `dispatchSync()` vs the sync driver

These are not the same thing, and the distinction determines the blast radius.

| | Path | Exercised today? |
|---|---|---|
| `Job::dispatch()` under `QUEUE_CONNECTION=sync` | Routes through Laravel's `SyncQueue` — real dispatch lifecycle, container resolution, job lifecycle events. Only serialization is skipped. | Yes |
| `Job::dispatchSync()` | Bypasses the queue driver entirely. A same-process `handle()` call. No driver, no push, no lifecycle. | No |

Consequence: **most jobs already execute through the real dispatch machinery**, so the flip
barely changes their code path. Only four jobs go from "never touched the queue" to "running
under Horizon" on day one (see §4).

---

## 1. Active bugs today

Sync mode is causing these *right now*. Both **A1** and **A2** self-correct on the flip with
no code change.

### A1 — `->delay()` is silently discarded · self-fixes on flip

**Where:** `app/Console/Commands/RetryUnavailableMenusCommand.php:55`

```php
// Stagger so a full run doesn't hit the scraping queue / Apify all at
// once — spread across the window instead of a single burst.
MenuFetchJob::dispatch((string) $menu->user_id, true)
    ->delay(now()->addSeconds($i * $stagger));
```

**Verified** against `vendor/laravel/framework/src/Illuminate/Queue/SyncQueue.php`:

```php
public function later($delay, $job, $data = '', $queue = null)
{
    return $this->push($job, $data, $queue);   // $delay discarded
}
```

Up to 50 forced re-scrapes currently fire back-to-back with no pacing. Google Places is the
only uncapped paid API on the platform, so this is the burst guard that matters most. The
comment at lines 53–54 describing the pacing is currently fiction.

Failure signature worth internalising: **no error, no log line, no test failure.** This is
invisible to any audit framed only as "what breaks when we flip".

### A2 — scheduler fan-out runs serially in the cron tick · self-fixes on flip

**Where:** `app/Console/Commands/RefreshIntegrationConnectionsCommand.php:37`

The command is commented *"the heavy work is on the queue, not here"* — untrue today. Under
sync, every due connection's refresh scrape runs serially inside the single hourly cron
process. Two consequences: long tick runtimes, and a crash mid-tick drops the remaining
fan-out until the next hour.

### A3 — Redis DB numbering documented wrong · **fix now**

**Where:** `CLAUDE.md:46` and `docs/runbooks/drills/01-worker-kill.md`

**Verified** against `config/database.php` and `.env.example`:

| DB | Actual | `CLAUDE.md:46` claims |
|---|---|---|
| 0 | **default — queue traffic + Horizon** | cache |
| 1 | cache | sessions |
| 2 | sessions | **queue** ❌ |
| 3 | dormant queue-override slot | — |
| 4 | cache locks | — |

The subtlety: `REDIS_QUEUE_DB=3` is defined but unused. `config/queue.php:72` sets
`'connection' => env('REDIS_QUEUE_CONNECTION', 'default')` and `config/horizon.php:12` sets
`'use' => 'default'`, so both queue traffic and Horizon land on **DB 0**. DB 3 only activates
if someone explicitly sets `REDIS_QUEUE_CONNECTION=queue`.

Why this is worth fixing before the flip: the drill runbook instructs an operator to run
`redis-cli -n 2 --scan --pattern '*queues:cloudflare*'` during a worker incident. That
inspects **sessions**, returns nothing, and reads as "the queue is empty" — under exactly the
pressure where a wrong conclusion is most expensive. A wrong doc is worse than a missing one.

---

## 2. Must settle before flipping

### B1 — no worker/scheduler topology is declared in this repo

No `Procfile`, no `.laravel-cloud.yml`, no `horizon`/`schedule:run` invocation in
`composer.json`, `.github/workflows/`, or `deploy/`. The only queue reference in
`composer.json` is the local-dev `dev` script (`queue:listen`), irrelevant to the deployed
env. "Turn on workers" is entirely a Laravel Cloud dashboard action this repo cannot see or
assert on.

**This is the one that can make things worse.** Setting `QUEUE_CONNECTION=redis` *without* a
provisioned worker is strictly worse than today's state: jobs enqueue to Redis and nobody
drains them — a silent, unbounded backlog instead of inline execution.

Checklist:

- [ ] Confirm in the Cloud dashboard that a Horizon/worker process is **provisioned and
      running**, not merely that `QUEUE_CONNECTION=redis` is set.
- [ ] Confirm the scheduler cron is enabled.
- [ ] After flipping, hit `GET /api/health/scheduler` (backed by
      `RecordScheduledTaskHeartbeat`) to prove the scheduler is actually ticking rather than
      that the app merely booted.

`docs/deploy/production-cutover.md` already flags this as a manual, easy-to-miss step
("**prod must not inherit** dev's `queue=sync` + 0 masters").

### B2 — Horizon dashboard is unauthenticated outside production · **FIXED IN CODE 2026-07-22**

**Where:** `app/Providers/AppServiceProvider.php::authorizeHorizonRequest()`

The gate now opens only for `local`/`testing`; **every deployed env** (development
included) follows the credential gate:

| Condition | Behaviour |
|---|---|
| `local` / `testing` | Always allowed |
| Deployed env, no dashboard credentials set | Sealed (403) |
| Deployed env, both credentials set | HTTP Basic, constant-time compare |

Rationale: `dev-api.partna.au/horizon` was confirmed publicly reachable (HTTP 200,
2026-07-22) and once real workers process jobs, `/horizon` displays **live job
payloads** — GDPR export IDs, email addresses, connection IDs — alongside a retry
button. "Non-prod" stopped being a safe proxy for "private network".

- [x] Gate fixed — `HorizonDashboardAuthTest` pins the new matrix.
- [ ] Set `HORIZON_DASHBOARD_USERNAME` / `HORIZON_DASHBOARD_PASSWORD` on the **dev**
      env (else `/horizon` is sealed after the next deploy — safe, but unusable).

---

## 3. Small code fixes

### C1 — `ReconcilePlatformTakedownJob` has no `failed()` handler

**Where:** `app/Jobs/Platforms/ReconcilePlatformTakedownJob.php`

Has `$tries`, `$backoff`, and `$timeout`, but no `failed()` handler. A staff kill-switch
takedown that exhausts its retries mid-sweep fails silently into `failed_jobs` with no
Nightwatch breadcrumb — leaving a **partially applied takedown** (some connections flipped
`is_active = false`, some not) that nobody is told about. Low technical severity (pure DB
flips, idempotent by construction), but the silence is the problem: this is a compliance
control.

### C2 — `SendStaffBroadcastEmailToSubscriberJob` has caller-owned queue assignment

**Where:** `app/Jobs/Notifications/SendStaffBroadcastEmailToSubscriberJob.php`

Declares no `$queue` or `onQueue()` of its own; it inherits `mail` from the caller's
`Bus::batch()->onQueue()` in `SendStaffBroadcastEmailsJob`. Correct today — that is the only
construction site. But any future direct `::dispatch()` (retry tooling, a new caller) silently
lands on the bare `default` queue **and** loses the batch's `allowFailures()` semantics.
Idempotency itself is fine (`insertOrIgnore` PK guard).

---

## 4. Watch on day one — no defect, just never executed

These four are the **only** jobs that go from never-touching-the-real-queue to running under
Horizon on day one. Everything else already routes through `Job::dispatch()` even under sync,
so its code path is essentially unchanged by the flip.

| Job | Queue | Why it is structurally different today |
|---|---|---|
| `Analytics\RecordAnalyticsEventJob` | `analytics` | **Never instantiated at all.** `AppServiceProvider:122-131` binds `AnalyticsIngestor` to `SyncIngestor` when `queue.default === 'sync'`; the job is bypassed by a hand-written parity shim. `QueuedIngestor`'s own docblock notes its fault escalation is *"latent until a queue worker is provisioned"*. |
| `ProcessImageVariantsJob` | `images` | Reached via `MediaUploadService::dispatchWithSyncFallback()` (`:449`) → `dispatchSync()`, bypassing the driver. |
| `ProcessLogoVariantsJob` | `images` | Same path. |
| `ProcessVideoVariantsJob` | `videos` (`redis_video`) | Same path (`:542`). Heaviest of the three — currently blocks the upload request entirely. |

None has a code-level defect: constructors are scalar-only, locks are Redis-native via the
shared `GuardsMediaProcessing` trait, idempotency and backoff check out. The untested surface
is the *integration*: container DI resolution inside a worker process, the real
serialize/unserialize round-trip, and Horizon's own timeout/SIGKILL machinery.

**Action:** monitoring posture, not a code change. Watch the `analytics`, `images`, and
`videos` queues on the first deploy.

Also re-verify at that point: the singleton-upload race
(`MediaUploadService::purgeExistingSingleton`, `:205`, tracked in
`docs/superpowers/plans/2026-07-20-singleton-upload-race-PROMPT.md`). Workers narrow its
window but **do not close it** — it is a pre-existing design issue, not a queue-mode artefact.

---

## 5. Genuinely neglected — inline external HTTP that should be jobs

Separate from the cutover: these connect flows block the HTTP request on external calls,
while their siblings (Instagram, menu fetch) already route through jobs. This is latency and
reliability we are leaving on the table, and it does **not** self-fix when workers turn on.

Ranked by impact:

1. **`ShopController::addBrand`** — `app/Http/Controllers/Api/Platforms/ShopController.php:115`
   → `ShopProviderDetector::detectDetailed()` (`ShopProviderDetector.php:70-119`).
   Chains up to **5 sequential** external HTTP probes (Shopify origin + probe, WooCommerce,
   Squarespace discovery, then a full generic page fetch), each with its own timeout, all
   serialized on one PHP-FPM worker. Worst case is an unsupported or unreachable store — the
   single most common outcome when a user pastes an arbitrary URL — which walks the entire
   chain before failing. The code's own comment concedes the cost: *"Detection + scrape run
   outside the lock (slow external HTTP)."* No job exists.
   **Suggested shape:** mirror `InstagramConnectJob` — return a pending placeholder, dispatch,
   poll for status.

2. **`GoogleBusinessController::connect`** —
   `app/Http/Controllers/Api/Platforms/GoogleBusinessController.php:101`
   → `GoogleBusinessService::fetchPlaceDetails()` (`:124-177`).
   Blocks the connect response on a Places Details call (5s timeout, 2 retries), then a
   **pooled** photo-media resolution (`resolvePhotoUrls`, `:351-405`, chunked at
   `google_places.pool_concurrency`, default 5, each with a 5s timeout), then a Street View
   metadata probe (`:414+`). Notably the *slower* Apify enrichment step is **already** deferred
   to `GoogleBusinessEnrichJob` (`:138-140`) — this chunk is the same shape of problem, simply
   not deferred yet.

3. **`EventsPlatformController::addAccount` / `addStandaloneEvent`** —
   `app/Http/Controllers/Api/Platforms/EventsPlatformController.php:68,115`
   (shared by `EventbriteController` and `HumanitixController`).
   Each does one organiser-page fetch **plus a pooled `fetchMany()` of ~8–10 event-detail
   pages** (`EventbriteScraper.php:59-94`, `HumanitixScraper.php:94-147`), entirely inline, on
   every connect. No job exists for either platform.

4. **`FreshaController`** — `app/Http/Controllers/Api/Platforms/FreshaController.php:70,116,139,148-149,185-186`.
   Four endpoints (`connect`, `team`, `saveSelection`, `employeeServices`) each perform a live
   synchronous HTML scrape of fresha.com (fetch + `__NEXT_DATA__` parse, plus a booking-GraphQL
   call for per-employee services), with **no job at all**. Gated behind a real
   `can_use_booking` capability, so this is live traffic, not dead code. The controller's own
   docblock (`:28-30`) states the promotion plan is extraction into a job-backed flow.

**Lower priority, noted for completeness:**

- `CustomDomainController.php:79,103,130,201` — inline Cloudflare-for-SaaS custom-hostname
  calls. Weak candidate: low frequency, explicitly user-initiated, and the user needs the
  CNAME/status back synchronously. Failures are already caught and reported gracefully. The
  `destroy()` teardown call (`:201`) has no visible timeout override and blocks the DELETE.
- `UserUploadController.php:324` — `ImageVariantService::deleteVariants()` runs inline in
  `destroy()` for images (video already uses `DeleteMediaArtifactsJob` at `:321`). Deliberate
  per its docblock ("only 2-3 variant files"). Not worth changing.

---

## 6. Verified clean — bank these

- **Serialization:** no job accepts a bare Eloquent model, closure, `UploadedFile`, or resource
  handle. All constructors take scalars, UUID strings, or arrays. All 41 use `SerializesModels`
  defensively even where unnecessary.
- **Transaction races:** none. The `public bool $afterCommit` typed-property fatal is worked
  around consistently (untyped trait property assigned in the constructor). Every
  dispatch-inside-`DB::transaction()` site traced — enquiries, moderation decisions, GDPR
  export, pre-account builds, account deletion, email subscriptions, staff broadcasts — uses
  `->afterCommit()`, `DB::afterCommit()`, or `ShouldQueueAfterCommit`.
- **Request-scoped state:** no job reads `request()`, `Auth::`, `session()`, or `auth()->` in
  `handle()` or its constructor.
- **Inline job invocation:** no `(new SomeJob(...))->handle()` pattern exists anywhere.
- **Queue coverage:** all 14 dispatched queue names — `default`, `images`, `scraping`,
  `platform_refresh`, `platform_connect`, `cache-warm`, `cloudflare`, `videos`, `gdpr`,
  `notifications`, `mail`, `analytics`, `moderation_high`, `streaming` — have a Horizon
  supervisor in `defaults` and in all three `environments` blocks. **Verified by hand, and it
  CAN drift** — corrected 2026-07-21. An earlier revision of this line claimed
  `HorizonQueueCoverageTest` asserted this generically. It does not: that file is a
  hand-enumerated set of per-queue `it()` blocks plus a hardcoded `$jobs` array, so a queue
  name with no supervisor passes CI green. That matters more here than a normal doc error,
  because the failure it hides is the one §B1 calls out as strictly worse than running sync —
  jobs enqueue to a lane nobody drains, a silent unbounded backlog rather than inline
  execution. **When you add a queue, add its supervisor by hand and check it; nothing will
  tell you.**
- **`redis_video`:** correctly reserved for `ProcessVideoVariantsJob` and
  `DeleteMediaArtifactsJob`, both calling `onConnection()` explicitly rather than only
  `onQueue()`. `retry_after` 3600s covers long ffmpeg encodes; dedicated `supervisor-videos`
  present in every block.
- **Failed jobs:** `public.failed_jobs` **and** `public.job_batches` both exist in
  `supabase/migrations/20260526000000_baseline_standalone_user.sql`, match `config/queue.php`
  (`database-uuids`), and are RLS-restricted to `core.partna_staff`. `DB_SEARCH_PATH` puts
  `public` first so unqualified names resolve. `queue:prune-failed --hours=72` already runs
  daily. **No silent-failure risk.**
- **Scheduler:** all 29 entries in `routes/console.php` use `->onOneServer()`,
  `->withoutOverlapping(N)`, and `->onFailure(...)`. The two `Schedule::job()` calls
  (`AggregateCacheMetricsJob`, `CheckStreamingLiveStatusJob`) already route through normal queue
  dispatch. Nothing in the file assumes in-process execution.
- **Observers:** `SiteObserver`, `UserObserver`, `ServiceCategoryObserver` et al. all dispatch
  jobs — none call Cloudflare, KV, or Redis inline.
- **Mail:** every Mailable/Notification send either uses `->queue()` / `Mail::queue()` at the
  call site or lives inside an already-queued job. No bare `Mail::send()` or `->notify()` in
  request-path code.
- **Idempotency:** every job that could double-charge or double-mirror (Instagram,
  GoogleBusiness, Menu, GoogleMenuPhotoScan, GeneratePreAccountSite — all Apify-billed) is
  `ShouldBeUnique` or `ShouldBeUniqueUntilProcessing` with `uniqueFor > timeout`, pinned by test.

### Deliberately sync forever — do not "fix" these

- `EarlyAccessService.php:77-85` and `AccountDeletionService.php:131-144` (`TXN-102`) — mail
  sent *outside* the transaction so a live SMTP call does not hold a `FOR UPDATE` lock. The
  comments document **why**, they are not worker-absence workarounds.
- `BackfillUserKvEntries` (`--sync`) and `BackfillSubdomainKvCommand` (`--queue`) — explicit
  CLI opt-in flags for one-off backfill tooling.

---

## 7. Known gaps in our ability to catch regressions

- **`phpunit.xml` pins `QUEUE_CONNECTION=sync` suite-wide**, and this cannot be overridden by
  deployed config. The suite will always execute jobs inline regardless of what production
  does. No *existing* test was found to be green only because of inline execution — every
  endpoint-level Feature test asserting a job side effect already uses `Queue::fake()` /
  `Bus::fake()` / `Notification::fake()`, and the ~37 job-importing tests without fakes are
  unit-style tests calling `handle()` directly, which is driver-agnostic. **The exposure is
  structural, not present-tense:** CI cannot catch a *future* async-only regression.
- **Soft ordering assumption:** `GoogleMenuPhotoScanJob` is dispatched with a 5-minute delay so
  a same-connect `MenuFetchJob` scrape settles first — self-acknowledged in
  `GoogleBusinessEnrichJob::handle()`, not enforced. Low blast radius: `MenuScanApplier` only
  enriches existing rows and never destructively overwrites, so a late rebuild is a correctness
  no-op. Note this delay is *also* subject to the A1 `SyncQueue::later()` issue today.
- **Stale reference:** `config/queue.php`, `config/horizon.php`, and `config/partna.php` all
  mention a `RedactShopJob` in comments (GDPR redaction on the `redis_gdpr` connection). No such
  class exists; only `ExportUserDataJob` uses the `gdpr` queue. Aspirational documentation, not
  a functional gap — the queue is supervisor-covered either way.
- **Per-connection Redis split:** `redis_gdpr` and `redis_scraping` jobs rely on the physical
  Redis key matching rather than an explicit `onConnection()` call. Mechanically correct today
  because all Redis queue connections share one underlying `REDIS_QUEUE_CONNECTION` — worth
  remembering if that env var is ever split per connection.

---

## 8. Recommended sequencing

| Order | Item | Cost | Blocker? |
|---|---|---|---|
| 1 | **A3** — fix Redis DB docs in `CLAUDE.md` + worker-kill drill | Trivial | No, but do it before you need it |
| 2 | **B2** — confirm `APP_ENV`, lock down `/horizon` | Trivial | Yes if env is reachable |
| 3 | **B1** — confirm worker + scheduler provisioned in Cloud dashboard | Verification only | **Yes — hard blocker** |
| 4 | Flip `QUEUE_CONNECTION=redis`; verify `GET /api/health/scheduler` | — | — |
| 5 | **§4** — watch `analytics`, `images`, `videos` on day one | Monitoring | No |
| 6 | **C1**, **C2** — `failed()` handler, explicit `$queue` | S | No |
| 7 | **§5** — job-ify Shop / GoogleBusiness / Events / Fresha connect flows | M–L each | No |

**A1** and **A2** resolve themselves at step 4 — no work required beyond confirming the
`RetryUnavailableMenusCommand` stagger actually paces once workers exist.

---

## 9. 2026-07-22 pre-flip verification — item status + new findings

Re-verified before enabling workers on the dev env: live probes via `cloud tinker development`
+ `environment:get`, a hand re-check of queue coverage, and a review of every queue-touching
commit since this doc's 07-20 compile.

### Status of this doc's action items

| Item | Status |
|---|---|
| A1 / A2 (delay discarded, serial fan-out) | Still present under sync; self-fix on flip as documented |
| A3 (Redis DB docs) | ✅ Fixed — `CLAUDE.md` + worker-kill drill now say DB 0 |
| B1 (worker/scheduler provisioning) | Still a dashboard action — see new findings below |
| B2 (Horizon dashboard auth) | ✅ **Fixed in code** — gate requires creds on every deployed env; set the dashboard env vars |
| C1 (`ReconcilePlatformTakedownJob::failed()`) | ✅ Added — reports + logs partial-takedown context |
| C2 (broadcast subscriber job queue) | ✅ Added — self-owned `mail` queue in constructor |
| §6 queue coverage ("nothing will tell you") | ✅ Now pinned generically — `HorizonQueueCoverageTest` discovers every dispatchable queue name from source and asserts supervisor coverage in all three env blocks, plus a reflection sweep of every `ShouldBeUnique` job's `uniqueFor > timeout` |

### New findings from live-env probing (all pre-flip actions)

1. **Deployed dev cache shares Redis DB 0 with queue+Horizon.** `config('database.redis.cache.database')`
   resolves to `0` on the dev env (Cloud-injected `REDIS_CACHE_DB=0`; repo default is 1). The whole
   DB-split exists because `Cache::flush()` issues a raw `FLUSHDB` — on DB 0 that would wipe pending
   jobs and Horizon state. `SELECT 1` verified working on Cloud Redis, so the split is achievable:
   **set `REDIS_CACHE_DB=1` explicitly on the env before flipping** (cache cold-starts, harmless).
   Same trap applies to prod — checklist §C updated.
2. **The dev scheduler has never ticked.** Zero `scheduler:last_run:*` heartbeat keys exist in dev
   Redis — daily prunes (`builds:prune-expired`, `handles:prune-expired-aliases`,
   `queue:prune-failed`) and `horizon:snapshot` have never run. Enable the scheduler in the Cloud
   dashboard alongside the worker.
3. **`GET /api/health/scheduler` was vacuously healthy — fixed in code.** `routes/console.php` only
   loads for console processes, so over HTTP the Schedule singleton was empty and the endpoint
   reported `healthy: true, tasks: []` even with cron off (exactly what dev returned live). The
   controller now loads the schedule on demand; the checklist §E/§F verification step is meaningful again.
4. **Dev env has `usesHibernation: true`.** A hibernated env cannot drain queues; confirm Cloud's
   worker/hibernation interplay when provisioning (expect to disable hibernation, or verify Cloud
   blocks it once a worker exists).
5. **The dev deploy command runs `partna:backfill-subdomain-kv --all --queue` on every deploy.**
   Inline (slow deploys) today; post-flip it enqueues a full KV backfill burst onto `cloudflare`
   per deploy. Harmless but noisy — decide keep vs remove.

### Post-07-20 code delta — verified clean

Every queue-touching change since compile (claim-invite outreach, per-vendor scraping throttles,
platform write-locking, Fresha→services projection, early-access builds, Resend suppression):
scalar-only constructors, correct `->afterCommit()` on every in-transaction dispatch, queued
mailables (`ClaimInviteMail` via `Mail::queue`), no request-scoped state, `uniqueFor`/`retryUntil`
sane, PWL dispatches moved outside lock closures (also avoids a `ConnectFetchJob` self-deadlock
under sync). Two sync-mode-only operational notes, both self-fixing on flip: the staff CSV batch
endpoint (`StaffPreAccountBuildController::batch`) currently runs up to 500 scrapes inline in one
request — don't exercise it at scale before the flip; the two one-shot backfill commands
(`BackfillPreviousWebsiteContentScanCommand`, `SweepStaleDesignKitContributionsCommand`) lose their
`->delay()` pacing if run before workers exist.

---

## Method

Compiled from four parallel read-only exploration agents covering, respectively: deliberate
sync choices and inline-invocation patterns; per-job async-readiness hazards across all 41 job
classes; inline request-path work that should be queued; and infrastructure readiness
(supervisor coverage, scheduler, `failed_jobs`, Horizon auth, test-suite canaries).

Claims marked **Verified** were independently confirmed against source after the agents
reported — specifically the `SyncQueue::later()` behaviour in vendor and the Redis DB numbering
in `config/database.php` / `.env.example`. Remaining findings carry file:line citations for
spot-checking.
