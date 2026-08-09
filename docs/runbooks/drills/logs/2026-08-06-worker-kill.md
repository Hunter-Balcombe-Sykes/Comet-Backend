# Drill log — 01 Worker kill mid-job

- **Date:** 2026-08-06 (AEST)
- **Runbook:** [../01-worker-kill.md](../01-worker-kill.md)
- **Operator:** Claude (Opus 5), driven by Josh
- **Code under test:** `drills/rerun-2026-08-06` @ `de8fcff7`
- **Environment:** LOCAL — worktree `backend-wt/drills-rerun-2026-08-06`, local Supabase stack,
  Homebrew Redis 6379, Horizon 5 supervisors / bare `queue:work` as each scenario requires.
- **KV mode:** **unconfigured** (`services.cloudflare.{account_id,kv_namespace_id,api_token}` all
  empty, asserted). Real KV remains forbidden — prod and dev share one `SUBDOMAIN_KV` namespace
  behind a zone-wide Worker, so drill writes would land in the namespace production reads from.
- **Variants run:** A (graceful SIGTERM, two attempts), A′ (sustained load), B (SIGKILL mid-job,
  final clean run instrumented in the foreground).

## Preconditions — verified, not assumed

| Precondition | Verified value |
|---|---|
| `QUEUE_CONNECTION` | `redis` |
| Queue connection / DB | `default` → Redis **DB 0** (not DB 3, despite the `queue` connection name) |
| `queue.connections.redis.retry_after` | **90** (shrunk from 360 via `REDIS_QUEUE_RETRY_AFTER` for the session; reverted in RESTORE) |
| `queue.connections.redis.block_for` | 5 |
| `SyncSubdomainToKvJob` | `$timeout` 30, `$uniqueFor` 45, queue `cloudflare` — unchanged from the runbook's stated facts |
| CF credentials | all three **empty** ⇒ unconfigured mode |
| `APP_ENV` | **`local`** for all KV-touching runs — see finding 1, this is not optional |
| Drill users | 6 (`drill-rd-20260806`, `drill-wk-20260806-2..6`), each with a provisioned published site; 1 handle alias on the first |

## Scenario A — graceful terminate (deploy semantics)

**Attempt 1 — batch of 6, signal immediately.** `posix_kill(master, SIGTERM)` landed **7.5 ms**
after the first dispatch (vs 12.2 ms in the 2026-08-05 run, and 167 ms for `horizon:terminate`).

Result: master and all supervisors exited cleanly; **all 6 jobs remained on the ready list**, 0 in
`reserved`, no failures. On restarting Horizon the queue drained to 0 — the jobs were picked up and
completed by the next worker.

That satisfies Pass A, but it does **not** exercise the interesting half (an in-flight job finishing
after the signal), because the signal beat the workers to the job. A second attempt at 39.3 ms
behaved identically. Cause, measured: the supervisor covers **10 queues**, so the worker cannot
block on `cloudflare` alone and pickup latency is up to ~3 s — orders of magnitude longer than any
achievable signal delay against a ~5 ms job. **The batch-of-6 recipe cannot enter this race.**

**Attempt A′ — sustained load** (the realistic deploy shape: a busy queue at deploy time).
888 dispatches over 8.04 s, SIGTERM at **t+4.05 s** after 450 dispatches.

| Observation | Value |
|---|---|
| Jobs that actually entered the queue | **12** (888 dispatches collapsed by `ShouldBeUniqueUntilProcessing`) |
| `RUNNING` lines | 12 |
| `DONE` lines | **12** |
| `FAIL` lines | **0** |
| Completions after the signal | yes — last `DONE` at 23:28:47, ~3 s after the SIGTERM |
| Left queued for the next worker | 6 |
| Stranded in `reserved` | **0** |
| New failed-jobs entries | **0** |

Every job that started, finished — including after the signal — and nothing was lost.

**Pass A: PASS.**

The 888→12 collapse is itself worth recording: the unique lock is doing very heavy lifting, and a
naive "dispatch per event" caller cannot flood this queue.

## Scenario B — SIGKILL mid-job (crash semantics)

Bare `queue:work redis --queue=cloudflare` (Horizon stopped so PID targeting is deterministic),
killed by the phpredis tight-loop watcher.

| Step | Measurement |
|---|---|
| Enqueue seen by watcher | t+1128 ms |
| Job reserved (ready list → 0) | t+4090 ms |
| **SIGKILL sent after reservation** | **0.009 ms** |
| Worker | dead |
| State immediately after | `ready=0`, **`reserved=1`** |
| Handler | **never entered** (no KV breadcrumb, no log line) |

**Divergence window.** With no worker running, `reserved=1` persisted indefinitely — sampled at
+10 s, +20 s, +30 s, unchanged. This re-confirms the runbook's precondition: `migrateExpiredJobs()`
runs inside the worker's `pop()` loop and there is **no background reaper**; elapsed time alone does
nothing.

**Unique-lock behaviour.** A re-dispatch issued while the lock was still held returned **no error**
and produced **no queue entry** (`ready` stayed 0) — the documented incident-response trap
reproduced exactly. The kill landed between reservation and handler entry, which is precisely the
window where `ShouldBeUniqueUntilProcessing`'s lock is still held. The lock lives on the
**`cache_locks` connection, Redis DB 4** (`laravel_database_laravel-cache-laravel_unique_job:…`),
not DB 0 or DB 1 — worth knowing, because scanning the wrong DB makes it look absent.

**Convergence** (worker in the foreground so its output is actually observable):

| Event | Time |
|---|---|
| Job reserved / worker killed | 09:38:28 |
| Worker started | 09:38:34 |
| **Job re-delivered — `RUNNING`** | **09:39:55** |
| `DONE` | 29.30 ms later |

Re-delivery at **≈87 s** from reservation against `retry_after=90` — matching, allowing for poll
granularity. Handler execution confirmed independently by the KV breadcrumb count rising 29 → 30.
Final state `ready=0 reserved=0`, **`queue:failed` empty**.

**Pass B: PASS**, with the standing scope caveat — "KV == DB" cannot be evidenced while KV writes
are no-ops by design.

**Idempotency:** the job ran repeatedly for the same users across A, A′ and B. DB state unchanged
throughout — 6 drill users, 6 sites, 1 alias. Nothing doubled.

## Verdict

| Criterion (from runbook) | Result | Notes |
|---|---|---|
| **Pass A** — every job completed or remained queued; never gone with a half-applied write | **PASS** | 12/12 DONE, 0 FAIL, 6 left queued, 0 stranded. Race genuinely entered under sustained load (SIGTERM at t+4.05 s with jobs in flight). |
| **Pass B** — divergence only between kill and re-delivery; after re-delivery no intervention, no failed-jobs entry | **PASS (same scope caveat)** | Re-delivery at ~87 s, hands-off, clean. The literal "KV == DB" half stays unevidenced — KV writes are no-ops by design. |
| Job is idempotent — re-running safe, nothing doubled | **PASS** | Ran many times per user across A/A′/B; 6 users, 6 sites, 1 alias, unchanged. |
| Unique-lock observation recorded | **PASS** | Re-dispatch inside the held-lock window dropped silently, no error. |

**Overall: PASS** for queue/crash/retry semantics. **PARTIAL** on the headline DB↔KV divergence
question, unchanged from 2026-07-31 and 2026-08-05 and blocked on the same thing: there is still no
dev-only KV namespace.

## Findings

1. **🔴 P2 — drill 01's "unconfigured KV" mode is incompatible with `APP_ENV=staging`, and the
   failure looks exactly like a Pass B failure.** `CloudflareKvService::guardUnconfigured()`
   deliberately **throws** under `production`/`staging` ("Refusing to silently no-op put in
   staging") and only logs-and-no-ops under `local`/`dev`/`test`. **Failure scenario:** the
   execute-prompt mandates `APP_ENV=staging` for drill 03; an operator runs 03 then 01 back-to-back
   in one session, starts a worker under the inherited `staging`, and every
   `SyncSubdomainToKvJob` dies with a `RuntimeException` and writes a failed-jobs entry. The
   runbook lists exactly that as a **FAIL signal** — "failed-jobs entry with exhausted retries on a
   healthy network" — so the drill reports a P1 queue-integrity failure against a system that is
   behaving as designed. This session produced that false FAIL (3 attempts, terminal, one
   `failed_jobs` row) before the cause was traced. Compounding it: Scenario A *passed* in the same
   session because Horizon had been started under `APP_ENV=local` per drill 03's ordering trap, so
   two workers in one drill disagreed about whether the same job succeeds. **Fixed here** — runbook
   now states the mode requires `local`/`dev`/`test` and says why.

2. **🟡 P3 — the Scenario A recipe cannot enter its own race window.** A batch of one job per drill
   user against a ~5 ms job cannot be raced by any achievable signal delay, because the supervisor
   covers 10 queues and so cannot block on `cloudflare` alone — measured pickup latency ~3 s, versus
   a 7.5 ms signal. **Failure scenario:** the operator signals promptly, sees "all jobs remained
   queued", records Pass A, and never observes the behaviour the scenario exists to test (does an
   in-flight job finish?). The 2026-08-05 run caught one in-flight job by luck. **Fixed here** —
   runbook now prescribes sustained dispatch for ~4 s before signalling, which reliably puts jobs in
   flight.

3. **🟡 P3 (methodology) — reading `ready` and `reserved` as two separate `redis-cli` calls can show
   both empty while a job is in flight between them.** Combined with killing the worker immediately
   after, this session briefly produced what looked like a job vanishing from both the queue and the
   reserved set — the runbook's most serious FAIL signal. It did not reproduce under foreground
   instrumentation. **Failure scenario:** a P1 data-loss finding filed off a two-call sample race.
   Also note `queue:work` prints nothing until a job *completes*, so an empty worker log is not
   evidence that nothing ran. **Fixed here** — runbook now says to sample atomically and to observe
   convergence with the worker in the foreground.

4. **🟢 Confirmed — no background reaper.** `reserved=1` held steady at +10/+20/+30 s with no worker.
   Convergence required starting one, then took ~87 s against `retry_after=90`.

5. **🟢 Confirmed — the unique lock silently swallows a re-dispatch after a pre-handler crash**, with
   no error to the caller. Lock lives on `cache_locks` / **Redis DB 4**.

6. **⚪ Still unanswered — DB↔KV divergence.** Blocked on the shared `SUBDOMAIN_KV` namespace, as in
   both prior runs. This will stay unanswered until a dev-only namespace exists; it is the single
   thing preventing this drill from being complete.

## Runbook corrections

Applied in this branch — see findings 1, 2 and 3 for the reasoning:

1. Unconfigured-KV mode requires `APP_ENV` in `local`/`dev`/`test`; under `staging`/`production` the
   guard throws by design and manufactures a false Pass B failure.
2. Scenario A needs sustained dispatch (~4 s) before the signal; a single batch cannot enter the race.
3. Sample `ready`/`reserved` atomically; observe convergence with the worker in the foreground;
   an empty `queue:work` log is not evidence that nothing ran.
4. The `ShouldBeUniqueUntilProcessing` lock lives on `cache_locks` (**DB 4**), not the queue DB.

## Next run due

On material change to job/queue plumbing, `SyncSubdomainToKvJob`, media jobs, or Horizon config.
**Re-run in real-KV mode as soon as a dev-only `SUBDOMAIN_KV` namespace exists** — that is the only
way finding 6 gets closed.
