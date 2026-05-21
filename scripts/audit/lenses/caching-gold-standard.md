# Caching: gold-standard adherence audit

Hunt every cache read/write in the codebase and measure it against the **Partna gold-standard caching pattern** established by `App\Services\Cache\CacheLockService` and deployed across `SiteCacheService`, `ProfessionalCacheService`, and the post-rebuild commerce analytics path (see `docs/analytics-rebuild-plan.md` v3.1, deployed 2026-05-06).

A cache that is correct in isolation can still be wrong: a missing single-flight lock causes stampedes on cold start, a synchronised TTL causes thundering-herd expiry, a TTL-only invalidation strategy lets dashboards go stale across the whole fleet, and a `Cache::forget` that doesn't match the write path makes the cache the source of stale truth. This lens looks for **deviations from the gold standard**, not for the absence of caching.

## The gold standard (what "correct" looks like here)

Every hot, expensive, multi-caller read should use `CacheLockService::rememberLocked` (or `rememberLockedNullable` for nullable returns) and satisfy **all** of:

1. **Single-flight lock** — exactly one regenerator on miss; concurrent callers block on `Cache::lock(...)` and read the freshly filled value. Plain `Cache::remember` / `cache()->remember` on hot keys is a stampede risk.
2. **TTL jitter ±20%** — every int TTL write is jittered (provided automatically by `JitteredTtl` trait). Hardcoded `60` / `300` / `3600` TTLs written through `Cache::put` or `Cache::remember` synchronise expiry across the fleet → thundering herd at the deploy boundary or scheduled flush.
3. **Stale-while-revalidate (SWR)** — a `$key:stale` companion at 10× TTL returns last-good immediately when the primary expires; the lock-holder recomputes silently. Caches without SWR force every concurrent caller to wait on the closure.
4. **Push invalidation on every write path** — every domain mutation that affects a cached read must call the matching `*CacheService::invalidate*` / `Cache::forget` / version-token bump. TTL-only invalidation = guaranteed stale window equal to TTL.
5. **Version-token pattern** for cross-cutting busts — `analyticsSummaryVersion`-style monotonic integers in cache keys allow O(1) bulk invalidation on config flips (feature flag toggle, settings change, brand reconnect) without scanning Redis.
6. **Pinned to Redis** — never the file or array driver in production. The `cache_locks` connection (separate Redis DB) must be used for lock keys so `Cache::flush()` on the data store does not release held locks.
7. **Bounded TTL** — no `INF`, `null`, `0`, or unbounded TTLs on user-data caches. Permanent caches require an explicit invalidation path on the write side or they become permanent bugs.
8. **Key generation centralised** — keys come from `CacheKeyGenerator` (or an equivalent domain helper), not ad-hoc string concatenation. Drift between writer and reader keys is a silent cache miss.

## Use the lens prefix `CCH` for findings

Number them `CCH-1`, `CCH-2`, … sequentially across the whole audit, regardless of category. (Note: the `CACHE` prefix is reserved by the broader scaling-antipatterns lens; this lens is the focused gold-standard adherence pass and uses `CCH` to avoid collision.)

## Findings categories

### (1) Missing single-flight (stampede risk)

- `Cache::remember(...)` / `cache()->remember(...)` / `Cache::rememberForever(...)` called on a hot read path **without** going through `CacheLockService::rememberLocked` or holding an explicit `Cache::lock(...)`.
- A "hot read path" here means any of: dashboard controllers under `app/Http/Controllers/Api/{Professional,Staff,Internal,PublicSite}`, the site/profile resolution path, analytics summary endpoints, brand connection status, capability lookups, public site payload, notification unread-count, embedded Shopify settings reads.
- Evidence to quote: the entire `Cache::remember(...)` call including key, TTL, and closure.
- Canonical fix: inject `CacheLockService` and replace with `$this->cache->rememberLocked($key, $ttl, fn() => ...)`.

### (2) Unjittered TTLs

- `Cache::put($key, $value, 60)` / `Cache::remember($key, 3600, ...)` etc. — any literal int TTL on a write that does NOT pass through `CacheLockService` or `JitteredTtl::withJitter(...)`.
- `now()->addMinutes(N)` / `now()->addHours(N)` literals on hot writes — `DateTimeInterface` TTLs sidestep the jitter helper.
- TTL constants on a class (`const TTL = 60`) used directly in `Cache::put` calls — confirm callers route through the jitter helper.
- Canonical fix: route the write through `CacheLockService`, or call `JitteredTtl::withJitter($ttl)` at the write site.

### (3) Missing or broken SWR

- `rememberLocked` calls that are correct but the same value also flows through a separate ad-hoc `Cache::remember` elsewhere that bypasses the `:stale` companion (split-brain reads).
- Read paths that catch a cache miss and synchronously recompute under high concurrency (`Cache::get` + fallback closure pattern) without the `:stale` last-good companion.
- SWR present on read path but invalidation only forgets the primary key, leaving the `:stale` key live → readers see last-good after the user explicitly expected fresh data (e.g. after a settings save).
- Canonical fix: standardise on `rememberLocked` for the read; on invalidation, forget BOTH `$key` and `$key:stale` (or call the matching `CacheLockService` invalidation helper if one exists — propose adding one if not).

### (4) TTL-only invalidation (no push-invalidate on the write path)

- A model `update()` / `save()` / observer hook that mutates data backing a cached read but does NOT call the matching cache service's invalidate method.
- Webhook handlers (Shopify `products/update`, `orders/edited`, Stripe `account.updated`, Square equivalents) that mutate state without invalidating the corresponding cache key.
- Settings writes (`SiteSettings`, `BrandTeam` member changes, `AccountCapabilities` change, design tokens, MFA enrolment) that don't bust the cache keyed off them.
- Soft-delete / restore paths that mutate visibility without bumping the version token or forgetting the resolved-site cache.
- Canonical fix: in the observer or service for the write, call `*CacheService::invalidateX($id)` or bump the version token. Always co-locate the invalidation with the write — never rely on TTL alone for user-visible reads.

### (5) Version-token gaps

- Reads keyed off `Cache::get('analyticsSummaryVersion', 0)` + scope id are correct; reads keyed off raw `$scopeId` without a version-token component cannot be O(1)-busted on config flip.
- Feature-flag flips (`SIDEST_*_ENABLED` env vars), capability matrix changes, brand reconnect events that should bust whole classes of cached reads — confirm a version token exists and is incremented on those events.
- Multi-tenant boundaries: confirm the cache key composition includes a tenant identifier where applicable (brand id, professional id, site id) — cross-tenant leak risk if the key collapses.
- Canonical fix: add a `*Version` integer in cache, increment it in the write path for the relevant event, include it in all reader keys.

### (6) Lock and connection hygiene

- `Cache::lock(...)` calls that lock on the default Redis DB rather than the `cache_locks` connection — `Cache::flush()` will release them.
- Locks with `$lockSeconds` shorter than worst-case closure runtime (e.g. a 5s lock around a closure that calls Shopify Admin API in line — possible 30s+).
- Locks with infinite block time (`->block(0, ...)` mistakes), or no block timeout at all, causing waiting workers to pile up.
- Lock keys constructed ad-hoc rather than auto-derived from the cache key — drift risk between writer and reader.
- Direct use of file / array cache driver in code that runs in production (config-driver-dependent code paths).
- Canonical fix: use `CacheLockService::rememberLocked`; if a bespoke lock is genuinely needed, pin it to `Cache::store('cache_locks')->lock(...)` with a `$lockSeconds` that exceeds P99 closure runtime + a `$blockSeconds` that reflects acceptable request tail latency.

### (7) Unbounded / pathological TTLs

- `Cache::rememberForever(...)` on user-mutable data — permanent caches require an explicit invalidation path; flag every site and confirm a corresponding write-path forget exists.
- `Cache::put($key, $value, null)` / `Cache::put($key, $value, 0)` — flag as bugs.
- TTLs measured in days/weeks on data that changes within hours (settings, capabilities, integration status, brand-affiliate links).
- TTLs measured in milliseconds (almost certainly a unit bug — Laravel expects seconds in most cases).

### (8) Key generation drift

- Cache keys built via string concatenation (`"site:".$id.":payload"`) in some call sites and via `CacheKeyGenerator` (or a domain helper) in others, for the same logical key — silent miss on reader/writer mismatch.
- Keys that include unstable inputs (`now()->timestamp`, request-id) and so never hit.
- Keys missing necessary scoping components (no tenant id, no version token, no locale where the cached value varies by locale).
- Canonical fix: every key for a given cached value originates from one helper method; readers and writers call the same helper.

### (9) Cache-then-database race on writes

- Code path that writes to cache before the DB transaction commits (cache shows new state, DB rolls back, cache lies).
- `Cache::put(...)` inside a DB transaction without an after-commit hook — flag and recommend `DB::afterCommit(fn() => $cache->forget($key))` for invalidations and `DB::afterCommit(fn() => $cache->put(...))` for warmups.
- Cache invalidation on `creating` / `updating` model events rather than `created` / `updated` / `saved` — TOCTOU window where readers see the new key before the row exists, or stale key after the row mutated but before invalidation fires.

### (10) Read-through that hides errors

- Closure inside `Cache::remember` that catches and swallows exceptions, caching an empty/sentinel value that pollutes the cache for the full TTL.
- `try { ... } catch { return []; }` patterns inside cached closures — silent failure becomes a fleet-wide stale-empty until TTL expiry.
- Canonical fix: let exceptions bubble (Nightwatch surfaces them); don't cache failure modes. If a sentinel is needed for negative caching, use `rememberLockedNullable` with a SHORT TTL and document the choice.

## Per-finding requirements

For every finding:
- Cite the category number (1–10).
- Default tier: **P1** for stampede risk on a known hot path (category 1) and for cache-then-DB races on financial/auth paths (category 9). **P2** for missing jitter (2), missing SWR (3), missing push-invalidate (4), version-token gaps (5), and lock hygiene (6). **P3** for unbounded TTLs (7) and key drift (8) where impact is bounded; **P2** if drift causes a known incorrect read.
- Quote verbatim evidence (the `Cache::remember(...)` call, the missing `forget`, the literal TTL, the lock construction).
- Name the canonical replacement: `CacheLockService::rememberLocked`, `JitteredTtl::withJitter`, version-token bump, `DB::afterCommit`, dedicated `cache_locks` connection, `CacheKeyGenerator`-routed key.
- For category 4 specifically: name the write site (file + line) AND the read site (file + line) — a missing push-invalidate is only meaningful when both sides are identified.

## Out of scope — do NOT re-flag

- The `CacheLockService` implementation itself and its `Concerns/JitteredTtl` trait (these define the gold standard).
- The `SiteCacheService::getPublicSitePayload` path (the canonical reference implementation).
- `commerce.orders` / `brand_affiliate_rollup` cached reads via the post-2026-05-06 rebuild (already on the gold-standard pattern).
- Test-only caches (`Cache::store('array')` in `tests/`).
- The `Bus::fake()` / `Cache::spy()` test helpers.

## Suggested per-domain scope groups

### Group A — Cache services and key generators (highest priority — model the bar)
```
--scope app/Services/Cache
```

### Group B — Dashboard read paths
```
--scope app/Http/Controllers/Api/Professional
--scope app/Http/Controllers/Api/Staff
--scope app/Http/Controllers/Api/Internal
--scope app/Http/Controllers/Api/PublicSite
```

### Group C — Write paths that should push-invalidate
```
--scope app/Observers
--scope app/Http/Controllers/Api/Webhooks
--scope app/Http/Controllers/Api/Shopify
--scope app/Services/Shopify
--scope app/Services/Stripe
--scope app/Services/Billing
```

### Group D — Site / handle / capability resolution (hottest paths)
```
--scope app/Services/Site
--scope app/Services/PublicSite
--scope app/Services/Accounts
--scope app/Http/Middleware
```

### Group E — Notifications and analytics caches outside commerce
```
--scope app/Services/Notifications
--scope app/Services/Analytics
--scope app/Jobs/Analytics
--scope app/Jobs/Cache
```

## Exhaustiveness directive

Walk every file in scope. Every `Cache::`, `cache()`, `->remember`, `->rememberForever`, `->put`, `->forget`, `->flush`, `->lock`, `CacheLockService` call site is a candidate. Three controllers each missing a single-flight lock on a hot read = three findings (`CCH-1`, `CCH-2`, `CCH-3`), not one consolidated finding. A write path with both a missing `afterCommit` AND a missing `:stale` forget = two findings. The adjudicator dedupes and re-tiers — **under-reporting is the failure mode**. Aim for breadth over consolidation; the gold standard is concrete and the diff against it should be itemised.
