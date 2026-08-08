# k6 Load-Testing Harness

DIY single-origin load tests for Partna's public read path + job pipeline.
Design: `docs/superpowers/specs/2026-07-18-k6-load-testing-design.md`.
Plan: `docs/superpowers/plans/2026-07-26-k6-load-testing.md`.

## Target: DEV only

- Origin / baseline / jobs → `https://dev-api.partna.au`
- Edge spike → `https://loadtest.partna.au/` (one zone-wide Worker; unavoidably prod edge, cache-HITs only)
- **Never** point the cache-buster or jobs at `api.partna.au` (live prod) — prod capacity run is deferred to `OPS-S4-3`.

## Named target load

50 concurrent viewers (launch-day peak × safety). Escalate to 200 only after a joint checkpoint:
`SPIKE_VUS=200 k6 run spike-edge.js`. Never escalate solo.

## Setup (once)

1. `brew install k6`
2. Seed the test handle on dev: apply `seed.sql` (Supabase MCP `execute_sql`, dev ref, or `psql "$DEV_DB_URL" -f seed.sql`).
3. Sync KV: `cloud tinker development` → `\App\Jobs\Cloudflare\SyncSubdomainToKvJob::dispatchSync('00000000-0000-4000-a000-000000000001');`
4. Verify: `curl -s https://dev-api.partna.au/api/public/profiles/loadtest | ...` → links 10 / gallery 6 / services 15.
   (Gallery is capped at 6 per site by the `core.enforce_site_gallery_max6` trigger — that's the real-world ceiling for any production site, not a seed shortfall.)

## Run order (checkpoint between every phase)

| Phase | Command | Pass criteria |
|-------|---------|---------------|
| 1 Baseline | `k6 run --out json=results/baseline-run1.json baseline.js` | p95 < 500ms, http_req_failed < 0.01. Record p50/p95/p99 below. |
| 2a Edge   | `k6 run --out json=results/spike-edge-run1.json spike-edge.js` | edge_cache_hit > 0.9; origin hits ~flat |
| 2b Origin | `k6 run --out json=results/spike-origin-run1.json spike-origin.js` | origin_5xx == 0; origin_429 > 0; Supabase connections flat |
| 3 Jobs (deferred) | `k6 run --out json=results/jobs-run1.json jobs.js` | jobs_5xx == 0; Horizon depth drains; worker memory recovers; no cross-queue starvation |

## Guardrails (§6)

- `X-Load-Test: 1` on every request.
- Start at the numbers above; escalate only after a joint review.
- **Kill switch:** Josh watches Supabase connections + Horizon depth; Ctrl-C k6 if either climbs toward a ceiling.
- **Teardown after every write scenario** (Phase 3): `psql "$DEV_DB_URL" -f teardown.sql`, then re-seed.

## Phase 3 limiter decision

The `analytics` limiter is 120/min per IP. To saturate the queue from one IP, either
(a) temporarily raise the limiter for a tight watched window and restore after, or
(b) drive jobs via a tinker loop. Decide with Josh at Phase 3 time.

**Resolved 2026-08-06 via option (b).** Option (a) was never needed: the queue questions are all
about the *worker*, so the HTTP limiter is irrelevant noise. Recipe that worked —

```bash
export PATH="$PATH:$HOME/.composer/vendor/bin"   # the `cloud` CLI is installed but NOT on PATH
cloud tinker development --code='... RecordAnalyticsEventJob::dispatch($payload) ...'
```

Dispatch ran at 291 jobs/s, so 20k builds a backlog faster than the ~60/s drain. Observe with
`Queue::connection("redis")->size($queue)` per queue, and put canary jobs on a queue **above** and
**below** `analytics` in supervisor-1's priority list — that pair is what makes starvation visible.
Note queued **closures do not work** from tinker (`serializable-closure` cannot reflect eval'd
source; they land in `failed_jobs`) — dispatch a real job class with `->onQueue(...)` instead.

`jobs.js` (and any other script that POSTs an analytics event) must send an `Origin`
header matching the seeded site's subdomain host (`https://loadtest.partna.au`) —
`AnalyticsController::originAllowed()` (SEC-1, 2026-07-24) fails closed with 404
"Site not found" on any pageview/click/etc. write with no Origin/Referer header,
since `site_id`/`subdomain` are public values and can't authenticate a caller alone.
`config.js`'s `EDGE_HOST` already resolves to the right value; every write-scenario
script must include it in its request headers.

## Baseline reference

- p50: **136.6 ms** · p95: **240.2 ms** · p99: **376.0 ms** · error rate: **0.00%**
- Date: **2026-07-31** · target: 50 concurrent · env: **dev** (`dev-api.partna.au`)
- Run: `baseline.js` @ 45 req/min for 5m · 678 requests · 226 iterations · 904/904 checks passed
- Thresholds: `p(95)<500` ✓ (238.73 ms as reported by k6) · `http_req_failed<0.01` ✓ (0.00%)
- Raw: `results/baseline-run1.json` · summary: `results/2026-07-31-baseline-run1.md`

Confirmed by three further runs on **2026-08-03** (`results/2026-08-03-baseline-warmup-comparison.md`):
p50 **132.9–140.3 ms**, p95 **218.3–241.4 ms** across four independent runs. That part is solid.

Re-run **2026-08-06** as a same-day control alongside phases 2a/2b/3: p50 **95.3 ms** · p95
**204.5 ms** · p99 **249.3 ms** · 0.00% errors · 900/900 checks (675 reqs / 225 iters, directly
comparable). **Faster than the reference on every percentile** — nothing has regressed. Per-endpoint
that run: profile **155.9 / 226.9 ms** (p50/p95), health 91.6 / 163.9, social-platforms **44.8** /
116.4 — the edge-served route is what pulls the blended p50 down, quantifying the caveat below.

### Read `max`, don't trust it

**`max` does not describe Partna and must not be quoted as a latency figure.** Two runs under
identical conditions produced **550 ms** and **4,563 ms** — an 8× spread. It is a blended
client+network+edge number sampling a rare event.

The origin's own log is the check that settles it: across the two fully-instrumented runs the origin
logged **970 requests, none over 500 ms** (worst 351 ms, on a cold first request), while k6 reported
maxima of 675 ms and 550 ms in the same runs. Judge the run on **p50/p95 and the thresholds**.

To get the server's side of any run — the buffer caps at 100 entries (~45 s of traffic), so poll
during the run rather than after:

```bash
cloud env:logs partna development --minutes 5
```

### ⚠️ Phase 1 is NOT origin-only

`/api/public/config/social-platforms` is served entirely from the Cloudflare edge and reaches the
origin **zero** times (measured: 485 requests sent, 0 arrived). One of the three endpoints is
measuring a CDN, which drags the blended p50 down. `dev-api.partna.au` is behind Cloudflare —
"origin, no edge" was never true for this phase.

### Warm-up (opt-in)

```bash
k6 run -e WARMUP=45s baseline.js
```

Runs an identically-paced `warmup` scenario first and scopes every threshold to
`{scenario:baseline}`, so warm-up traffic is excluded from pass/fail but still lands in the raw JSON.
Default is OFF — a bare `k6 run baseline.js` is byte-identical to the reference above.

Note it does **not** reduce the tail (that was tested on 2026-08-03: the warm-up run's max was
*higher* than the no-warm-up control). Genuine cold start is ~+270 ms server-side on the **first
request only**. Use it when you want the measured slice free of connection-setup noise, not as a
tail fix.

### Phases 2a / 2b / 3 — run 2026-08-06, all PASS

All three ran for the first time on 2026-08-06 and all three passed their thresholds
(`results/2026-08-06-phases-2a-2b-3.md`):

- **2a edge** — `edge_cache_hit` **100%** (15,476/15,476), 0% failed. Origin hits: **zero**.
- **2b origin** — `origin_5xx` **0**, `origin_429` **925** of 1,029 (≈60/min got through, matching
  the limiter). Supabase connections flat: peak **35 of 60**, never >1 active query.
- **3 jobs** — `jobs_5xx` **0**, `jobs_accepted` **142**, and all 142 provably landed in
  `analytics.site_visits` (3,069 → 3,211). Zero dispatched-but-lost jobs.

- **3b queue saturation** — 20,000 jobs dispatched straight onto the queue (bypassing the HTTP
  limiter). Drained at a steady **58–65 jobs/s**, **zero lost, zero failed**.

### ⚠️ Two real findings from those runs

1. **Cross-queue starvation is real.** `supervisor-1` is `balance => false`, so one worker drains
   its ten queues in **strict priority order**. During the 20k `analytics` backlog, canaries on
   **`default` (priority 2) ran immediately**, while canaries on **`images` (priority 6) sat
   untouched for the full ~4.5-minute drain** and cleared only once `analytics` hit zero. A visitor
   spike therefore stalls `images`, `streaming`, `platform_refresh`, `platform_connect` and
   `cloudflare_bulk` for its whole duration. Delay, not loss — but its blast radius had never been
   measured. The web tier is unaffected (workers are a separate instance).
2. **Origin TTFB degrades badly under 50 concurrent.** The origin needed p50 **2.9 s** just to
   return a **429**, and **4.3 s** to serve a 200, at ~17 req/s — while the same client at the same
   50 VUs saw **239 ms** p95 TTFB against the edge. Confirmed by the origin's *own* access log
   (`duration_ms` p50 **2,900**), and it is **container-wide**: `/api/health` went 0.20 s → 2.4 s
   during the flood. No threshold covers this.

   **This is expected to apply to production.** Both environments run the *same*
   `flex.g-1vcpu-512mb` single replica with **autoscaling off** (`cloud instance:list`), and one
   core ÷ ~60 ms CPU/request ≈ the 17 req/s ceiling measured. The 429 is returned by **route**
   middleware (`routes/api.php:178`), so every rejected request has already paid for FPM handoff,
   full Laravel boot and the global stack. Cheapest fix is rejecting at **Cloudflare**, so hostile
   volume never buys origin CPU. (Not verified against prod — load-testing prod is `OPS-S4-3`.)

Still open: **worker memory recovery**, which no CLI surface exposes — see the write-up.

## Collaboration (§8)

Claude drives k6 + `cloud env:logs partna development --live`. Josh watches Horizon
(depth, worker memory), Supabase connections (Supavisor headroom), Nightwatch.
Run one phase, stop, review both sides, decide escalate/move-on/abort together.
