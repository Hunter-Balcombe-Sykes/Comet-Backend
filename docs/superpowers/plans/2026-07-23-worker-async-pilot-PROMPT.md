# PROMPT — Worker/async layer: PILOT-tier fixes

> Paste this whole file as the opening message of a fresh session in the
> Comet-Backend repo (`/Users/joshuahunter/Herd/Side Street/backend`).
> You are the **orchestrator**. You dispatch subagents; you do not write
> production code yourself.

---

## Mission

Ship every pilot-tier remediation from the 2026-07-23 worker/async-layer review.
Two source documents, and **neither contains the other**:

| Source | What it holds | Status |
|---|---|---|
| `audits/workers/2026-07-23-worker-async-review-TRIAGE.md` | 24 pipeline findings (`audit.sh`, 4 runs merged) in 10 work units, with checkboxes | **Exists. Execution source of truth.** |
| `docs/reviews/2026-07-23-worker-async-layer-review.md` §8 | 20-item roadmap incl. environment-shaped items the pipeline structurally could not find (§4, last paragraph) | **Exists. No checkboxes.** |

The review states plainly that the pipeline "caught the code-shaped ones and none
of the environment-shaped ones." Twelve roadmap items therefore have **no TRIAGE
entry**. They are specified inline in §4 below and must be folded into the TRIAGE
file before execution starts, so that one file remains the source of truth and
`archive-done.sh` works.

**Scope of this run:** TRIAGE units 1–9 + the twelve `RV-*` units below.
**Explicitly out of scope:** TRIAGE unit 10 (`R3-SCALE-1`, analytics lane capacity)
and roadmap items 11, 12, 16–20 — those are the launch/scale tiers and have their
own prompt (`2026-07-23-worker-async-launch-PROMPT.md`).

---

## Non-negotiable rules

Read `CLAUDE.md` at the repo root first, then obey these. Several exist because
they have already cost a session.

- **`scripts/audit/fix-flow.md` is the runbook.** Not this file — this file
  supplies scope and the units the TRIAGE lacks. Where they disagree, fix-flow
  wins.
- **Branch `audit-fix/worker-async-pilot-2026-07-23` off `development`**, in a
  dedicated worktree, after `git fetch && git pull`. Never commit to
  `development` or `production`. The worktree needs its **own** `composer install`
  and `.env` — do **not** symlink `vendor` or `.env`, that breaks feature tests.
  If edits appear not to take effect, run `composer dump-autoload -o` from the
  real repo root (`.claude/worktrees/` poisons the classmap).
- **Units run sequentially. Never dispatch two implementer subagents at once.**
  Later fixes depend on earlier ones and parallel edits to the same files collide.
- **Never run `composer test` while an implementer subagent is running.** Two
  concurrent suites corrupt each other. Full-suite runs need
  `COMPOSER_PROCESS_TIMEOUT=0` — the 300 s default kills them mid-run.
  `ConnectResolverYoutubeTest` is a known load flake, not a regression.
- **Every implementer prompt must explicitly forbid `git stash`.** Subagents do
  not inherit that rule and will happily stash over shared working state.
- **Verify each finding's premise before implementing it.** Grep the symbol, read
  the file, check the migration. Findings routinely reference columns, flags and
  classes that have since moved. If the premise is false, mark the unit
  `PREMISE-STALE`, record why, and move on — do not invent a fix.
- **Tests run SQLite; production is Postgres.** A green suite does not prove a
  constraint-bound write works. Verify against the DDL in `supabase/migrations/`.
  `DB::connection('pgsql')->getDriverName()` returns `sqlite` under test — that is
  the seam for gating Postgres-only code.
- **No Laravel migration files.** Schema changes are raw SQL in
  `supabase/migrations/`. The composer guard rejects the alternative. Nothing in
  this run should need a migration — if a unit seems to, stop and escalate.
- **Keep commits surgical.** Do not run Pint across unchanged files; it churns the
  baseline and buries the actual diff.
- **Logs come from the Cloud CLI only** — `cloud env:logs partna development
  --minutes 30`. `mcp__laravel-boost__read-log-entries` and `last-error` are
  forbidden; they return stale test output.

---

## Execution policy

Per `fix-flow.md` §1 and the TRIAGE header:

- **Plan:** Opus 4.8 · **Implement:** Sonnet 4.6 · **Review:** a *separate*
  Sonnet 4.6 instance, never the implementer.
- **Combine plan+impl:** YES for S/XS units · NO for P1 or L/XL.
- **Always specify the model explicitly on every dispatch.** An omitted model
  silently inherits this session's, which defeats the policy.
- **Final whole-branch review runs on Opus 4.8**, not the session default.
- Hand artifacts over as **files**, not pasted text: brief → implementer →
  report file → reviewer. Bulk diffs never enter your context.
- Track progress in a ledger (`.superpowers/sdd/progress-2026-07-23-worker-async-pilot.md`)
  as well as todos. Conversation memory does not survive compaction; re-dispatching
  a completed unit is the most expensive failure mode there is. After any
  compaction, trust the ledger and `git log` over your recollection.

### Blocker gate

Per fix-flow: **P0 · auth · money · DB/migration · L/XL · Standalone** → produce
the plan, present it with blast radius and your recommendation, and **wait for
Josh's explicit go-ahead**.

**Front-load these.** Do not hit them one at a time mid-run. Before dispatching
any implementer, author the plans for all gated units — TRIAGE units 1, 2, 3 and
`RV-4`, `RV-6`, `RV-8` — and present them to Josh as **one batched sign-off**.
Then execute continuously. This is the deliberate reconciliation between
fix-flow's gate and continuous orchestration; it is not a licence to skip the gate.

---

## Step 0 — surface the two items Josh must do himself

These are dashboard/env actions, not code. **Report them in your first message,
then proceed — do not block the code work on them.**

- **`RV-1` · Verify Valkey `maxmemory-policy` on `partna_dev_cache`.** The app ACL
  denies `CONFIG` and `INFO`, so only the Laravel Cloud dashboard can answer. If it
  is any `allkeys-*` policy, **queued jobs are being evicted silently** — no error,
  no `failed_jobs` row, no trace. It must be `noeviction`. The review calls this the
  single highest-value check in the document, and it outranks every code fix here.
- **`RV-2` · Set `HORIZON_NOTIFICATION_EMAIL`** (or `_SLACK_WEBHOOK`) on
  `development` and `production`. Twelve already-tuned `waits` thresholds in
  `config/horizon.php` currently fire into a void, and Nightwatch structurally
  cannot cover queue depth — it instruments job *execution*, and a job nobody
  consumes never executes.

---

## Step 1 — extend the TRIAGE file

Append a `## Review-only addendum — pilot tier` section to
`audits/workers/2026-07-23-worker-async-review-TRIAGE.md` containing one
`- [ ]` checkbox entry per `RV-*` unit in §4 below, in the same entry format the
file already uses (Where · Affects · Effort · What to do · Technical · Plain
English). Bump the `## Progress` counts to include them.

Commit this as its own commit before any code lands. Rationale: fix-flow ticks
boxes in the TRIAGE, `archive-done.sh` reads those boxes, and the standing repo
convention is that TRIAGE — not CONSOLIDATED — is the doneness source of truth.
Units without a box would silently never be tracked.

---

## Step 2 — run the units

Order matters. Dependencies are noted; the TRIAGE's own ordering notes still apply
(units 1→2 share a fix shape; unit 4 lands before unit 8 to avoid rebase churn).

| # | Unit | Source | Gate |
|---|---|---|---|
| 1 | `RV-7` Horizon ≥5.47.2 | §4 | autonomous — **own commit, first** |
| 2 | TRIAGE unit 4 — `R3-OBS-1…6` exception reporting | TRIAGE | autonomous |
| 3 | `RV-3` `$uniqueFor` permanent-lock + guard `is_int` skip | §4 | autonomous |
| 4 | TRIAGE unit 8 — trivial job properties | TRIAGE | autonomous |
| 5 | TRIAGE unit 5 — scheduler TTL & prune hygiene | TRIAGE | autonomous |
| 6 | `RV-9` `block_for` | §4 | autonomous |
| 7 | `RV-10` moderation `Notify*` transactions | §4 | autonomous |
| 8 | `RV-11` `platforms/` orphan sweeper | §4 | autonomous |
| 9 | `RV-5` cap + stagger `integrations:refresh` | §4 | autonomous |
| 10 | TRIAGE unit 6 — AI re-billing on retry | TRIAGE | autonomous |
| 11 | TRIAGE unit 7 — bulk-fanout pacing | TRIAGE | autonomous |
| 12 | TRIAGE unit 9 — P3 cache/lock polish | TRIAGE | autonomous |
| 13 | TRIAGE unit 1 — stamp-before-send mail trio | TRIAGE | 🔒 |
| 14 | TRIAGE unit 2 — GDPR deletion mail | TRIAGE | 🔒 — after 13 |
| 15 | TRIAGE unit 3 — Apify budget double-spend | TRIAGE | 🔒 money |
| 16 | `RV-6` Google Places spend ceiling | §4 | 🔒 money |
| 17 | `RV-8` `RefreshController` → job | §4 | 🔒 contract change |
| 18 | `RV-4` memory over-commit | §4 | 🔒 cost decision |
| 19 | `RV-12` split mail supervisor | §4 | 🔒 — **strictly after 18** |

Autonomous units first is deliberate: they shake out worktree, autoload and suite
problems while the blast radius is small, before anything touches a money or GDPR
path.

---

## Step 3 — completion

1. `COMPOSER_PROCESS_TIMEOUT=0 composer test` on the full branch. Must be green.
2. `php artisan pint --dirty` — changed files only.
3. Final whole-branch review, dispatched on **Opus 4.8**, given the branch diff as
   a file (`git merge-base development HEAD` → `HEAD`). If it returns findings,
   dispatch **one** fix subagent with the complete list — not one fixer per finding.
4. `scripts/audit/archive-done.sh audits/workers/` — run it, never ask. If boxes
   remain it stays put and reports why.
5. Report: units done, units blocked with reason, `PREMISE-STALE` units, test
   status, branch name. **Do not merge or push to `development`** — Josh reviews
   and merges.

---

## 4. Review-only units (`RV-*`) — full specification

These have no TRIAGE entry. Every claim below cites the review; verify each
premise against current code before implementing.

### `RV-3` · `ShouldBeUnique` with no `$uniqueFor` takes a permanent lock
**Effort S · autonomous · roadmap #3**

- **Where:** `app/Jobs/Platforms/LinkInBioScanJob.php:32`,
  `app/Jobs/Platforms/ScanPreviousWebsiteContentJob.php:53`,
  `tests/Feature/.../HorizonQueueCoverageTest.php:394-400`
- **Technical:** `UniqueLock::acquire()` reads `($job->uniqueFor ?? 0)`.
  `RedisLock::acquire()` branches on `if ($this->seconds > 0)` — with `0` it falls
  through to **`SETNX` with no expiry**. A worker SIGKILLed (OOM, deploy, timeout)
  before `UniqueLock::release()` leaves that key in Redis **forever**, and every
  future dispatch for that `uniqueId` is silently discarded — no error, no
  `failed_jobs` row. The lock lives in DB 4, deliberately excluded from
  `Cache::flush()`, so clearing it needs manual Redis surgery.
- **What to do:** Declare an explicit `$uniqueFor` on both jobs (both have
  `$timeout` 60; pick a value comfortably above it, consistent with sibling
  scraping jobs). Then fix the guard: `HorizonQueueCoverageTest` does
  `$uniqueFor = $defaults['uniqueFor'] ?? null; if (! is_int($uniqueFor) ...) { continue; }`
  — a job declaring **no** `uniqueFor` yields `null`, fails `is_int`, and is
  skipped. The `continue` intended for constructor-assigned values also swallows
  the genuinely dangerous case. Make a missing `uniqueFor` on a `ShouldBeUnique`
  job **fail** the test, and confirm the new assertion fails before the fix.

### `RV-4` · Worker memory over-commit
**Effort S · 🔒 cost decision · roadmap #4**

- **Where:** `config/horizon.php` supervisor blocks; Laravel Cloud Worker cluster
- **Technical:** Permitted worker heap is `2 × 256 (supervisor-1) + 256
  (supervisor-long) + 512 (supervisor-videos) = 1280 MiB` on a **1024 MiB**
  `flex-1gb` box — a 25% over-commit before counting the Horizon master, three
  middleman processes, or the scheduler that shares this instance. Horizon's
  `memory` is a *restart-after-exceeded* threshold checked **between jobs**, not a
  cap, so nothing prevents that sum being reached. An OOM kill means no `failed()`,
  orphaned locks and orphaned temp files. ffmpeg's RSS is outside PHP's
  `memory_get_usage()` entirely, so Horizon cannot see it at all.
- **What to do:** Present Josh both options with cost — raise the Worker instance,
  or lower `supervisor-videos.memory`. This is his call, not yours. **`RV-12`
  depends on the outcome.**

### `RV-5` · `integrations:refresh` uncapped, unstaggered fan-out
**Effort S/M · autonomous · roadmap #5**

- **Where:** `app/Console/Commands/RefreshIntegrationConnectionsCommand.php:32-39`
- **Technical:** Dispatches one `RefreshConnectionJob` per due connection in a tight
  `lazyById()` loop, no cap, no stagger. Fires at 03:00 — the same minute eleven
  other scheduled entries fire, eight of which query Postgres directly from the
  same 1 GiB container. It targets `platform_refresh`, which sits **second-to-last**
  in `supervisor-1`'s strict-priority list, so the largest burst in the system is
  aimed at the second-lowest-priority queue.
- **What to do:** Add a per-run cap and a dispatch stagger (`->delay()` spread).
  The existing "capacity scales with the fleet" comment is a deliberate choice —
  preserve the intent, bound the burst. Consider moving the 03:00 slot off the
  collision minute.

### `RV-6` · Google Places has no spend ceiling
**Effort M · 🔒 money · roadmap #6**

- **Where:** `app/Http/Controllers/Api/Platforms/GoogleBusinessController.php:101`
  → `fetchPlaceDetails`; `app/Services/.../GoogleBusinessService`
- **Technical:** No spend ceiling exists in code — only burst limiters
  (`preaccount-places`, 30/min) and `pool_concurrency` 5; the primary dashboard
  path has **neither**. `ApifyBudget` guards a *different* vendor. At the vendor
  end Google's billing docs state that *"Setting a budget does not automatically
  cap Google Cloud or Google Maps Platform usage or spending"* — budgets are alerts
  only. Places SKUs run **$5–$35 per 1,000 calls**. This is the only uncapped paid
  API in the system.
- **What to do:** An `ApifyBudget`-style gate around the Places call path. Study
  `ApifyBudget` first and note `R4-RES-1`'s lesson — **the claim must be taken at
  every billed call site, not once per logical operation**, or the accounting
  undercounts exactly the way Apify's does. Prefer a per-user *and* global bound
  over Apify's global-only, date-keyed design.

### `RV-7` · Upgrade `laravel/horizon` to ≥5.47.2
**Effort S · autonomous · roadmap #7**

- **Technical:** Horizon 5.47.2 fixes a metric-clearing bug that manifests
  specifically under **phpredis with a scan prefix configured**. This app runs
  phpredis 6.3.0 with prefix `partna_database_` on Horizon **5.47.0** — the bug
  conditions exactly. Constraint is already `^5.45`, so `composer update
  laravel/horizon` suffices.
- **What to do:** Run it first, as its own commit, so the lockfile change is not
  tangled with logic. Confirm the resulting version is ≥5.47.2 and the suite is green.

### `RV-8` · `RefreshController::refresh()` blocks inline
**Effort S · 🔒 contract change · roadmap #8**

- **Where:** `app/Http/Controllers/Api/Platforms/RefreshController.php:40,76-82`
- **Technical:** Calls `PlatformRefresher::refresh()` **inline, in a `foreach` over
  every connected row**. `SafeUrlFetcher`'s timeouts are **per-hop** (8 s × 6 hops,
  doubled by the 403 alternate-UA retry ≈ 96 s), so worst case is ~108 s **× row
  count** in a single request. `FetchBudget` (20 s wall-clock) exists but is opt-in
  and this path does not use it.
- **What to do:** `RefreshConnectionJob` already exists and wraps this exact call
  for the cron dispatcher, with rate limiting and queueing. Dispatch it per row.
  **This changes the endpoint's response contract** (synchronous result → accepted).
  Present the response shape to Josh before implementing — the frontend is a
  separate repo and must not be broken silently.

### `RV-9` · `block_for` is `null` on all four connections
**Effort S · autonomous · roadmap #9**

- **Where:** `config/queue.php` — `redis`, `redis_scraping`, `redis_gdpr`, `redis_video`
- **Technical:** `null` means **no `BLPOP`** — the worker polls in PHP userland
  between `--sleep` intervals, costing both pickup latency and Redis command
  volume. A positive value gives near-instant pickup while still returning control
  for signal handling. **Do not use `0`** — it blocks forever and, per the Laravel
  docs, *"will also prevent signals such as `SIGTERM` from being handled until the
  next job has been processed"*, which breaks zero-downtime deploys.
- **What to do:** Set ~5 on all four. Verify Horizon still terminates cleanly.

### `RV-10` · Moderation `Notify*` jobs duplicate on retry
**Effort M · autonomous · roadmap #10**

- **Where:** `app/Jobs/Moderation/NotifyOnCallStaffJob.php`,
  `NotifyReportedUserJob.php`, `NotifyReporterJob.php`
- **Technical:** They write `markDispatched` (commit) → send → `markCompleted`
  (commit) **non-transactionally**, guarded only by
  `if ($entry->status === 'completed') return;`. A crash between send and complete
  leaves `dispatched`, so the retry re-sends. `NotifyReporterJob` is worst: it loops
  over reporters with **no per-recipient idempotency key**, so a mid-loop crash
  re-emails everyone already contacted.
- **What to do:** The correct pattern is already in the same directory —
  `SuspendUserJob`, `SuspendSiteJob` and `QuarantineMediaJob` wrap all three steps
  in one transaction and are consequently safe. Follow it. For `NotifyReporterJob`,
  a per-recipient key is required regardless, since one transaction around a loop
  of sends still re-sends on retry.

### `RV-11` · No sweeper for orphaned `platforms/` media
**Effort M · autonomous · roadmap #14**

- **Technical:** The only uncovered failure class in the review. Ten
  `DeleteMirroredMediaJob` failures at `2026-07-23 03:21:15` (R2 4xx on a
  `platforms/instagram/...` prefix listing); its `failed()` only reports and logs.
  `gdpr:sweep-purged-video-artifacts` reads `EVENT_PURGED` audit rows for **video**
  paths, and `media:gc-orphaned-video-artifacts` LISTs the **`videos/`** prefix —
  **neither touches `platforms/`**. Scraped Instagram media orphaned in R2 leaks
  storage indefinitely.
- **What to do:** New console command + scheduled entry, modelled on the two
  existing video sweepers. Use `->onOneServer()` and an explicit
  `withoutOverlapping(N)` where `N` exceeds the plausible run time — note
  `R2-SCHED-1`'s lesson that a TTL shorter than the cadence lets the lock expire
  mid-execution. Keep it off the 03:00 collision minute. Include an age guard so it
  cannot race an in-flight mirror.

### `RV-12` · Split transactional mail into its own supervisor
**Effort M · 🔒 strictly after `RV-4` · roadmap #15**

- **Where:** `config/horizon.php:96-104`
- **Technical:** `supervisor-1` drains **eleven** queues with **two** processes under
  `balance => false` (strict listed priority). `mail` and `notifications` are 2nd/3rd,
  which is correct — but a single long job elsewhere in that supervisor (a 180 s
  Cloudflare purge, a 300 s logo job) occupies one of only two processes; two
  concurrent long jobs stall **every** transactional email.
- **What to do:** **Do not implement before `RV-4` is resolved.** A fourth supervisor
  adds a middleman + worker (~180 MiB) to a box already permitting 1280 MiB on 1024 —
  splitting first trades a latency problem for the 2026-07-22 OOM. The review states
  the sequencing explicitly and `config/horizon.php:96-104` carries a caution comment.
- **Also correct the comment while you are there.** It claims `balance => false` is
  "the only strategy that respects `maxProcesses` — `simple`/`auto` floor at one
  worker PER QUEUE." `Supervisor::scale()` is invoked **only** from
  `SupervisorCommands\Scale` (manual `horizon:scale`/dashboard); the automatic path
  treats `maxProcesses` as a hard ceiling. **Real behaviour is worse than the comment
  claims:** with `simple`/`auto` and `maxProcesses` (2) < queue count (11), the first
  pools to claim workers exhaust the budget and the rest get **zero** — starvation,
  not a floor of one. The conclusion stands; the justification is wrong.

---

## Reference

- Review: `docs/reviews/2026-07-23-worker-async-layer-review.md`
- TRIAGE: `audits/workers/2026-07-23-worker-async-review-TRIAGE.md`
- Runbook: `scripts/audit/fix-flow.md`
- Launch tier: `docs/superpowers/plans/2026-07-23-worker-async-launch-PROMPT.md`
