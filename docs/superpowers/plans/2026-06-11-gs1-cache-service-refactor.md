# Task: route every raw `Cache::*` call through the cache-service layer (unblock the GS-1 CI gate)

## Goal
CI's **GS-1** gate — "No raw `Cache::*` calls outside cache services" in `.github/workflows/ci.yml` —
is structurally red on `development`. It fails the build *before* `Run tests` executes. Your job:
eliminate every flagged raw `Cache::{put,remember,rememberForever,forget,add}` call by routing it
through `CacheKeyGenerator` + a `*CacheService` (or `CacheLockService`), keeping behavior identical,
so GS-1 passes and the suite runs. **No `ci.yml` edits, no allowlist additions** — this is the
"refactor everything" path (chosen 2026-06-11).

## Background / why this exists
GS-1 enforces that cache keys flow through `CacheKeyGenerator` and a `*CacheService` so tenant-prefix
discipline is centralised — "a rogue `Cache::put('user_count', $n)` in a controller is one git push
away from a cross-tenant data leak" (the lint's own preamble). The 50+ red CI runs were masked by the
Pint style gate failing first; that was fixed and merged 2026-06-11 (commit `c7c150d2`, PR #203), which
exposed GS-1 as the next blocker. GS-1 is **pre-existing** — 17 real violations predate the Pint work.

### ⚠️ Local repro gotcha (read first)
GS-1 only reproduces locally with **`git grep -P`** (PCRE). macOS/BSD regex silently ignores `\b` in
`git grep -E`, so the CI command shows **0 violations on a Mac** and looks green when it isn't. Always verify with:
```bash
git grep -nP '\bCache::(put|remember|rememberForever|forget|add)\b' -- \
  'app/' ':!app/Services/Cache/' ':!app/Http/Controllers/Api/Webhooks/' \
  ':!app/Http/Controllers/Api/HealthController.php' ':!app/Observers/Core/CustomerObserver.php'
```
This must return **0 lines** when you're done (CI runs the same, on Linux/GNU where `\b` works).

## The cache-service layer you must route through
Canonical layer lives in `app/Services/Cache/` (GS-1-allowlisted):
- **`CacheKeyGenerator`** — builds tenant-safe keys. Use it for every key; do not hand-format key strings.
- **`CacheLockService::rememberLocked(string $key, DateTimeInterface|int $ttl, Closure $cb, int $lockSeconds = 10, int $blockSeconds = 5): mixed`**
  and `rememberLockedNullable(...)` — single-flight + SWR (`:stale` keys). Use for data caches that
  recompute on miss (stampede-safe).
- **`SiteCacheService`**, **`UserCacheService`** — existing domain cache services; mirror their shape
  for any new `*CacheService`.
- Study `docs/caching-gold-standard.md` §7 (invalidation), §10 (pattern table) for TTL/version-key intent.

The GS-1 allowlist (do NOT extend it): `app/Services/Cache/`, `app/Http/Controllers/Api/Webhooks/`,
`app/Http/Controllers/Api/HealthController.php`, `app/Observers/Core/CustomerObserver.php`.

## Full inventory — 17 real calls (10 files) + 5 comment false-positives

### Category A — idempotency / dedup (atomic SETNX `Cache::add`, plus `Cache::forget` cleanup)
Not a key-leak risk; these are atomic locks. Route through a small dedup/idempotency helper in
`app/Services/Cache/` (e.g. a `CacheIdempotencyService::claim($key, $ttl): bool` + `release($key): void`
wrapping `Cache::add`/`forget`), keyed via `CacheKeyGenerator`. Preserve the exact SETNX semantics
(`add` returns false when the key already exists — the retry/skip logic depends on it).
- `app/Http/Controllers/Api/Internal/SupabaseEmailHookController.php:75` `Cache::add($dedupKey, 1, now()->addSeconds(300))`
- `app/Http/Controllers/Api/Internal/SupabaseEmailHookController.php:111` `Cache::forget($dedupKey)` (release on benign retry)
- `app/Http/Middleware/Logging/LogLeadRateLimits.php:57` `Cache::add($dedupKey, 1, self::DEDUP_TTL_SECONDS)`
- `app/Jobs/Notifications/SendFeedbackEmailJob.php:81` `Cache::add($idempotencyKey, true, 86400)`
- `app/Jobs/Notifications/SendFeedbackEmailJob.php:91` `Cache::forget($idempotencyKey)` (release on failure)
- `app/Services/Analytics/AnalyticsDedupGuard.php:24` `Cache::add($key, $mintedUuid, $ttlSeconds)`
- `app/Services/Analytics/AnalyticsCacheService.php:44` `Cache::add("analytics:ingest-debounce:{$userId}", 1, 30)`
  (already a "cache service" by name but lives under `app/Services/Analytics/`, not the allowlisted
  `app/Services/Cache/` — either move the debounce primitive into the Cache layer or call the new
  idempotency helper.)

### Category B — rate-limit counters (`Cache::add`)
- `app/Http/Controllers/Api/Platforms/InstagramController.php:234` `Cache::add($dayKey, 0, now()->addDay())` (daily counter init)
- `app/Http/Controllers/Api/Platforms/InstagramController.php:248` `Cache::add($cooldownKey, 1, self::applyJitter($cooldownSeconds))` (cooldown SETNX)
  Treat like Category A (atomic claim), or add a `RateLimitCacheService`. Keep `applyJitter` behaviour.

### Category C — data caches (`Cache::put` of fetched payloads) — the core GS-1 target
These cache *fetched data* and are exactly what should run through a `*CacheService` + `rememberLocked`.
Prefer converting the surrounding get/put to `CacheLockService::rememberLocked` with a version/TTL key
from `CacheKeyGenerator`. Keep `applyJitter` on the TTL.
- `app/Http/Controllers/Api/Platforms/ShopController.php:115` `Cache::put($this->catalogKey($id), $detectedProducts, ...)`
- `app/Http/Controllers/Api/Platforms/ShopController.php:168` `Cache::put($this->catalogKey($id), $products, ...)`
  (`catalogKey()` is a private key-builder in the controller — move key construction into the service.)
- `app/Services/Platforms/YoutubeThumbnailResolver.php:95` `Cache::put($this->cacheKey($id), $hasMaxres ? 'maxres' : 'hq', ...)`

### Category D — idempotent-response cache (`Cache::put` of a response envelope)
- `app/Http/Middleware/IdempotencyKey.php:117` `Cache::put($cacheKey, ['v'=>1,'status'=>..,'body'=>..,'headers'=>..], self::TTL_SEC)`
  Route the get/put pair through a service method (e.g. `IdempotencyCacheService::remember/store`),
  keys via `CacheKeyGenerator`. Preserve the `v`/status/body/headers envelope and TTL exactly.

### Category E — invalidation (`Cache::forget`)
- `app/Services/FeatureFlags/FeatureFlagService.php:172` `Cache::forget(self::REGISTRY_KEY)`
- `app/Services/FeatureFlags/FeatureFlagService.php:173` `Cache::forget(self::REGISTRY_KEY.':stale')`
- `app/Services/FeatureFlags/FeatureFlagService.php:192` `Cache::forget("ff:pro:{$proId}")`
- `app/Services/FeatureFlags/FeatureFlagService.php:193` `Cache::forget("ff:pro:{$proId}:stale")`
  Add forget methods on a `FeatureFlagCacheService` (or extend an existing one) that build the same keys
  (primary + `:stale`) via `CacheKeyGenerator`. The flag read-path keys must match the new generator
  output exactly — change reads and forgets together so they stay in sync.

### Comment false-positives (5) — also block GS-1; reword so the literal token no longer matches
The grep matches `Cache::add`/`Cache::put`/`Cache::forget` inside comments. Reword these to e.g.
`cache add()` / `cache-add SETNX` so the `Cache::<verb>` literal is gone, without losing meaning:
- `app/Http/Controllers/Api/Internal/SupabaseEmailHookController.php:107` (`// ... the retry sees Cache::add return false ...`)
- `app/Http/Controllers/Api/Platforms/InstagramController.php:228` (`// Cache::get + Cache::put read-modify-write ...`)
- `app/Http/Controllers/Api/PublicSite/IndividualProfileController.php:41` (`* ... Cache::forget needed in observers.`)
- `app/Http/Middleware/Logging/LogLeadRateLimits.php:54` (`// ... Cache::add is atomic SETNX ...`)
- `app/Services/Analytics/AnalyticsDedupGuard.php:9` (`// ... atomic SETNX (Cache::add) ...`)

## Procedure
1. **Pre-flight:** `git fetch origin && git switch -c chore/gs1-cache-service-refactor origin/development`.
   Run the `git grep -nP` command above — confirm the 17 (record the exact set; the line numbers here
   are from `development` @ post-`c7c150d2` and may drift).
2. **GATE A — tests green first:** `composer test` (main checkout, **not** a `.claude/worktrees/` dir —
   feature tests break there). If red, stop and report.
3. **Refactor by category.** One service concept at a time, smallest blast radius. Behavior-preserving:
   same keys' effective namespace, same TTLs (keep `applyJitter`), same SETNX return-value semantics,
   same envelope shapes. Add/extend `*CacheService` classes under `app/Services/Cache/`.
4. **Reword the 5 comments** so the `Cache::<verb>` literal disappears.
5. **GATE B — GS-1 clean:** the `git grep -nP ...` command returns **0 lines**.
6. **GATE C — behavior unchanged:** `composer test` still green. Add/adjust Pest tests for any new
   service method (idempotency claim/release, rememberLocked data path, forget invalidation).
7. **Pint stays clean:** `vendor/bin/pint --test` passes (scope any `vendor/bin/pint` fixes to your own
   changed lines — the repo baseline is now clean as of `c7c150d2`; don't re-churn it).
8. **PR → development.** Wait for CI to reach and pass **both** GS-1 and `Run tests` (this is the first
   time `Run tests` will run in CI in a long while — watch it). Merge with a **merge commit** (repo
   convention), delete the branch local + remote.

## Hard constraints
- **Do NOT edit `.github/workflows/ci.yml`** or add to the GS-1 allowlist. The whole point is to satisfy
  the lint, not exempt code from it.
- **Behavior-preserving only.** GS-1 guards cross-tenant cache-key safety — do not change a key's
  effective value in a way that orphans live cache entries without also updating every reader. Change
  reads + writes + invalidations of the same key together.
- Preserve atomic `Cache::add` SETNX semantics — idempotency/dedup correctness depends on the
  false-on-exists return value.
- Don't bundle unrelated changes. One focused PR.

## Definition of done
`git grep -nP '\bCache::(put|remember|rememberForever|forget|add)\b'` over the non-allowlisted paths
returns 0; `composer test` green; `vendor/bin/pint --test` passes; CI on the merge is GREEN
**end-to-end including `Run tests`**; branch deleted.

---
*Authored 2026-06-11 as the agreed follow-up to the Pint-baseline CI unblock (PR #203 / `c7c150d2`).
Line numbers are a snapshot — re-run the `git grep -nP` to get the live set before editing.*
