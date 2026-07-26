# Execute prompt — BUILD the k6 load-testing harness (`scripts/launch-check/k6/`)

Paste everything below the line into a fresh Claude Code session in this repo. This pass is **build-only**: write every script, smoke-test each one solo, and produce the usage doc. It does **not** run the joint measured load tests — those are a separate, later collaborative session (see "Deferred to a later session" below). Because no live-judgment escalate/abort calls happen in this pass, Sonnet is fine; reserve Opus for the later measured-run session if that one warrants it.

---

Build the **k6 load-testing harness** end-to-end: `docs/superpowers/plans/2026-07-26-k6-load-testing.md` (the plan is the source of truth — read it **fully** first; spec at `docs/superpowers/specs/2026-07-18-k6-load-testing-design.md` for background/rationale only). The plan's **Global Constraints** and its three locked decisions are ACCEPTED as written — implement them:

- **Target = DEV** (`dev-api.partna.au`), a regression pass. The prod capacity run is deferred to `OPS-S4-3`.
- **Named target load = 50 concurrent viewers**, scripts parameterized to step to **200** after a joint checkpoint (`SPIKE_VUS=200`).
- **Throwaway handle = `loadtest`** (fixed user id `…001`, site id `…002`), created + seeded by the plan.

If the environment now contradicts one of these (see Pre-flight), **STOP and flag to Josh** rather than redesigning.

## What kind of build this is (read before starting)

This is a **self-contained k6 harness under `scripts/launch-check/k6/`** — NOT a Laravel feature.

- **Zero** app-code, `config/partna.php`, or Supabase **migration** changes. Seed/teardown is raw SQL scoped to the fixed test IDs. If a task seems to need an app change, STOP — it's out of scope for a `scripts/` tool.
- **No `composer test` and no `ci.yml` job** — by design (k6 is slow, manual, and needs a live target).
- It is **environment-interactive even in build mode**: Task 2/3 seed the dev DB and sync Cloudflare KV for real, and every task's smoke run is a real (tiny, short) HTTP request against dev. That part you do solo. What you do **not** do solo — this pass, at all — is escalate any script beyond its baked-in smoke parameters (1-3 VUs, 10-15s).

## Deferred to a later session (do NOT do these now)

Per the plan's collaboration model (spec §8), the **measured runs are collaborative checkpoints**, not solo work: Josh watches Horizon (queue depth, worker memory), Supabase connections (Supavisor headroom), and Nightwatch while the harness drives real load. That means, in the plan's task list:

- Task 4 Step 4 (`baseline.js` measured run)
- Task 5 Step 4 (`spike-edge.js` measured run)
- Task 6 Step 5 (`spike-origin.js` measured run)
- Task 7 Step 5 (Phase 3 measured run — already gated behind Phases 1-2 passing *and* a limiter decision with Josh)

**Skip all four this pass.** Leave those checkboxes unticked in the plan. Everything else in each task — writing the script, the solo smoke run, and the commit — is in scope and should be completed.

## Precondition — get the plan onto your build branch

The design **spec** is already on `development`. The **plan** and this prompt were authored on another branch (uncommitted at authoring time). Before Task 1:

```bash
git fetch origin
git checkout -b feat/k6-load-testing origin/development
# If the plan isn't already on development, carry it (and this prompt) from the branch that has it:
git ls-files docs/superpowers/plans/2026-07-26-k6-load-testing.md || \
  git checkout <branch-with-plan> -- \
    docs/superpowers/plans/2026-07-26-k6-load-testing.md \
    docs/superpowers/plans/2026-07-26-k6-load-testing-EXECUTE-PROMPT.md
```

Work in the **main checkout** (harness `.claude/worktrees/` symlink `vendor`/`.env` and break things). **Shared repo — `git add` new files immediately; Josh commits** (stage + summarize the diff, let Josh run the commit, or commit only when he says so).

## Your role & approach

Use the **superpowers:executing-plans** skill (phase-by-phase with review checkpoints) — it fits an environment-interactive build better than pure orchestration.

- You **personally** run every seed / KV-sync / smoke-run / curl-verify step — those need coherent hands-on judgment, not delegation.
- You **may** delegate the isolated *script-authoring* of a task (writing `baseline.js`, etc.) to a subagent. Child agents inherit the main-loop model.
- **Never run two k6 scripts at once** against the same origin/handle — they share the single 60/min per-IP limiter bucket and skew each other's metrics (still applies to smoke runs if you're iterating quickly).
- After each task: prove the deliverable empirically (show the smoke-run output / the verify curl), do an independent review pass (a fresh reviewer over the diff, or your own skeptical re-verification for pure-glue tasks), tick the task's build-scope checkboxes (not the deferred CHECKPOINT ones), then stage for Josh to commit.

## Pre-flight — re-validate before building (plan is 8+ days downstream of the spec; the code moves fast)

Report drift before writing anything. The 2026-07-26 authoring session already verified the six spec checks; re-confirm the ones that can move:

1. **Environment reality unchanged** — dev still serves `dev-api.partna.au` as an isolated regression target; **prod is still only live-serving with no prod-mirrored staging**. `curl -s -o /dev/null -w '%{http_code}\n' https://dev-api.partna.au/api/health` → 200. **If a prod-mirrored staging now exists, or prod re-paused, STOP** — the §2 target decision changes.
2. **`k6` installed** — `k6 version` (else `brew install k6`).
3. **Limiter intact + ON** — `config('partna.public_profile.rate_limit_per_minute')` still 60 and the global `$throttleEnabled` gate is ON on dev (Task 6 Step 1 covers this; a disabled limiter makes even the Phase 2b smoke run assert nothing meaningful).
4. **Seed schema still matches** — the plan's `seed.sql` targets real 2026-07-26 baseline columns. The Task-3 verify curl (`links 10 / gallery 40 / services 15`) is the drift detector: zeros = a column/constraint moved, not an empty result. Fix the SQL, don't proceed on a false-empty site.

## Build order

**All of Tasks 1-7, build-scope only:** Task 1 (scaffold `config.js` + `.gitignore`) → Task 2 (provision `loadtest` user+site+KV) → Task 3 (Phase-0 seed + teardown, verify representative payload) → Task 4 (write + smoke `baseline.js`) → Task 5 (write + smoke `spike-edge.js`) → Task 6 (write + smoke `spike-origin.js`) → Task 7 (write + smoke `jobs.js`, teardown+re-seed after, write `README.md`).

For Tasks 4-7: write the script, run the **safe smoke run** baked into that task (1-3 VUs, 10-15s), commit, and **STOP at the "CHECKPOINT — joint measured run" step** — do not run it. Move to the next task instead.

The `README.md` in Task 7 is the usage doc: run commands per phase, pass criteria, setup steps, guardrails, and the "Baseline reference" fill-in-later table. That's the deliverable that lets anyone (including a later session) pick up the measured runs without re-reading the whole plan.

## Gotchas block (keep these in front of you every task; hand to any subagent verbatim)

- **NEVER point the cache-buster (`spike-origin.js`) or `jobs.js` at `api.partna.au` (live prod).** Origin is `dev-api.partna.au`, full stop. Prod is now live (post-cutover); the prod capacity run is a separate, later `OPS-S4-3` window, not this pass.
- **The edge test unavoidably hits the prod Worker.** There is one zone-wide route (`*/*` on `partna.au`, no dev edge — EDGE-102), so `loadtest.partna.au` (Phase 2a, even the smoke run) flows through the production Worker + shared `SUBDOMAIN_KV`. That's acceptable (cache-HITs only, ≈0 origin/DB impact) but know it, and know the `loadtest` KV entry is visible to prod too.
- **429 is a PASS, not a failure.** `spike-origin.js` and `jobs.js` set `http.setResponseCallback(http.expectedStatuses(...))` so 200/201/429 are expected; only 5xx counts as `http_req_failed`. A smoke run full of clean 429s is the limiter *working* — don't "fix" it.
- **Seed/teardown is DEV-ONLY and idempotent.** Confirm the target is dev (`glncumufgaqcmqhzwrxm`) before every seed/teardown apply. The seed is one atomic `DO` block (rolls back wholesale on any error).
- **Raw SQL does NOT fire the KV observer.** After any seed/re-seed, dispatch `\App\Jobs\Cloudflare\SyncSubdomainToKvJob::dispatchSync('00000000-0000-4000-a000-000000000001')` via `cloud tinker development`, or `loadtest.partna.au` won't resolve for the edge smoke run.
- **Teardown after every write scenario, then re-seed.** The Task 7 `jobs.js` smoke run writes analytics rows — run `teardown.sql` after, and re-apply `seed.sql` before any further read-path run (teardown deletes the handle). Reads (baseline/edge/origin) don't write, so they need no teardown.
- **Logs: Cloud CLI only.** If you want to sanity-check a smoke run in logs, tail `cloud env:logs partna development --tail 50` (a `--live` tail is only worth holding open during the deferred measured runs). FORBIDDEN: `mcp__laravel-boost__read-log-entries` / `last-error` (stale test-suite output).

## Definition of done (this pass)

- All seven tasks' build-scope steps complete: `config.js`, `seed.sql`/`teardown.sql`, `baseline.js`, `spike-edge.js`, `spike-origin.js`, `jobs.js` written, each smoke-run passed solo, each committed.
- `README.md` written and committed — documents setup, per-phase run commands + pass criteria, guardrails, teardown, and notes the measured runs as **not yet run** (pending a scheduled joint session with Josh).
- Every "CHECKPOINT — joint measured run" checkbox (Tasks 4/5/6/7) left **unticked** — those are explicitly out of scope this pass.
- Results JSON stays gitignored — never committed.

## Ship — STOP, do not push

When all seven tasks' build-scope steps are done:

1. Ensure every task is committed on `feat/k6-load-testing` with its build-scope checkboxes ticked (measured-run checkboxes left unticked).
2. Report to Josh and **stop — do NOT merge or push.** Summarize: which scripts landed, each smoke-run's output, any drift found in pre-flight, and that the harness + README are ready for a later joint session to run the actual measured tests at the 50-concurrent target. Josh decides when to schedule that session (and separately, merge/push timing).

## Failure posture — stop and ask Josh when

- Pre-flight shows the environment reality shifted — a prod-mirrored staging appeared, or prod re-paused — changing the §2 target decision.
- The Task-3 verify curl returns zeros (seed column/constraint drift) — don't run against a false-empty site.
- A smoke run shows `edge_cache_hit` at ~0 after warmup — a real Worker cache bug (spec §7). Flag it; do not tune the threshold to pass.
- A smoke run shows any 5xx from `spike-origin.js` or `jobs.js` — the limiter isn't shielding Postgres even at 3 VUs; that's a real finding, flag it rather than proceeding.
- Anything tempts an app-code / `config/` / migration change — out of scope by definition.
- Anything requires pointing load at prod (`api.partna.au`) without an explicit prod-window decision.
- Anything tempts running a "CHECKPOINT — joint measured run" step solo — don't; that's the one hard line this pass draws.
