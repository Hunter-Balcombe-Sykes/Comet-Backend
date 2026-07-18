# k6 Load-Testing — Design Spec

**Date:** 2026-07-18
**Status:** Design approved, pre-implementation
**Owner:** Josh (drives review) + Claude (drives k6)
**Fills:** the "k6" flow in the Assurance Flows Map; the current-architecture replacement for `launch-readiness-checklist.md` TECH-7 (the checklist's original TECH-7 scenarios — Shopify webhook burst, brand dashboards — predate the standalone strip and no longer apply).

---

## 1. Goal & non-goal

**Goal.** Produce *measured evidence* that Partna's public read path and job pipeline survive a **defined** load level at today's infrastructure sizing, with the edge cache and rate limiter demonstrably doing their jobs. Establish a baseline that future releases regression-test against.

**Explicit non-goal.** This does **not** prove we survive an arbitrary viral spike. A single-origin DIY test cannot model a distributed multi-IP thundering herd (see §7). "Certain up to the target load" is the honest claim; "certain, full stop" is not achievable here and is out of scope.

---

## 2. Environment decision — dev (= de facto prod)

We test against **`dev-api.partna.au` / `<handle>.partna.au`**, backed by the dev Supabase (`glncumufgaqcmqhzwrxm`).

**Why dev, not an isolated env:**
- Per the Laravel Cloud reality, the `development` env serves *both* API domains and is the live DB for everything. Dev isn't a rehearsal of prod — right now it *is* prod.
- It's the only target that faithfully exercises the real **Cloudflare Worker + Cache API**, **Supavisor** pooler, and **Horizon** queues. A throwaway Supabase branch may diverge on exactly those (Supavisor tenant prefix, Worker `SUBDOMAIN_KV` binding), so it can return green while meaning nothing.
- Local has neither a Cloudflare edge nor Supavisor, so it physically cannot answer the cache/pool questions. Local is for **script shakedown only**, never real runs.

**Why the usual "don't load-test live" risk is low *now*:** pre-beta, zero customers. The residual real risks we control for are (a) Cloudflare/Supabase **cost** from request volume, (b) **analytics-table pollution** from write scenarios, (c) tripping Cloudflare/Supabase's *own* limits. All handled by the guardrails in §6.

**Durable position (post-split).** When the real production env is later un-paused, dev becomes a genuinely isolated environment and stays the default target for **regression** load tests. **Capacity** questions ("will prod take launch-day peak?") then require a prod-sized target (prod in a window, or a prod-mirrored staging) and a re-baseline — tracked as `OPS-S4-3` in the launch checklist.

---

## 3. The two layers under test

The production read path is two-tier and each tier fails differently:

1. **Edge tier** — a viewer hits `<handle>.partna.au` → the Cloudflare Worker → `caches.default`. After the first miss, every subsequent identical view is served from edge cache and **never touches the backend**. This is the viral-view path.
2. **Origin tier** — `api.partna.au/api/public/profiles/{handle}` directly. What the Astro app calls on a cache miss, and what a cache-busting attacker hits. Here the `public-profile` rate limiter + Postgres + Supavisor live.

Both are tested deliberately: "does virality cost money" is answered at the edge; "does the DB survive a cache-buster" is answered at the origin.

---

## 4. Rate-limiter constraint (shapes every scenario)

`GET /api/public/profiles/{handle}` is gated by the `public-profile` limiter: **60/min, keyed by `CF-Connecting-IP ?? request IP`** (defined in `AppServiceProvider`, tunable via `config('partna.public_profile.rate_limit_per_minute')`). Sibling limiters: `public-site` 60/min, `analytics` 120/min, `authenticated` 300/min (by `supabase_uid`).

**Implication.** k6 from one machine is one IP → one bucket. A naïve high-VU origin flood measures *how fast the limiter says 429*, not backend capacity — the limiter shields Postgres. This is a feature, but it bounds what a single origin can observe.

**Decision — limiter handling for the origin scenario:**
- **(A) Leave the limiter on (default, do first).** Honest single-attacker sim; needs no config change. Proves the defense holds.
- **(B) Temporarily raise `config('partna.public_profile.rate_limit_per_minute')`** for a tight, watched window, then restore — *only* if (A) leaves us genuinely unsure whether Postgres could take the load absent the limiter. More setup; must remember to restore.

We start with (A) and reach for (B) consciously, if at all.

---

## 5. Scenarios — phased, checkpoint between each

Run in order. **Stop after each phase and review both client- and server-side together** before escalating load. No fire-and-forget, no auto-ramp.

### Phase 0 — Data seeding (prerequisite, do before any measured run)
Seed the throwaway **test handle's** site with **representative volume** — realistic block count, analytics rows, media rows — so queries do the work they'll do live. Rationale: the dev DB is near-empty (pre-beta); a query that table-scans at 100k rows is instant on 50 and returns a false green. Without this step, every capacity number is suspect. Seed via a one-off artisan/tinker snippet scoped to the test `site_id`; teardown reverses it (§6).

### Phase 1 — Baseline (read-only, safe)
- **Load:** 10 VUs, 5 min, **paced under the rate limits** (think-time between requests).
- **Endpoints:** `GET /public/profiles/{handle}`, `GET /public/config/social-platforms` (aggressively cacheable), `GET /health`.
- **Thresholds (baked into the script):** `http_req_duration p(95) < 500ms`, `http_req_failed rate < 0.01`.
- **Output:** reference p50/p95/p99 + error rate → the number every future release regresses against.

### Phase 2a — Edge spike (the real viral path)
- **Load:** 50–100 VUs against `<handle>.partna.au` (the Worker).
- **Assert:** `cf-cache-status: HIT` after warmup. A sustained `MISS`/`DYNAMIC` means the Worker's `caches.default.put()` isn't populating the edge cache — a real cost/scale bug.

### Phase 2b — Origin cache-buster (attacker sim)
- **Load:** 50–100 VUs against `api.partna.au/api/public/profiles/{handle}?rand=<per-request>` (defeats edge cache).
- **Assert (limiter ON, option A):** clean `429`s engage; origin request volume stays bounded; **Supabase connection count stays flat** (limiter shields Postgres).

### Phase 3 — Job / upload saturation (writes — defer until 1 & 2 pass)
- **Load:** ~20 concurrent job-triggering requests (analytics writes and/or a media upload).
- **Watch:** Horizon queue depth, worker memory (does it climb and *not* recover?), job failure rate, and **cross-queue starvation** — the `redis_video` connection vs the default/analytics queues.
- Held last because it writes rows and touches R2; the read path must be trusted first.

---

## 6. Guardrails (in every script + runbook)

- **Named target load.** Before judging pass/fail, fix a target = expected launch-day peak × safety factor. Thresholds are read as "meets target," and results are reported as *"evidence we handle [target] at today's sizing,"* never as blanket certainty.
- **Agreed caps up front.** Start at the §5 numbers; escalate *only* after reviewing a run together.
- **Test-data isolation.** A dedicated throwaway **test handle** + an `X-Load-Test: 1` request header, so every write is tagged and scoped to one `site_id`.
- **Cleanup.** A teardown SQL snippet deletes the test site's analytics/media rows (and reverses Phase 0 seed) after each write scenario — keeps fake traffic out of real analytics tables.
- **Kill switch.** Josh watches Supabase connection count + Horizon depth live; if either climbs toward its ceiling, Ctrl-C k6 immediately.

---

## 7. What this proves — and what it does not

**Proves (bankable):**
- Edge cache absorbs repeat views (`cf-cache-status: HIT`) — virality doesn't melt origin or the bill.
- The `public-profile` limiter 429s before Postgres feels a single-source flood.
- The queue/workers survive ~20 concurrent jobs without memory runaway or cross-queue starvation.
- A baseline p50/p95/p99 for release-over-release regression.

**Does not prove:**
- **Survival of an arbitrary viral spike.** One machine = one IP; a real spike is thousands of *distinct* IPs, each with its own 60/min bucket *and* its own cold-cache-miss → origin hit. The scary case — a cold cache hit by many IPs in the first seconds (thundering herd) — needs **distributed** load (k6 Cloud, multi-region). That is a separate, larger, later step before public launch, not part of this DIY pass.
- **Capacity beyond the named target** (§6).
- **Correctness / data-race / rare-input bugs** — load tests find ceilings and concurrency issues, not logic errors.
- **Third-party ceilings** (R2, Cloudflare, Supabase) — a pass may mean we didn't reach *their* limit; a fail may be *their* throttle, not ours.

Empty-DB false confidence is mitigated by Phase 0. Sizing-drift is mitigated by the `OPS-S4-3` re-baseline.

---

## 8. Collaboration mechanics

- **Claude drives k6** — writes scripts (thresholds baked in), kicks off runs, collects JSON artifacts, tails `cloud env:logs partna development --live`.
- **Josh holds the server-side view** — Horizon dashboard (queue depth, worker memory), Supabase connection/query stats (Supavisor headroom), Nightwatch (slow routes/jobs).
- **Checkpoint between every phase** — run one, stop, look at both sides, decide escalate/move-on/abort together.

---

## 9. Deliverables & layout

```
scripts/launch-check/k6/
  config.js          — target URL, test handle, thresholds, target-load constant
  seed.md | seed.php — Phase 0 representative-data seed + teardown
  baseline.js        — Phase 1
  spike-edge.js      — Phase 2a
  spike-origin.js    — Phase 2b
  jobs.js            — Phase 3
  README.md          — exact run commands + what "pass" looks like per phase
  results/           — JSON artifacts (gitignored)
```

Each run: `k6 run --out json=results/<phase>-<label>.json <phase>.js`, exit 0/1 from thresholds, plus a short human read of `cf-cache-status` distribution, Supabase peak connections, and Horizon peak depth against the target.

---

## 10. Open items to resolve at build time

1. **Target-load number** — Josh sets the expected launch-day peak × safety factor.
2. **Test handle** — pick/create the throwaway handle + test user for isolation.
3. **k6 availability** — confirm k6 installed locally (else `brew install k6`).
