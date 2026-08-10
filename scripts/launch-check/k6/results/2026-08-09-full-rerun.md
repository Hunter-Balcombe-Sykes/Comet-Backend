# k6 full re-run — 2026-08-09

**All four phases PASS.** Every threshold met, zero 5xx anywhere, zero lost jobs.

| | |
|---|---|
| Environment | **dev** (`dev-api.partna.au`, Supabase `glncumufgaqcmqhzwrxm`) |
| Commits | `e4631e846` → `6a2332bbe` → `9c512c5a8` (two deploys landed mid-pass — see Caveats) |
| Target load | 50 concurrent (named target, not escalated) |
| Prior full pass | `2026-08-06-phases-2a-2b-3.md` |
| What changed since | **Laravel Cloud edge rate limiting is now LIVE on dev.** This re-run is the first full pass with it in place. |

| Phase | Verdict | Headline |
|---|---|---|
| 1 Baseline | ✅ PASS | p50 **51.6 ms** · p95 **140.9 ms** · 0.00% errors · 900/900 checks |
| 2a Edge | ✅ PASS | `edge_cache_hit` **100.00%** (34,216/34,216) · zero origin hits |
| 2b Origin | ✅ PASS | `origin_5xx` **0** · `origin_429` **39,942** · only ~2.2 req/s reached PHP |
| 3 Jobs | ✅ PASS | `jobs_5xx` **0** · `jobs_accepted` **119** · **all 119 rows landed**, zero lost |

---

## Phase 1 — baseline

Run three times. Two were compromised or informative in different ways, so all three are recorded.

| Run | File | p50 | p95 | max | Errors | Notes |
|---|---|---|---|---|---|---|
| B | `baseline-2026-08-09b.txt` | 60.5 ms | 153.8 ms | 8.52 s | 0.00% | a deploy landed in its final 6 s |
| C | `baseline-2026-08-09c.txt` | 57.6 ms | 142.8 ms | 4.04 s | 0.00% | clean window |
| **D** | `baseline-2026-08-09d-instrumented.txt` | **51.6 ms** | **140.9 ms** | **256 ms** | 0.00% | clean, with concurrent origin-log capture — **quote this one** |

All three passed `p(95)<500` and `http_req_failed<0.01`, all with 100% check success including the
seeded-shape check (gallery 6 / services 15 / links 10).

Per-endpoint, run D (`http_req_duration`):

| Endpoint | p50 | p95 | max |
|---|---|---|---|
| `/api/public/profiles/loadtest` | 38.2 ms | 161.7 ms | 220.4 ms |
| `/api/public/config/social-platforms` | 44.4 ms | 109.0 ms | 256.2 ms |
| `/api/health` | 90.2 ms | 158.9 ms | 195.6 ms |

**Faster than every prior reference.** 2026-07-31 p50 136.6 / p95 240.2 → 2026-08-06 p50 95.3 / p95
204.5 → today p50 **51.6** / p95 **140.9**. Nothing has regressed.

## Phase 2a — edge spike

`edge_cache_hit` **100.00%** — 34,216 of 34,216 measured requests served HIT, 0.00% failed across
35,244 requests at **260 req/s**, 853 MB served.

**Origin impact: none.** Over a log window fully covering the spike the origin logged **68 access
entries** in total. Had even 1% of the spike missed cache, that would have been ~342 origin hits.
The Worker holds the page at `s-maxage=86400`.

**Do not quote this phase's `http_req_duration` p95 (463.8 ms) as an edge latency.** Splitting the
phases shows it is the *tester's own downlink*, not Cloudflare:

| Phase | p50 | p95 | max |
|---|---|---|---|
| `http_req_waiting` (TTFB) | **57.6 ms** | **133.0 ms** | 2,230 ms |
| `http_req_receiving` (body) | 71.8 ms | 368.4 ms | 2,843 ms |
| `http_req_blocked` (conn setup) | 0.0 ms | 0.0 ms | 226 ms |

Median `receiving` **exceeds** median `waiting` — the harness's own tell that a number is transport.
At 24 KB × 260 req/s the run pulled 6.3 MB/s. Real edge TTFB is p95 **133 ms**, *better* than the
239 ms recorded on 2026-08-06.

## Phase 2b — origin cache-buster

`origin_5xx` **0** · `origin_429` **39,942** of 40,008 requests · client p50 **41.9 ms** ·
471 req/s. The 4 failed checks were client-side `dial: i/o timeout`, not server errors.

**The edge rate limit is doing the work.** Against 2026-08-06 (before it existed):

| | 2026-08-06 | 2026-08-09 (this run) |
|---|---|---|
| Requests completed in 60 s | 1,029 | **40,008** |
| Client p50 | **2,911 ms** | **41.9 ms** |
| `origin_5xx` | 0 | 0 |

Reproduces run 3 earlier today (39,909 requests, p50 39.4 ms) almost exactly.

**Supabase stayed flat.** Sampled three times mid-flood: **34 → 37 → 37 connections of 60**, never
more than **1 active query**, zero idle-in-transaction. Idle baseline is 28. The limiter genuinely
keeps the flood off Postgres.

**How much reached PHP:** in a sampled 44 s window the origin logged **96 profile requests, ~2.2/s
— all 429**. So Cloudflare absorbed ~99.8% of the flood. Client-side arithmetic agrees:
40,008 − 39,942 − 4 = **62 requests served 200** in the minute, which is Laravel's own
`public-profile` limiter (60/min) showing through behind the edge.

### ✅ RESOLVED 2026-08-10 — the 429 cost was a flood artifact, not a standing defect

Re-measured **at rest**: 80 req/min from one IP (under the edge's 100/min so everything is forwarded,
over Laravel's 60/min so the tail is throttled), one VU, idle container, cache-busted so requests
actually reach Laravel.

| Status | n | Origin p50 | p95 | max |
|---|---|---|---|---|
| 429 | 22 | **78 ms** | 83 ms | 92 ms |
| 200 | 42 | 85 ms | 96 ms | 117 ms |
| 304 | 2 | 99 ms | — | — |

**A 429 costs 78 ms — cheaper than serving a 200**, which is what a short-circuit before the
controller should cost. The 1,390 ms below was measured only during a 470 req/s flood and is CPU
contention on the shared 1-vCPU box, not an intrinsic cost of issuing a 429. Since the edge limit
now absorbs ~99.8% of such floods, the condition that produced it no longer reaches the origin.
**No fix required.** The original framing of this as an open item was wrong.

### Historical: the origin appeared slow to issue a 429 (superseded by the above)

Of the trickle that does reach PHP, the origin's **own** access log records p50 **1,390 ms**,
p95 2,879 ms, max 3,121 ms to return a 429 — at ~2 req/s, where the container is otherwise idle.

That is better than 2026-08-06 (origin p50 ~2,900 ms) but nowhere near the ~40 ms the same
container serves a normal request in. A 429 is issued by **route** middleware (`routes/api.php:178`),
before any controller or cache work, so that time is Laravel boot plus the limiter's own store
check and nothing else. The edge limit means far fewer requests pay this cost — it does not make
the cost go away.

## Phase 3 — jobs

`jobs_5xx` **0** · `jobs_accepted` **119** · 10,186/10,186 checks · 5,093 requests in 30 s ·
p50 49.2 ms.

**Every accepted write provably landed.** `analytics.site_visits` 6,844 → **6,963** (+119),
loadtest site 3,000 → **3,119** (+119), 119 rows in the last 10 minutes. Zero dispatched-but-lost
jobs — worth stating explicitly because `QueuedIngestor` is deliberately fail-open, so a 201 alone
proves nothing. Queues drained to **0** on `analytics`/`default`/`images`/`notifications`;
`failed_jobs` **0**.

`jobs_accepted` is 119 vs 142 on 2026-08-06 — expected, not a regression: the new edge limit now
throttles these POSTs too.

**Teardown:** deleted only the 119 rows this run wrote, verified back to exactly 6,844 / 3,000.
A full `teardown.sql` drops and recreates the user + site, and a hard delete orphans KV alias
entries — no gain when the new data is purely additive.

---

## ⚠️ Unresolved: intermittent multi-second stall on the profile route

Seen in **2 of 3** baseline runs today. Precise fingerprint:

- **Profile route only.** `/api/health` and `/api/config/social-platforms` stayed normal in the
  same second (42–105 ms).
- **TTFB-bound.** Run C's worst: `duration` 4,042 ms = `waiting` 3,961 ms + `receiving` 82 ms.
  Connect and TLS were 0 — not connection setup, not body download.
- **Not a whole-route block.** At 12:05:18 a profile request completed in 4,042 ms *while another
  profile request completed in 42.5 ms*. Individual requests fall into a slow path; their
  neighbours do not.
- Magnitudes: run B 8.53 s (6 requests), run C 4.04 s and 1.88 s, run D none (max 256 ms).

**Not caught server-side.** The origin log's ~100-entry buffer rotated the event out both times —
in run C's case by **2 seconds**. Run D was instrumented with an overlapping poller that
successfully captured **252 unique entries across 6m13s**, proving the technique works, but the
stall did not occur that run. During run D the origin logged **zero** requests over 500 ms.

**Hypothesis considered and falsified:** both stalls fell ~6.5 min after a container-recycling
deploy. But the 12:10:30 deploy puts "+6.5 min" at ~12:17, inside run D's window, and run D was
clean. Discard it.

### What the route's own instrumentation rules out

Nightwatch issue **#386** (`GET|HEAD /api/public/profiles/{handle}`, last seen 2026-08-09T02:11:49,
i.e. during phase 2b) gives the breakdown of its slowest recorded occurrence — **1,610 ms**:

| Component | Cost |
|---|---|
| Cache events | **5 spans, 7 ms total (0% of execution time)** — all HITS |
| Queries | **none** |
| Outgoing requests / mail / jobs | none |

A request that took 1.6 s did **essentially no work**: cache warm (`handle.resolve:loadtest` hit,
`public.profile:loadtest:…` hit) and **zero DB queries**. That rules out, with evidence:

- a slow query or Postgres contention — there were no queries at all;
- a cache stampede / lock convoy in `CacheLockService::rememberLocked` — its cold-miss path blocks on
  `$lock->block(5)`, which would have fitted the 1.8–8.5 s magnitudes beautifully, but these were
  cache **hits**, so that path was never entered;
- the payload builder — it never ran.

**It also argues the 4–8.5 s baseline stalls were not inside PHP.** Nightwatch's worst recorded
occurrence for this route is 1,610 ms; the 4,042 ms and 8,528 ms events do not appear at all,
despite being far over the 1,000 ms threshold that captures them.

### The one structural difference from `/api/health`

`/api/health` never stalls. Comparing the two responses:

| | `/api/public/profiles/{handle}` | `/api/health` |
|---|---|---|
| `etag` | `W/"43d3…"` **present** | absent |
| `vary` | `Accept-Encoding,Accept-Encoding, Origin` — **duplicated** | `Accept-Encoding,Origin` |
| `cache-control` | `no-cache, private` | `no-cache, private` |
| `cf-cache-status` | BYPASS | BYPASS |

The profile route carries a validator; health does not. And the origin access log shows profile
responses alternating **200 / 304 with `bytes_sent: 0`** while k6 always receives a full body — so
something in front of the origin issues conditional revalidations the load generator never sent.
That intermediary is the only component present in the profile path and absent from the health path,
it sits exactly where an unattributed TTFB stall would live, and it is invisible to both Laravel and
Nightwatch.

The duplicated `Accept-Encoding` in `Vary` is a real if minor defect on its own — two layers are each
appending it, and `Vary` is a cache key.

### The intermediary, identified

Follow-up investigation (2026-08-09, 10-minute probe + 15s-cadence log capture) **identified it**.
`curl` had reported `cf-cache-status: BYPASS` only because it sends no `Accept-Encoding`, and
`Vary` includes that header — so the earlier probe read a different cache key than k6 and browsers
use. With `Accept-Encoding: gzip`:

| Request | `cache-control` | `cf-cache-status` |
|---|---|---|
| no `Accept-Encoding` | `no-cache, private` | BYPASS |
| `Accept-Encoding: gzip` | **`public, max-age=30, s-maxage=30`** | **HIT** (`age: 3`) |

`AddPublicCacheHeaders` (`CACHEABLE_PATH_PREFIXES` includes `api/public/profiles`) marks this route
`public, max-age=N, s-maxage=N`, and **Laravel Cloud's Cloudflare honours it**. Measured over two
independent runs:

| Endpoint | Client sent | Reached origin | Share |
|---|---|---|---|
| `/api/health` | 225 / 451 | 225 / 444 | **~100%** |
| `/api/public/profiles/{handle}` | 225 / 451 | 19 / 37 | **8.2–8.4%** |

Health reaching 100% proves the access log is **not sampled** — so ~92% of profile requests
genuinely never reach the origin. The origin's split for the ones that do is **19 × 200 / 18 × 304**,
i.e. half are conditional revalidations the load generator never sent. At a 30s TTL over 10 minutes
(~20 expiries) that matches almost exactly.

This also explains an oddity in the baseline: profile p50 (**38.2 ms**) is *faster* than the trivial
`/api/health` (**90.2 ms**) — profile is mostly served at the edge, health always goes to origin.

**`PARTNA_CACHE_PUBLIC_MAX_AGE = 30` in BOTH dev and production** (verified via
`cloud environment:get`), so this topology is identical on prod.

### The likely mechanism, and the fix worth considering

The header is `public, max-age=30, s-maxage=30` with **no `stale-while-revalidate`**. Without it, an
expired edge entry forces waiting clients to **block** while the edge revalidates against the origin.
That is precisely the observed shape: TTFB-bound, profile-only, invisible to PHP, and a 4,042 ms
request completing alongside a 42.5 ms one (one client waiting on the revalidation, another served
from cache).

Notably the Worker's own page response already uses this pattern —
`public, max-age=15, stale-while-revalidate=30, s-maxage=86400` — so SWR is established elsewhere in
the stack and only the API route lacks it. Adding `stale-while-revalidate` to
`AddPublicCacheHeaders` would let the edge serve last-good instantly and refresh in the background,
removing the blocking window entirely.

**Still not proven:** why a given revalidation occasionally takes 4–8.5 s rather than the ~113 ms the
origin actually needs. And the stall **did not reproduce** in a dedicated 10-minute probe (451
profile requests, max 569 ms), so it is intermittent, not on-demand. Treat the SWR change as
addressing the mechanism the evidence points to, not as a confirmed cure.

### ⚠️ A stale assumption in the code

`AddPublicCacheHeaders::VARY_BY_PREFIX` carries this comment:

> *SEC-1: no shared cache in front of this route honors Vary today — the router Worker passes /api
> straight through, and this endpoint has no CDN edge cache in the current topology.*

**That is now false.** There is a CDN edge cache in front, it is serving ~92% of profile requests,
and it does key on `Vary`. The comment is load-bearing for a security argument about tenant
separation under a future "Cache Everything" rule. Profile responses are keyed by handle in the URL
so no cross-tenant exposure follows from this, but the stated premise needs correcting before
someone reasons from it again.

**To catch it**, re-run with the poller alongside:

```bash
# terminal 1 — overlapping capture (buffer holds only ~45s, so poll on an overlap)
for i in $(seq 1 18); do cloud env:logs partna development --minutes 5 >> origin-logs.jsonl; sleep 20; done
# terminal 2
k6 run --out json=baseline.json baseline.js
```

Then compare the k6 request's `http_req_waiting` against the origin's own `duration_ms` for the
same second. If the origin logged seconds → it is inside Laravel. If the origin logged ~80 ms →
the delay is in front of the app.

---

## ✅ 2026-08-10 — the 304 fix verified by measurement, not just by header

Re-ran the probe profile-only at 45/min (matching the pre-fix profile rate exactly, so origin-reach
% is comparable; the earlier probe's added `/api/health` pushed the client to 90 req/min and tripped
the edge limit, whose 429s never reach the origin and would deflate the denominator). 450 profile
requests, 10 minutes, 15s-cadence origin capture.

| Metric | Pre-fix | Post-fix | Spec predicted |
|---|---|---|---|
| Origin reach | 8.2% | **4.4%** | below 8.2% ✓ |
| 304 share of origin touches | 49% (19×200/18×304) | **95%** (1×200 / 19×304) | 304-dominant ✓ |
| Origin touches / 10 min | 37 | **20** | — |

20 touches over 10 minutes at a 30 s TTL is exactly the ~20 revalidations the cache should need. The
extra 17 before the fix were the forced refetches each poisoned 304 caused. **Origin load for this
route halved.** Client side: 450/450 checks, 0 errors, p50 48.6 ms, and no stall (max 845 ms).

## ⚠️ SSR subrequests reach the API as Cloudflare IPs, not visitor IPs

Measured 2026-08-10 from the origin access log. A visitor-triggered Astro SSR render produced:

```
05:09:32  304  120ms  172.69.186.143  /api/public/profiles/loadtest
05:09:32  200  150ms  162.158.3.134   /api/public/profiles/loadtest/integrations
```

Both are Cloudflare egress ranges. Direct requests log the real client IP (my own showed as
`150.228.243.132`), so the log records the client IP — meaning the SSR path genuinely does **not**
carry the visitor's IP.

Consequence: **both** rate limiters aggregate SSR traffic onto a small pool of IPs — the Laravel
`public-profile` limiter keys on `CF-Connecting-IP ?? ip()` (`AppServiceProvider.php:435`, 60/min)
and the Laravel Cloud edge limit is per-IP (100/min, fixed on Basic). Laravel's is the tighter of
the two, so it trips first.

Headroom is bounded by how often SSR actually reaches the API, which two caches throttle: the LC
edge (`s-maxage=30`) and the Astro Worker's own subrequest cache (`cacheTtl: 300`, see
`CloudflarePurgeService::purgeHandle`). At roughly one API call per 5 min per handle per colo, the
order of magnitude is a few hundred live handles per egress IP before the 60/min limiter bites —
**an estimate, not a measurement**, and pools are per-colo so real headroom is likely higher.

Not urgent at pilot scale (dozens of sitepages). Worth re-checking from the `ip` field in the access
log once real traffic exists, before scaling past ~100 live handles.

## Caveats

**Two deploys landed mid-pass, from a parallel session sharing this checkout.**

| Commit | Deployed (local) | Contents | Effect |
|---|---|---|---|
| `6a2332bbe` | 11:57:47 → 11:59:00 | `package-lock.json` only | recycled the container in the last 6 s of baseline run B |
| `9c512c5a8` | 12:09:29 → 12:10:30 | one markdown doc | landed between phase 2a and phase 2b |

Neither touches PHP, so neither can change backend latency — but both recycled the app container.
Baseline run B is therefore **not** the figure to quote; run D is. Phases 2b, 3 and baseline D ran
on `9c512c5a8`; phase 2a and baselines B/C ran on `6a2332bbe` or earlier.

**Guardrails observed:** dev only, `X-Load-Test: 1` on every request, 50 concurrent (not escalated
to 200), phase 3 written data removed and verified.

Raw k6 JSON was written to a scratch directory and deleted after analysis (it is gitignored and
totals ~360 MB), so every derived number above was extracted at write-up time.
