# Cutover Phase 5 — Post-cutover (workers on, backups, docs) (paste-in execute prompt)

Operationalises `production-cutover.md` Phase 5 — the steps that only make sense once customer data
already lives in prod. Runs AFTER go-live is verified, not as part of it.

**How to use:** open a **fresh Claude Code session in this repo on model Opus**, then paste everything
from `=== PROMPT START ===` to the end as your first message.

---

## GATE — do not start until every box is true

- [ ] **Phase 3 go-live is done** — `api.partna.au` serves from the prod Laravel Cloud env, confirmed live
      (not on the raw `*.laravel.cloud` URL only).
- [ ] **Phase 4's launch-check gate PASSED** — `audits/launch-check/<date>/REPORT.md` exists per
      `docs/superpowers/plans/2026-07-24-launch-check-3-cutover-PROMPT.md`, with no Task-8/Task-9 FAIL and
      no RLS-DISABLED finding.
- [ ] **Prod is stable** — Nightwatch (prod project) shows no boot exceptions since go-live.
- [ ] **For the worker flip specifically — B1 is resolved.** A Horizon worker AND the scheduler are
      actually **provisioned and running** in Laravel Cloud right now, not merely that
      `QUEUE_CONNECTION=redis` is set as an env var somewhere. An env var without a running worker is
      strictly worse than sync (silent unbounded backlog) — do not treat "the var exists" as satisfying
      this.

**STOP if any box is false.** Report which one and why instead of proceeding.

---

```
=== PROMPT START ===

Execute docs/deploy/PROMPT-execute-cutover-phase-5-post-cutover.md — Phase 5, post-cutover. Read
docs/deploy/production-cutover.md Phase 5 in full, AND docs/deploy/queue-worker-cutover.md §10
("Production post-cutover worker flip"), §4 (the day-one queue watch), and blockers B1/B2, IN FULL,
before doing anything. Those two files are the source of truth — do not invent steps beyond them.

## Cutover context (read first)
- Prod Supabase ref is `edplucmvkcnokyygxqsb` — the opposite of dev `glncumufgaqcmqhzwrxm`. Confirm the
  ref before every prod-facing command.
- Prod is now LIVE with real customer data (accounts, sites, media) — nothing in this run is a dry run.
- Per Josh's 2026-07-22 sequencing decision, prod launched on `QUEUE_CONNECTION=sync` (jobs inline, the
  same known-good mode dev ran for months) precisely so the DB re-baseline and the first-ever prod
  Horizon boot wouldn't coincide. This run IS that decoupled worker-flip step, done once, calmly, now
  that prod is stable.
- These steps change prod BEHAVIOR (the worker flip) and prod OPERATIONAL POSTURE (backups, docs) — Josh
  drives every dashboard/env/secret change (Laravel Cloud, Supabase, GitHub Actions secrets); you
  prepare, verify, and confirm. Never edit `.env` directly, never change Supabase project settings or
  GitHub secrets yourself.

## Standing discipline
- NEVER `git stash` / `git checkout <file>` / `git restore` / `git reset`. Forbid `git stash` explicitly
  in every subagent prompt.
- Work on branch `docs/cutover-phase-5-post-cutover` off `origin/development`. The ONLY repo edit in this
  run is Step 4 (CLAUDE.md) plus ticking the three Phase 5 checkboxes in `production-cutover.md`. Commit
  there. DO NOT push — Josh reviews and merges.
- Verify `git rev-parse --abbrev-ref HEAD` + `git diff --cached --stat` before every commit. Trailers:
  Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>
  Claude-Session: <your session url>
- No batching approvals across steps — each prod-behavior change gets its own explicit Josh confirmation
  immediately before it is applied.

## Steps

### Step 1 — Turn workers on (the gated mini-cutover)
This is a SEQUENCE — order matters, each sub-step gates the next. For each: prepare the exact value →
read-only verify current state → present to Josh and get explicit confirmation → Josh applies it in the
Laravel Cloud / Supabase UI → you verify the result before moving on.

1. **Cache/queue DB split.** Confirm `REDIS_CACHE_DB=1` is set on prod (not defaulted to 0). Cache must
   not share DB 0 with the queue/Horizon connection — `Cache::flush()` issues a raw `FLUSHDB` that would
   wipe pending jobs and Horizon state alongside the cache. Propose the value; Josh applies; verify via
   `cloud tinker production` → `config('database.redis.cache.database')` === `1`.
2. **B1 — provision + confirm a RUNNING Horizon worker, and the scheduler.** Hard blocker. Setting
   `QUEUE_CONNECTION=redis` without a running master is strictly worse than sync: jobs enqueue to Redis
   and nobody drains them — a silent, unbounded backlog instead of inline execution. Confirm in the
   Laravel Cloud dashboard (Josh) that a Horizon process is provisioned and running, and that the
   scheduler cron is enabled — not merely that an env var is set. Verify:
   `cloud command:run production --cmd="php artisan horizon:status"` reports running.
3. **B2 — lock down `/horizon` before real jobs flow through it.** Confirm
   `HORIZON_DASHBOARD_USERNAME` / `HORIZON_DASHBOARD_PASSWORD` are set on prod. Once real workers process
   jobs, `/horizon` renders live job payloads — GDPR export IDs, email addresses, connection IDs — beside
   a retry button. The code-level gate (`AppServiceProvider::authorizeHorizonRequest()`, pinned by
   `HorizonDashboardAuthTest`) already seals the dashboard with a 403 on any deployed env missing either
   credential, so this is safe-but-unusable until set, never open. Josh sets both; verify a 401/basic-auth
   prompt at `/horizon` before proceeding.
4. **Flip `QUEUE_CONNECTION=redis`.** Only after 1–3 are confirmed. Josh applies in Laravel Cloud. Verify:
   `cloud tinker production` → `config('queue.default')` === `'redis'`.
5. **Confirm hibernation is OFF.** A hibernated env cannot drain queues, and prod's documented rollback
   path is "hibernate the env" — so this interplay is load-bearing. Confirm with Josh in the Cloud
   dashboard, or verify Cloud refuses to hibernate an env with an active worker.
6. **Confirm the scheduler actually ticked**, not merely that the app booted: hit
   `GET /api/health/scheduler` and look for a real fired task. `builds:prune-expired`,
   `handles:prune-expired-aliases`, `queue:prune-failed`, and `horizon:snapshot` are load-bearing.
7. **Run the §4 day-one watch.** Watch the `analytics`, `images`, and `videos` queues, and specifically
   the four jobs that go from never-touching-a-real-queue to running under Horizon on day one:
   `RecordAnalyticsEventJob`, `ProcessImageVariantsJob`, `ProcessLogoVariantsJob`,
   `ProcessVideoVariantsJob`. Also confirm the `->delay()` staggers that were silently discarded under
   sync (e.g. `RetryUnavailableMenusCommand`'s pacing against Google Places, the only uncapped paid API)
   are now real — no burst on flip. Pull `cloud env:logs partna production --minutes 15` and check
   Nightwatch for job failures or timeout escalations throughout.

   Note: an earlier draft of this runbook assumed a deploy-time KV backfill command
   (`partna:backfill-subdomain-kv`) fires on every deploy and would produce a KV burst right after the
   flip. Re-verified 2026-07-26 and found MOOT — neither env's `deployCommand` runs it; both are a
   commented-out `# php artisan migrate --force`. Nothing to watch for there.

CONFIRM explicitly before 2, 3, and 4 specifically — each is a live behavior change against prod with
real customer data. Josh executes every dashboard action; you verify the result before advancing to the
next sub-step.

### Step 2 — Re-point the off-platform backup
1. Read-only verify today's state: the weekly R2 backup (`partna-db-backup` GitHub Action) targets dev's
   `SUPABASE_DB_URL` secret.
2. Prepare the new value: prod's pooler connection string (session mode, port 5432) for
   `edplucmvkcnokyygxqsb`. Present it to Josh; he updates the `SUPABASE_DB_URL` secret in the
   `partna-db-backup` repo (`gh secret set`, or the GitHub UI — his call, it's a credential).
3. The `--schema` flags in the dump command stay valid unchanged — prod was re-baselined with the same
   standalone migrations as dev.
4. Rename the dump prefix (`partna-dev-` → a live/prod prefix) so weekly R2 objects are unambiguous going
   forward.
5. Re-run the drill-04 restore rehearsal (`docs/runbooks/drills/04-backup-restore.md`) against the new
   target: trigger a manual `workflow_dispatch`, confirm the dump lands in
   `s3://partna-db-backups/weekly/`, decrypt + restore to a scratch/staging target, integrity-check row
   counts, write the drill log.

CONFIRM the `SUPABASE_DB_URL` value with Josh before it is set — it is a credential. Josh applies the
secret; you verify the workflow run and review the drill log.

### Step 3 — Confirm Supabase Pro is on prod
1. Verify the Supabase Pro upgrade landed on the prod project `edplucmvkcnokyygxqsb`. This was supposed to
   happen at Phase 2, before go-live, specifically so managed daily backups and paid-tier limits already
   covered the riskiest window — this step is a confirmation, not the first time it's done.
2. If it is somehow still on Free, STOP and flag to Josh — that is a Phase-2 gap surfacing late, not
   something to quietly patch here.
3. If dev (`glncumufgaqcmqhzwrxm`) no longer needs Pro (the paid tier follows the live data), ask Josh
   whether to downgrade dev.

CONFIRM the downgrade decision explicitly with Josh — do not downgrade dev unilaterally; it may still be
needed for rehearsal/staging work.

### Step 4 — Rewrite CLAUDE.md's stale "Current reality" block
1. Read CLAUDE.md's `## Environments` section — the "Current reality (2026-06-16)" paragraph claiming
   development serves both domains and prod is inactive predates cutover and is now wrong.
2. Rewrite it to state plainly: post-cutover, production serves `api.partna.au` from the prod Supabase
   project (`edplucmvkcnokyygxqsb`); development serves `dev-api.partna.au` from dev Supabase
   (`glncumufgaqcmqhzwrxm`); each is deployed independently — prod via `development → production`
   fast-forward + promotion, not by pushing `development` directly. Update the environment table above it
   if any URL/ref mapping there is now stale or misleading.
3. Also tick the three Phase 5 checkboxes in `production-cutover.md` (backup re-point, Supabase Pro
   confirm, CLAUDE.md rewrite) with a dated one-line note each, pointing back at this prompt.
4. This is the one repo edit in this run. Normal commit discipline applies: verify
   `git rev-parse --abbrev-ref HEAD` and `git diff --cached --stat` before committing; use the trailers
   above; do NOT push without Josh's go-ahead; NEVER `git stash`.

CONFIRM the exact replacement wording with Josh before committing — this file is read at the start of
every future session; get it right once.

## PROD-SAFETY RULES (non-negotiable)
- Prod ref `edplucmvkcnokyygxqsb`, dev ref `glncumufgaqcmqhzwrxm` — never confuse them; confirm the ref
  before every prod-facing command.
- The worker flip (Step 1) is a live behavior change against real customer data. B1 (an actually-running
  Horizon worker, not just the env var) is a hard precondition — verify it BEFORE proposing the
  `QUEUE_CONNECTION=redis` flip. Never propose the flip on the strength of the env var alone.
- Agent prepares, verifies, and confirms; Josh applies every dashboard/env/secret change (Laravel Cloud,
  Supabase, GitHub Actions secrets). Never edit `.env` directly, never touch Supabase project settings,
  never set a GitHub secret value yourself.
- Read-only against git except the single commit in Step 4. NEVER `git stash` / `git checkout <file>` /
  `git restore` / `git reset`.
- No batching approvals — each prod-behavior change (Step 1.2-1.4 especially) gets its own explicit
  confirmation immediately before it is applied.

## Stop and ask Josh if
- B1 is not satisfied (no running Horizon worker confirmed in the Cloud dashboard) — do NOT propose or
  apply the `QUEUE_CONNECTION=redis` flip under any circumstance.
- The §4 day-one watch shows a queue backing up (jobs stuck in `analytics`/`images`/`videos`, Horizon
  masters flapping, `failed_jobs` climbing) — stop the watch, report, do not silently retry or hot-fix.
- The drill-04 restore rehearsal fails (bad dump, failed decrypt, integrity mismatch on restore) — report
  immediately; do not consider the backup re-point done.
- Supabase Pro is not actually on prod (a Phase-2 gap surfacing here).
- Anything requires touching prod beyond what is specified above.

## When done — report
- **Workers:** Horizon masters up, all queues (`analytics`/`images`/`videos` and the rest) draining not
  backing up, `failed_jobs` count sane, scheduler confirmed ticking, hibernation confirmed off.
- **Backup:** `SUPABASE_DB_URL` re-pointed to prod, dump prefix renamed, drill-04 restore rehearsal
  passed with a written log.
- **Supabase Pro:** confirmed on prod (and dev's fate decided, if raised).
- **CLAUDE.md:** Current-reality block rewritten and committed (branch + commit hash, unpushed unless
  Josh said push); `production-cutover.md` Phase 5 checkboxes ticked.
- Cutover complete — or, if anything above did not finish, say so plainly with what is outstanding and
  why. Do not report Phase 5 as done on the strength of Step 1-3 alone if Step 4 (or any sub-step) is
  still open.

=== PROMPT END ===
```
