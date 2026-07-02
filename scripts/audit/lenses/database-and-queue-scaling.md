# Database & queue scaling: N+1, unbounded reads, connection scoping, queue shape, vendor budgets, migration safety, backpressure

Find scaling failures of a different shape from the read-side caching / aggregate-rebuild patterns the team has already eliminated. This lens hunts the **Eloquent + Postgres + Horizon + Redis + vendor-API** failure modes that surface at the platform's traffic shape: **public sitepage resolution** (edge-cached; backend sees cache misses + purge rebuilds — hottest path), **analytics ingest** (write-heavy; fire-and-forget on the `analytics` queue), and **fan-out** paths (per-user cache invalidation, KV sync, notification dispatch, media/video variants, streaming live-status polling). Think "thousands of users; one user's page going viral" — not order volume.

This lens is a **sibling** to `scaling-antipatterns.md` (read-side caching) and `lifecycle-correctness.md` (race / idempotency / vendor hygiene). Where the other two lenses focus on a hot read or an in-flight write, this one focuses on **throughput, capacity, and the resources that get exhausted before correctness fails**.

## Use the lens prefix `SCALE` for findings

Number them `SCALE-1`, `SCALE-2`, … sequentially across the whole audit.

## Findings categories

### (1) N+1 query patterns

- `foreach ($collection as $row) { $row->relation->... }` without an upstream `with('relation')`.
- Accessors on `BaseModel` that issue a query each time they're read (`getXAttribute` doing `->where(...)->first()`).
- Resource classes (`app/Http/Resources/*`) that load relations lazily inside `toArray` — every list endpoint hydrates N relations per row.
- `Collection::map` / `Collection::each` issuing per-item queries.
- Observers (`app/Observers/`) that read sibling rows on every save (e.g. a counter recomputed via `->fresh()->children()->count()`).

### (2) Unbounded result sets / memory pressure

- `Model::all()` or `->get()` where the table will grow beyond ~10K rows at scale.
- Jobs that load a full collection into memory (`->get()` then `foreach`) instead of `->chunk(N)` / `->cursor()` / `LazyCollection`.
- `Bus::batch($jobs)` with `$jobs` materialised as a full array of job objects in memory.
- Response endpoints returning unpaginated lists.
- `Log::*` calls that pass a full API response body or full webhook payload — log indices OOM and Nightwatch payload limits kick in.

### (3) Connection pool & transaction scoping

- `DB::transaction(...)` blocks that wrap external API calls (Cloudflare, Twitch, Kick, Resend/Postmark) — connection held while waiting on vendor I/O.
- Transactions that span multiple controller actions or persist across queued-job boundaries.
- Long-running jobs (> 30s) that hold an Eloquent connection open while idle (e.g. waiting on `sleep` or polling).
- Connection leaks: code that opens a non-default connection (e.g. `redis_video`) and doesn't return it to the pool.
- Per-request `DB::reconnect()` calls — flag any that shouldn't be there.

### (4) Queue / Horizon shape

- Jobs that should be on a domain queue (`notifications`, `analytics`, `cloudflare`, `streaming`, `images`, `gdpr`, `mail`, `cache-warm`, `moderation_high`) but land on `default`.
- `config/horizon.php` supervisors with min/max process counts that don't match the traffic shape (verify against the actual `config/horizon.php` — do NOT propose specific numbers without reading it).
- Jobs without `$tries` / `$timeout` — runaway execution on vendor outage. (`$backoff` absence is already CI-enforced by `JobHygienePolicyTest.php` — do not re-flag absence; flag wrong values.)
- Jobs without a `failed()` handler on a path that has user-visible consequences (notifications, KV sync, media pipeline, GDPR).
- `Bus::chain` where order matters but the chain is dispatched on a queue that doesn't guarantee order under contention.
- Missing `WithoutOverlapping` / `Skip` middleware on jobs that should not run concurrently (e.g. per-user cache rebuilds, `SyncSubdomainToKvJob` for the same site).

### (5) Outbound vendor rate-limit budgets

- **Cloudflare API** (`app/Services/Cloudflare/`, `app/Jobs/Cloudflare/`): KV write calls and cache-purge calls in tight loops without rate-limit awareness — Cloudflare KV has per-account write limits; `CloudflareCachePurgeJob` bulk-purge vs individual URL comparison.
- **Twitch / Kick** (`app/Services/Streaming/TwitchApiClient`, `KickApiClient`, `StreamingTokenManager`): token-refresh bursts and live-status poll requests — `CheckStreamingLiveStatusJob` runs every two minutes; verify each poll respects per-token rate limits and that token refresh is cached, not re-fetched per-user-per-tick.
- **Platform connectors** (`app/Services/Platforms/`): per-host politeness on outbound scrapes (Bandcamp, Eventbrite, HumanitixScraper, etc.) and `PlatformRefresher` daily batch — quantify per-host request rate at scale; missing per-host delay is a finding. All fetches must go through `SafeUrlFetcher` — raw `Http::get($userUrl)` on user-supplied URLs is a separate security finding (flag here too as a scale + safety concern).
- **Email** (Resend/Postmark via `app/Jobs/Notifications/`): fan-out notification jobs dispatched without per-user sending-rate awareness — at scale, a single broadcast to all users may exceed provider per-second limits.

### (6) Scheduler stampede

- `routes/console.php` jobs are well-structured (all have `->onOneServer()` and `->withoutOverlapping(N)` per the file's documented conventions) — flag only cases where the `withoutOverlapping` TTL is shorter than or equal to the cadence (same-tick race), or where a high-frequency job (e.g. `everyMinute`) spawns unbounded fan-out.
- `CheckStreamingLiveStatusJob` (`everyTwoMinutes`, `withoutOverlapping(5)`) — verify the job itself does not dispatch one sub-job per active streaming user without a per-user stagger; at thousands of users this is a thundering herd on the `streaming` queue.
- `integrations:refresh` scheduled command — verify it processes rows in bounded batches (it exposes `--limit` and `--throttle-ms`) rather than loading all stale rows in one pass.

### (7) Per-user noisy-neighbour risk

- Shared queues where one user's burst (e.g. bulk media upload, analytics ingest spike on a viral post) can starve other users' jobs — check whether high-volume per-user paths use a dedicated sub-queue or priority.
- Lack of per-user rate limits on the API surface — `config('partna.public_profile.rate_limit_per_minute')` exists; confirm it's actually applied on `IndividualProfileController` and on analytics ingest endpoints.
- Lack of per-user quotas on expensive paths: media transcode (`ProcessVideoVariantsJob`), KV sync (`SyncSubdomainToKvJob`), streaming token refresh — check `config('partna.limits.*')` values are enforced, not just declared.
- Cache keys that collide across users — any key that doesn't include a `user_id` or `site_id` namespace in a multi-user context is a finding.

### (8) Migration safety under load

- `ALTER TABLE` that lacks `CONCURRENTLY` on index creation against tables that will be hot at scale (public profile reads hit `site.sites`, `core.users`, `site.design_kits`).
- `NOT NULL` column additions with a default that requires a full-table rewrite (Postgres 11+ avoids rewrite for `DEFAULT` if the default is a constant — verify each).
- Backfills inside the migration itself instead of as a separate job — long migrations block deploy.
- Missing `SET lock_timeout` / `statement_timeout` on schema changes against hot tables.
- Reversible migrations that re-create indexes synchronously in `down()`.
- New CHECK constraints added without `NOT VALID` + later `VALIDATE CONSTRAINT` for hot tables.

### (9) Backpressure / inbound ingress

- Webhook / hook controllers (`app/Http/Controllers/Api/Webhooks/`, `app/Http/Controllers/Api/Internal/`) that do heavy work synchronously instead of acknowledging fast and dispatching a job.
- Analytics ingest controller — confirm it acknowledges immediately and hands off to `RecordAnalyticsEventJob`; synchronous DB writes on a high-frequency ingest path are a scaling anti-pattern.
- No queue-depth alerting defined — if a Cloudflare outage causes `cloudflare` queue depth to spike, no signal tells Horizon to spin up more workers. Flag if `config/horizon.php` has no balance / auto-scale configuration for the `cloudflare` supervisor.
- Jobs that re-enqueue themselves on transient failure without exponential backoff — synthetic backpressure on the queue.

### (10) Index hygiene & query planner readiness

- New live queries introduced by recent commits (analytics v2, integrations v2, skeleton-system design-kit reads) without verified composite indexes for the `WHERE` + `ORDER BY` + `LIMIT` shape.
- Unindexed JSONB queries — `WHERE settings->>'foo' = 'bar'` on `site.sites.settings` without a GIN index on the relevant path.
- Missing partial indexes where a status filter dominates the query (`WHERE status = 'active'` on a table where most rows are inactive).
- Stale planner stats: tables with > 10% dead tuples likely need `ANALYZE` (only flag if the migration created the table in question).

### (11) Memory pressure in jobs / fan-out

- `Bus::batch()` invocations that build a >10K-job array in memory before dispatch.
- Notification fan-out that hydrates notification-recipient rows eagerly at fan-out time at large recipient counts — prefer cursor/lazy iteration.
- Image / video pipeline jobs (`ProcessImageVariantsJob`, `ProcessVideoVariantsJob`) that load full file contents into PHP memory instead of streaming — flag if `app/Services/Media/` reads entire files before processing.
- Resource classes that hydrate full models when only an ID + display name is rendered.

## Per-finding requirements

For every finding:
- Cite the category number (1–11).
- Name the canonical replacement: `with(...)` eager load, `chunk()` / `cursor()`, `LazyCollection`, `domain-queue routing`, `$tries + $timeout + failed()`, `WithoutOverlapping`, `per-user rate limit`, `CONCURRENTLY index`, `acknowledge-fast + dispatch`, `composite index on (col1, col2)`, `streaming reads`, `Bus::batch(allowFailures: true)`, etc.
- Reason about scale in terms of the platform's actual traffic shape: public-page viral spike on a single user, analytics ingest write volume, streaming poll fan-out per active streamer. A finding harmless at 100 users but P1 at 10K users is in scope.
- Quote verbatim evidence; do not invent.

## Out of scope — do NOT re-flag

- Read-side caching antipatterns (`scaling-antipatterns.md` owns these).
- Race / idempotency / anchor decoupling / reconcile loops (`lifecycle-correctness.md`).
- Commerce schema / Stripe payout pipeline / Shopify / booking / Square / Fresha — removed.
- Findings about adding read replicas or sharding today — only flag code that **actively prevents** moving to replicas later.
- Absence of `$backoff` on ShouldQueue jobs — `JobHygienePolicyTest.php` CI-enforces this; re-flagging is noise.

## Suggested per-domain scope groups

### Group A — Models & resources (N+1 + unbounded reads)
```
--scope app/Models
--scope app/Http/Resources
```

### Group B — Jobs & queue shape
```
--scope app/Jobs
--scope config/horizon.php
--scope config/queue.php
--scope routes/console.php
```

### Group C — Services with vendor I/O & transaction scoping
```
--scope app/Services/Cloudflare
--scope app/Services/Streaming
--scope app/Services/Platforms
--scope app/Services/Media
```

### Group D — Controllers (backpressure, list endpoints, accept-fast)
```
--scope app/Http/Controllers/Api/Webhooks
--scope app/Http/Controllers/Api/User
--scope app/Http/Controllers/Api/Staff
--scope app/Http/Controllers/Api/Internal
--scope app/Http/Controllers/Api/PublicSite
```

### Group E — Migrations under load
```
--scope supabase/migrations
```

## Exhaustiveness directive

Walk every file in the run's `--scope`. Emit a finding for every distinct quotable instance. If three jobs each lack `$timeout`, that is three findings; if a single file has both an N+1 and an unbounded `->get()`, that is two findings. The adjudicator dedupes. **Under-reporting is the failure mode to avoid.**
