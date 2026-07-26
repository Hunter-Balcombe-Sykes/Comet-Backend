# Execute prompt — RUN the k6 load-testing measured checkpoints (joint session)

Paste everything below the line into a fresh Claude Code session in this repo. This pass is the **collaborative measured-run session** the build pass (`2026-07-26-k6-load-testing-EXECUTE-PROMPT.md`) deliberately deferred. Live escalate/pause/abort judgment calls happen here — **use Opus**, not Sonnet.

---

Run the deferred **CHECKPOINT — joint measured run** steps in `docs/superpowers/plans/2026-07-26-k6-load-testing.md` (Task 4 Step 4, Task 5 Step 4, Task 6 Step 5, and — gated — Task 7 Step 5). The harness itself (`scripts/launch-check/k6/`) is already built, smoke-tested, and committed. This pass does **not** write new scripts; it runs them at real scale with Josh watching live, records results, and fills in the README's baseline table.

## Precondition — locate the branch

The build session worked on `feat/k6-load-testing-wt` inside an isolated worktree (`.claude/worktrees/k6-load-testing`), separate from the main checkout's `feat/k6-load-testing` (which only has the first 3 tasks). **These may have been reconciled into one `feat/k6-load-testing` branch since** — check first:

```bash
git branch -a --list '*k6-load-testing*'
git log --oneline -1 <each candidate>   # the one with 7+ commits ending "tick build-scope checkboxes" has the full harness
```

If ambiguous, ask Josh which branch/worktree is canonical before proceeding — do not guess or redo work on the wrong one.

## Pre-flight — re-verify before running anything (time has passed; re-check drift)

1. **Dev is actually healthy, not just alive.** `/api/health` is a liveness probe that never touches the database — during the build session it stayed 200 through a real ~5-minute DB-connectivity outage. Use a DB-touching route instead:
   ```bash
   curl -s -o /dev/null -w '%{http_code}\n' https://dev-api.partna.au/api/public/profiles/loadtest
   ```
   Expect `200`. If `500`, **stop** — check `cloud env:logs partna development --minutes 10` before doing anything else. Do not load-test a broken environment.
2. **Seed still represents the handle correctly:**
   ```bash
   curl -s "https://dev-api.partna.au/api/public/profiles/loadtest" | python3 -c "import sys,json; d=json.load(sys.stdin)['data']['profile']; print('links',len(d['links']),'gallery',len(d['gallery']),'services',len(d['services']))"
   ```
   Expect `links 10 gallery 6 services 15` (gallery is 6, not 40 — `site.site_media`'s gallery pool is hard-capped at 6/site by `core.enforce_site_gallery_max6`; the build session's `seed.sql` already accounts for this). Zeros or a 500 here means re-apply `seed.sql` + re-sync KV (see harness README "Setup").
3. **`loadtest.partna.au` resolves through the Worker:** `curl -sI https://loadtest.partna.au/ | grep -i "^HTTP"` → `200`.
4. **k6 installed:** `k6 version` (≥ v0.50; the build session installed v2.1.0 via `brew install k6`).
5. **Rate limiter still ON:** `cloud tinker development --code="echo config('partna.public_profile.rate_limit_per_minute');"` → `60`.
6. **Environment reality unchanged:** dev still an isolated regression target; prod (`api.partna.au`) still live with no prod-mirrored staging. If that's changed, stop and flag — the plan's target decision (§2) would need revisiting.
7. **No other k6 run in flight** — check for a live `cloud env:logs partna development --live` tail or Monitor from a stale session; `spike-origin`/`jobs` share one 60/min-per-IP limiter bucket with anything else hitting dev from this machine.

## Your role & approach

This is **not** solo work. Per the plan's §8 collaboration model:
- **You drive k6** (run the commands, tail `cloud env:logs partna development --live` in the background) and narrate what the metrics mean in real time.
- **Josh watches** Horizon (queue depth, worker memory), Supabase connections (Supavisor headroom vs the free-tier ceiling), and Nightwatch — in parallel, on his own screen.
- **Run one phase, stop, both review, decide together**: escalate (raise VUs/duration) / move on to the next phase / abort. Never decide to escalate unilaterally.
- **Kill switch:** if Josh says queue depth or connection count is climbing toward a ceiling, Ctrl-C the k6 run immediately — don't finish the scheduled duration first.

## Run order

Each phase is `k6 run --out json=results/<phase>-run1.json <script>.js` from `scripts/launch-check/k6/`. Bump the run number (`run2`, `run3`, ...) on a retry rather than overwriting — results/ is gitignored, so this is free.

1. **Phase 1 — `baseline.js`** (Task 4 Step 4). Pass = `http_req_duration p(95) < 500ms`, `http_req_failed < 1%`. Record p50/p95/p99 + error rate into the README's "Baseline reference" table — **this is the number future releases regress against**, so get a clean run before moving on.
2. **Phase 2a — `spike-edge.js`** (Task 5 Step 4). Pass = `edge_cache_hit > 0.9` **and** origin request count stays ~flat during the sustained phase (Josh checks Cloudflare analytics / origin logs — this proves the edge is actually absorbing load, not just reporting a good ratio). A sustained MISS/DYNAMIC or a real origin-traffic bump is a Worker cache bug (spec §7) — flag it, don't tune the threshold.
3. **Phase 2b — `spike-origin.js`** (Task 6 Step 5). Pass = `origin_5xx == 0`, `origin_429 > 0`, Supabase connection count stays flat (limiter shielding Postgres). **Optional Option B** (spec §4): only if Josh decides PG capacity is genuinely unclear, temporarily raise the limiter for a tight watched window and restore after — a separate, conscious checkpoint, not automatic.
4. **Phase 3 — `jobs.js`** (Task 7 Step 5) — **GATED**: only run if Phases 1 & 2 both passed, **and** Josh has made the Phase 3 limiter decision live (README "Phase 3 limiter decision": either temporarily raise the 120/min analytics limiter for a watched window, or drive load via a tinker loop instead). Watch: Horizon queue depth (drains after?), worker memory (climbs and recovers, or climbs and doesn't?), job failure rate, and cross-queue starvation (`redis_video` vs `default`/`analytics`). This is a **write scenario** — run `teardown.sql` immediately after, then re-apply `seed.sql` + re-sync KV before any further read-path work.

## Escalation to the named target's ceiling (optional, after all of the above pass)

The plan's named target is 50 concurrent viewers; scripts are parameterized to step to 200 (`SPIKE_VUS=200`) for edge/origin. Only attempt this **after** a joint review of the 50-VU results, and only if Josh wants that data point — it's not required for this pass to be considered done.

## After each phase

- Fill in the README's "Baseline reference" table (Phase 1 numbers) and note pass/fail for each phase run.
- Tick **only** the CHECKPOINT checkbox(es) in `docs/superpowers/plans/2026-07-26-k6-load-testing.md` for phases actually completed this session (Task 4 Step 4 / Task 5 Step 4 / Task 6 Step 5 / Task 7 Step 5) — leave any not run this pass unticked.
- `git add` the README + plan changes; **Josh commits** (or explicitly tells you to), per the shared-repo convention.

## Definition of done (this pass)

- Phases 1 and 2a/2b measured, reviewed jointly, and recorded. Phase 3 either measured (if gated conditions were met) or explicitly left deferred with a one-line note why.
- README's baseline table filled in with real numbers, not placeholders.
- Plan's CHECKPOINT boxes ticked only for what actually ran.

## Ship — STOP, do not push

Report to Josh: pass/fail per phase, the recorded baseline numbers, anything flagged as a real bug (not tuned away), and whether Phase 3 ran or why it didn't. **Do not merge or push** — that, and whether/when to schedule a 200-VU escalation or the deferred prod capacity run (`OPS-S4-3`), are Josh's call.

## Failure posture — stop and flag, don't work around

- Horizon queue depth climbs without draining, or worker memory climbs without recovering → abort the run, flag it as a real capacity finding.
- Supabase connection count approaches the Supavisor/free-tier ceiling → abort immediately (kill switch).
- Any 5xx from `spike-origin.js` or `jobs.js` → stop, flag as a real limiter/capacity gap — do not tune thresholds to make it pass.
- `edge_cache_hit` near 0 after warmup → real Worker cache bug (spec §7) — flag, don't "fix" by lowering the threshold.
- Anything tempts pointing load at `api.partna.au` (live prod) → stop; that's the separate, later `OPS-S4-3` window, not this session.
- Pre-flight step 1 (dev health) fails → stop before running anything; a broken environment can't produce a meaningful measurement.
