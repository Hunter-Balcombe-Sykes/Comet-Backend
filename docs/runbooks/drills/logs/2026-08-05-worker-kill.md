# Drill log — 01 Worker kill mid-job

- **Date:** 2026-08-05 (AEST; all times below UTC)
- **Runbook:** [../01-worker-kill.md](../01-worker-kill.md) (at commit `d6caef96`; repo HEAD `d6caef96`)
- **Operator(s):** Claude (Opus 5), driven by Josh
- **Environment:** LOCAL stack — Herd site `partna-drill.test` on worktree
  `backend-wt/drills-2026-08-05`, local Supabase (`supabase_db_Partna-Development`,
  **67 migrations** applied from-zero via `scripts/db/fresh-reset.sh`), local Redis 6379 with
  the **deployed** keyspace split (queue=0, cache=1, sessions=2, locks=4).
- **Mode/variants run:** **Unconfigured-KV mode** (queue-semantics-only) + Scenarios A, B and B′.

## KV mode — still unconfigured, still deliberately

Unchanged from 2026-07-31: prod and dev share one `SUBDOMAIN_KV` behind a single zone-wide
Worker, so real-KV mode would write `drill-wk-*` keys into the namespace **production** reads
from. `services.cloudflare.account_id` verified `NULL` before starting; `CloudflareKvService`
no-ops via `guardUnconfigured`. **DB↔KV divergence is therefore still not observable** — that
remains blocked on a dev-only KV namespace (2026-07-31 Finding 5), which is a platform change,
not a drill change.

## Target job facts — re-verified before running

| Runbook claim | Observed | Match |
|---|---|---|
| `$timeout` 30 s | `30` | ✅ |
| `$tries` 3 | `3` | ✅ |
| `$backoff` [10,30,60] | `[10,30,60]` | ✅ |
| `$maxExceptions` 2 | `2` | ✅ |
| `ShouldBeUnique`, `$uniqueFor` 45 s | implements **`ShouldBeUniqueUntilProcessing`**, `45` | ⚠️ runbook names the wrong interface — see Finding 2 |
| queue `cloudflare`, redis connection | key `laravel_database_queues:cloudflare` on DB 0 | ✅ |
| `retry_after` 360 s default | shrunk to **90 s** for the session per runbook preconditions | ✅ |

## Timeline

| Time (UTC) | Phase | Action / observation |
|------------|-------|----------------------|
| 15:18 | ARRANGE | Drill user `drill-wk-20260805` (`13815f08-…`) + site `019fcd5b-…` (published) + one handle alias. Five more drill users (`-2`…`-6`) created so Scenario A has a real batch to race against. |
| 15:20:00 | ARRANGE | Horizon started (5 supervisors). Provisioning backlog drained; queue baselined to ready=0 reserved=0, `queue:failed` empty. |
| 15:20:40 | INJECT A | 6 jobs dispatched in 12.2 ms, then `SIGTERM` to the Horizon master **12.2 ms after the first dispatch**. |
| 15:20:40 | OBSERVE A | One job `RUNNING → DONE` (13.82 ms) *after* the SIGTERM was already in flight; the other 5 stayed on the ready list. Horizon exited **cleanly, exit 0**, 0 processes left. `queue:failed` empty. |
| 15:21:13 | RESET | Backlog drained with `queue:work --stop-when-empty`; ready=0 reserved=0. |
| 15:22:03 | INJECT B | Bare `queue:work redis --queue=cloudflare` (pid 26240); tight-loop watcher `kill -9`'d it **1 poll** after the job left the ready list. post-kill: ready=0 **reserved=1**, worker dead. |
| 15:22:04 | OBSERVE B | Re-dispatch **within 45 s** of the kill → ready stayed **0**. Silently dropped by `ShouldBeUnique`, exactly as documented. |
| 15:22–15:24 | OBSERVE B | With **zero workers**, reserved stayed **1 for 145 s** — 55 s past `retry_after`=90 s. No self-healing. |
| 15:24:22 | OBSERVE B | A worker started at t+145 s → **converged in 1 s**. Recovery is *worker*-triggered, not time-triggered. |
| 15:24:43 | INJECT B′ | Controlled re-run: SIGKILL mid-job (pid 27869), worker #2 started immediately and left running. |
| 15:26:20 | RECOVER B′ | Re-delivered and completed in 10.98 ms. **Time-to-convergence: t+97 s** (`retry_after` 90 s + poll granularity). |
| 15:26 | RECOVER B′ | Final: ready=0, reserved=0, `queue:failed` empty, no duplicated rows. |

## Evidence

Scenario A — SIGTERM landing *inside* the execution window (this is what 2026-07-31 could not
achieve; it terminated 167 ms after a 32 ms job, so the drain path was never actually raced):

```
master_pid=24750 users=6
dispatched 6 jobs in 12.2 ms; SIGTERM sent 12.2 ms after first dispatch
t+1s  ready=5 reserved=0 horizon_procs=0
...
t+15s ready=5 reserved=0 horizon_procs=0

horizon log tail:
  2026-08-04 15:20:40 App\Jobs\Cloudflare\SyncSubdomainToKvJob ....... RUNNING
  2026-08-04 15:20:40 App\Jobs\Cloudflare\SyncSubdomainToKvJob .. 13.82ms DONE
INFO  No failed jobs found.
```

One job in flight completed; five remained queued; nothing was reserved, lost, or failed.

Scenario B — SIGKILL mid-job, and the unique lock surviving it:

```
worker pid=26240 alive=YES
watcher: job enqueued
watcher: SIGKILL -> pid 26240 after 1 polls
post-kill: ready=0 reserved=1 worker_alive=NO

re-dispatch attempted at 15:22:04 (within 45s of the kill)
after re-dispatch: ready=0 reserved=1      # dropped: ShouldBeUniqueUntilProcessing lock still
                                           # held, because the kill beat handler entry (see F2)
```

The stuck job, with no worker anywhere:

```
t+ 10s  ready=0 reserved=1 workers=0
t+ 50s  ready=0 reserved=1 workers=0
t+ 90s  ready=0 reserved=1 workers=0      # retry_after already elapsed
t+110s  ready=0 reserved=1 workers=0
t+130s  ready=0 reserved=1 workers=0

before worker start: ready=0 reserved=1   # ~t+145s
CONVERGED 1s after worker start
final: ready=0 reserved=0
  2026-08-04 15:24:22 App\Jobs\Cloudflare\SyncSubdomainToKvJob ... 4.24ms DONE
```

Scenario B′ — convergence with a worker present throughout:

```
watcher: SIGKILL -> pid 27869 after 2 polls
worker#2 started immediately (pid 27998), left running
  t+19s reserved=1
  t+40s reserved=1
  t+60s reserved=1
  t+80s reserved=1
CONVERGED at t+97s after kill
final: ready=0 reserved=0
  2026-08-04 15:26:20 App\Jobs\Cloudflare\SyncSubdomainToKvJob .. 10.98ms DONE
INFO  No failed jobs found.
```

Idempotency after the job ran twice against the same user:

```
sites_for_user=1   aliases_for_user=1   drill_users=6
INFO  No failed jobs found.
```

## Verdict

| Criterion (from runbook) | Result | Notes |
|--------------------------|--------|-------|
| **Pass A** — job completed, or remained queued; failure would be a half-applied write with the job *gone* | **PASS** | And this time genuinely raced: SIGTERM at t+12 ms, one job finished *after* the signal, five stayed queued. The 2026-07-31 caveat ("the A race window was never entered") is now closed. |
| **Pass B** — divergence only between kill and re-delivery; after re-delivery KV == DB, no intervention, no failed-jobs entry | **PASS (same scope caveat)** | Re-delivery at t+97 s, clean, hands-off, no failed job. The literal "KV == DB" half stays unevidenced — KV writes are no-ops by design. |
| Job is idempotent — re-running is safe, nothing doubled | **PASS** | Ran twice per user across A/B/B′; 1 site, 1 alias, 6 users. |
| Unique-lock observation recorded | **PASS** | Re-dispatch inside 45 s dropped silently. |
| **Fail signal** — job vanished from queue *and* reserved set without effect | **not triggered** | Every reserved entry was accounted for. The 2026-07-31 unexplained transient (its Finding 3) **did not recur** across three kill cycles. |

**Overall: PASS** for queue/crash/retry semantics. **PARTIAL** on the headline DB↔KV divergence
question, unchanged and unanswerable in this mode.

## Findings

1. **(Re-confirmed, now with sharper evidence) A crashed job is recovered by a *live worker*,
   not by elapsed time.** The job sat reserved for **145 s** against `retry_after`=90 s with
   zero workers, then converged in **1 second** once a worker appeared.
   `migrateExpiredJobs()` runs inside the worker's `pop()` loop; there is no background reaper.
   Operationally: if a deploy kills the last worker on a low-traffic queue like `cloudflare`,
   nothing converges until a worker returns — the outage is unbounded, *not* `retry_after`-bounded.
   This was 2026-07-31's Finding 1 and it is still not written down anywhere an operator would
   look. **Fixed in this branch** — added to `docs/deploy/routine-deploy.md` and
   `docs/runbooks/queue-backed-up.md`.
2. **Post-crash re-dispatch is a silent no-op — but only in a narrower window than the runbook
   claims.** The runbook (and this log's first draft) says `ShouldBeUnique` holds the lock through
   a SIGKILL. The job actually implements **`ShouldBeUniqueUntilProcessing`**
   (`SyncSubdomainToKvJob.php:45`, with a comment at :37-44 explaining why), and Laravel releases
   that lock **when the handler starts**, not when it finishes
   (`Illuminate/Queue/CallQueuedHandler.php`). So the correct rule is:

   - SIGKILL landing **between reservation and handler entry** → lock still held → a re-dispatch
     inside `uniqueFor` is silently dropped. **This is what was observed here**, and the worker
     log corroborates it: it printed **no `RUNNING` line at all** before dying, i.e. the kill beat
     `JobProcessing`. The tight-loop watcher fired after 1 poll, so that is expected.
   - Crash **mid-`handle()`** — OOM, a deploy kill on a slow job, the likelier real incident →
     the lock was already released → a re-dispatch **does** queue.

   The operator advice ("wait out `uniqueFor`, or rely on re-delivery") is safe either way, but an
   operator applying the runbook's blanket version would wrongly conclude a re-dispatch had failed
   when it actually queued. **Fixed in this branch** — corrected in both `01-worker-kill.md` and
   `queue-backed-up.md`, and the guidance now lives in the queue runbook rather than only in a
   drill runbook nobody reads at 3 a.m.
3. **2026-07-31's Finding 3 (unexplained transient) did not reproduce.** Three kill cycles,
   every reserved entry accounted for at every observation. Recording the non-reproduction so
   the open question does not stay open on one unrepeated observation. Still not *explained* —
   but no longer supported by evidence.
4. **2026-07-31's Finding 4 (local Redis collapsing cache and queue onto DB 0) is real and
   still present in the main checkout's `.env`** (`REDIS_DB=0` **and** `REDIS_CACHE_DB=0`),
   which means a local `Cache::flush()` issues `FLUSHDB` against the queue+Horizon database.
   This drill ran with the deployed split (0/1/2/4) precisely so the results would be
   transferable. **Nothing to commit:** `.env.example` already carries the correct split
   (`REDIS_DB=0`, `REDIS_CACHE_DB=1`, `REDIS_SESSION_DB=2`, `REDIS_QUEUE_DB=3`,
   `REDIS_CACHE_LOCKS_DB=4`, lines 112–120) with the explanatory comment. The drift is in the
   untracked local `.env` only, which per CLAUDE.md is Josh's to change, not this branch's.
5. **DB↔KV divergence remains unmeasured** and cannot be measured until a dev-only KV namespace
   exists. Unchanged from 2026-07-31 Finding 5. Not actionable inside a drill.

## Runbook corrections

Applied to `../01-worker-kill.md` in the same commit as this log:

1. **Scenario A as written cannot enter its own race window.** A single dispatch of a ~10 ms
   job is over before any terminate command can boot. Added the technique that worked: dispatch
   a *batch* (one job per drill user, so `ShouldBeUnique` doesn't dedupe them) and signal the
   master directly with `posix_kill($master, SIGTERM)` rather than shelling out to
   `horizon:terminate` — 12 ms instead of 167 ms.
2. **The `kill -9` watcher's `sleep 0.02` polling is too coarse** for a job this fast; each
   `redis-cli` spawn alone costs more than the job's runtime. Replaced with the raw-phpredis
   tight-loop watcher used here (`~50 µs` per `LLEN`), which landed the kill in 1–2 polls
   both times.
3. **Finding 1 deserves a preconditions warning, not just a step-5 note.** Moved the "a
   reserved job needs a live worker, not elapsed time" fact up so the operator reads it before
   spending 3 minutes watching a set that will never drain on its own.

## Next run due

On material change to job/queue plumbing, `SyncSubdomainToKvJob`, media jobs, or Horizon config.
Re-run in **real-KV mode** if and when a dev-only KV namespace exists (Finding 5).
