# Queue priority inversion + origin rate limiting — design

**Date:** 2026-08-07 · **Origin:** k6 phases 2b / 3b (`scripts/launch-check/k6/results/2026-08-06-phases-2a-2b-3.md`)

Two findings from the 2026-08-06 load-test run. Both are latency problems under load; neither
loses data.

---

## Part 1 — Queue priority inversion (IMPLEMENTED)

### Problem

`supervisor-1` runs `balance => false`, so one worker drains its ten queues in **strict priority
order**. `analytics` was listed 5th, **above** `images`.

That is an inversion: `analytics` is the highest-volume and least urgent queue (fire-and-forget
visitor beacons — nobody waits on one), while `images` is low-volume and highly urgent (a user is
watching for their upload to appear).

Measured on dev: a 20,000-job `analytics` backlog held an `images` job **unstarted for the full
4.5-minute drain**, while a `default` job — one rank *above* analytics — ran immediately. At the
measured ~58 jobs/s, an hour-long visitor spike stalls image processing for about an hour.

### Decision: reorder (option A)

```
was:  moderation_high, default, cloudflare, cache-warm, analytics, images, streaming,
                                                        ^^^^^^^^^
      platform_refresh, platform_connect, cloudflare_bulk

now:  moderation_high, default, cloudflare, cache-warm, images, streaming,
      platform_refresh, platform_connect, analytics, cloudflare_bulk
                                          ^^^^^^^^^
```

Rejected alternatives:

| Option | Why not |
|---|---|
| Dedicated `supervisor-analytics` | Costs ~192 MB of the ~320 MB headroom on the 2 GB worker box (current use: 512+256+256+512+192 = 1,728 MB). More robust, but buys little over reordering while eating most of the spare memory. Revisit if analytics volume grows enough to matter. |
| `balance => 'auto'` | Explicitly forbidden by this file's own docblock: `Supervisor::scale()` raises `maxProcesses` toward one-per-queue (ten workers × 256 MB on supervisor-1 alone), which is the 2026-07-22 dev OOM loop. |

Analytics now waits behind the low-volume queues instead. Those are near-empty in practice, so the
wait is seconds — and analytics is precisely the work that can afford to wait.

### Files changed

1. `config/horizon.php` — `defaults.supervisor-1.queue` reordered, with the rationale in-line.
2. `config/horizon.php` — the `waits` composite key, which is **the comma-joined queue array
   verbatim**. `MonitorWaitTimes` reads back exactly the key the supervisor registers, so a reorder
   that misses this string silently drops the lane to the accidental 60 s ceiling. A warning comment
   now sits above it.
3. `tests/Unit/Jobs/HorizonQueueCoverageTest.php` — updated that key's assertion, and added a new
   guard: *"analytics is listed AFTER images on supervisor-1"*, matching the file's existing pattern
   for `cloudflare_bulk`/`cloudflare` and `notifications`/`mail`.

### Verification

`HorizonQueueCoverageTest` 37 passed, plus `HorizonScheduleTest`, `RedisTimeoutBoundsTest`,
`HorizonDashboardAuthTest` (18 passed).

The new guard was **mutation-tested**: reverting the array to the old order made it fail with
`analytics must drain after images`, *and* independently failed the composite-key test — confirming
both the guard and the array↔waits coupling actually bite. (Config copied aside and restored with
`cp`; never `git checkout`, which would have destroyed uncommitted work.)

**Outstanding:** `config/*.php` is compiled at build time by `php artisan optimize`, so this changes
nothing until dev is redeployed. End-to-end proof requires re-running the 20k saturation test after
deploy — see "Re-run plan".

---

## Part 2 — Origin rate limiting at Cloudflare (TO BE APPLIED BY JOSH)

### Problem

The app's `throttle:public-profile` limiter is **route** middleware (`routes/api.php:178`), so a
rejected request has already paid for PHP-FPM handoff, full Laravel boot, and the global middleware
stack — roughly **60 ms of CPU to say "no"**. On a single 1-vCPU box that caps the origin at ~17
req/s, and under a 50-VU flood the origin needed p50 **2.9 s to return a 429** (confirmed by its own
access log). It is container-wide: `/api/health` went **0.20 s → 2.4 s** during the flood.

Both dev and production run the *same* `flex.g-1vcpu-512mb`, single replica, **autoscaling off** —
so this is expected to apply to production, not just dev.

An in-app fix cannot solve this: the framework must boot before any application code runs. The
rejection has to happen *before* the origin.

### The rule

Cloudflare zone `partna.au` is on the **Free** plan, which allows **one** rate-limiting rule.

```
Security → WAF → Rate limiting rules → Create rule

If incoming requests match:
    (http.host in {"api.partna.au" "dev-api.partna.au"}
     and starts_with(http.request.uri.path, "/api/"))

With the same characteristics:  IP
Rate:                           50 requests per 10 seconds
Then take action:               Block
Duration (mitigation timeout):  10 seconds
```

**Why scoped to the API hostnames rather than zone-wide.** Dev and prod share one Cloudflare zone,
and `loadtest.partna.au` — the host k6 phase 2a drives at ~120 req/s from a single IP — lives on it.
A zone-wide rule would throttle that test and destroy its meaning. Scoping to the API hosts protects
the thing that is actually fragile (the origin) and leaves the edge path untouched.

**Threshold rationale.** 50 req / 10 s = 5 req/s per IP. The measured flood ran at ~15 req/s, so the
rule engages on it; a real visitor makes a handful of API calls per page load, nowhere near it. The
app's 60/min limiter stays in place as defence in depth — this is an outer guard, not a replacement.

**Note:** this consumes the Free plan's single rate-limiting rule.

### Not chosen

- **Cache-key normalisation** (making `?rand=` irrelevant so cache-busting is impossible) would be
  elegant, but custom cache keys are Enterprise-only. The Page Rules "Ignore Query String" equivalent
  is too blunt for API routes that use legitimate query parameters (`?limit=50`).
- **Scaling the instance** (2–4 vCPU, or enabling autoscaling) raises the ceiling but costs money per
  environment and does not stop hostile traffic buying origin CPU. Worth revisiting on real traffic;
  not the first move for a pre-beta product.

### Why Claude could not apply it

The deployed `CLOUDFLARE_API_TOKEN` can read the zone (it returned the plan) but returns
`Authentication error` (code 10000) on `/rulesets` — it is scoped to KV, purge, and custom hostnames
only. Applying this needs either a dashboard change or a token with `Zone → Rate Limit → Edit`.

---

## Re-run plan (verification)

After the config is deployed to dev, and again after the Cloudflare rule is live:

| Test | Expectation |
|---|---|
| **3b saturation** (20k jobs + canaries) | `images` canary completes **immediately** rather than waiting for the drain. `default` unchanged. This is the direct proof of Part 1. |
| **2b origin flood** | `origin_5xx == 0` and `origin_429 > 0` still pass (Cloudflare's Block action returns 429). **New check: `/api/health` stays ~0.2 s during the flood** instead of degrading to 2.4 s — this is the real regression test for Part 2. |
| **2a edge spike** | Still 100% cache HIT and unaffected — proves the rule is scoped to the API hosts and did not catch the pages edge. |
| **1 baseline** | Unchanged (p50 ~95 ms, p95 ~205 ms) — confirms no collateral damage. |

Numbers must be written into the k6 results markdown at write-up time: `results/*.json` and
`results/*.txt` are gitignored and will not survive.

---

## Verification results — 2026-08-09, deployed `185d6a587`

Part 1 deployed to dev (auto-deploy on push; `deployment.succeeded`). Confirmed live three ways
before testing: `config()` shows the new order; the `waits` composite key still matches the live
supervisor key; and the **workers' own registered key** reads
`redis:moderation_high,default,cloudflare,cache-warm,images,streaming,platform_refresh,platform_connect,analytics,cloudflare_bulk`
— i.e. the running processes picked it up, not just the config file.

### Part 1 — FIXED, and the before/after is unambiguous

| | before fix | after fix |
|---|---|---|
| analytics backlog when canary dispatched | 15,774 | **16,060** |
| `images` canary cleared after | **4.5 min**, and only once analytics hit 0 | **≤18 s**, with **15,395 analytics jobs still queued** |
| `default` canary | immediate | immediate |

One `images` canary was consumed *within the dispatch call itself*. Clearing a lower-priority job
while 15,000+ analytics jobs remain queued was structurally impossible before the change.

Throughput and integrity unchanged: 20,012 jobs (20,000 + 12 canaries) all landed — **zero lost,
`failed_jobs` 0** — and the queue drained to empty normally.

### Other phases — no regressions

| Phase | Result | vs previous |
|---|---|---|
| 1 baseline | ✅ p50 **77.8 ms**, p95 **164.5 ms**, 904/904 checks, 0% failed, exit 0 | Faster again (was 95.3 / 204.5) |
| 2a edge | ✅ `edge_cache_hit` **99.96%** (26,199/26,209), `http_req_failed` **0.04%** | Was 100% / 0.00%; both still far inside thresholds |
| 2b origin | ✅ exit 0, `origin_5xx` **0**, `origin_429` **810** of 931 | p50 **2,840 ms** vs 2,911 ms — reproduces |

### Part 2 — NOT yet in effect

The rule as first saved used `http.host`, which the **Free plan does not support** — its rate-limiting
expressions are restricted to the **Path** and **Verified Bot** fields only. Verified empirically:
300 requests at 10/s (double the threshold, across three windows) returned 300 × 200, and a
differential test against the correctly-spelled `api.partna.au` also failed to limit — ruling out a
typo and confirming the field restriction.

**Corrected rule — drop the host clause entirely:**

```
starts_with(http.request.uri.path, "/api/")
```

Still correctly scoped, by path rather than host: phase 2a hits `loadtest.partna.au/` (path `/`,
no match) and the baseline runs at ~7.5 req/10 s, well under 50. Re-confirmed not active as of the
2026-08-09 re-run (150 requests at 10/s → 150 × 200), so 2b above is a **pre-Cloudflare control**.

Consequence to expect once live: `jobs.js` (~10.7 req/s ≈ 107 per 10 s) will be blocked by Cloudflare
rather than the app's 120/min limiter, changing what that phase measures. Phase 3b (direct dispatch)
is unaffected.

### Note on the 2b tail

Run 2's p95 was **33.9 s** against run 1's 4.8 s, entirely in `http_req_waiting` and concentrated on
the 429s (429 p95 34.4 s vs 200 p95 4.9 s). p50 was stable (2,840 vs 2,911 ms). This is consistent
with the finding rather than contradicting it: once arrival rate exceeds service rate the queue grows
without bound and latency stops being stationary, so p50 tracks typical queue depth while the tail
tracks how long the overload happened to persist. Judge this phase on p50 and the thresholds; the
standing rule against quoting `max` applies to this p95 too.
