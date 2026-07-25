# Sitepage cache freshness — design

**Date:** 2026-07-25
**Status:** approved, ready for implementation plan
**Repos touched:** `backend` (Laravel + `cloudflare-worker/`), `PartnaAu/partna-frontend` (one-line handoff)

## Problem

Two user-visible symptoms, reported together, with two different causes.

1. **The dashboard live preview never reflects a design change.** It stays on the
   pre-change render until the user leaves `/account/design`.
2. **The public sitepage can serve a stale design for minutes** — and in the
   worst case for the full 24-hour edge TTL — with no user-accessible remedy.
   A hard refresh does nothing.

## Evidence

Measured against `themetapunter` on `development`, 2026-07-25 03:50–04:20 UTC.

| Boundary | Measured |
|---|---|
| `handle.resolve` Redis TTL | 30s primary, 300s `:stale` twin (`CacheLockService` writes 10×) |
| `/api/public/profiles/{handle}` | `max-age=30, s-maxage=30`, `cf-cache-status: BYPASS` |
| Astro → API subrequest | **not** edge-cached — 11 backend hits for 10 forced renders |
| Save → Cloudflare edge eviction | ~4–13s |
| Worker edge entry TTL | 86,400s (`PRIMARY_CACHE_TTL_S`), purge-only invalidation |
| Preview iframe reload | ~1.5s after click (400ms `MIN_GAP_MS` + 900ms debounce) |

Reproduction of the stale re-pin, flipping a `design_kits` column with a warm cache:

```
+1s    edge HIT, old value, age=24
+4s    edge EVICTED (origin, age=0)   <- purge landed, re-rendered from STALE data
+8s    edge HIT again, age=3          <- the stale render is now pinned
+36s   origin correct                 <- 30s resolve TTL finally lapsed
+86s   origin correct | edge STILL stale, age=82
+90s   follow-up purge lands -> correct
```

Supporting measurements:

- `?cachebust=12345` returns `x-partna-cache: hit` — `cacheKeyFor()` strips the
  query string, so query-based cache busting cannot work.
- `Cache-Control: no-cache` + `Pragma: no-cache` returns `x-partna-cache: hit` —
  `serveIndividual()` never inspects request cache directives, so a hard refresh
  cannot bust the edge.
- `?architecture=staple` returns `cache-control: no-store` — the existing bypass
  works.
- `invalidateSitePayload()` deletes `handle.resolve` and its `:stale` twin
  correctly (verified `exists: true → false` for both).
- Queues healthy: `cloudflare` lane empty, purge job completes in ~600ms, zero
  `CloudflareCachePurgeJob` entries in `failed_jobs`.

## Root cause

The payload cache key is `public.profile:<handle>:<updated_at_ts>`, deliberately
built so that any mutation rolls the key forward and stale data is structurally
impossible.

But `<updated_at_ts>` is itself read out of `handle.resolve`, a 30s cache
(`IndividualProfileController::show()`, lines 70–99). **The rotation signal lives
inside the cached value**, so the key can only roll as fast as that cache allows.

`invalidateSitePayload()` deletes the resolve entry, but deletion is not
sufficient. A request that read the DB *before* the write committed can call
`Cache::put()` *after* the delete, re-installing the pre-write timestamp with a
fresh 30s lease. `CacheLockService::rememberLocked()` has no fencing against this.
Classic delete-then-stale-set race.

The 24-hour edge TTL then converts that sub-minute race into a day-long outcome:
the purge lands in ~4s, the edge refills from the still-stale API, and pins the
wrong render. The single follow-up purge at +120s is the only rescue, and
`CloudflareCachePurgeJob::handle()` explicitly never chains.

Two independently reasonable decisions compose into the bug. Timestamp-keyed
cache keys are sound. Caching the handle resolve is sound. Putting the former's
input inside the latter is not.

## Design

### 1. Timestamp floor (root cause)

A monotonic floor that the invalidation path controls and the read path cannot
regress below.

- **New:** `CacheKeyGenerator::handleResolveFloor(string $handle): string`
  returning `handle.resolve.floor:<handle>`.
- **`SiteCacheService::invalidateSitePayload()`** writes
  `$site->updated_at->timestamp` to that key with TTL
  `config('partna.public_profile.resolve_floor_ttl', 600)`. When `updated_at` is
  null (theoretically possible on a malformed row) the floor write is skipped
  rather than written as `0` — a `0` floor is a no-op under `max()`, but writing
  it would overwrite a valid higher floor from a prior save.

  **The write must be monotonic (only-raise), not a blind `Cache::put`:**
  `$floor = max((int) Cache::get($key, 0), $ts)` before the put. The null/`0`
  guard above is one instance of a general hazard — `invalidateSitePayload()`
  has many callers beyond the design-save path (`ServiceCategoryObserver`,
  `UserCacheService`'s catch-all via the memoized `$professional->site`
  relation, `ClaimSiteService`, deletion paths), and several can hold a `Site`
  instance whose `updated_at` predates a concurrent save. A blind put from
  such a caller would regress a higher floor written moments earlier, and if
  that coincides with a stale resolve re-set — the exact race being fixed —
  the old timestamp wins again for up to 30s. The read-modify-write is not
  atomic, but it shrinks the exposure from "any invalidation within 600s" to
  microseconds, and its worst case degrades to today's behaviour rather than
  anything new.

  **Invariant — floor writes only ever happen post-commit.** Verified for every
  current caller (`SiteObserver` and `ServiceCategoryObserver` are
  `$afterCommit = true`; `UserSiteController::update`'s explicit second bust
  runs after the save; `ClaimSiteService` invalidates outside its transaction
  closure), but nothing enforces it, and breaking it is worse than the bug
  being fixed: a floor written inside an open transaction hands out the
  post-write key before the data commits, so a racing reader caches
  pre-commit data under the authoritative new key — and since
  `public.profile:*` keys are never explicitly busted (rotation-by-key is the
  design), that entry lives for the full payload TTL plus its stale window.
  The floor-write helper's docblock must state this constraint; any future
  caller of `invalidateSitePayload()` inside a transaction must defer it.
- **`IndividualProfileController::show()`** computes
  `$ts = max((int) $resolved['updated_at_ts'], (int) Cache::get($floorKey, 0))`
  and builds the payload key from `$ts`.

A stale-set can still land in `handle.resolve`, but it can no longer take
effect — for the **timestamp** variant of the race. The `not_found` variant is
*not* covered: a stale-set can re-install `['not_found' => true]` just as it
can re-install an old timestamp, and the controller 404s before ever reaching
the `max()`. That matters for first publish/claim (a just-published site can
404 briefly and the edge could pin that render), not for design edits on an
existing site. The follow-up purge chain (change 3) is the rescue there —
accepted, not fixed, by this design.

The floor read adds a third Redis round-trip to the public-profile hot path
(previously resolve + payload). A per-request cost paid to close a per-save
race — negligible at pilot scale, noted for the record.

**Floor TTL rationale.** The floor must outlive any stale resolve entry that
could carry an older stamp. Resolve primary is 30s with ±20% jitter (≤36s); its
`:stale` twin is 10× (≤360s). 600s clears that with margin.

**Deliberately unchanged:**

- `CacheLockService` — generic and used across the codebase; the fix belongs at
  this one call site, not in shared infrastructure.
- `WarmPublicSiteCacheJob` — it reads the site fresh from the DB, so its key is
  already authoritative. If a later save moves the floor past the job's stamp,
  the job warms a key nobody reads: wasted work, never wrong data. Applying the
  floor there would be *worse* — it would file data-read-at-T under a key
  claiming T′.
- `resolve_cache_ttl` stays at 30s. The floor makes its staleness harmless.

### 2. Preview bypass

**Worker** — `cloudflare-worker/src/index.js`, `serveIndividual()`: add `preview`
to the existing bypass check alongside `skeleton` and `architecture`. Straight to
origin, `no-store`, no cache read, no cache write.

```js
const previewParams = new URL(request.url).searchParams;
if (previewParams.has("preview") || previewParams.has("skeleton") || previewParams.has("architecture")) {
  return finalize(await env.PARTNA_PAGES.fetch(originRequest), {sitepage: true, noStore: true});
}
```

**Frontend handoff** — `app/(app)/account/(dashboard)/design/page.tsx:96`:

```diff
-<iframe key={bump} src={url} title="Live preview of your site" className="size-full" />
+<iframe key={bump} src={`${url}?preview=1`} title="Live preview of your site" className="size-full" />
```

This works **only in combination with change 1**. Bypassing the edge sends the
preview to Astro, which asks the API — and without the floor, that answer can
still be up to 30s stale. Change 1 is what makes the bypass actually return the
user's change.

The concat is safe (`sitepageUrl()` never emits a query string), but note that
when a custom domain is primary and active, `sitepageUrl()` returns the custom
domain — the iframe then carries `?preview=1` on that host. Covered: custom
domains resolve via KV and route into the same `serveIndividual()`
(`index.js` custom-domain branch), where the bypass lives.

The 900ms debounce and 400ms `MIN_GAP_MS` stay as they are. Once both the edge
and the API are guaranteed fresh, ~1.5s is a correct reload point.

**Accepted risk (explicit):** a cache-bypass query param is a cache-busting lever
— anyone appending `?preview=1` skips the edge and reaches the Astro origin. This
hole already exists via `?architecture=` and `?skeleton=`, so it is not new, but
`preview` is a more guessable name. Accepted for now: Cloudflare bot protection
sits in front and pre-beta traffic is nil. Origin rate-limiting is separate work
and explicitly out of scope here.

### 3. Chained follow-up purges (defence in depth)

`CloudflareCachePurgeJob` fires one follow-up at +120s and never chains. Replace
with a bounded depth counter driven by
`config('partna.cache.purge_followup_schedule', [120, 300, 900])` — **absolute
offsets from the primary purge**, not per-hop delays.

**Dispatch topology: the primary dispatches all follow-ups up-front**, one per
schedule entry, each with its own delay and depth — *not* a chain where each
follow-up dispatches the next. A chain loses its tail if any link exhausts its
retries, and the +900s purge is precisely the one a degraded-Cloudflare window
most needs. Up-front dispatch has no such fragility: `uniqueId()`'s depth
discriminator keeps the three from coalescing, and the 30s `uniqueFor` lock
expires long before any delay elapses (consistent with the existing follow-up
lock rationale in the same file). `followUpDepth` then only exists to feed
`uniqueId()` and logging — no job ever re-dispatches.

**Serialization constraint — carried from the `$bulk` precedent in this same
file.** `followUpDepth` must be a **plain property with a class-level default**,
assigned in the constructor body — *never* a promoted readonly parameter. A
promoted property has no class default, so a payload serialized before this
deploy and unserialized after it would leave the property uninitialized and fatal
in `uniqueId()` on retry. The existing `followUp` bool stays as-is so in-flight
payloads keep working.

`uniqueId()` gains the depth so successive follow-ups do not coalesce into each
other:

```php
.($this->followUp ? '|fu'.$this->followUpDepth : '')
```

`uniqueFor` stays 30 for follow-ups and 240 for primaries — unchanged.

`config('partna.cache.purge_followup_seconds')` is superseded by the schedule and
is **removed in this change**, along with its `PARTNA_CACHE_PURGE_FOLLOWUP_SECONDS`
env read. Its docblock currently asserts the value "must exceed the sum of the
payload staleness windows" — an invariant the 120s default already violated
against the 300s figure cited in `CloudflarePurgeService::purgeHandle()`. That
stale claim goes with it; the schedule's own comment states the real contract.

### Out of scope

- Edge TTL stays at 24h (`PRIMARY_CACHE_TTL_S = 86_400`).
- Origin rate-limiting for bypass params.
- Any change to `CacheLockService`.

## Data flow after the change

```
save (design kit)
  ├─ writeDesignKit()                      DB write, committed
  ├─ $site->touch()                        rotates sites.updated_at
  │   └─ SiteObserver::saved (afterCommit)
  │       ├─ invalidateSitePayload()       deletes handle.resolve + :stale
  │       │                                floor = max(existing floor, new ts)   ← new
  │       ├─ CloudflareCachePurgeJob       edge evicted ~4s; follow-ups dispatched
  │       │                                up-front at +120/300/900s
  │       └─ WarmPublicSiteCacheJob        warms payload under the fresh DB stamp
  └─ invalidateSitePayload()               explicit second bust (unchanged)

read (public sitepage OR preview)
  ├─ handle.resolve  → possibly stale ts (race still possible)
  ├─ floor           → authoritative new ts                                       ← new
  └─ key = max(the two)  → always the post-write key → payload rebuilt fresh
```

## Testing

- **Race test** (extend `tests/Feature/Cache/DesignKitCacheInvalidationTest.php`):
  seed `handle.resolve` with an old stamp → invalidate → simulate the in-flight
  writer re-putting the old stamp → assert the controller still resolves the
  *new* payload key. This test must fail without the floor.
- **Floor helper**: key format, that `invalidateSitePayload()` writes it, and that
  the TTL comes from config rather than a literal.
- **Monotonicity**: a floor write carrying an older timestamp does NOT regress
  an existing higher floor (the stale-`Site`-instance caller scenario). This
  test must fail with a blind `Cache::put`.
- **`CloudflareCachePurgeJobTest`**: the primary dispatches one follow-up per
  schedule entry with the configured delays (up-front, no chaining — assert a
  follow-up's `handle()` dispatches nothing), and `uniqueId()` differs per depth.
- **Worker guard**, following the existing `ReservedSubdomainWorkerSyncTest`
  precedent (PHP parses `index.js`, since `cloudflare-worker/` has no JS harness):
  assert `preview` is present in the bypass condition.
- Tests run SQLite while production is Postgres. Nothing here is
  constraint-bound, so no DDL verification is required.

## Rollout and verification

1. Laravel changes deploy with `development`.
2. Worker needs its own `wrangler deploy` — it does **not** ship with the Laravel
   deploy.
3. Frontend one-liner applied separately in `PartnaAu/partna-frontend`.

Verify with the harness used to diagnose this: change a `design_kits` column,
then poll both layers until each flips.

```bash
# origin (bypasses edge) vs edge, same page
curl -sS "https://<handle>.partna.au/?architecture=staple" | grep -oE "\-\-dk-border-radius:[^;]*"
curl -sS -D- -o /dev/null "https://<handle>.partna.au/" | grep -i "x-partna-cache"
```

Success criteria: after a save, the origin reflects the change on the **next**
request (no 30s window), and the edge reflects it within one purge cycle with no
stale re-pin.

## Rejected alternatives

| Alternative | Why not |
|---|---|
| Drop the `handle.resolve` cache entirely | Correct, but adds two DB queries to every public profile request. The cache exists precisely to avoid that under traffic. |
| Delay the edge purge past the resolve TTL | Leaves the race in place and makes *every* change visibly ~40s slower. Trades a rare bug for a constant tax. |
| Reuse `?architecture=` for the preview | Ships without a Worker deploy, but overloads a param meaning "render this layout" to also mean "skip cache". Kept as an emergency stopgap only. |
| Retry-reload the preview iframe | Treats the symptom, still races, wastes renders. |
| Cut `PRIMARY_CACHE_TTL_S` to 1h | Reasonable insurance, but the floor removes the stale source. Revisit if another stale source appears. |
