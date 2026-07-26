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
(b) drive jobs via a tinker loop. Decide with Josh at Phase 3 time. Phase 3 stays
deferred until Phases 1 & 2 pass.

`jobs.js` (and any other script that POSTs an analytics event) must send an `Origin`
header matching the seeded site's subdomain host (`https://loadtest.partna.au`) —
`AnalyticsController::originAllowed()` (SEC-1, 2026-07-24) fails closed with 404
"Site not found" on any pageview/click/etc. write with no Origin/Referer header,
since `site_id`/`subdomain` are public values and can't authenticate a caller alone.
`config.js`'s `EDGE_HOST` already resolves to the right value; every write-scenario
script must include it in its request headers.

## Baseline reference (fill after first Phase 1 run)

- p50: __ ms · p95: __ ms · p99: __ ms · error rate: __
- Date: ____ · target: 50 concurrent · env: dev

## Collaboration (§8)

Claude drives k6 + `cloud env:logs partna development --live`. Josh watches Horizon
(depth, worker memory), Supabase connections (Supavisor headroom), Nightwatch.
Run one phase, stop, review both sides, decide escalate/move-on/abort together.
