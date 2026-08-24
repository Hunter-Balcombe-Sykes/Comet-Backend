# Finding C — the 314 ms `supabase:jwks` cache-hit span

**Date:** 2026-07-31 · **Status:** diagnosed, no code change · **Severity:** P3 (measurement artefact, not a defect)
**Sources:** Nightwatch dev `#372` / `#374`; measurements taken on dev via `cloud tinker development`.

## Verdict in one line

It is **not** a Redis cost. It is **first-operation-in-a-cold-container overhead** being billed to
whichever operation happens to run first — and `supabase:jwks` is structurally always first, because
`VerifySupabaseJwt` is auth middleware. Connection establishment is a real but **minority** component
(~41 ms measured, 13 % of 314 ms). Nothing to fix on the Redis path.

## Two corrections to the brief's stated lead

1. **The span is on the `cache` connection (DB 1), not `cache_locks`.** `CacheLockService::rememberLocked`
   opens with a bare `Cache::get($key)` (`CacheLockService.php:87`) against the default store, and
   `config/cache.php:77` resolves that store to `'connection' => cache`. `cache_locks` is reached only
   by `Cache::lock()` — which a **hit** never gets to, because the fast path returns at line 88.
2. **`JWK::parseKeySet` is not inside the span, and the docblock's cost estimate is wrong by ~100×.**
   `VerifySupabaseJwt.php:365` and `:390` both claim "~150-300ms for ES256". Measured on dev: **2.9 ms**.
   That number is load-bearing — it is the entire stated justification for the APCu layer — and it is
   not close.

## What was ruled out, with evidence

| Hypothesis | Verdict | Evidence |
|---|---|---|
| Lock contention inside `rememberLocked` | **Ruled out** | A hit returns at `CacheLockService.php:88`, before `Cache::lock()` is ever constructed. |
| `JWK::parseKeySet` CPU on an APCu miss | **Ruled out** | Runs *after* `fetchJwks()` returns, outside the span. Measured 2.9 ms on dev. |
| Payload size / decompression / unserialize | **Ruled out** | JWKS document is **240 bytes** (339 serialized). `unserialize` × 100 = 0.08 ms total. `REDIS_CACHE_COMPRESSION=false`. |
| Connection establishment | **Confirmed, but insufficient** | See below. |

**Nightwatch's span genuinely does enclose connection establishment.** `Repository::get()` dispatches
`RetrievingKey`, *then* calls `$this->store->get()`, *then* dispatches `CacheHit`; Nightwatch's
`CacheEventSensor` starts its timer on the first and stops on the last. phpredis connects lazily on
first use, so a cold connect lands inside the span. The mechanism is real.

But the magnitude is not. Cold TLS connect + `AUTH` + `SELECT` + `PING` to dev's managed Valkey
(`tls://cache-….ap-southeast-2.caches.laravel.cloud`), measured over 4 rounds with `Redis::purge()`
between each to force a genuine reconnect:

```
round 1 — COLD 40.5 ms | WARM 0.77 ms
round 2 — COLD 42.5 ms | WARM 0.93 ms
round 3 — COLD 41.4 ms | WARM 0.72 ms
round 4 — COLD 40.3 ms | WARM 0.71 ms
```

~41 ms accounts for **13 %** of #372's 314 ms and **8 %** of #374's 492 ms. The rest is not Redis.

## What it actually is

The residual is cold-container overhead. Three independent lines of evidence:

1. **Every *subsequent* Redis op in the same request was fast** — 1 ms × 4 in #372, and 8/2/1/1 ms in
   #374 — on the same connection, same store, same request. Only the first op is expensive.
2. **The two samples disagree (314 ms vs 492 ms).** An intrinsic per-op cost is stable; a cold-start
   penalty varies with container state. It varies.
3. **The same 10× first-call ratio shows up in an unrelated subsystem in the same process.** While
   probing, the first outbound HTTPS call to the JWKS URL cost **748.3 ms**; five identical repeat
   calls in that same process cost **91 / 77 / 55 / 62 / 62 ms**. Redis and HTTP are different
   transports with different libraries — the shared factor is "first use in a fresh process".

Consistent with all of it: **~70 % of both requests' wall time is unaccounted by any span** (756 ms of
1,082 ms in #372; 662 ms of 1,179 ms in #374). The whole request was slow. One Redis op merely wore
the blame because it went first.

## Blast radius

**Not every authenticated request.** It is the first authed request on a freshly-spawned worker.

- Both issues are **single occurrences** — `first_seen == last_seen` on each.
- They are the **only two open route issues in the entire dev environment**. The platform-selection
  endpoints are hit far more than twice; every other hit stayed under the 1,000 ms threshold.
- Frequency is a **dev artefact**: low traffic means workers idle out and respawn constantly. The
  docblock at `VerifySupabaseJwt.php:39` already says so — "workers idle out within seconds during low
  traffic". Under sustained production traffic workers stay warm and this largely disappears.

## Does it explain the k6 805 ms max? — No.

`scripts/launch-check/k6/baseline.js` sends **zero** `Authorization` headers (`grep -ci` → 0) and hits
only `/api/public/profiles/{handle}`, `/api/public/config/social-platforms` and `/api/health`.
`VerifySupabaseJwt` never runs on any of them, so `supabase:jwks` is never read. The jwks span cannot
explain that tail, and the k6 report's Observation 1 should not be read as if it does.

The *mechanism* plausibly transfers — a cold worker's first Redis op would be billed to whichever
public-path cache key came first, and the report itself notes the tail was "concentrated in early
iterations". But that is a separate untested hypothesis about a different set of keys, and it is worth
saying plainly rather than letting the jwks number stand in for it.

> **Tested 2026-08-03 — the mechanism does not transfer, and the "early iterations" premise was
> false.** The re-run tail sat mid-run (165–167 s), warming made it no better, and `max` proved
> non-reproducible (550 ms vs 4,563 ms under identical conditions). Cold start on the public path is
> real but ~+270 ms server-side on the **first request only**. So the cold-start reasoning in this
> document is sound for the *authed* route it was derived from, and simply too small to account for
> the k6 tail. See `scripts/launch-check/k6/results/2026-08-03-baseline-warmup-comparison.md`.

## Recommendation

**Do not fix the 314 ms.** It is a cold-start artefact on a low-traffic dev environment, it is not
Redis-specific, and the only Redis-side lever (connection cost) is 41 ms of it. Chasing it would mean
persistent connections, which trade a real correctness risk for ~40 ms on a request that is already an
outlier.

Two things *are* worth doing, neither in scope here:

| # | Action | Effort | Status |
|---|---|---|---|
| 1 | Correct the "~150-300ms for ES256" claim to the measured figure | XS | ✅ **DONE** — `1cfbcd62`. Four sites, not two (`:39`, `:365`, `:390`, `:561`). Re-measured first against the real dev JWKS to confirm it is genuinely `kty=EC / alg=ES256 / crv=P-256`, so the correction addresses the algorithm the claim names: **2.09 ms first sample, 0.01 ms median over 19 more**. APCu was **kept** — the comment now states its real value (skipping the Redis round-trip, ~1 ms warm / ~41 ms when it pays lazy-connect) instead of a CPU cost that isn't there. Comment-only, proven at token level. |
| 2 | If the k6 tail matters, re-run phase 1 with a warm-up stage and compare | S | ✅ **DONE** — 2026-08-03, three instrumented runs. Result: **the tail is not cold-start**, and the warm-up does not remove it (warm-up max 674.8 ms vs no-warm-up control 550.3 ms). `max` is not reproducible at all — 550 ms vs 4,563 ms under identical conditions — while p50/p95 hold within ~8% across four runs. The origin's own log across 970 requests never exceeded 500 ms. Also found: `/api/public/config/social-platforms` is 100% Cloudflare-edge-served and never reaches the origin, so the baseline was always a blended origin+edge number. Write-up: `scripts/launch-check/k6/results/2026-08-03-baseline-warmup-comparison.md`. |

## Reproduce

```bash
cloud tinker development --code='
use Illuminate\Support\Facades\Redis;
Redis::connection("cache")->ping();
foreach (range(1,4) as $i) {
  Redis::purge("cache");
  $t=microtime(true); Redis::connection("cache")->ping(); $cold=(microtime(true)-$t)*1000;
  $t=microtime(true); Redis::connection("cache")->ping(); $warm=(microtime(true)-$t)*1000;
  printf("round %d — COLD %.1f ms | WARM %.2f ms\n", $i, $cold, $warm);
}'
```
