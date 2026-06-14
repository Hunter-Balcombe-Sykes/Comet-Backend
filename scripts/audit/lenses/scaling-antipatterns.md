# Scaling antipatterns: write amplification, rebuild-on-write, weak caching

Find write-amplification, rebuild-on-write, and weak-caching patterns in the codebase. These are the three antipattern shapes that cause systems to fall over at scale: a single event that kicks off a full aggregate rebuild, unbounded per-row write loops, and caches that look like protection but aren't (no single-flight, no jitter, TTL-only invalidation).

## Background — the antipattern shapes

**Rebuild-on-write** rewrites an entire aggregate (DELETE + INSERT or recompute-and-overwrite) in response to a single event. The cost scales with total data size, not event payload size — a single ingest job that rebuilds a full analytics period is fine at ten users and catastrophic at ten thousand.

**Write amplification** produces N rows per event where N is unbounded by event payload size. One notification dispatched per recipient eagerly, one row per block per save, one ledger entry per item in a loop — the write volume grows with data cardinality, not with request rate.

**Weak caching** is a cache that provides no real protection: no single-flight lock (stampedes on cold cache), no TTL jitter (synchronised thundering herd at deploy time), TTL-only invalidation (dashboard shows stale data equal to TTL), or cache facades that fall back to file driver in production.

The highest-risk surfaces today:
- **Analytics v2 ingest**: `app/Services/Analytics/{Ingestors,Writers}`, `app/Jobs/Analytics/RecordAnalyticsEventJob.php` — the write-heavy path (skeleton sitepage events, sessions, regions, platform/product breakdowns). Any per-event rebuild or unbounded write loop here is the most acute risk.
- **Notification fan-out**: `app/Jobs/Notifications/`, `app/Services/Notifications/NotificationPublisher.php` — per-user dispatch that could fan out to all users on a broadcast.
- **Observer-triggered work**: `app/Observers/` — per-save job dispatches that multiply under bulk operations.

## Use the lens prefix `CACHE` for findings

Number them `CACHE-1`, `CACHE-2`, … sequentially across the whole audit, regardless of category.

## Findings categories

### (1) Rebuild-on-write

Jobs or services with `rebuild*Day` / `rebuild*Hour` / `rebuild*Period` / `recompute*` methods that `DELETE`-then-`INSERT` or overwrite an aggregate in response to a single user or webhook event. Flag any dispatch site that fires a full recompute job for a single event.

The canonical replacement: a trigger-maintained signed-delta rollup (append-only event log + AFTER-INSERT trigger applies signed delta to a mutable projection) or — if cardinality is low enough — a live query fronted by `CacheLockService::rememberLocked` (60s TTL + ±20% jitter + SWR + push invalidation on write).

### (2) Write amplification

Handlers that produce N rows per event where N is unbounded by event payload size:
- Per-row write loops inside jobs or service methods (one DB insert per item, per recipient, per block in a `foreach`).
- New `*_events` / `*_entries` / `*_receipts` projections that become the source of truth for aggregate reads when a single row + JSONB would suffice.
- Notification dispatch that creates one `NotificationReceipt` per recipient at fan-out time, before any recipient has read or clicked — prefer lazy materialisation.
- Observer hooks that dispatch multiple independent jobs per single model save — chain them or batch them.

### (3) Weak cache on hot reads

- `Cache::remember` or `cache()->remember` in dashboard controllers/services without single-flight (no `CacheLockService::rememberLocked` lock) — stampede risk on cold cache after a deploy or eviction.
- Caches with no TTL jitter on hot keys (synchronised expiration → thundering herd).
- Caches with TTL only and no push-invalidation on the write path (page/dashboard goes stale rather than fresh).
- Cache facades that don't pin to Redis (file driver fallback defeats the cache under load).
- Missing version-token pattern where an upstream config flip should bust a cache (see `analyticsSummaryVersion` in `CacheKeyGenerator` and `AnalyticsCacheService::bumpVersion` as the reference pattern).
- `INF` / `null` / `0` TTLs on hot keys.
- `Cache::forget` on a single key where a prefix-flush or stale-key cleanup is also needed.

### (4) Aggregate tables that should be live queries

Per-day or per-hour rollup tables read by exactly one endpoint whose source is well-indexed enough that `SUM`/`COUNT` over a date range is sub-100ms at expected scale (thousands of users; analytics v2 events tables are append-only with indexed `user_id` + `occurred_at`). Aggregate columns that always equal `SUM(child.x) WHERE child.parent_id = id` and are kept in sync via observers are safer as a DB-level rollup or live query.

### (5) Hot-path heavy work and fan-out

- Analytics ingest jobs (`app/Jobs/Analytics/RecordAnalyticsEventJob.php`, ingestors under `app/Services/Analytics/Ingestors/`) doing synchronous multi-table work, full re-aggregation, or large JSONB normalisation on the ingest thread — should be fire-and-forget with all heavy work deferred to the `analytics` queue.
- Notification fan-out jobs (`SendStaffBroadcastEmailsJob`, `SendStaffBroadcastEmailToSubscriberJob` and similar) that dispatch one child job per recipient created eagerly at fan-out time rather than lazily on first read/click.
- Observer hooks that dispatch multiple jobs per save — chain or batch them (`Bus::chain`, `Bus::batch`).
- Eager-loaded Eloquent collections that hydrate full models where a `selectRaw` aggregate would do.

### (6) Append-only / mutable confusion

- Tables that are functionally an audit log but get `UPDATE`d (loses auditability — should be append-only event log + separate mutable projection).
- Tables that are functionally a projection but are append-only (forces O(N) scans for current state — should be mutable with an event log alongside).
- For notifications specifically: distinguish the immutable "notification was sent" record from the mutable "user read-state" — they belong in separate tables (`notifications.notifications` vs. a per-user read-receipt table).

## Per-finding requirements

For every finding:
- Cite the category number (1–6).
- Name the canonical replacement: live query + `rememberLocked` + jitter + SWR + push-invalidate, OR trigger-maintained signed-delta rollup, OR append-only event log + mutable projection, OR chunked/batched fan-out.
- Quantify expected impact: reason about "thousands of users × one user's page going viral" (public sitepage path) or "analytics ingest backlog under burst ingest" (write-heavy path), not order volume.

## Out of scope — do NOT re-flag

- The `CacheLockService` implementation itself and `Concerns/JitteredTtl` — these define the gold standard, not the antipattern.
- `app/Services/Cache/SiteCacheService::getPublicSitePayload` — the canonical reference implementation.
- Test-only caches (`Cache::store('array')` in `tests/`).
- Pint/style findings — out of scope for correctness lenses.

## Suggested high-value targets

```
--scope app/Jobs/Analytics
--scope app/Services/Analytics
--scope app/Observers
--scope app/Jobs/Notifications
--scope app/Services/Notifications
--scope app/Jobs/Cache
--scope app/Jobs/Cloudflare
--scope app/Services/Cache
--scope app/Http/Controllers/Api/User/Analytics
--scope app/Http/Controllers/Api/Staff
```

## Exhaustiveness directive

Do NOT stop after the first finding in a category. Walk every file in scope and emit a finding for every distinct instance you can quote evidence for. If three call sites each have an unlocked `Cache::remember`, that is three findings (`CACHE-1`, `CACHE-2`, `CACHE-3`), not one consolidated finding. If two observer hooks each dispatch unbounded fan-out, emit two findings. If a job has both unbounded `foreach` writes and a missing batching call, that is two findings. The adjudicator will dedupe and re-tier; **under-reporting is the failure mode to avoid**. Aim for breadth over consolidation — keep going until every file in `--scope` has been read and every distinct quotable instance is recorded.
