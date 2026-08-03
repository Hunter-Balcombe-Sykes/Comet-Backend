# k6 Phase 1 — warm-up re-run and tail diagnosis

- **Date:** 2026-08-03
- **Env:** dev — `https://dev-api.partna.au`
- **Purpose:** settle run 1's Observation 1 — that its **max 805.2 ms** against a p99 of 376 ms was
  cold-start, "concentrated in early iterations". That was inferred, never measured.
- **Runs:** three 5-minute runs at the standard 45 req/min, each preceded by ~6.5 min of dev idle.
- **Raw:** `2026-08-03-baseline-cold.json`, `-warm.json`, `-cold2.json` (gitignored, local only)

## Verdict

**The tail is not cold-start, and a warm-up stage does not remove it.** Run 1's Observation 1 is
refuted on both halves: the slow requests were not in early iterations, and warming does not help.

The steady-state baseline (p50/p95) is solid and reproducible. The **max is not a measurement of
Partna** — it is dominated by the transport between k6 and the origin, and it is not reproducible
run to run.

## Results

| Run | Condition | p50 | p95 | p99 | **max** | origin max |
|---|---|---|---|---|---|---|
| run 1 (2026-07-31) | unknown | 136.6 | 240.2 | 376.0 | **805.2** | not captured |
| **A** | cold, no warm-up | 140.3 | 241.4 | 889.8 | **4562.9** | not captured |
| **B** | **45 s warm-up**, measured slice | 132.9 | 224.5 | 320.7 | **674.8** | **182** |
| **C** | cold, no warm-up | 134.0 | 218.3 | 293.9 | **550.3** | **351** |

All runs: 100% checks passed, 0 failed requests, both thresholds green.

Three things follow directly:

1. **p50 and p95 are stable to within ~8%** across four independent runs. That part of the baseline
   is trustworthy and worth keeping as the reference.
2. **max is not reproducible.** Two runs under *identical* conditions (A and C — both cold, both
   without warm-up) produced 4562.9 ms and 550.3 ms. An 8× spread means the max is sampling a rare
   event, not a property of the configuration.
3. **The warm-up did not help.** Run B, with a 45 s warm-up, produced a *higher* max (674.8 ms) than
   Run C with no warm-up at all (550.3 ms). If the tail were cold-start, this would have been the
   run that fixed it.

## Where the latency actually is

k6 reports `http_req_duration = sending + waiting + receiving`, where `waiting` is TTFB — the only
part the server can be blamed for. `blocked`/`tls` (connection setup) sit *outside* duration
entirely. Run 1 never split these, which is why a client-side cost was read as a server-side one.

Run B measured slice, and the origin's own access log for the same requests:

| View | p50 | p95 | max |
|---|---|---|---|
| k6 `http_req_duration` | 132.9 | 224.5 | 674.8 |
| k6 `http_req_waiting` (TTFB) | 129.7 | 212.1 | 496.6 |
| **origin `duration_ms`** | **74** | **91** | **182** |

**Across Runs B and C the origin logged 970 requests and not one exceeded 500 ms.** The origin's
own worst request in either run was 351 ms. Meanwhile k6 saw maxima of 675 ms and 550 ms. The
~55-60 ms steady-state gap is transport (Melbourne → origin, via Cloudflare); the tail is the same
path misbehaving occasionally.

### Cold start is real, but small and single-shot

Run C, the cleanest cold run, isolates it. The first request of the run:

```
off=0.00s  dur=455.2  blocked=187.2  tls=43.9  wait=452.7   /api/public/profiles/loadtest
```

and the origin logged that same request at **351 ms** against an 80 ms steady p50. So a genuinely
cold container costs roughly **+270 ms server-side, once**, plus ~190 ms of client connection setup.
That is the entire cold-start budget. It cannot produce an 805 ms p-max spread over a 5-minute run,
and it is nowhere near the 4.5 s seen in Run A.

Note dev runs with `usesHibernation: true` (from `cloud deployment:list development`), so containers
really do go cold — the effect exists, it is just far smaller than run 1 assumed.

### The 4.5 s outlier in Run A

Run A's tail was **not** at the start. It was a burst at **165–167 s**, mid-run:

```
165.3s  4562.9ms  wait=4562.6  recv=0.1    /api/public/profiles/loadtest
165.4s  3342.3ms  wait=3274.4  recv=67.8   /api/public/profiles/loadtest
166.6s  3252.7ms  wait=3239.0  recv=13.5   /api/public/profiles/loadtest
166.7s  1347.4ms  wait=1347.3  recv=0.1    /api/health
```

Every one is TTFB-bound, and `/api/health` — a liveness-only endpoint with no DB work, normally
~80 ms — stalled at the same instant. Simultaneous impact across unrelated endpoints points at the
container or the path to it, not at any application code path.

**Honest limit:** Run A predates the server-log capture, and the 100-entry log buffer had already
rotated past that window by the time it was queried. So it is **not established** whether the origin
itself stalled or whether the stall was in front of it. Runs B and C were fully instrumented and no
comparable event occurred, so the question stays open. It is a rare event — one occurrence in
~2,100 requests across three runs.

## Finding: one third of this baseline never reaches Partna

`/api/public/config/social-platforms` is served entirely from the Cloudflare edge and **never
touches the origin**. Measured, not inferred — over the fully-logged Runs B and C:

| Endpoint | k6 sent | reached origin |
|---|---|---|
| `/api/health` | 485 | 485 |
| `/api/public/profiles/{handle}` | 485 | 485 |
| `/api/public/config/social-platforms` | 485 | **0** |

Confirmed independently by headers: `cf-cache-status: HIT`, `age: 963`, `cache-control: max-age=3600`.

This makes `results/2026-07-31-baseline-run1.md`'s framing — "origin; no edge, no KV" — wrong for a
third of the requests, and it drags the blended p50 down (that endpoint's p50 is ~48 ms against
~159 ms for the profile route). **The baseline as recorded is a blended origin+edge number.**

Two smaller observations from the same logs:

- The profile route shows an exact 50/50 **200 / 304** split at the origin (Run C: 113 / 113) while
  k6 received a body every time — all 904 `'profile has data envelope'` checks passed. Something in
  front is revalidating on the client's behalf. The mechanism is not established here.
- The profile response carries `vary: Accept-Encoding,Accept-Encoding, Origin` — `Accept-Encoding`
  is duplicated. Harmless-looking, but `Vary` is a cache key, so it is worth a look.

## What this means for the harness

The max is being read as if it described Partna. It does not: it is a blended client+network+edge
number whose worst value swings 8× between identical runs. p50/p95 are the trustworthy signals, and
both are comfortably inside threshold.

The `p(95)<500` threshold is the right gate and it passes with ~2× headroom. No code change is
indicated on the application side by any of this.

## Reproduce

```bash
cd scripts/launch-check/k6
k6 run --out json=results/cold.json baseline.js                 # cold control
k6 run -e WARMUP=45s --out json=results/warm.json baseline.js   # warm-up variant
```

Warm-up is opt-in; a bare `k6 run baseline.js` is byte-identical to the recorded reference
(verified with `k6 inspect`). With `WARMUP` set, every threshold is scoped to `{scenario:baseline}`
so warm-up traffic is excluded from pass/fail while still appearing in the raw JSON.

Server-side truth for any run — this is what separates a slow server from a slow path to it:

```bash
cloud env:logs partna development --minutes 5   # poll during the run; buffer caps at 100 entries
```

## Still not measured

Unchanged from run 1 — Phases 2a (edge spike), 2b (origin flood/limiter) and 3 (jobs) have **not**
been run, and the named 50-concurrent target remains unmeasured. These runs peaked at 1 concurrent
VU. `LC-K6` stays unticked.
