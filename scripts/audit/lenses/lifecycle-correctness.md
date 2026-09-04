# Lifecycle correctness: race-safety, idempotency, anchor decoupling, reconcile loops, vendor resilience, observability discipline

Find lifecycle / state-machine / vendor-integration bugs that match the house doctrine patterns codified below. These bug shapes recur anywhere the system writes a state transition, dispatches a retry, fires a periodic notification, holds external state in flight, or caches a hot read across writes.

The goal is **durability as the platform grows from pre-beta to thousands of users**, not just pre-pilot readiness. Findings must justify themselves against the **scale context** below — but a finding that only manifests at scale is still in scope.

This lens is a **sibling** to `scaling-antipatterns.md`. That lens covers rebuild-on-write, write amplification, weak caching, and aggregate-tables-that-should-be-live-queries. **Run this lens for everything else** — race conditions, idempotency, anchor fields, retry loops, in-flight state mutations, vendor API hygiene, log discrimination, policy gating. Findings that overlap with the scaling lens should be emitted under whichever lens is more specific; the adjudicator dedupes.

## Scale context (every finding must justify against this)

Pre-beta; no paying customers yet, but foundational code must be durable. Traffic shape:
- **Hottest path:** public sitepage resolution — mostly served from Cloudflare edge (Worker + KV + Cache API); backend sees cache misses and cache-purge rebuilds. Keep `IndividualProfilePayloadBuilder` payloads cheap.
- **Write-heavy path:** analytics ingest (sitepage events, sessions, regions, platform/product breakdowns) — fire-and-forget jobs on the `analytics` queue.
- **Fan-out paths:** per-user cache invalidation, KV sync, notification dispatch, media/video variants, streaming live-status polling.

Reason about "thousands of users × a single user's page going viral (public-traffic spike)" rather than order volume. Single Supabase Postgres primary, Redis, multi-instance Laravel Cloud (no in-process state survives deploys).

## Use the lens prefix `LIFE` for findings

Number them `LIFE-1`, `LIFE-2`, … sequentially across the whole audit, regardless of category.

## Canonical patterns (house doctrine)

These patterns are the standard that every finding must cite. When a finding violates one of these, name the pattern in the remediation.

- **Race-safe read-modify-write** — `lockForUpdate` on the row being mutated + a `UNIQUE` constraint as an idempotency key + a typed `catch (UniqueConstraintViolationException $e)` (never `catch (QueryException $e)` + string-matching on the message — that form is version-unstable across Postgres releases and constraint renames).
- **Anchor decoupling** — when retries reset a deadline field, keep a separate `*_started_at` field for warning-window arithmetic. The retry-safety field and the warning-clock field are different concerns and must not share a column.
- **JSONB dedup for periodic notifications** — instead of a separate `*_warnings_sent` table, store a `{milestone: timestamp, …}` JSONB on the parent row. Dedup is a single read; retry storms cannot double-fire.
- **In-flight aggregate reconciliation** — when the source-of-truth set shrinks under an in-flight aggregate (cancel, deletion, prune), the aggregate must reconcile, not assume the original snapshot is still valid.
- **Daily reconcile jobs for external-state dependence** — any state that transitions in response to an external event (KV write, platform API callback, streaming status change) must have a reconcile job that catches missed deliveries. Webhooks are at-least-once and occasionally zero.
- **Distinct logs for distinct failure modes** — a function with N distinct outcomes needs N distinct log strings, or a typed return so the caller can branch. Conflated logs are invisible in Nightwatch.
- **Vendor API version pinning** — every vendor client instantiation pins the API version explicitly; auto-upgrades cannot silently break behaviour.
- **Verbatim vendor error capture** — vendor errors stored verbatim on the failing record, not paraphrased. Rephrasing destroys debuggability.
- **Policy over inline role-scoping** — any "show only my records" query goes through a Policy ability (`$this->authorizeForUser($user, 'verb', $resource)`), never inline branching in the controller.
- **Cache lock-timeout fallthrough** — `CacheLockService::rememberLocked` falls through to compute on lock timeout instead of throwing. Cache primitives degrade gracefully under contention; they never become a single point of failure.
- **Jittered 1→N invalidation** — any 1→N cache invalidation dispatched as a synchronous loop is a thundering-herd risk and needs jitter (±20%, via the `JitteredTtl` concern).
- **`:stale` twin busting** — every cache key that has a `:stale` SWR twin must invalidate both halves on the write path (`bust(:stale)` paired with `bust(:fresh)`).
- **Version-keyed caches** — when invalidating individual keys is too brittle, version-key the namespace and bump the version on mutation. Bump is atomic; readers include the version in their key.

## Findings categories

### (1) Idempotency on the write path

- Inserts into events / receipt / movement / alias tables without a `UNIQUE` constraint backing an idempotency key.
- `INSERT … ON CONFLICT DO NOTHING` missing where the same write may be retried (job retry, observer re-fire, concurrent requests).
- `catch (QueryException $e)` + `str_contains` / regex matching on the error message — replace with `catch (UniqueConstraintViolationException $e)`.
- Idempotency-key derivation from a non-deterministic field (e.g. `now()->timestamp`) — must be derived from a deterministic hash of the event or payload.
- Outbound API calls (`Http::` facade, `CloudflareKvService`, `TwitchApiClient`, `KickApiClient`) without an explicit idempotency key passed to the vendor — retries cause duplicates.

### (2) Race-safety on read-modify-write

- Reading a counter / state, computing the new value, writing it back — without `lockForUpdate` or an equivalent advisory lock — across two or more statements.
- Status transitions that read the current status and write a new one without locking — two concurrent requests racing produce a torn state.
- Observers that update aggregate columns on the parent in response to child saves — flag if the update is not a signed-delta or not done under a row lock.
- Handle/subdomain rename: confirm the alias-row insert + site update are atomic and that concurrent renames cannot each succeed, leaving two active aliases pointing to the same user.

### (3) Anchor / time-window correctness

- Periodic-warning logic keyed off a field that is mutated by retries (the anchor-decoupling shape). Pattern: any "T-N day warning" job that reads a deadline column the retry loop also resets — propose a `*_started_at` decoupling.
- "Send a warning every N days" fan-out without a JSONB dedup column on the parent — re-runs spam users.
- Cron-driven jobs that bucket by `whereBetween('field', [now()+N, now()+N+1])` — confirm the bucket field is the right anchor (start-time, not deadline) and that the bucket is exclusive (no overlap with the next bucket).
- `handles:prune-expired-aliases` — confirm it reads `expires_at`, not `reclaim_until`, and that the comparison is `<= now()`, not `< now()` (off-by-one on boundary aliases).
- `expirationTtl` written to Cloudflare KV for alias entries — confirm the value matches `expires_at` in the DB row. Drift between the two means the edge evicts before (or after) DB prunes.

### (4) Reconcile / repair jobs for external-state dependence

- Any state that transitions in response to an external event (Cloudflare KV write, Twitch/Kick live-status poll, platform-connector scrape, streaming token refresh) without a sibling reconcile job that catches missed or stale deliveries.
- `SyncSubdomainToKvJob` — confirm there is a periodic reconcile or consistency check that catches KV entries that diverged from DB state (DB rename happened, KV write failed silently, job was never retried).
- `CheckStreamingLiveStatusJob` — confirm it handles API timeouts and vendor errors by marking the token stale or failing visibly, not by silently leaving the last-known status in place indefinitely.
- Reconcile jobs that exist but do not log when they catch a divergence — silent reconcile is invisible drift; must emit a warning-level log with discriminating context.
- Reconcile jobs that re-trigger downstream side-effects on every run without their own dedup guard.
- Long-lived in-flight states without a "stuck for > N hours" alert path (throw or `$this->fail()` — `Log::warning` alone does not alert Nightwatch).

### (5) In-flight aggregate / batch handling

- Any batch or fan-out that snapshots a set of source rows at dispatch time and processes them later — flag if a deletion or state change between snapshot and processing is not handled (the in-flight reconciliation shape).
- Fan-out that dispatches jobs per-item and has no "retry only failed members" path — full re-run causes double effects on already-succeeded members.
- Mid-flight cancellation / deletion paths that return `null` / `false` / a sentinel — confirm the caller distinguishes "cancelled" from "still in flight" and logs each outcome distinctly.
- Moderation `CaseStateMachine` — confirm that a case deletion or retraction between `NotifyReportedUserJob` dispatch and execution does not cause the job to silently operate on a stale case state.

### (6) Vendor-integration hygiene

- Any vendor client instantiation (`TwitchApiClient`, `KickApiClient`, Cloudflare API, platform scrapers using `Http::`) without an explicit API version pin (env-backed constant or constructor parameter) — auto-upgrades cannot silently break behaviour.
- Vendor error messages paraphrased into our own copy on the failing record instead of stored verbatim — `$this->fail(new \RuntimeException($e->getMessage()))` is correct; `$this->fail(new \RuntimeException('API call failed'))` destroys signal.
- Vendor exception handling that catches a broad base class and continues silently — every catch must re-throw, `$this->fail()` with full context, or be a typed expected-failure.
- Synchronous vendor calls (`Http::` facade, `CloudflareKvService`, `TwitchApiClient`) in observers, Resource classes, or controllers in the request cycle — any vendor latency propagates to user-facing p99.
- `SafeUrlFetcher` bypass — any outbound `Http::get($url)` on a user-supplied URL that does not go through `SafeUrlFetcher` is an SSRF risk and a finding (canonical fix: route through `SafeUrlFetcher`).
- Platform connector scrapers (`PlatformRefresher`) without explicit `$tries` and `$backoff` — vendor outage produces an uncontrolled retry storm.
- Retry loops that do not cap `$backoff` at a sane ceiling — unbounded exponential backoff can exceed Horizon's `retry_after`, causing the job to be treated as a lost job and re-queued from the beginning.

### (7) Authorization & validation hygiene

- Inline role-scoping in controllers (`if ($type === 'individual') { ->where('user_id', $user->id) }`) — replace with a Policy ability.
- Inline `abort(403, ...)` / `abort_unless(...)` — replace with `$this->authorizeForUser($user, 'verb', $resource)` against a Policy. CI rejects these, but flag any that slipped through.
- Inline `validate([...])` calls in controllers — replace with Form Request classes.
- Endpoints that accept a user/site ID from a request param without re-authorizing against the resolved actor.
- 403 where 404 should be — public (`/api/public/`) endpoints must always 404 on missing-or-not-yours; returning 403 reveals the resource exists and enables enumeration.
- `authorizeForUser` vs `authorize` — the standard `authorize()` calls `Gate::forUser(null)` which silently passes under Supabase JWT; flag any use of `authorize()` or `$this->authorize()` in controllers.
- `AccountCapabilities::for($user)` not consulted before acting — notification dispatchers, route guards, and API response construction must check capabilities. Flag any dispatcher or endpoint that acts unconditionally.

### (8) Cache invalidation & graceful degradation

These are the patterns NOT covered by `scaling-antipatterns.md` (which focuses on read-side weak caching). Focus here is on the **write-path invalidation** discipline.

- `Cache::forget(key)` on the write path without also busting the `:stale` SWR twin (the `:stale` twin busting shape).
- 1→N per-user invalidations dispatched as a synchronous `foreach` — must be jittered (the jittered 1→N invalidation shape).
- Cache invalidation that targets individual keys when a version-key bump would be safer — flag any cache namespace invalidated key-by-key from more than three call sites; that is a signal it should be version-keyed.
- `CacheLockService::rememberLocked` calls without a fallthrough on lock timeout — under contention, a single hot key becomes a single point of failure for the public sitepage resolution path.
- Caches busted on "model saved" but not on the upstream config flip that changes the cached value (e.g. a `design_kits` column change should bust the public profile cache, but only `SiteObserver` does it).
- `SiteCacheService` or `UserCacheService` writes that happen before the DB transaction commits — readers between the cache write and the rollback serve phantom state.

### (9) Notification fan-out & dedup

- Fan-out jobs (`DispatchEnquiryNotificationsJob`, `SendStaffBroadcastEmailsJob`) that do not dedup on the recipient × event combination — job retry → duplicate emails.
- Periodic or event-driven notifications without a JSONB dedup column on the parent row (the JSONB dedup shape) — flag any "send on event N" loop without a sent-at guard.
- Fan-out that dispatches one job per recipient without `Bus::batch()` at recipient counts > 100 — Redis pipeline pressure at fan-out peak.
- Notification preference and capability checks done inside the per-recipient job rather than at fan-out time — wasted job dispatches when most recipients are ineligible.
- `AccountCapabilities::for($user)` not checked before dispatching a notification — dispatching to users who cannot receive the notification type wastes queue capacity and may surface unexpected UI states.

### (10) Observability / Nightwatch readiness

- `Log::warning` / `Log::error` calls without `user_id`, `request_id`, and operation name in context — Nightwatch correlation breaks.
- Exception messages without a discriminator (`'something went wrong'`) — Nightwatch grouping is useless.
- Swallowed exceptions: `try { ... } catch (\Throwable $e) { return null; }` without a log or rethrow — Nightwatch never sees it.
- `Log::warning` used as a breadcrumb when an alert is needed — Nightwatch alerts trigger on exceptions and auto-detected slow jobs/routes, NOT on log queries. A soft failure that needs attention must throw or `$this->fail($e)`; a bare `Log::warning` is invisible.
- Heavy log payloads (full vendor API response body) inside a fan-out job — log index OOM at scale.
- Slow-query / slow-job paths without a recognisable controller method or job class name to attribute in Nightwatch.

### (11) Schema correctness adjacent to the patterns above

- Tables that should have `UNIQUE` on (idempotency_key, scope) and don't (alias tables, event tables, job-dedup tables).
- Status columns backed by `VARCHAR` / `TEXT` without a `CHECK` constraint — flag any `status` / `state` column that accepts free-form strings.
- Columns named `*_started_at` / `*_at` where the column is actually a deadline (or vice versa) — naming must match semantics, especially where anchor-decoupling matters.
- `site.site_subdomain_aliases` and `core.user_handle_aliases` — confirm both have the expected `UNIQUE` constraints and `CHECK` on `expires_at > reclaim_until`.
- `site.design_kits` — confirm all columns are `NULLABLE` with no `DEFAULT` expression (defaults live in the design-system package, not the DB).
- Indexes on hot-read paths missing for the joins introduced by current resolver logic (`PublicSiteResolver`, `IndividualProfilePayloadBuilder`).
- `site.sites.architecture_id` — confirm the `CHECK` constraint (`sites_architecture_id_check`) constrains it to exactly `'one'` (single-architecture) with no fallback.

## Per-finding requirements

For every finding:
- Cite the category number (1–11).
- Name the canonical pattern by short label: `lockForUpdate + UNIQUE`, `UniqueConstraintViolationException`, `*_started_at decoupling`, `JSONB dedup`, `daily reconcile job`, `verbatim vendor error capture`, `Policy + Form Request`, `bust :stale twin`, `jittered 1→N invalidation`, `version-keyed cache`, `Bus::batch`, `Log-with-context`, `SafeUrlFetcher`, `DB::afterCommit`, `AccountCapabilities gate`.
- Quantify expected impact at the scale context (thousands of users; viral-traffic spike on one user's page). A finding that is harmless with ten users but dangerous at a thousand is in scope and should say so.
- Quote verbatim evidence from the source files; do not invent line numbers.

## Out of scope — do NOT re-flag

- Shopify, Stripe, Square, Fresha, commerce schema, billing schema, brand/affiliate roles, `CommissionPayoutService`, `commission_ledger_entries` — all removed in the 2026-05-22 standalone strip and must not be referenced as live code.
- Read-side weak caching / stampede / aggregate-tables-that-should-be-live-queries — covered by `scaling-antipatterns.md`. If a finding straddles both, emit under whichever is more specific.
- Findings about adding read replicas / sharding today — current load does not justify it; only flag code that actively prevents moving to replicas later.
- Dormant CSAM/moderation vocabulary — kept on purpose; do not flag as dead code.
- Empty/minimal capability maps in `AccountCapabilities` — deliberate (individual-only today).
- The legacy `'professional'` request-attribute key and `current.pro` alias — deliberate rename deferral.
- `fresha`/`apify` config keys in `config/services.php` — legacy remnants, harmless.
- Larastan-covered symbol-existence issues (undefined methods/properties/classes/config keys).
- Pint style findings — out of scope for correctness lenses.

## Suggested per-domain scope groups

Run the lens against one group at a time. Each group is sized so the DeepSeek scan completes in a single pass and the adjudicator's findings stay re-runnable per domain.

### Group A — Handle/subdomain rename lifecycle + KV sync (canonical two-systems-must-agree surface)
```
--scope app/Services/Site
--scope app/Services/Cache
--scope app/Jobs/Cloudflare
--scope app/Services/Cloudflare
--scope routes/console.php
```

### Group B — Account deletion / GDPR state machine + account lifecycle (segments, early access, pre-account builds)
```
--scope app/Services/User
--scope app/Services/Segments
--scope app/Services/EarlyAccess
--scope app/Services/PreAccount
--scope app/Jobs/Account
--scope app/Jobs/Gdpr
--scope app/Http/Middleware/Context
```

### Group C — Moderation case state machine + audit trail
```
--scope app/Services/Moderation
--scope app/Jobs/Moderation
--scope app/Policies
```

### Group D — Notifications fan-out & dedup
```
--scope app/Services/Notifications
--scope app/Jobs/Notifications
--scope app/Http/Controllers/Api/User/Notifications
```

### Group E — Streaming token lifecycle + platform refreshers
```
--scope app/Services/Streaming
--scope app/Jobs/Streaming
--scope app/Services/Platforms
--scope app/Jobs/Platforms
--scope app/Routing
--scope app/Ingest
--scope app/Content
--scope app/Site
```

### Group F — Observers + cache write-path invalidation
```
--scope app/Observers
--scope app/Services/Cache
```

### Group G — Media variant pipeline (job chains + partial-failure cleanup)
```
--scope app/Jobs
--scope app/Services/Media
```
(Target root-level jobs: `ProcessImageVariantsJob.php`, `ProcessVideoVariantsJob.php`, `DeleteMediaArtifactsJob.php`.)

### Group H — Schema correctness adjacent to all of the above
```
--scope supabase/migrations
```
(Run last; most findings here will reference work flagged in earlier groups.)

## Exhaustiveness directive

Do NOT stop after the first finding in a category. Walk every file in the run's `--scope` and emit a finding for every distinct quotable instance. If three jobs each lack a reconcile guard, that is three findings (`LIFE-1`, `LIFE-2`, `LIFE-3`), not one consolidated finding. If a single file has both a missing `lockForUpdate` and a swallowed exception, that is two findings. The adjudicator will dedupe and re-tier; **under-reporting is the failure mode to avoid**. Aim for breadth — keep going until every file in the group's scope has been read and every distinct quotable instance is recorded.
