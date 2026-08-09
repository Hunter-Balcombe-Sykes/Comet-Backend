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

### Part 2 — CANNOT WORK AS DESIGNED. The API bypasses this Cloudflare zone.

The rule was saved, set Active, and then corrected to drop the `http.host` clause. It still never
fired: **600 requests at 30/s — 6× the 50/10s threshold — returned 600 × 200**, with
`cf-cache-status: BYPASS` ruling out caching as the explanation.

The cause is upstream of the expression entirely. Zone DNS:

| record | proxied? | points at |
|---|---|---|
| `*.partna.au` (A) | **PROXIED** | the wildcard serving `<handle>.partna.au` pages + the zone Worker |
| `api.partna.au` (CNAME) | **dns-only** | `partna-production-uovh3z.laravel.cloud` |
| `dev-api.partna.au` (CNAME) | **dns-only** | `partna-development-fsh3vz.laravel.cloud` |

An explicit record beats the wildcard, so **API traffic goes straight to Laravel Cloud and never
transits this zone**. No zone-level rate-limiting or WAF rule can affect it, whatever the expression.

⚠️ The misleading tell: API responses still carry `server: cloudflare` and a `cf-ray`. That is
**Laravel Cloud's own Cloudflare**, not this zone. `cf-ray` is not evidence of zone transit — check
`dns_records[].proxied`.

**Correction to an earlier claim in this document's history.** The first diagnosis was that the Free
plan's restriction of rate-limit expressions to the Path and Verified Bot fields caused the `http.host`
clause to silently never match. That restriction is real (Free = 1 rule, fixed 10 s period, IP
characteristic, Path/Verified-Bot fields only, eventually-consistent counters) but it was **not** the
operative cause. The differential test — a second, correctly-spelled hostname also failing to limit —
was read as confirming the field theory when it in fact pointed at the real answer: neither host is on
the zone. Rule out "is this traffic even on my zone?" before theorising about expression syntax.

### Options, none of them free

| Option | What it takes | Risk |
|---|---|---|
| **A. Orange-cloud the API records** | Proxy `api` / `dev-api`, then the rule works as specced | ⚠️ The Worker route is `*/*` on `partna.au`, and its `RESERVED` set (`cloudflare-worker/src/index.js`, mirrors `config('partna.reserved_subdomains')`) contains **`api` but NOT `dev-api`**. Proxying `dev-api` without adding it to RESERVED in **both** files routes dev API traffic into the brand-subdomain KV lookup and breaks it. Also needs TLS/redirect verification against Laravel Cloud. |
| **B. Laravel Cloud's own edge protections** | Investigate whether the platform offers rate limiting / WAF at its edge | Unknown; not yet checked |
| **C. Scale the app instance** | `flex.c-2vcpu-512mb` or larger, and/or enable autoscaling (currently `none`, min=max=1) | Costs money per environment; raises the ceiling but does not stop hostile traffic buying origin CPU |
| **D. Accept for now** | Nothing | Defensible while pre-beta with no customers and prod stopped; the app's own 60/min limiter still protects Postgres, which the load tests confirmed |

The 2b figures above are therefore a **true origin measurement**, not a pre-Cloudflare control —
Cloudflare was never in that path.

---

## Part 2 — RESOLVED via option B (Laravel Cloud edge rate limiting) · 2026-08-09

Laravel Cloud's edge **is** Cloudflare (they partner). Their WAF (OWASP Core Ruleset) and DDoS
mitigation already ran on all traffic through that edge; **basic rate limiting** just needed enabling:
*environment canvas → "Edge network" card → "Web Application Firewall" tab*. Threshold is a fixed
**100 requests/minute per IP** (customisable only on Business); action set to **Throttle** — not
Challenge, which is unsolvable for API clients. Per-environment, so dev and prod are separate.

This is the right layer precisely because `dev-api.partna.au` CNAMEs to `laravel.cloud`: the traffic
that bypasses the `partna.au` zone still traverses Laravel Cloud's edge.

### Verified working

| test | result |
|---|---|
| 300 requests @ 10/s | **110 × 200, 190 × 429** — threshold engages, with the documented counter overshoot |
| block response | HTTP 429, body `error code: 1015`, `server: cloudflare` — **Cloudflare's own rate-limit page, not a Laravel response**, so the request never reaches PHP |
| 900 requests @ 30/s | **78 × 200, 822 × 429**; origin log window shows ~**2.2 req/s** arriving; server `duration_ms` p50 **186 ms**, max **470 ms** — origin stayed healthy throughout |

### Phase 2b, re-run with the limiter live — the headline result

| | before (unprotected) | after (edge limiter) |
|---|---|---|
| requests completed in 60 s | 1,029 | **39,909** |
| client p50 | **2,911 ms** | **39.4 ms** |
| `origin_5xx` | 0 | **0** |
| `origin_429` | 925 | **39,802** |
| requests reaching origin | ~all | ~**2.1 req/s** (100 capped log entries spanning 46 s) |
| origin statuses | 429s costing 2,900 ms each | 77 × 429, 20 × 200 |

Rejection now costs **39 ms at the edge instead of 2,911 ms at the origin** — a ~74× improvement, and
the reason throughput rose 39× (k6's VUs cycle instead of blocking). The origin no longer sees the
flood at all. Exit 0; both thresholds pass.

⚠️ **Reading the origin log correctly:** `cloud env:logs` caps at ~100 entries, so the *count* is
truncated and cannot be used directly. The informative measure is the **time span** of those 100
entries — 100 entries across 45–46 s ⇒ ~2.1–2.2 req/s reaching origin. Had the origin been taking the
full 30 req/s, 100 entries would have spanned ~3 s.

Residual: the ~2/s that do reach the origin still cost p50 **1,292 ms** on the DB-backed profile route,
above the ~156 ms baseline. Not saturation (zero 5xx, and `/api/health` returned to **193–248 ms**
once the flood stopped), but the origin is not instant under the trickle either. The underlying
capacity limit is unchanged — see below.

### Still worth doing (capacity, as distinct from abuse)

Rate limiting stops *abuse*; it does not raise the ceiling for *legitimate* traffic. Laravel Cloud's
own autoscaling formula explains the ~17 req/s measured knee exactly:

```
workers per replica = floor(memory_mb / 30)   →  floor(512/30) = 17 concurrent requests
desired replicas    = ceil(active_requests / workers_per_replica)
```

So the binding constraint is **memory, not CPU** — 512 MB only fits 17 PHP workers. Two independent
routes (a load ramp, and their published formula) landed on the same number.

1. **Turn autoscaling on.** Currently `scalingType: none`, min = max = **1**, in *both* dev and prod.
   Modes are None / Custom (min–max) / Unlimited; HTTP scaling reacts to *active requests*. Being
   pinned at one replica with no headroom is the underlying fragility. This also answers "what
   happens past 8 vCPU": you scale **horizontally** — the per-instance cap never binds.
2. **More RAM before more CPU** — 1 GB doubles workers per replica to ~34.
3. **Laravel Octane** (a compute-settings toggle, FrankenPHP) boots the framework once and holds it
   in memory, attacking the ~60 ms/request bootstrap that made rejection expensive in the first place.
   Needs testing for shared-state caveats.

### Note on the 2b tail

Run 2's p95 was **33.9 s** against run 1's 4.8 s, entirely in `http_req_waiting` and concentrated on
the 429s (429 p95 34.4 s vs 200 p95 4.9 s). p50 was stable (2,840 vs 2,911 ms). This is consistent
with the finding rather than contradicting it: once arrival rate exceeds service rate the queue grows
without bound and latency stops being stationary, so p50 tracks typical queue depth while the tail
tracks how long the overload happened to persist. Judge this phase on p50 and the thresholds; the
standing rule against quoting `max` applies to this p95 too.
