# k6 Phase 1 — baseline run 1

- **Date:** 2026-07-31
- **Env:** dev — `https://dev-api.partna.au` (origin; no edge, no KV, no writes)
- **Script:** `baseline.js` — `constant-arrival-rate` 45 req/min, 5m, preAllocatedVUs 10, maxVUs 20
- **Command:** `k6 run --out json=results/baseline-run1.json baseline.js`
- **Raw:** `baseline-run1.json`
- **Scope:** **Phase 1 only.** Phases 2a (edge spike), 2b (origin spike) and 3 (jobs) were
  deliberately not run — decision 2026-07-31, so no prod-edge traffic was generated unsupervised.

## Result: PASS

Both thresholds green; the run's own exit code was 0.

| Threshold | Target | Actual | |
|---|---|---|---|
| `http_req_duration` | p(95) < 500 ms | **238.73 ms** | ✓ |
| `http_req_failed` | rate < 0.01 | **0.00%** | ✓ |

## Latency distribution

Computed from the 678 `http_req_duration` points in `baseline-run1.json`:

| Metric | Value |
|---|---|
| p50 | 136.6 ms |
| p90 | 197.6 ms |
| p95 | 240.2 ms |
| p99 | 376.0 ms |
| max | **805.2 ms** |
| avg | 129.3 ms |
| min | 29.0 ms |

## Volume

```
checks_total.......: 904     3.010277/s
checks_succeeded...: 100.00% 904 out of 904
checks_failed......: 0.00%   0 out of 904

  ✓ profile 200
  ✓ profile has data envelope
  ✓ social 200
  ✓ health 200

http_reqs..........: 678    2.257708/s
http_req_failed....: 0.00%  0 out of 678
iterations.........: 226    0.752569/s
data_received......: 5.6 MB 19 kB/s
data_sent..........: 114 kB 381 B/s
```

## Seed state

The `loadtest` handle was **already present on dev** and verified before the run against the
README's own criteria — links **10** / gallery **6** / services **15**. `seed.sql` was therefore
**not applied**, and `teardown.sql` was **not run**: the seed pre-existed this session, and tearing
down state this run did not create risks breaking someone else's setup. Re-verify the three counts
before the next run rather than assuming they persist.

`SyncSubdomainToKvJob` was **not** dispatched. Phase 1 hits the origin directly and needs no KV
entry — and skipping it avoided a write into the `SUBDOMAIN_KV` namespace, which is **shared with
production**. Phase 2a will need that sync, and that sharing should be a conscious decision at the
time, not a side effect.

## Observations

1. **max 805 ms against a p99 of 376 ms.** The tail is roughly 2× p99, concentrated in early
   iterations — consistent with cold caches/connections rather than sustained load, since the run
   held a flat 45 req/min and never queued (max 1 VU active against 10 preallocated). Corroborated
   by an unrelated Nightwatch slow-route issue on dev the same morning
   (`#372`, `GET /api/platforms/twitch/selection`, 1,082 ms) whose **single slowest span was a
   `supabase:jwks` cache *hit* costing 314 ms** — a cache hit that expensive is connection
   establishment being billed to the first operation, not lookup cost. Worth confirming before it
   is read as steady-state latency.
2. **This run does not exercise the named target.** 45 req/min with a peak of 1 concurrent VU is a
   latency baseline, not a load test. The 50-concurrent guardrail figure remains unmeasured.
3. **Zero 5xx, zero failed checks, no 429s** — the run stayed under the 60/min public-profile
   limiter by design (45/min), so it says nothing about limiter behaviour either. That is Phase 2b.

## Not measured (explicitly)

- Edge cache-hit ratio (Phase 2a) — **unknown**
- Origin behaviour under flood / limiter engagement (Phase 2b) — **unknown**
- Supavisor connection headroom at 50 concurrent — **unknown**
- Horizon queue depth / worker memory under write load (Phase 3) — **unknown**

## Next

Phases 2a and 2b, jointly per the harness README's collaboration protocol (one phase, stop,
review both sides). Escalation to `SPIKE_VUS=200` requires a joint checkpoint and must never be
done solo.
