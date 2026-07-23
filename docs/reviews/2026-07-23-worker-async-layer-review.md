# Worker / async execution layer — comprehensive review

**Date:** 2026-07-23 · **Branch:** `development` · **Reviewer:** Claude (read-only)
**Scope:** everything this backend runs outside the request/response cycle — `app/Jobs/**`, `app/Console/Commands/**`, `routes/console.php`, `config/{queue,horizon,database}.php`, the services jobs call into, and the deployed Laravel Cloud worker topology.

> **Status of this document.** Complete. Section 4 carries the output of four sequential
> `scripts/audit/audit.sh` runs (24 findings, 0 P0) with per-run reconciliation. Every behavioural claim cites `file.php:LINE`, a log/probe
> excerpt, or a fetched URL. Anything I could not verify is in §10.

---

## 1. Executive summary

The async layer is structurally sound — **zero P0s across 24 pipeline findings, and all nine queue
invariants pass**. The problems are not in the lane topology; they are in what happens when
something fails.

1. **The system loses work while reporting success.** Four mail jobs stamp their idempotency flag
   *before* `Mail::send()`, so a failed send leaves the flag committed, the retry short-circuits,
   Horizon records a **clean success**, and `failed()` — the only route to Nightwatch — never fires
   (`JOB-1`…`JOB-4`). `SendAccountDeletionRequestMailJob` is the severe case: `failed()` is also the
   only code that clears the deletion token, so a **GDPR deletion request strands permanently** with
   no alert. Around it: eight jobs have no `failed()` handler at all (`OBS-6`), five sites swallow
   exceptions without `report()` (`OBS-1`…`OBS-5`), and queue-backlog alerting is implemented but
   **inert** — neither `HORIZON_NOTIFICATION_*` var is set, so twelve tuned `waits` thresholds fire
   into a void, and Nightwatch structurally cannot cover it (it instruments job *execution*).

2. **Paid-API spend has no working ceiling.** Google Places has none in code — only burst limiters,
   and the main `GoogleBusinessController::connect` path has not even that — and none at the vendor:
   Google's docs state plainly that budgets *"do not automatically cap"* spend. Apify *has* a cap but
   the accounting undercounts it: one `MenuFetchJob` can fire **up to 20 billed runs against 2
   accounted claims** (`RES-1`), and that budget is a **global daily cap keyed only by date**, so one
   bot-blocked restaurant can starve Instagram connect and Google Business sync platform-wide.

3. **One infrastructure check outranks everything else.** All queue state sits on a single 250 MB
   Laravel Valkey shared with cache, sessions and locks. Laravel Cloud recommends `allkeys-lru` for
   Valkey — under which **queued jobs are evicted silently**. The app cannot self-check: the ACL
   denies both `CONFIG` and `INFO`. Verify this in the dashboard today; if it is not `noeviction`,
   it is the only failure here that destroys work with no trace at all.

*Correction to the brief's premise:* this repo is **already on phpredis** (6.3.0, `REDIS_CLIENT` set);
`predis/predis` is vestigial. The real performance leverage is putting inline platform scrapes behind
the async+poll pattern the codebase already implements, and batching analytics ingest.

> **Revision 2026-07-23 (post-review).** The first draft of this document recommended moving video to
> Cloudflare Stream and the image lane to Cloudflare Images, calling them "the strongest candidate for
> offloading" and "could be deleted entirely." **Both claims were wrong and have been corrected in
> §6, §8 and §9.** The error was quoting each product's headline price without pricing the incumbent:
> R2 egress is free and serving media from an R2 custom domain through our own zone is explicitly
> permitted, so **delivery costs $0 today at any traffic level**. Both Cloudflare products meter
> something we currently get for nothing. Stream in particular bills **per minute delivered**, which
> converts a fixed, already-provisioned compute cost into a traffic-proportional one — the wrong
> direction for 30 s autoplay clips on sitepages. See §6 for the numbers.

**Load caveat, stated once and applying throughout.** Current production load is effectively zero:
every queue depth measured 0, `analytics.site_visits` holds 3,944 rows, `site_media` 47, and
`notifications.notifications` 28. Nothing here is derived from observed throughput. All scaling
claims in §8 are projections from code and config, and are labelled as such.

---

## 2. Verdict table

| Area | Verdict | One-line justification |
|---|---|---|
| **Topology / lanes** | **Healthy** | `retry_after` > max `$timeout` holds on all three lanes with ≥60 s margin; `balance=false` is the right call, though the config comment's stated *reason* is wrong (§7.B). |
| **Redis substrate** | **At-risk** | 250 MB Valkey holds queue + cache + sessions + locks; eviction policy unverifiable from the app and probably `allkeys-lru`; `CONFIG`/`INFO` both denied. |
| **Observability** | **Broken** | Backlog alerting inert (env unset); 8 jobs with no `failed()`; 5 swallowed-exception sites; Nightwatch structurally blind to no-consumer queues and queue depth. |
| **Scheduler** | **Watch** | Twelve entries fire simultaneously at 03:00 (8 hitting Postgres); a genuine duplicate slot at 03:25; four unbounded prunes, all currently on tiny tables. |
| **Scraping** | **At-risk** | Places uncapped in code *and* at the vendor; Apify budget undercounted up to 4× on a global per-day cap (`RES-1`); 13 of 16 recent failures on `scraping`; `menu:retry-unavailable` abandons a menu past 6 h. |
| **Video** | **Watch** | Serial `maxProcesses 1` encode lane; `memory 512` on a 1 GiB box; `retry_after` 3600 vs `$timeout` 720 means a killed encode is unreserved for a full hour. |
| **Image / logo** | **Watch** | Pixel-bomb guard is real and correct (`ImageVariantService::loadImage`); `ProcessLogoVariantsJob` has no self-margin before its own hard kill. The lane is **not** eliminable via CDN transforms — see §6. |
| **Notifications / mail** | **At-risk** | Stamp-before-send silently drops 4 transactional mail types incl. GDPR deletion (`JOB-1`…`JOB-4`); fan-out is otherwise well built; no provider rate limit on broadcasts. |
| **Analytics** | **Watch** | One job per pageview; rollup tables still never populated, so all reads compute from raw. |
| **Cache & edge** | **Healthy** | `SyncSubdomainToKvJob` verified as the sole KV writer; warm job correctly unique. Live cache hit-rate is 46.8% vs a 90% SLO — tuning, not a defect. |
| **GDPR / deletion** | **Healthy** | Export genuinely streams on both read and upload; sweeper coverage is thorough and deliberate. |
| **Moderation** | **Watch** | Three `Notify*` jobs write their action log non-transactionally around an un-keyed side effect → duplicate notifications on retry; `PurgeModerationCacheJob` has no completed-guard at all. |
| **Pre-account** | **Watch** | `ApproveEarlyAccessBuildJob` has no `failed()` handler while its sibling does; stuck window is ~90 min. |

---

## 3. The map

### 3.0 Deployed reality (Phase 0)

Probed live against `development` via `cloud command:run` on 2026-07-23.

| Fact | Value | Source |
|---|---|---|
| App instance | `flex.g-1vcpu-512mb`, min/max replicas 1, `usesScheduler: false` | `cloud instance:list` |
| Worker instance | **`flex-1gb`** (1 vCPU / 1 GiB), min/max replicas 1, **`usesScheduler: true`** | `cloud instance:list` |
| Managed cache | **Laravel Valkey `valkey-pro.250mb`**, `ap-southeast-2`, `isPublic: true` | `cloud cache:list` |
| PHP | 8.4.23 | runtime probe |
| Redis client | **`phpredis` 6.3.0** (`REDIS_CLIENT=phpredis`) | runtime probe |
| `igbinary` | present but unused (`REDIS_IGBINARY` unset) | runtime probe |
| `imagick` / `gd` / `exec` | all available | runtime probe |
| `QUEUE_CONNECTION` | `redis` | env |
| Redis DBs | 0 = queue+Horizon, 1 = cache, 2 = sessions, 3 = dormant, 4 = locks | runtime probe |
| Cache store / lock store | `cache` (DB 1) / `cache_locks` (DB 4) | runtime probe |
| Postgres guards | `statement_timeout = 30s`, `lock_timeout = 10s`, user `app_backend` | runtime probe |
| Queue depths (all 14) | **0** | runtime probe |
| `failed_jobs` | 16 total — `scraping` 13, `default` 2, `images` 1 | runtime probe |

Two notes on things that look like findings but are not:

- **`REDIS_QUEUE_DB=3` is set on dev but has no effect.** `config/queue.php:72` resolves via
  `REDIS_QUEUE_CONNECTION` (default `default` → DB 0). This reads as though queues live on DB 3.
  It is **already documented** at `docs/deploy/queue-worker-cutover.md:113-117`, and `CLAUDE.md:35`
  now describes the mapping correctly. No action beyond awareness.
- **Both invariants about `Cache::flush()` hold.** Cache is pinned to DB 1 and the lock store
  resolves to `cache_locks` (DB 4), so a raw `FLUSHDB` can never wipe Horizon/queue state on DB 0.

> ⚠️ `cloud cache:list --json` prints Valkey **passwords in plaintext**. They are deliberately not
> reproduced in this document. Treat that command's output as a secret.

### 3.1 Lane topology (Phase 1b)

All four queue *connections* resolve to the same Redis connection (`default`, DB 0) — verified at
runtime, matching the design note at `config/horizon.php:108-111`. A "lane" is therefore purely a
`retry_after` tier, not a separate datastore.

| Connection | `retry_after` | `block_for` | `after_commit` | Supervisor | Queues | Longest `$timeout` on it | Margin |
|---|---|---|---|---|---|---|---|
| `redis` | 360 | `null` | `false` | `supervisor-1` (`timeout` 300, `memory` 256, max 2) | `moderation_high`, `notifications`, `mail`, `default`, `cloudflare`, `cache-warm`, `analytics`, `images`, `streaming`, `platform_refresh`, `platform_connect` | `ProcessLogoVariantsJob` **300** | **60 s ✓** |
| `redis_scraping` | 660 | `null` | `false` | `supervisor-long` (`timeout` 660, `memory` 256, max 1, `nice` 5) | `scraping`, `gdpr` | `MenuFetchJob` / `ExportUserDataJob` **600** | **60 s ✓** |
| `redis_gdpr` | 660 | `null` | `false` | *(none — dispatch-side only)* | `gdpr` → drained by `supervisor-long` | `ExportUserDataJob` 600 | 60 s ✓ |
| `redis_video` | 3600 | `null` | `false` | `supervisor-videos` (`timeout` 3600, `memory` 512, max 1, `nice` 5) | `videos` | `ProcessVideoVariantsJob` **720** | 2880 s ✓ |

**Invariant 1 (`retry_after` > max `$timeout`) — PASSES on every lane.** This is the most dangerous
class of queue bug and it is clean. Mechanism confirmed in framework source: `LuaScripts::pop()`
sets the `:reserved` ZSET score to `now + retry_after` at reservation time, and
`migrateExpiredJobs()` blindly `RPUSH`es anything past its score back onto the live queue with **no
fencing or ownership check** — so an expired reservation on a still-running job means genuine
concurrent double-execution.

Two nuances no guard test covers:

- `supervisor-1.timeout` (300) **exactly equals** `ProcessLogoVariantsJob::$timeout` (300) —
  zero margin between Horizon's SIGKILL clock and the job's own.
- `redis_video`'s margin is arguably *too wide*: a worker killed mid-encode leaves that job
  unreserved for a full hour before any retry.

**Memory arithmetic (the real constraint).** Permitted worker heap on the 1 GiB box:
2 × 256 (`supervisor-1`) + 256 (`supervisor-long`) + 512 (`supervisor-videos`) = **1280 MiB on a
1024 MiB container**, a 25% over-commit before counting the master, three middleman processes, or
the scheduler that shares this instance. Horizon's `memory` is a *restart-after-exceeded* threshold
checked **between jobs**, not a hard cap — so nothing prevents that sum being reached.

### 3.2 Job inventory (Phase 1a)

**Connection resolution, stated once.** No job except the two media-deletion/video jobs calls
`->onConnection()`. Everything else rides `config('queue.default')` = `redis`. Because all four
queue connections resolve to the same physical Redis connection, **it is the queue name — not the
connection name — that decides which supervisor drains a job.** `ProcessVideoVariantsJob` and
`DeleteMediaArtifactsJob` are the exceptions, explicitly setting
`config('partna.video_queue.connection')` = `redis_video`.

`$failOnTimeout` is default (`false`) everywhere except `GeneratePreAccountSiteJob:53` and
`ApproveEarlyAccessBuildJob:48`, which set it `true`.

| Job | Queue | `$tries` | `$timeout` | `$backoff` | `retryUntil` | Unique (`uniqueFor`) | `failed()` | Idempotent on replay? |
|---|---|---|---|---|---|---|---|---|
| `ConnectFetchJob` | `platform_connect` | 3 | 45 | `[5,20]` | — | ✓ `{platform}:{connId}` (120) | ✓ marks terminal | ✓ locked re-derive |
| `RefreshConnectionJob` | `platform_refresh` | **0** | 120 | `[30,120,300]` | +2 h | ✓ `{connId}` (7200) | ✗ | ✓ same op as cron |
| `ReconcilePlatformTakedownJob` | `platform_refresh` | 3 | 120 | `[30,120,300]` | — | ✗ | ✓ | ✓ flips are idempotent |
| `MenuFetchJob` | `scraping` | **0** | **600** | `[30,120]` | +30 m | ✓ `{userId}` (1800) | ✓ sets `unavailable` | ✓ skip-gate + wholesale rebuild in one txn |
| `InstagramConnectJob` | `scraping` | **0** | 150 | `[30,120]` | +15 m | ✓ (900) | ✓ `markFailed()` | ✗ re-mirrors media (re-bills) |
| `GoogleBusinessEnrichJob` | `scraping` | **0** | 130 | `[30,120]` | +15 m | ✓ `{userId}:{placeId}` (900) | ✓ clears cache markers | ~ cache markers prevent re-bill unless evicted |
| `GoogleMenuPhotoScanJob` | `scraping` | 1 | 280 | `[60]` | — | ✓ (3600) | ✗ | ✗ re-bills OCR (≤12 calls) |
| `WebsiteMenuHtmlScanJob` | `scraping` | 3 | 120 | `[30,120]` | — | ✓ sha1(text) (3600) | ✗ | content-safe, **re-bills AI** |
| `WebsiteMenuPdfScanJob` | `scraping` | 3 | 120 | `[30,120]` | — | ✓ sha1(url) (3600) | ✗ | content-safe, **re-bills OCR+AI** |
| `LinkInBioScanJob` | `scraping` | 2 | 60 | `[30]` | — | ✓ **no `uniqueFor` → 0** ⚠ | ✗ | ~ upsert-shaped |
| `ScanPreviousWebsiteContentJob` | `scraping` | 2 | 60 | `[30]` | — | ✓ **no `uniqueFor` → 0** ⚠ | ✗ | ~ fill-if-empty |
| `EnrichLinkCardJob` | `scraping` | 3 | 60 | `[30,120,300]` | — | ✓ (300) | ✗ | ✓ |
| `DeleteMirroredMediaJob` | `scraping` | 4 | 120 | `[60,300,900]` | — | ✓ `{folder}` (300) | ✓ log only | ✓ delete is no-op |
| `GeneratePreAccountSiteJob` | `scraping` | **0** | 300 | `[30]` | +10 m | ✓ `{buildId}` (600) | ✓ forces `FAILED` | ✓ guarded short-circuit |
| `ApproveEarlyAccessBuildJob` | `scraping` | **0** | 300 | `[30]` | +10 m | ✓ `{signupId}` (600) | **✗ none** ⚠ | ~ re-scrapes + re-notifies |
| `ExportUserDataJob` | `gdpr` | 3 | **600** | `[60,300,900]` | — | ✗ | ✓ `markFailed()` | ~ email at-least-once (documented) |
| `ProcessVideoVariantsJob` | `videos` (`redis_video`) | 2 | **720** | `[60,300,900]`ᵉ | — | ✗ (Redis lock instead) | ✓ + R2 cleanup | ✓ lock + terminal-state |
| `DeleteMediaArtifactsJob` | `videos` (`redis_video`) | 4 | 120 | `[60,300,900]` | — | ✗ | ✓ log only | ✓ delete is no-op |
| `ProcessImageVariantsJob` | `images` | 3 | 120 | `[30,120,600]` | — | ✗ (Redis lock instead) | ✓ + R2 cleanup | ✓ lock + terminal-state |
| `ProcessLogoVariantsJob` | `images` | 3 | **300** | `[30,120,300]` | — | ✗ (Redis lock instead) | ✓ resets + falls back | ✓ `updateOrCreate` |
| `CloudflareCachePurgeJob` | `cloudflare` | 3 | 180 | `[5,15,60]` | — | ✓ (240 / 30 follow-up) | ✓ + on-call escalation | ✓ purge idempotent |
| `SyncSubdomainToKvJob` | `cloudflare` | 3 | 30 | `[10,30,60]` | — | ✓ **UntilProcessing** (45) | ✓ | ✓ re-reads state fresh |
| `WarmPublicSiteCacheJob` | `cache-warm` | 3 | 10 | `[5,15,30]` | — | ✓ lowered subdomain (120) | ✓ | ✓ cache overwrite |
| `AggregateCacheMetricsJob` | `default` | 3 | 30 | 30 | — | ✗ | ✓ | ✓ read-only |
| `RecordAnalyticsEventJob` | `analytics` | 3ᶜ | 30ᶜ | 10ᶜ (flat) | — | ✗ | ✓ | ✓ `insertOrIgnore` on PK |
| `CheckStreamingLiveStatusJob` | `streaming` | 1 | 90 | 0 | — | ✗ (`WithoutOverlapping`+`expireAfter(120)`) | ✓ | ✓ read-only poll |
| `SendStaffBroadcastEmailsJob` | `notifications` | 3 | 120 | `[10,30,60]` | — | ✓ (600) | ✓ | ✓ coordinator re-walks |
| `SendStaffBroadcastEmailToSubscriberJob` | `mail` | 3 | 30 | `[10,30,60]` | — | ✗ (DB receipt PK) | ✓ | ✓ `insertOrIgnore` receipt |
| `SendTransactionalNotificationEmailJob` | `notifications`→`mail`ᵈ | 3 | 30 | `[30,120,300]` | — | ✗ | ✓ | ~ **at-least-once** (stamp after send) |
| `SendEnquiryConfirmationJob` | `notifications` | 3 | 30 | `[30,90,180]` | — | ✗ | ✓ | ⚠ **stamp-before-send** — no duplicate, but a failed send is lost silently (JOB-1 r3) |
| `SendEnquiryNotificationJob` | `notifications` | 3 | 30 | `[30,90,180]` | — | ✗ | ✓ | ⚠ **stamp-before-send** (JOB-3 r3) |
| `SendSubscriptionConfirmationJob` | `notifications` | 3 | 30 | `[30,90,180]` | — | ✗ | ✓ | ⚠ **stamp-before-send** (JOB-2 r3) |
| `DispatchEnquiryNotificationsJob` | `notifications` | 3 | 30 | `[30,90,180]` | — | ✓ `{enquiryId}` (300) | ✓ | ✓ `Cache::add` claim |
| `SendFeedbackEmailJob` | `notifications` | 3 | 30 | `[30,120,600]` | — | ✗ | ✓ | ✓ per-recipient key |
| `SendAccountDeletionRequestMailJob` | `notifications` | 3 | 15 | `[30,120,300]` | — | ✗ (`ShouldBeEncrypted`) | ✓ clears token | ⚠ **stamp-before-send strands GDPR deletion** (JOB-4 r3) |
| `SyncCustomerMarketingOptInJob` | `notifications` | 3 | 30 | 30 (flat) | — | ✗ | ✓ | ✓ idempotent overwrite |
| `NotifyOnCallStaffJob` | `moderation_high` | 3 | 30 | `[10,30,60]` | — | ✓ `{actionLogId}` (300) | ✓ trait | **✗ duplicate page on retry** ⚠ |
| `NotifyReportedUserJob` | `notifications` | 3 | 60 | `[10,30,60]` | — | ✓ (300) | ✓ trait | **✗ duplicate notify** ⚠ |
| `NotifyReporterJob` | `notifications` | 3 | 60 | `[10,30,60]` | — | ✓ (300) | ✓ trait | **✗ re-emails whole loop** ⚠ |
| `NotifyStaffOfCaseUpdateJob` | `notifications` | 3 | 30 | `[10,30,60]` | — | ✓ + `ShouldQueueAfterCommit` | ✓ | ✓ read + send |
| `PurgeModerationCacheJob` | `moderation_high` | 3 | 60 | `[10,30,60]` | — | **✗ no unique, no guard** ⚠ | ✓ trait | ✗ re-dispatches both children |
| `QuarantineMediaJob` | `moderation_high` | 3 | 60 | `[10,30,60]` | — | ✗ | ✓ trait | ✓ single txn |
| `SuspendSiteJob` | `moderation_high` | 3 | 60 | `[10,30,60]` | — | ✗ | ✓ trait | ✓ single txn |
| `SuspendUserJob` | `moderation_high` | 3 | 60 | `[10,30,60]` | — | ✗ | ✓ trait | ✓ single txn |

ᶜ config-driven (`partna.analytics.*`), assigned in the constructor — see the guard-test note in §3.6.
ᵉ `$tries = 2` means only the first backoff gap is ever used — the other two are dead (SCALE-3, r3).
ᵈ dispatched with a site-level `->onQueue('mail')` override at `NotificationPublisher.php:144`.

**Traits.** `GuardsMediaProcessing` is a Redis `SET NX EX (timeout+60)` concurrency lock plus a
terminal-state check — it does **not** enforce any size/pixel ceiling. The pixel-bomb guard lives
one layer down in `ImageVariantService::loadImage()` and is correct: it reads only the file header
via `getimagesize()` and rejects `width*height > partna.image_max_pixels` (24 MP default,
`config/partna.php:1168`) **before any bitmap is allocated**. `HasCloudflareRetryPolicy` supplies
`tries=3`, `backoff=[10,30,60]`, `maxExceptions=2`. `ThrottlesPreAccountScraping` attaches
`RateLimited('platform-connect'|'preaccount-places')` plus a `retryUntil` of +10 min, deliberately
shorter than its consumers' 600 s unique window.

### 3.3 Scheduler inventory & the 03:00 collision (Phase 1c)

Every entry uses `->onOneServer()` and an explicit `withoutOverlapping(N)`; most daily ones add
`->runInBackground()` (which forks a separate process). Full per-entry detail is in
`routes/console.php`, which is unusually well-annotated.

**The wall-clock finding is not the 5-minute spacing of the daily prunes — it is 03:00 itself.**
At that single minute **twelve** scheduled entries fire together:

| Cadence | Entries firing at 03:00 |
|---|---|
| daily 03:00 | `analytics:purge-raw-events` (heaviest — batched deletes across 6 tables) |
| hourly | `integrations:refresh`, `integrations:refresh-backlog`, `AggregateCacheMetricsJob`, `builds:reconcile-stuck`, `media:cleanup-stuck-processing` |
| every 15 min | `analytics:compute-popularity`, `moderation:sla-scan`, `menu:retry-unavailable` |
| every 5 min | `horizon:snapshot` |
| every 2 min | `CheckStreamingLiveStatusJob` |
| every minute | `keep-alive-ping` |

**Eight of the twelve query Postgres directly**, on the same instance, from the same 1 GiB container
that runs every Horizon worker. Two aggravating factors:

- `integrations:refresh` has **no cap and no stagger** — it dispatches one `RefreshConnectionJob`
  per due connection in a tight `lazyById()` loop
  (`RefreshIntegrationConnectionsCommand.php:32-39`). This is deliberate ("capacity scales with the
  fleet"), but it means the 03:00 tick is also the largest fan-out of the hour, landing on
  `platform_refresh` — which sits **second-to-last** in `supervisor-1`'s strict-priority list.
- `analytics:compute-popularity`'s site selection is bounded to a 60-minute activity window, but the
  **per-site aggregation reads that site's entire raw-event history** with no date bound
  (`ComputeContentPopularityScores.php:410-503`), floored only by the 90-day purge.

**Genuine duplicate slot:** `partna:prune-notifications` (`routes/console.php:51`) and
`handles:prune-audit-logs` (`:181`) are **both scheduled at 03:25**.

**Sunday 04:00–05:00** adds six weekly sweeps at 10-minute spacing, including
`media:gc-orphaned-video-artifacts` (a full `videos/` R2 prefix LIST) at 04:20.

**Interaction with `statement_timeout = 30s`.** Four scheduled commands issue a single unbatched
bulk DELETE/UPDATE with no LIMIT — `feature-flags:prune-expired`
(`PruneExpiredFeatureFlagOverridesCommand.php:21-23`), `early-access:prune-old-signups` (`:63`),
`notifications:prune-unsubscribed-subscriptions` (`:57`), and
`moderation:prune-resolved-signal-pii` (`:100-104`). All target tables that are small today, so this
is latent. But at scale the 30-second statement timeout converts each into a *permanent* failure:
the DELETE can never complete, so the table grows without bound and retention enforcement silently
stops — and these are precisely the commands enforcing PII retention windows.

### 3.4 Failure & recovery map (Phase 1d)

| Sweeper | Failure it compensates for | Max time state stays wrong |
|---|---|---|
| `builds:reconcile-stuck` (hourly) | `GeneratePreAccountSiteJob` SIGKILLed, never calls `failed()` | ~90 min to *mark* failed; re-dispatch is event-driven, so real recovery is unbounded |
| `media:cleanup-stuck-processing` (hourly) | Image/video job killed while holding its Redis processing lock; retry no-ops inside the lock window | ~90–120 min; **no auto re-dispatch — the user must re-upload** |
| `gdpr:sweep-stale-exports` (daily 03:35) | `ExportUserDataJob` SIGKILLed between `markProcessing()` and completion | ~25 h, during which that user's export requests are blocked entirely |
| `gdpr:sweep-purged-video-artifacts` (daily 03:45) | `DeleteMediaArtifactsJob` exhausted retries during an R2 outage *and* the account was then hard-deleted | ~24 h inside a 30-day ledger window; outside that window it is **not covered here at all** |
| `media:gc-orphaned-video-artifacts` (weekly Sun 04:20) | Orphaned `videos/` R2 objects from any cause | up to ~8 days (7-day cadence + 24 h age guard) |
| `menu:retry-unavailable` (every 15 min) | Transient Apify bot-block leaving a menu `unavailable` | bounded to a **6-hour** window — past that the menu **ages out permanently** with no automatic recovery |

**Failures nothing covers:**

- **Orphaned mirrored platform media.** Ten `DeleteMirroredMediaJob` failures at exactly
  `2026-07-23 03:21:15` (R2 4xx on a `platforms/instagram/...` prefix listing). Its `failed()`
  handler only reports and logs. The `gdpr:sweep-purged-video-artifacts` ledger sweep reads
  `EVENT_PURGED` audit rows for **video** paths and `media:gc-orphaned-video-artifacts` LISTs the
  **`videos/`** prefix — neither touches `platforms/`. Scraped Instagram media orphaned in R2 has
  no sweeper.
- **A job enqueued to a queue with no consumer.** Nothing detects this (see §7).

---

### 3.5 Dispatch graph & fan-out (Phase 1e)

Most of the graph is one hop: a controller, observer or console command dispatches a leaf job. The
fan-outs that matter:

| Fan-out | N | Bounded? |
|---|---|---|
| `SendStaffBroadcastEmailsJob` → `SendStaffBroadcastEmailToSubscriberJob` | every subscribed row for the list | **Unbounded** — but chunked `chunkById(500)` then `Bus::batch` of 200 (`:82-105`) |
| `integrations:refresh` → `RefreshConnectionJob` | every connection due across all platforms | **Unbounded**, no stagger (`RefreshIntegrationConnectionsCommand.php:32-39`) |
| `StaffEarlyAccessController::approveBulk` (`--all_waitlisted`) → `ApproveEarlyAccessBuildJob` | whole waitlist | **Unbounded** (`:231`) |
| Backfill commands → `SyncSubdomainToKvJob` / `ScanPreviousWebsiteContentJob` | whole cohort | chunked 500, unbounded in total |
| `ModerationActionDispatcher` → `Bus::chain([...])` + notify jobs | ≤5 + conditional | **Bounded** by a static map (`:27-54`) |
| `ScanPreviousWebsiteContentJob` → `WebsiteMenuPdfScanJob` | ≤ `MAX_PDF_SCANS`, staggered 15 s | **Bounded** |
| `PurgeModerationCacheJob` → KV sync + cache purge | 2 | **Bounded** |
| `CloudflareCachePurgeJob` → itself (`followUp`, +120 s) | 1 | **Bounded** — guarded by `if (!$this->followUp)` |
| `GeneratePreAccountSiteJob` → source generator | 1 per branch | **Bounded** (linear, not a fan-out) |

`RefreshConnectionJob` is a leaf — the hourly fan-out does not cascade further.

### 3.6 Invariant scorecard

The nine invariants the review brief asked to be confirmed, each checked independently of the
guard tests:

| # | Invariant | Result |
|---|---|---|
| 1 | `retry_after` > max `$timeout` per lane | **PASS** — ≥60 s margin on all three lanes (§3.1) |
| 2 | Horizon supervisor `timeout` > job `$timeout` | **PASS, but `ProcessLogoVariantsJob` has zero self-margin** — see note below (pipeline finding #JOB-1) |
| 3 | Every dispatched queue has a consumer | **PASS** — exact 14/14 match |
| 4 | `->afterCommit()` on dispatches inside transactions | **PASS except one** — `AccountDeletionService.php:1251` via `PruneExpiredPreAccountBuilds.php:105` (low severity, §5) |
| 5 | `WithoutOverlapping` keys match producer/consumer | **PASS** — only one usage in `app/Jobs`, correctly `->expireAfter(120)` |
| 6 | `onOneServer`/locks use a shared atomic store | **PASS** — lock store resolves to `cache_locks` (Redis DB 4), not `array`/`file` |
| 7 | `RateLimited` jobs use `tries=0` + `retryUntil` | **PASS** — all five rate-limited jobs comply |
| 8 | `SerializesModels` + deleted models | **PASS** — no job serialises a deletable model; all pass scalar IDs and re-fetch with explicit missing/trashed guards |
| 9 | `Cache::flush()` cannot touch DB 0 | **PASS** — cache pinned to DB 1, locks to DB 4 |

**Two problems the invariant list did not anticipate**, both verified against vendor source:

- **`ShouldBeUnique` with no `$uniqueFor` takes a permanent lock.** `UniqueLock::acquire()` reads
  `($job->uniqueFor ?? 0)`, and `RedisLock::acquire()` branches: `if ($this->seconds > 0)` uses
  `SET ... EX ... NX`, else falls through to **`SETNX` with no expiry**. A worker SIGKILLed (OOM,
  deploy, timeout) before `UniqueLock::release()` leaves that key in Redis **forever**, and every
  future dispatch for that `uniqueId` is silently discarded — no error, no failed-jobs row. The
  lock lives in DB 4, which is deliberately excluded from `Cache::flush()`, so clearing it needs
  manual Redis surgery. Affected: **`LinkInBioScanJob:32`** and
  **`ScanPreviousWebsiteContentJob:53`**.
- **The guard test skips exactly those two jobs.** `HorizonQueueCoverageTest.php:394-400` does
  `$uniqueFor = $defaults['uniqueFor'] ?? null; if (! is_int($uniqueFor) || ! is_int($timeout)) { continue; }`
  with the comment *"constructor-driven knobs — covered by the explicit list above"*. A job that
  declares **no** `uniqueFor` yields `null`, fails `is_int`, and is skipped — and neither job
  appears in the explicit 4-job list at `:325-330`. The `continue` intended for constructor-assigned
  values also swallows the genuinely dangerous case.

## 4. Findings (audit pipeline)

Four narrow runs, executed sequentially. **No findings in this section are hand-written** — all come
from `scripts/audit/audit.sh` (DeepSeek scan → Claude adjudication). Full detail, fix plans and
evidence live in the linked folders; only the ranked summary is reproduced here.

### Reconciliation

| Run | Folder | P0 | P1 | P2 | P3 | Total | IDs | Union |
|---|---|---|---|---|---|---|---|---|
| 1. jobs-correctness | [`audits/workers/2026-07-23-jobs-correctness/`](../../audits/workers/2026-07-23-jobs-correctness/CONSOLIDATED.md) | 0 | 0 | 1 | 0 | 1 | `JOB-1` | 1 ✓ |
| 2. scheduler-correctness | [`audits/workers/2026-07-23-scheduler-correctness/`](../../audits/workers/2026-07-23-scheduler-correctness/CONSOLIDATED.md) | 0 | 0 | 2 | 1 | 3 | `SCHED-1…3` | 3 ✓ |
| 3. worker-scaling (`scale-health`, 6 lenses) | [`audits/sweeps/2026-07-23-worker-scaling/`](../../audits/sweeps/2026-07-23-worker-scaling/CONSOLIDATED.md) | 0 | 5 | 9 | 3 | 17 | `JOB-1…5`, `OBS-1…7`, `SCALE-1…3`, `CACHE-1`, `CCH-1` | 17 ✓ |
| 4. media-scraping-lanes | [`audits/workers/2026-07-23-media-scraping-lanes/`](../../audits/workers/2026-07-23-media-scraping-lanes/CONSOLIDATED.md) | 0 | 1 | 1 | 1 | 3 | `RES-1…3` | 3 ✓ |
| **Total** | | **0** | **6** | **13** | **5** | **24** | | **24 ✓** |

Counts reconcile against the union of IDs in all four runs. **No P0s.**

> ⚠️ **ID collision across runs.** Run 1's `#JOB-1` (`ProcessLogoVariantsJob` timeout margin) and
> Run 3's `#JOB-1` (`SendEnquiryConfirmationJob` stamp-before-send) are **different findings sharing
> an ID** — the pipeline numbers per-run, not globally. Always qualify by run when referencing these.
> No semantic duplicates were found across runs.

Signal quality was good: run 1's scan produced 8 drafts and adjudication kept **1**, so the
adjudicator is doing real work rejecting plausible-but-wrong findings.

### P1 — High

**The stamp-before-send cluster (`JOB-1`…`JOB-4`, run 3) is the most important result of this
review**, and it is an internal inconsistency rather than a design choice.

Four mail jobs stamp their idempotency flag **inside the `lockForUpdate` transaction, before**
`Mail::send()`. The failure chain: the send throws → the stamp has already committed → the retry
reads the flag, returns early, and `handle()` returns **without throwing** → Horizon records a
**clean success** → `failed()` never fires → nothing reaches Nightwatch. The mail is permanently
lost *and* the system reports success. Decisively, `SendTransactionalNotificationEmailJob` in the
same directory stamps *after* the send and documents why ("preferable to silently dropping"), so
the correct pattern already exists in-repo.

| ID | Finding | Where |
|---|---|---|
| `JOB-1` | `SendEnquiryConfirmationJob` — a visitor's "we got your message" confirmation is dropped silently | `SendEnquiryConfirmationJob.php:65-90` |
| `JOB-2` | `SendSubscriptionConfirmationJob` — same pattern | `SendSubscriptionConfirmationJob.php` |
| `JOB-3` | `SendEnquiryNotificationJob` — same pattern | `SendEnquiryNotificationJob.php` |
| `JOB-4` | **`SendAccountDeletionRequestMailJob` — strands a GDPR deletion request.** `failed()` is the *only* path that clears `deletion_token_hash`/`deletion_requested_at`, so the user sits in permanent "pending deletion, no email" limbo needing DB intervention, with zero alert. Adjudicator recommends treating this as its own reviewed unit (it is `ShouldBeEncrypted` — the URL carries a raw deletion token) | `SendAccountDeletionRequestMailJob.php:61-94` |
| `OBS-1` | `SourceGenerationException` swallowed in `GeneratePreAccountSiteJob::handle()` — a failed signup build is invisible to Nightwatch | `GeneratePreAccountSiteJob.php` |
| `RES-1` | **Menu-scrape fallback double-spends the capped Apify budget.** `fetchStores()` claims one slot per target, then a retryable miss falls back to full `fetch()`, which claims a *second* slot and runs its own `MAX_ATTEMPTS=4` loop of billed actor calls — up to **20 real Apify runs against 2 accounted claims** for a 4-target store. `ApifyBudget` is a **global daily cap keyed only by date, not by user**, shared with Instagram connect and Google Business enrichment, so one bot-blocked store can starve the whole platform for the day | `MenuApifyScraper.php:52-80, 100-165` |

### P2 — Should fix (13)

| ID · run | Finding |
|---|---|
| `JOB-1` · r1 | `ProcessLogoVariantsJob` has zero self-margin before its hard kill, so its `failed()` fallback can be preempted (§6) |
| `SCHED-1` · r2 | `analytics:compute-popularity` uses `withoutOverlapping(14)` under an `everyFifteenMinutes()` cadence — violates the convention in the file's own header docblock; a >14 min run lets the lock expire mid-execution and the next tick start concurrently |
| `SCHED-2` · r2 | Two PII-retention prunes (`notifications:prune-unsubscribed-subscriptions`, `early-access:prune-old-signups`) use a single unbounded `DELETE` while three sibling sweepers batch theirs |
| `OBS-2` · r3 | `ProcessImageVariantsJob` swallows cache-purge dispatch failures with no `report()` |
| `OBS-3` · r3 | `MenuFetchJob` swallows scan-reapply failures — enrichment loss invisible |
| `OBS-4` · r3 | `SourceGenerationException` swallowed in `ApproveEarlyAccessBuildJob::handle()` |
| `OBS-5` · r3 | Lock-contention timeouts in `GoogleBusinessEnrichJob`/`EnrichLinkCardJob` log warnings but never `report()` — sustained contention invisible |
| `OBS-6` · r3 | **Eight platform/pre-account jobs have no `failed()` callback** — terminal failures land in `failed_jobs` with no Nightwatch signal (matches my inventory exactly: `EnrichLinkCardJob`, `GoogleMenuPhotoScanJob`, `LinkInBioScanJob`, `ScanPreviousWebsiteContentJob`, `WebsiteMenuHtmlScanJob`, `WebsiteMenuPdfScanJob`, `RefreshConnectionJob`, `ApproveEarlyAccessBuildJob`) |
| `SCALE-1` · r3 | Analytics ingest shares a single 2-process lane with 10 other queues, list-ordered behind them — corroborates §5c and §6 |
| `SCALE-2` · r3 | `SendStaffBroadcastEmailToSubscriberJob` has no email-provider rate limiting; a large broadcast can exceed Resend per-second caps |
| `SCALE-3` · r3 | `ProcessVideoVariantsJob` declares three backoff gaps but `$tries = 2` only ever consumes the first |
| `CACHE-1` · r3 | `ReconcilePlatformTakedownJob`'s per-model save loop can flood the `cloudflare` queue and delay unrelated users' purges |
| `RES-2` · r4 | Website-menu AI scan jobs retry from scratch, re-billing Mistral/DeepSeek on any transient failure |

### P3 — Nice to have (5)

`SCHED-3` (r2) `PruneExpiredPreAccountBuilds` plucks all candidate IDs into memory · `CCH-1` (r3)
`GuardsMediaProcessing` uses raw `Redis::set`/`del` rather than the dedicated `cache_locks`
connection, so the media lock does not live on the documented lock DB · `JOB-5` (r3)
`SyncCustomerMarketingOptInJob` missing `ShouldBeUnique` on a concurrency-sensitive write ·
`OBS-7` (r3) `SendEnquiryNotificationJob` hardcodes its queue name instead of reading config ·
`RES-3` (r4) orphaned temp file if `rename()` fails in `VideoVariantService::makeTmpFile`.

### How the pipeline output relates to this document

The pipeline **confirmed** three things I derived independently: the analytics single-lane
contention (`SCALE-1` ≡ §5c/§6), the eight-jobs-without-`failed()` list (`OBS-6`, identical set),
and the unbatched prunes (`SCHED-2`, though it usefully narrowed my four to the two that are both
PII-bearing and unboundedly growing).

It **found three things I missed**: `SCHED-1` (the lock-TTL-under-cadence violation), the
stamp-before-send cluster, and `RES-1`'s budget double-spend.

It **did not surface** the items in §3.6 that require runtime or vendor context — the `uniqueFor = 0`
permanent-lock bug, the Valkey eviction-policy exposure, the inert backlog alerting, or the memory
over-commit. That is expected: those are only visible by probing the deployed environment and
reading vendor source, which a static scan over `app/Jobs/` cannot do. It is also the honest answer
to "did the pipeline catch the repo-specific invariants" — **it caught the code-shaped ones and none
of the environment-shaped ones.**

---

## 5. Right work in the right place

### 5a. Synchronous work that should be a job

The controlling fact is `SafeUrlFetcher`'s timeout model: `timeout_seconds` (8 s,
`config/partna.php:1204`) and `connect_timeout_seconds` (3 s, `:1221`) are **per-hop**, and
`max_redirects` is 5 (`:1207`). One `fetch()` is therefore up to 48 s, doubled by the one-shot
403-retry under an alternate User-Agent (`SafeUrlFetcher.php:101-114`) → **~96 s worst case per
call**. A true wall-clock budget exists (`FetchBudget`, 20 s, `config/partna.php:1230`) but is
**opt-in per call site** and used by only three (`ConnectResolver`, `HighlightsPicker`,
`YoutubeThumbnailResolver`) — none of the controllers below.

| Endpoint | Site | Blocks inline | Worst case | Verdict |
|---|---|---|---|---|
| `POST /platforms/{platform}/refresh` | `RefreshController.php:40,76-82` | `PlatformRefresher::refresh()` **inline, in a `foreach` over every connected row** | ~108 s **× row count** | **Highest-value fix.** `RefreshConnectionJob` already exists and wraps this exact call for the cron dispatcher, with rate-limiting and queueing. Swap the inline call for `RefreshConnectionJob::dispatch()` per row. |
| `POST /platforms/shop/brands` | `ShopController.php:108-115` | `ShopProviderDetector::detectDetailed` probes Shopify → Woo → Squarespace → generic **sequentially** | ~384 s | Heaviest single endpoint found |
| `POST /platforms/apple/{music,podcast}/connect` | `AppleController.php:62` | two sequential iTunes lookups | ~192 s | Should be async+poll |
| `POST /platforms/fresha/selection`, `/employee-services` | `FreshaController.php:149-169, 205-216` | location scrape **then** a GraphQL POST | ~108 s | Should be async+poll |
| `POST /platforms/fresha/connect`, `GET /team` | `FreshaController.php:57-76, 130-137` | full Fresha page scrape, no cache | ~96 s | Should be async+poll |
| `POST /platforms/{eventbrite,humanitix,skool}/connect` | `EventbriteController.php:59-68` etc. | organiser + N event page scrapes | ~96 s | Should be async+poll |
| `PUT /me/site/custom-domain` | `CustomDomainController.php:72-79` | two Cloudflare API calls | ~15 s | Acceptable — explicit `timeout(5)`/`timeout(10)`, load-bearing |
| `POST /platforms/google-business/connect` | `GoogleBusinessController.php:101` | Places Details call | ~15 s | Acceptable — bounded; Apify enrichment correctly deferred |

**Clean, no finding:** `POST /public/enquiry` (`PublicEnquiryController.php:31`) — the public
unauthenticated path does **no** external work; both notification paths are correctly deferred to
jobs (`:143`, `:146`). The bot-token gate ahead of it is bounded at 3 s and fails open.
`SubdomainAvailabilityController` is pure DB.

**The good pattern already exists and is used.** `InstagramController::connect` (`:47`) writes a
`pending` placeholder, dispatches, returns **202**, and exposes `connectStatus` (`:122`) —
explicitly to avoid a ~110 s inline Apify scrape. `Booking`/`Reservations`/`CustomLinks`/
`OnlineOrdering` use a zero-I/O `minimalCard()` + `EnrichLinkCardJob` + a `*/status` poll. The
registry-driven `GenericPlatformController::connectDeferred()` (`:157-269`) generalises this but is
**built-and-switched-off** — `config('partna.connect.deferred')` defaults to empty
(`config/partna.php:1396-1401`).

### 5b. Jobs that shouldn't be jobs

Four jobs whose entire `handle()` is a couple of cheap DB writes — `QuarantineMediaJob`,
`SuspendUserJob`, `SuspendSiteJob`, `SyncCustomerMarketingOptInJob`. All are dispatched from
low-frequency, non-latency-sensitive paths, so this is a design smell rather than a live problem.

**The one that matters:** `MediaUploadService::dispatchWithSyncFallback()` (`:449-484`, and
`dispatchVideoJob()` `:542-565`). The documented condition is environment/driver-based and false in
production — but there is a **second** fallback: if the queue push itself *throws*, it catches and
runs `dispatchSync` (`:469-483`). So during a Redis outage, the entire image-variant or video
transcode pipeline executes **inline in the request thread**. That is a deliberate resilience
trade-off, but it means upload request latency is explicitly unbounded during a Redis incident.

Both backfill commands (`BackfillUserKvEntries.php:55`, `BackfillSubdomainKvCommand.php:45`) are
confirmed **console-only** — no production request path reaches them.

### 5c. Wrong lane

`supervisor-1` consumes **eleven** queues in a single process (max 2 on deployed envs) under
`balance => false`, i.e. strict listed-priority draining. Consequences, ranked:

1. **Head-of-line blocking is real and measured.** A `CloudflareCachePurgeJob` took **6 s** in the
   live log sample. Its `$timeout` is 180 s. `SendStaffBroadcastEmailsJob` is 120 s and
   `ProcessLogoVariantsJob` 300 s. Any of these occupies a worker that `mail` and `notifications`
   sit behind. With 2 processes, two concurrent long jobs stall **every** transactional email.
2. **Priority order is defensible but `platform_refresh` is misplaced.** `moderation_high` first is
   correct (T&S, low volume). But `platform_refresh` sits 10th of 11 while being the target of the
   hourly uncapped `integrations:refresh` fan-out — the largest burst in the system is aimed at the
   second-lowest-priority queue. Under sustained load it drains last, by construction.
3. **`supervisor-long` runs `scraping` and `gdpr` on one process.** A 600 s `ExportUserDataJob`
   blocks every scrape, and a 600 s `MenuFetchJob` blocks a GDPR export — a regulated-timeline
   workload behind a best-effort one.
4. **`DeleteMediaArtifactsJob` rides the video lane** (`redis_video` / `videos`, `$timeout` 120)
   behind up-to-720 s encodes on a `maxProcesses 1` supervisor. GDPR-relevant deletions queue
   behind bulk transcoding.

**Should transactional mail get its own supervisor?** On the evidence: not yet, and the config
comment's caution is justified — but for a *different* reason than it states (§7.B). The binding
constraint is the 1 GiB box already carrying a 1280 MiB permitted-heap over-commit. A fourth
supervisor adds a middleman + worker (~180 MiB) to a container that cannot afford it. **The correct
sequencing is: right-size the worker instance first, then split the mail lane.** Splitting first
would trade a latency problem for the 2026-07-22 OOM.

---

## 6. Performance per avenue

### Video — `ProcessVideoVariantsJob`, `redis_video`, `supervisor-videos`

Inputs are tightly bounded already: `video_max_duration_seconds` = **30 s** and
`video_max_upload_size` = **200 MB** (`config/partna.php:1312-1313`). Two MP4 tiers are produced
(720p "optimized" + 1080p "maximized") plus a poster, via `ffmpeg` shelled out from the worker
(`exec` confirmed enabled; `imagick`/`gd` present).

- **The lane is serial by construction.** `supervisor-videos.maxProcesses = 1` in every
  environment, so throughput is `3600 / T` videos per hour where `T` is per-video wall-clock.
  *Estimate, not measured:* two x264 tiers of a 30 s clip on a shared 1 vCPU plausibly lands at
  T ≈ 90–240 s → **15–40 videos/hour**. The job's own `$timeout` of 720 s is the hard ceiling; past
  that a video simply fails.
- **Memory is the binding risk, not CPU.** `memory 512` for this supervisor on a 1 GiB box, against
  a total permitted worker heap of 1280 MiB (§3.1). ffmpeg's RSS is *outside* PHP's
  `memory_get_usage()`, so Horizon's threshold cannot see it at all — the container OOM-killer will
  fire first, and an OOM kill means no `failed()` and no temp-file cleanup.
- **`retry_after` 3600 vs `$timeout` 720 is a 2880 s dead zone.** A worker killed mid-encode leaves
  that job unreserved for a full hour before anything can retry it.
- **Temp-file lifecycle is the known weak point**, and the codebase agrees: two separate sweeps
  exist (`gdpr:sweep-purged-video-artifacts` daily, `media:gc-orphaned-video-artifacts` weekly).
  Their existence is the honest signal that SIGKILL-orphaned artifacts are expected.
- **`DeleteMediaArtifactsJob` shares this lane** (`$timeout` 120) and therefore queues behind
  up-to-720 s encodes on a single process — GDPR-relevant deletions behind bulk transcoding.

**Verdict: fix the lane in place. Do NOT move it to Cloudflare Stream.** *(Corrected 2026-07-23; the
first draft called this "the strongest candidate for offloading.")*

Stream's headline is attractive — **$0 encoding**, ingest directly from a URL so R2 originals never
pass through a PHP worker — but the pricing comparison has to include what we pay today, which for
delivery is **nothing**. R2 egress is free, and serving media from an R2 custom domain
(`MEDIA_DISK_URL`, `config/filesystems.php:80`) through our own zone is explicitly permitted:
Cloudflare retired old Section 2.8 and replaced it with a rule allowing video and large files on the
CDN *provided they are hosted on a Cloudflare service like Stream, Images, or R2*.

Stream bills **$5/1,000 min stored** and **$1/1,000 min delivered**. Storage is negligible; delivery
is not, because `video_max_duration_seconds` = 30 s describes *autoplay* clips, so delivered minutes
track pageviews almost 1:1.

| | Today (R2 + our zone) | Cloudflare Stream |
|---|---|---|
| Encoding | 1 already-provisioned Horizon process | free |
| Storage @ 10k clips | ~500 GB R2 ≈ **$7.50/mo** | 5,000 min ≈ **$25/mo** |
| Delivery @ 5M pageviews/mo | **$0** | 250k–2.5M min ≈ **$250–$2,500/mo** |

The cost plan puts *total* scale-tier infrastructure at $875–2,230/mo, so Stream's delivery line alone
could double it — to retire one worker process. **Stream converts a fixed compute cost into a
variable traffic cost**, which is the wrong direction for a product whose traffic is projected to
outgrow its media catalogue by orders of magnitude.

Second cost, understated in the first draft: output becomes HLS/DASH rather than two direct MP4 URLs
plus a poster, so `SiteMediaResource` (`app/Http/Resources/SiteMediaResource.php:72-84`) changes shape
and the sitepage player changes with it — a live frontend + design-system contract, not a detail.

The defects listed above are all real, and all cheap local fixes: close the `retry_after`/`$timeout`
dead zone, resolve the `memory 512`-on-1 GiB over-commit (roadmap #4), and move
`DeleteMediaArtifactsJob` off this supervisor so GDPR deletions stop queueing behind transcodes.
Revisit Stream only if long-form video is ever introduced — where an encoding ladder genuinely earns
its keep — and price the delivery side *first*.

### Image / logo — `ProcessImageVariantsJob`, `ProcessLogoVariantsJob`, queue `images`

- **Decompression-bomb protection is real and correctly placed.** `ImageVariantService::loadImage()`
  calls `getimagesize()` (header-only, a few KB) and throws `UnprocessableImageException` when
  `width * height > partna.image_max_pixels` (24 MP) **before any bitmap allocation**. This is the
  right defence — a byte-size cap alone would not catch it. One hardening note: OWASP guidance and
  ImageMagick's own docs recommend `policy.xml` resource ceilings as a decoder-enforced backstop,
  since a header parser can itself be targeted.
- **`ProcessLogoVariantsJob` has no self-margin before its own hard kill** (pipeline finding
  #JOB-1). Its `$timeout` (300) equals `supervisor-1.timeout` (300), but that equality is
  *coincidental*: `Illuminate\Queue\Worker::timeoutForJob()` prefers the **job's own** `$timeout`
  over the worker option, so the value actually enforced is the job's 300 s and raising the
  supervisor timeout would be a no-op. The defect is that the job leaves no gap before the kill
  boundary, and its `failed()` handler is the *only* path that resets `processing_state` to
  `pending` and dispatches the `ProcessImageVariantsJob` fallback. A run that genuinely consumes
  300 s is killed at the instant its safety net should fire, stranding the logo in `processing`
  until `media:cleanup-stuck-processing` catches it up to ~90 min later. Fix is to lower the job's
  `$timeout` to ~270–280 s; note this also shortens the `GuardsMediaProcessing` lock TTL, which is
  derived as `$timeout + 60`.
- Its `failed()` handler is unusually good: it resets to `pending` and dispatches
  `ProcessImageVariantsJob` as a degraded fallback rather than terminal-failing the logo.

**Verdict: adopt Cloudflare image transformations as a delivery layer only. The lane cannot be
deleted.** *(Corrected 2026-07-23; the first draft claimed "this lane could be deleted entirely.")*

Four things this lane does that no CDN transform can replace:

1. **`ImagePaletteExtractor`** — dominant colours per media, persisted at `ImageVariantService::storePalette()`
   and backfillable via `BackfillMediaPaletteCommand`. Consumed by the design kit.
2. **`LogoProcessorClient`** — background-removed PNG plus vectorized SVG from the self-hosted Worker +
   Container. Cloudflare Images has no background removal, so `ProcessLogoVariantsJob` survives in any
   scenario (and `DesignMediaResource` returns its `svg_url`).
3. **Upload-time pixel-bomb rejection** — the `getimagesize()` guard must reject at upload with a clean
   422. A delivery-time dimension limit cannot do this; by then the bomb is already in R2.
4. **`MediaVariant` rows** — `GalleryImageResource`, `DesignMediaResource` and `SiteMediaResource` all
   return a `variants` map that the frontend and design system consume.

What transforms *do* buy is real: the pipeline emits only 2400 px and 4000 px WebP, so a phone
downloads a 2400 px hero. Right-sizing is an LCP win the current pair cannot deliver without
generating more variants (more compute, more storage).

Pricing is **$0.50 per 1,000 *unique* transformations**, first 5,000/month free, where "unique" means
each source×params combination, **re-billed each calendar month** regardless of cache status.
Transforming a **remote** image already in R2 incurs only the transformation fee — no Cloudflare
storage or delivery charge. Note the axis this bills on: **catalogue size × variants × months**, not
traffic. That is why this is defensible where Stream is not — our catalogue is small
(`site_media` = 47 rows today) while projected traffic is 5M pageviews/mo. Pilot scale sits inside the
free tier outright. Limits to respect: 100 MP source area (the repo's 24 MP cap is stricter),
12,000 px max dimension, AVIF output capped at 1,200 px.

**Recommended shape:** keep the pipeline as the source of truth; layer `/cdn-cgi/image/` responsive
delivery on top of the stored `optimized` variant. Additive, reversible (it is a URL prefix), free at
pilot scale, and it leaves palette, logo processing, upload validation and the API contract untouched.

**Not recommended: Cloudflare Images *storage*.** That is a different product from transformations and
the two are routinely conflated. It would upload originals into Cloudflare, duplicating R2, splitting
the source of truth, and breaking a deliberate invariant — `LogoProcessorClient`'s docblock records
that the container holds no storage credentials specifically "so Comet-Backend remains the sole writer
to R2."

### Scraping — `app/Jobs/Platforms/*`, `redis_scraping`, `supervisor-long`

- **Failures concentrate here: 13 of 16 rows in `failed_jobs` are on `scraping`.**
- **Google Places is uncapped at both layers, and this is the sharpest cost risk in the review.**
  In code: no spend ceiling exists — only burst limiters (`preaccount-places`, 30/min) and a
  `pool_concurrency` of 5; the primary dashboard path `GoogleBusinessController::connect` → 
  `fetchPlaceDetails` (`:101`) has **neither**. `ApifyBudget` (daily cap 300) guards a *different*
  vendor — the Apify Google-Business scraper, not the official Places API. At the vendor: Google's
  billing docs (2026-07-17) state plainly that *"Setting a budget does not automatically cap Google
  Cloud or Google Maps Platform usage or spending."* Budgets are **alerts only**; a hard stop means
  building a Pub/Sub → Cloud Function billing kill-switch, which disables every API on the project.
  Places SKUs run **$5–$35 per 1,000 calls**.
- **Per-vendor throttle coverage is partial.** `RateLimited` is attached to `MenuFetchJob`
  (key `menu`), `InstagramConnectJob` (`instagram`), `GoogleBusinessEnrichJob` (`google-business`),
  `RefreshConnectionJob` (keyed by platform), and the two pre-account jobs. **Not throttled at all:**
  `ConnectFetchJob` (deliberate — documented at `:81-85`, keyless public scrapes must not share the
  paid Apify budget), `GoogleMenuPhotoScanJob`, `EnrichLinkCardJob`, `LinkInBioScanJob`,
  `ScanPreviousWebsiteContentJob`, `WebsiteMenuHtmlScanJob`, `WebsiteMenuPdfScanJob`.
- **No circuit breaker anywhere.** When a vendor is down, each job retries on its own schedule;
  nothing backs off globally. With `tries = 0` on the five `retryUntil` jobs, a vendor outage
  produces sustained retry pressure bounded only by the 10–30 min `retryUntil` windows.
- **Cost-idempotency is weaker than state-idempotency.** Several jobs are content-safe on replay but
  re-bill: `WebsiteMenuHtmlScanJob` (AI `structure()`), `WebsiteMenuPdfScanJob` (OCR + AI),
  `GoogleMenuPhotoScanJob` (up to 12 Mistral OCR calls). `GoogleBusinessEnrichJob` mitigates via
  cache markers but documents at `:64-69` that an eviction re-bills.
- **`supervisor-long` runs `scraping` and `gdpr` on one process** — a 600 s GDPR export blocks every
  scrape, and vice versa.

### Notifications / mail — queues `notifications` + `mail`

- **Broadcast fan-out is properly engineered:** `chunkById(500)` → `Bus::batch` of 200 →
  per-subscriber `insertOrIgnore` on a receipt PK for at-most-once delivery. Unbounded in total, but
  bounded in memory and Redis pipeline size.
- **Four mail jobs stamp their idempotency flag *before* the send** (pipeline `JOB-1`…`JOB-4`, run 3).
  I initially recorded these as "at-most-once by design," which is only half the picture: the
  ordering does prevent duplicates, but a failed send leaves the flag committed, so the retry
  short-circuits, `handle()` returns without throwing, Horizon logs a **success**, and `failed()` —
  the only route to Nightwatch — never fires. `SendAccountDeletionRequestMailJob` is the severe case
  because `failed()` is also the only code that clears the deletion token. The correct ordering
  already exists in the same directory (`SendTransactionalNotificationEmailJob`), which stamps after
  the send and documents the reasoning. See §4.
- **Null-email tolerance is good but not total.** `User::routeNotificationForMail()` is nullable and
  Laravel's `MailChannel:57-60` skips a null route, so the moderation notifications are structurally
  safe. Explicit guards exist in `SendTransactionalNotificationEmailJob:188-199`, `ClaimNotifier:52-55`
  and the three enquiry/subscription jobs. **The one gap:** `SendAccountDeletionRequestMailJob:99-104`
  calls `Mail::to($professional->primary_email)` with no null check — reachable only by a claimed,
  authenticated user, so latent rather than live.
- **Transactional mail sits behind bulk work.** `mail` and `notifications` are 2nd/3rd in
  `supervisor-1`'s strict-priority list, which is correct — but a single long job elsewhere in that
  supervisor (a 180 s Cloudflare purge, a 300 s logo job) occupies one of only two processes.
- **Moderation notify jobs can duplicate.** `NotifyOnCallStaffJob`, `NotifyReportedUserJob` and
  `NotifyReporterJob` write `markDispatched` (commit) → send → `markCompleted` (commit)
  **non-transactionally**, guarded only by `if ($entry->status === 'completed') return;`. A crash
  between send and complete leaves `dispatched`, so the retry re-sends. `NotifyReporterJob` is worst:
  it loops over reporters with no per-recipient idempotency key, so a mid-loop crash re-emails
  everyone already contacted. Contrast `SuspendUserJob`/`SuspendSiteJob`/`QuarantineMediaJob`, which
  wrap all three steps in one transaction and are consequently safe.

### Analytics — `RecordAnalyticsEventJob`, queue `analytics`

- **One job per event.** `QueuedIngestor::ingest()` (`:27-30`) dispatches once per HTTP beacon, and
  `AnalyticsController` calls it once per pageview/click/section_view/section_dwell/item_view/
  session_ping. This is the clearest scaling wall in the system: every visitor interaction becomes a
  Redis round-trip plus a serialize/deserialize cycle plus a Postgres insert, competing for one of
  two `supervisor-1` processes at 7th priority of 11.
- The job itself is cheap and correctly idempotent (`insertOrIgnore` on a minted PK), so the fix is
  batching, not correctness.
- **Rollup tables remain unpopulated.** All reads compute from raw events, and
  `analytics:compute-popularity` aggregates a site's **entire** raw-event history per run
  (`ComputeContentPopularityScores.php:410-503`), bounded only by the 90-day purge. Read latency
  therefore grows with retained volume, and the 15-minute cadence multiplies it.

### Cache & edge

- **`SyncSubdomainToKvJob` is verified as the sole KV writer.** `CloudflareKvService` is the only
  class calling the KV REST API, and its only caller is that job. All 15 other sites merely dispatch
  it. The edge Worker reads the namespace and never writes it. Invariant holds.
- `WarmPublicSiteCacheJob` is `ShouldBeUnique` on the lowercased subdomain with `uniqueFor` 120 >
  `$timeout` 10 — correct.
- **Live signal:** `AggregateCacheMetricsJob` reported `prefix=pro hit_rate=46.8%` and
  `prefix=site hit_rate=46.7%` against a 90% SLO at 06:00. At 3,944 total site visits this is
  low-traffic noise (cold keys dominate), not a defect — but it means the SLO alert is firing
  continuously and will be tuned out by habituation before it ever signals something real.
- `CloudflareCachePurgeJob` took **6 s** in the live sample, on the shared `supervisor-1`.

### GDPR / deletion

- **`ExportUserDataJob` genuinely streams**, on both ends: the payload builder is driven as a
  `Generator` writing row-by-row to an open file handle (`:84-88`), and the upload is
  `$disk->put($remotePath, $stream)` from `fopen(...,'rb')` (`:93-94`). No full materialisation.
  The docblock at `:118-121` documents email delivery as deliberately at-least-once.
- Sweeper coverage is the most thorough in the codebase. The one uncovered case is **orphaned
  `platforms/` mirrored media** (§3.4).

---

## 7. Research findings

Every claim below was fetched during this review; URLs given inline. A subsection at the end lists
where current documentation **contradicts** this repo's assumptions.

**Queue mechanics (Laravel 12.62 / framework source).**
`LuaScripts::pop()` sets the `:reserved` ZSET score to `now + retry_after` **at reservation time**,
and `RedisQueue::migrate()` → `migrateExpiredJobs()` sweeps that ZSET on every worker poll and
blindly `RPUSH`es expired entries back onto the live queue — **with no fencing or ownership check**.
The docs' warning is verbatim: *"If your `--timeout` option is longer than your `retry_after`
configuration value, your jobs may be processed twice."*
(https://laravel.com/docs/12.x/queues#worker-timeouts)

**`RateLimited` consumes an attempt.** *"Releasing a rate limited job back onto the queue will still
increment the job's total number of `attempts`."* The documented remedy is `tries = 0` +
`retryUntil` — which all five rate-limited jobs here implement.
(https://laravel.com/docs/12.x/queues#rate-limiting)

**`$failOnTimeout`.** `failed()` **is** called on a timeout kill, synchronously inside the SIGALRM
handler *before* the process SIGKILLs itself — but only when `$failOnTimeout = true`
(`Worker::registerTimeoutHandler`, `markJobAsFailedIfItShouldFailOnTimeout`).

**`WithoutOverlapping`.** `expiresAfter` defaults to **0 = no expiry**; a hard-killed worker holds
the lock indefinitely unless `->expireAfter()` is called. (This repo's single usage sets it.)

**Horizon `memory` is a restart-after-exceeded threshold, not a cap** — checked *between* jobs via
`memory_get_usage(true)`, then the worker exits cleanly and `WorkerProcess::monitor()` restarts it.
The top-level `memory_limit: 64` applies to the Horizon **master** process only.

**`fast_termination => false`** (this repo's setting) makes the supervisor block **indefinitely**
waiting for in-flight workers on terminate — there is no Horizon-side timeout. The outer bound is
whatever the platform's stop-timeout allows.

**Redis / Valkey durability.** Redis ships `appendonly no` and `save 3600 1 300 100 60 10000`, so
under default RDB-only settings a crash can lose **up to an hour** of enqueued jobs on a
low-write-rate queue. Eviction is key-level with no notion of "this key is a job": under any
`allkeys-*` policy, queued jobs are deleted silently.
(https://redis.io/docs/latest/operate/oss_and_stack/management/persistence/,
https://redis.io/docs/latest/develop/reference/eviction/)

**`block_for: null`** (this repo's setting on all four connections) means **no `BLPOP`** — the worker
polls in PHP userland between `--sleep` intervals. That costs both pickup latency and Redis command
volume. A positive value gives near-instant pickup while still returning control for signal
handling; `0` blocks forever and, per the docs, *"will also prevent signals such as `SIGTERM` from
being handled until the next job has been processed"* — so `0` is the wrong choice for a
zero-downtime deploy.

**Laravel Cloud.** `flex-1gb` = **1 vCPU / 1 GiB**. Horizon must run as a *custom* background
process (`php artisan horizon`), and Cloud restarts it if it exits. Current Horizon 5.x registers a
SIGTERM handler at `MasterSupervisor` level (`ListensForSignals`) that performs the same graceful
path as `horizon:terminate`, so Cloud does not need to invoke that command explicitly — **provided
`pcntl` is available**, which is not documented (§10). A documented autoscaling caveat: each replica
runs its **own independent Horizon master**, so replica count multiplies supervisors, not just
workers.

**Nightwatch coverage.** Issues are created by `report()`-routed exceptions and threshold-breaching
slow executions only. It tracks per-job queued/processed/released/failed counts and p95 durations.
It does **not** monitor queue depth, does **not** detect worker death, and **structurally cannot
detect a job enqueued to a queue with no consumer** — such a job never executes, so no event ever
fires. `Log::warning`/`Log::error` never create issues; only four `issue.*` webhook events exist.
(https://nightwatch.laravel.com/docs/jobs, /webhooks, /logs)

**Package advisories.** No security advisories against `laravel/horizon`, `laravel/nightwatch`, or
`predis/predis` at any version. The two `laravel/framework` GHSAs were both fixed *before* 12.62.0,
so this app is unaffected. **One concrete upgrade is warranted:** Horizon **5.47.2** fixes a
metric-clearing bug that manifests specifically under **phpredis with a scan prefix configured** —
this app runs phpredis with prefix `partna_database_` on Horizon **5.47.0**, matching the bug
conditions exactly. The constraint is already `^5.45`, so `composer update laravel/horizon` suffices.

### Where current docs contradict this repo's assumptions

1. **`config/horizon.php:112-115` — the stated reason for `balance => false` is wrong.**
   The comment claims `false` "is the only strategy that respects `maxProcesses` — `simple`/`auto`
   floor at one worker PER QUEUE (`Supervisor::scale` raises `maxProcesses` to the pool count)."
   `Supervisor::scale()` does exist and does raise `maxProcesses` to the pool count — but it is
   invoked **only** from `SupervisorCommands\Scale`, i.e. the manual `horizon:scale` command and
   dashboard control. The automatic path (`Supervisor::loop()` → `autoScale()` → `AutoScaler::scale()`
   → `scalePool()`) treats `maxProcesses` as a **hard ceiling** and never raises it.
   **The real behaviour is worse than the comment claims, not better:** with `simple`/`auto` and
   `maxProcesses` (2) < queue count (11), the first pools to claim workers exhaust the budget and the
   remaining queues get **zero** workers — starvation, not a floor of one.
   **The conclusion still stands** — `balance => false` remains correct here — but the justification
   should be restated, because someone reasoning from the current comment would draw the wrong
   inference about what `auto` would do.

2. **`CLAUDE.md:30` says "PHP 8.2".** `composer.json` requires `php: ^8.4` and Laravel Cloud runs
   **8.4.23**. Minor, but it is the stated stack.

3. **The prompt's own premise — "this repo runs `predis/predis ^3.3` (pure PHP)" — is false in the
   deployed environment.** `REDIS_CLIENT=phpredis` is set and `phpredis 6.3.0` is loaded. Laravel's
   own docs name phpredis as the recommended client and predis as the fallback, so this repo is
   already on the recommended path. `predis/predis v3.4.2` is a direct requirement in
   `composer.json` but is not exercised at runtime.

4. **`docs/deploy/queue-worker-cutover.md` §A3 and §9 are now stale in a good way.** §A3 flags
   `CLAUDE.md:46` as documenting the Redis DB mapping wrongly — `CLAUDE.md:35` now states it
   correctly, matching my runtime probe. §9 finding 2 says *"the dev scheduler has never ticked"* —
   it is ticking now (`CheckStreamingLiveStatusJob` every 2 min in the live log, `/api/health/scheduler`
   returning 200 to Better Stack). Both are resolved; the doc should be marked so.

5. **`docs/runbooks/drills/03-redis-down.md:60,113` instruct `redis-cli SHUTDOWN NOSAVE` and
   `redis-cli DEBUG SLEEP 15`.** The deployed Valkey ACL denies even `INFO` and `CONFIG` to the
   `application` user (probe: `NOPERM ... 'info'`), so these drill steps are almost certainly not
   executable with app credentials. `01-worker-kill.md` was already corrected to `-n 0`.

---

## 8. Roadmap

### Load model — assumptions stated explicitly

Derived from code, config and live probes; guesses are marked.

| Quantity | Today | Source |
|---|---|---|
| Queue depth, all 14 queues | 0 | live probe |
| `analytics.site_visits` | 3,944 rows | live probe |
| `site_media` | 47 rows | live probe |
| `pre_account_builds` | 11 rows | live probe |
| `failed_jobs` | 16 (13 `scraping`) | live probe |
| Video: max duration / size | 30 s / 200 MB | `config/partna.php:1312-1313` |
| Video encode wall-clock | **GUESS** 90–240 s per clip (2 tiers, shared 1 vCPU) | not measured |
| Analytics events per visitor session | **GUESS** 5–15 (pageview + clicks + section views + dwell + ping) | inferred from `AnalyticsController` beacon types |
| Refresh fan-out | 1 job per due connection per hour, uncapped | `RefreshIntegrationConnectionsCommand.php:32-39` |

**Current load is effectively zero.** There is no measured throughput to extrapolate from, so the
tiers below are structural reasoning about *what saturates first*, not capacity planning.

**At 10×** (~hundreds of users): the first thing to break is **`supervisor-1` head-of-line
blocking**. Two processes drain eleven queues in strict priority; the hourly `integrations:refresh`
fan-out targets `platform_refresh` at 10th priority, so refresh latency degrades first and silently.
Analytics (7th) begins to lag behind pageview arrival. Nothing alerts, because backlog alerting is
inert.

**At 100×**: three walls, roughly simultaneously.
1. **Analytics job-per-event** saturates the shared workers outright.
2. **Valkey 250 MB** becomes a real constraint with queue + cache + sessions + locks co-resident —
   and *this* is when the eviction policy stops being theoretical.
3. **The unbounded prunes meet `statement_timeout = 30s`** and begin failing permanently, so
   retention enforcement stops while the tables that need pruning are the ones growing.

### Prioritised changes

| # | Change | Problem it solves | Tier | Effort | Risk | Blast radius | Reversible |
|---|---|---|---|---|---|---|---|
| 0a | **Reorder stamp-after-send in the 4 mail jobs** (`JOB-1`…`JOB-4`) | Silent permanent loss of transactional mail; GDPR deletion stranded with no alert | pilot | **M** | Low | 4 jobs | Yes |
| 0b | **Fix `RES-1` Apify double-spend accounting** | One bot-blocked store can exhaust a global daily cap shared by 3 features | pilot | **M** | Low | Menu scrape | Yes |
| 0c | **Add `failed()` + `report()` to the 8 silent jobs** (`OBS-1`…`OBS-6`) | Terminal failures reach `failed_jobs` with no Nightwatch signal | pilot | **M** | Low | 8 jobs | Yes |
| 1 | **Verify/set Valkey `maxmemory-policy = noeviction`** | Silent job eviction — the only failure here that loses work with zero trace | pilot | **S** | Low | Whole queue | Yes |
| 2 | **Set `HORIZON_NOTIFICATION_EMAIL`** | Activates 12 already-tuned `waits` thresholds; today backlog is unmonitored end-to-end | pilot | **S** | Low | None | Yes |
| 3 | **Add `$uniqueFor` to `LinkInBioScanJob` + `ScanPreviousWebsiteContentJob`; fix the guard's `is_int` skip** | Permanent `SETNX` lock silently black-holes all future dispatches for that key | pilot | **S** | Low | 2 jobs | Yes |
| 4 | **Resolve the 1280 MiB-on-1024 MiB over-commit** — raise the Worker instance or lower `supervisor-videos.memory` | OOM kill → no `failed()`, orphaned locks and temp files | pilot | **S** | Low | Worker box | Yes |
| 5 | **Cap + stagger `integrations:refresh`** | Uncapped hourly fan-out aimed at a near-lowest-priority queue | pilot | **S/M** | Low | Refresh freshness | Yes |
| 6 | **Add a Google Places spend ceiling in code** (an `ApifyBudget`-style gate around `GoogleBusinessService`) | The only uncapped paid API; the vendor offers alerts, not caps | pilot | **M** | Low | GB connect path | Yes |
| 7 | **Upgrade `laravel/horizon` to ≥5.47.2** | Known metric-clearing bug under phpredis + scan prefix — exactly this config | pilot | **S** | Low | Horizon metrics | Yes |
| 8 | **Move `RefreshController::refresh()` to `RefreshConnectionJob`** | Inline vendor scrape in a `foreach` — ~108 s × row count in one request | pilot | **S** | Low | 1 endpoint | Yes |
| 9 | **Set `block_for` to ~5 on all four connections** | `null` means no `BLPOP`: worse pickup latency and higher Redis command volume | launch | **S** | Low | All queues | Yes |
| 10 | **Wrap the 3 moderation `Notify*` jobs in a transaction (or add a per-recipient key)** | Duplicate staff pages and duplicate reporter emails on retry | launch | **M** | Low | Moderation | Yes |
| 11 | **Batch analytics ingest** (buffer + periodic flush, or `Bus::batch`) | Job-per-pageview is the clearest scaling wall | launch | **M/L** | Medium | Analytics only | Yes |
| 12 | **Async+poll the heavy platform connects** (Fresha, Shop, Apple, Eventbrite, Skool) | Up to ~384 s inline; the pattern already exists and is proven | launch | **M/L** | Medium | Connect UX | Yes |
| 13 | **Chunk the four unbounded prunes** | At scale they hit `statement_timeout` and fail permanently, halting PII retention | launch | **S/M** | Low | Prune commands | Yes |
| 14 | **Add a sweeper for orphaned `platforms/` media** | Only uncovered failure class found; currently leaks R2 storage | launch | **M** | Low | R2 | Yes |
| 15 | **Split transactional mail into its own supervisor** — *after* #4 | Mail behind bulk work in a 2-process supervisor | launch | **M** | Medium | Worker memory — **contradicts the OOM caution in `config/horizon.php:96-104`; do not do this before #4** | Yes |
| 16 | **Move `DeleteMediaArtifactsJob` off `supervisor-videos`; close the `retry_after` 3600 / `$timeout` 720 dead zone** | GDPR deletions queue behind up-to-720 s transcodes; a killed encode is unreserved for a full hour | launch | **S** | Low | Video lane | Yes |
| 17 | **Add `/cdn-cgi/image/` responsive delivery on top of the stored `optimized` variant** | Phones currently download a 2400 px hero; free under 5k unique transforms/mo; reversible URL prefix | scale | **M** | Low | Image URLs only — pipeline, palette, logo and API contract all unchanged | Yes |
| 18 | **Per-vendor circuit breakers** | No global backoff; a down vendor is retried per-job indefinitely | scale | **M** | Low | Scraping | Yes |
| 19 | **Enable `REDIS_IGBINARY`** | `igbinary` is present but unused; ~3× payload memory saving on a 250 MB instance | scale | **S** | Medium — **requires a cache flush on deploy** (`config/database.php:174-185`) | Cache | Yes |
| 20 | **Drop `predis/predis`** | Unused at runtime; hygiene only, no security or perf argument | scale | **S** | Low | None | Yes |

**Backpressure.** Nothing currently notices a growing queue. #2 is the minimum viable answer; a
depth-threshold check in the existing `/api/health/*` surface would be the fuller one.

**Multi-server readiness.** Every scheduled entry already uses `->onOneServer()` and the lock store
is a shared Redis DB, so the correctness groundwork is done. The caveat from Cloud's docs: a Worker
cluster scaled past one replica runs **one Horizon master per replica**, so `maxProcesses` is
per-replica, not global — the memory arithmetic in §3.1 must be redone before scaling horizontally.

---

## 9. Open questions for Josh

1. **What is the Valkey `maxmemory-policy` on `partna_dev_cache` (and the prod cache)?** Only the
   Laravel Cloud dashboard can answer — the app's ACL denies `CONFIG`/`INFO`. If it is
   `allkeys-lru`, queued jobs can be evicted silently and this becomes the top P0.
2. **Is 250 MB the right Valkey size** for queue + cache + sessions + locks + Horizon metrics once
   real traffic starts? It is the smallest tier offered.
3. **Is the `Worker cluster` at `flex-1gb` intentional?** The permitted worker heap is 1280 MiB.
   Either raise the instance or lower `supervisor-videos.memory`.
4. **Do you want backlog alerting on?** Setting `HORIZON_NOTIFICATION_EMAIL` is a one-line env
   change that activates twelve already-tuned thresholds.
5. **Is `menu:retry-unavailable`'s 6-hour abandonment window intended?** Past it, a menu is stuck
   until a human clicks refresh, with no alert.
6. ~~**Video: keep ffmpeg in-worker, or move to Cloudflare Stream?**~~ **Resolved 2026-07-23: keep
   ffmpeg in-worker.** Stream's free encoding is outweighed by metered delivery ($1/1,000 min) against
   a $0 incumbent, on 30 s autoplay clips where delivered minutes track pageviews — see §6.
7. **Is `isPublic: true` on the Valkey cache required?** It is internet-reachable with password auth.

---

## 10. Unverified — needs a runtime check

1. **Valkey `maxmemory-policy`, `maxmemory`, and persistence mode.** The app ACL denies `CONFIG` and
   `INFO`. Dashboard or Laravel support only. *(This gates finding #1 — it is the one item worth
   checking today.)*
2. **Laravel Cloud's SIGTERM grace period for a custom `php artisan horizon` process.** The only
   published number (90 s) belongs to the unrelated Managed Queues subsystem. With
   `fast_termination => false` and a 720 s video `$timeout`, an encode could be SIGKILLed on deploy.
3. **Whether `pcntl` is loaded** on the Cloud runtime — it gates Horizon's graceful SIGTERM path and
   the `$failOnTimeout` SIGALRM handler. (My probe attempt failed on shell quoting, not on substance.)
4. **Whether `ffmpeg`/`ffprobe` are on `$PATH`** in the worker container. `exec()` is enabled and
   `PARTNA_VIDEO_UPLOADS_ENABLED=true`, but the binaries were not confirmed; Cloud documents ffmpeg
   only as a Private-Cloud add-on.
5. **Actual video encode wall-clock**, needed to turn the §6 throughput estimate into a real number.
6. **Whether the 10 simultaneous `DeleteMirroredMediaJob` R2 4xx failures** (2026-07-23 03:21:15)
   were a credential/permission issue or a transient — the exception is truncated in `failed_jobs`.
