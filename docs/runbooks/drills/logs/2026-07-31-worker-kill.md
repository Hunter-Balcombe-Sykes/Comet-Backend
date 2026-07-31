# Drill log — 01 Worker kill mid-job

- **Date:** 2026-07-31
- **Runbook:** [../01-worker-kill.md](../01-worker-kill.md) (at commit `152e7923`; repo HEAD `b19ca5d3`)
- **Operator(s):** Claude (Opus 5), driven by Josh
- **Environment:** LOCAL stack — Herd site `partna-drill.test` on worktree
  `backend-launch-check-ops`, local Supabase (`supabase_db_Partna-Development`, 63 migrations
  applied from-zero via `scripts/db/fresh-reset.sh`), local Redis 6379.
- **Mode/variants run:** **Unconfigured-KV mode** (queue-semantics-only) + Scenarios A and B.

## KV mode — why unconfigured, deliberately

The runbook offers "real KV" as the fuller drill. **It must not be used on this project.**
The Cloudflare KV namespace is *shared between prod and dev* (one zone-wide Worker, one
`SUBDOMAIN_KV`), so a real-KV drill writes `drill-wk-*` handle keys into the namespace the
**production** Worker reads from. Unconfigured mode is the only safe option here, not a
shortcut. `services.cloudflare.account_id` verified `NULL` before starting; `CloudflareKvService`
no-ops via `guardUnconfigured`.

Consequence, stated plainly: the job's only external side effect is the KV write, and that
write is a no-op. **DB↔KV divergence itself was therefore not observable in this run.** What
this drill did prove is the queue/crash/retry semantics around it. See Findings.

## Target job facts — re-verified before running

| Runbook claim | Observed | Match |
|---|---|---|
| `$timeout` 30s | `30` | ✅ |
| `$tries` 3 | `3` | ✅ |
| `$backoff` [10,30,60] | `[10,30,60]` | ✅ |
| `$maxExceptions` 2 | `2` | ✅ |
| `ShouldBeUnique`, `$uniqueFor` 45s | implements, `45` | ✅ |
| queue `cloudflare`, redis connection | key `laravel_database_queues:cloudflare` on DB 0 | ✅ |
| `retry_after` 360s default | shrunk to **90s** for the session per runbook preconditions | ✅ |

## Timeline

| Time (UTC) | Phase | Action / observation |
|------|-------|----------------------|
| 04:56 | ARRANGE | Drill user `drill-wk-20260731` created (`47c17217-…`), status `active`. Site had to be provisioned explicitly — see Runbook corrections. Site `019fb688-…`, subdomain `drill-wk-20260731`, published. |
| 04:57 | ARRANGE | Queue drained to clean baseline: ready=0, reserved=0, `queue:failed` empty. |
| 04:58:09 | INJECT A | Horizon started (14 procs). Dispatch, then `horizon:terminate` **167 ms** later. |
| 04:58:09 | OBSERVE A | Job `RUNNING` → `DONE` in **32.09 ms**. Horizon drained and exited cleanly. ready=0, reserved=0, no failed jobs. |
| 04:59:11 | INJECT B | Bare `queue:work redis --queue=cloudflare`; watcher `kill -9`'d it the instant the job left the ready list. |
| 04:59:11 | OBSERVE B | Worker log shows `RUNNING` with **no `DONE`** — kill landed genuinely mid-execution. ready=0, **reserved=1**. |
| 04:59:2x | OBSERVE B | Re-dispatch within 45 s of the kill → **ready stayed 0**: silently dropped by `ShouldBeUnique`, exactly as documented. |
| 05:02–05:07 | OBSERVE B | With **no worker running**, the job stayed `reserved=1` for 178 s — far past `retry_after=90 s`. No self-healing. |
| 05:14:25 | INJECT B′ | Controlled re-run: SIGKILL mid-job again, then a worker started immediately and **left running**. |
| 05:15:57 | RECOVER B′ | Job re-delivered and completed in 41.40 ms. **Time-to-convergence: t+91 s** after kill (`retry_after` 90 s + 1 s poll granularity). |
| 05:16 | RECOVER B′ | Final: ready=0, reserved=0, `queue:failed` empty. |

## Evidence

Scenario A — graceful terminate:

```
A: dispatched at 1785473884.1699
terminate sent at 1785473884.3372421          # +167ms
2026-07-31 04:58:09 App\Jobs\Cloudflare\SyncSubdomainToKvJob ....... RUNNING
2026-07-31 04:58:09 App\Jobs\Cloudflare\SyncSubdomainToKvJob .. 32.09ms DONE
cloudflare llen=0 reserved=0
INFO  No failed jobs found.
```

`ShouldBeUnique` dedupe — three identical dispatches, no worker:

```
dispatch#1 sent / dispatch#2 sent / dispatch#3 sent
--- queue depth after 3 identical dispatches (no worker) ---
llen=1                     # #2 and #3 silently dropped
```

Scenario B — SIGKILL mid-job, and the unique lock surviving it:

```
pre: llen=1 reserved=0
worker pid=46246
SIGKILL sent at iteration 47
post-kill: llen=0 reserved=1
worker alive? NO
--- worker log ---
2026-07-31 04:59:11 App\Jobs\Cloudflare\SyncSubdomainToKvJob ....... RUNNING   # no DONE

re-dispatch attempted within 45s of SIGKILL
llen after re-dispatch = 0   # silently dropped by ShouldBeUnique, as documented
reserved = 1
```

Scenario B′ — convergence with a live worker present throughout:

```
SIGKILL worker#1 (pid 50593) at poll 44
post-kill: ready=0 reserved=1
worker#1 log:  05:14:25 SyncSubdomainToKvJob ....... RUNNING      # no DONE

worker#2 pid=50781   (started immediately, left running)
  t+20s reserved=1 ready=0
  t+40s reserved=1 ready=0
  t+61s reserved=1 ready=0
  t+81s reserved=1 ready=0
  CONVERGED at t+91s after kill

time-to-convergence: 91 (retry_after=90s)
final: ready=0 reserved=0
worker#2 log:  05:15:57 SyncSubdomainToKvJob .. 41.40ms DONE
INFO  No failed jobs found.
```

## Verdict

| Criterion (from runbook) | Result | Notes |
|--------------------------|--------|-------|
| **Pass A** — job completed, or remained queued; failure would be a half-applied write with the job *gone* | **PASS** | Job completed in 32 ms; Horizon drained cleanly; nothing lost. Note the job finished *before* SIGTERM could interrupt it (32 ms job vs 167 ms terminate), so the drain path is proven but the A race window was never genuinely entered. |
| **Pass B** — divergence only between kill and re-delivery; after re-delivery KV == DB, no manual intervention, no failed-jobs entry | **PASS (with a scope caveat)** | Re-delivery and clean completion at t+91 s, no intervention, no failed-jobs entry. The literal "KV == DB" half is **not evidenced** — KV writes were no-ops by design. |
| Job is idempotent — re-running is safe, nothing doubled | **PASS** | Job ran twice against the same user (initial + re-delivery) with no duplicate rows or errors. |
| Unique-lock observation recorded | **PASS** | Re-dispatch inside 45 s dropped silently; confirmed twice. |
| **Fail signal** — job vanished from queue *and* reserved set without effect | **not triggered** in the controlled run | See Finding 3 for one unexplained transient. |

**Overall: PASS** — for queue/crash/retry semantics. **PARTIAL** on the drill's headline
DB↔KV divergence question, which unconfigured-KV mode cannot answer.

## Findings

1. **A crashed job is recovered by a *live worker*, not by elapsed time.** With zero workers on
   the queue the job sat reserved for 178 s (≫ `retry_after` 90 s) and never migrated back.
   Laravel's `migrateExpiredJobs()` runs inside the worker's `pop()` loop; there is no
   background reaper. Operationally: if a deploy kills the last worker on a low-traffic queue
   like `cloudflare`, nothing converges until a worker returns — the outage is unbounded, not
   `retry_after`-bounded. Worth a line in the deploy runbook. Not a code bug.
2. **Post-crash re-dispatch is a silent no-op for 45 s.** `ShouldBeUnique` holds the lock
   through a SIGKILL. An operator reacting to a crash by re-dispatching within 45 s gets
   *no queue entry and no error*. Behaves as documented, but it is a footgun for a human
   incident responder; the recovery guidance should say "wait out `uniqueFor`, or rely on
   re-delivery" rather than "re-dispatch".
3. **One unexplained transient.** Between t+178 s and a later check, a reserved entry left the
   reserved set with no worker running and no failed-jobs entry, with `ready` also 0. No
   `queue:work`/`horizon` process was present (`ps` verified). Not reproduced in the controlled
   B′ run. Recorded rather than dismissed; if it recurs it is the runbook's stated FAIL signal.
   **Not fixed, not explained.**
4. **Local Redis collapses cache and queue onto DB 0.** Local `.env` sets `REDIS_DB=0` *and*
   `REDIS_CACHE_DB=0`, so a local `Cache::flush()` (`FLUSHDB`) would wipe queued jobs and
   Horizon state — the exact hazard CLAUDE.md documents for the deployed envs, present locally.
   Deployed envs are correctly split (queue=0, cache=1, sessions=2, locks=4). Local-only;
   flagged, not fixed.
5. **DB↔KV divergence remains unmeasured on this project** and cannot be measured by this drill
   as written, because the only safe KV mode is the one that disables the writes. Answering it
   needs a *separate dev-only KV namespace*, which does not exist today (prod and dev share one).
   That is the real prerequisite, and it is a platform change, not a drill change.

## Runbook corrections

Applied to `../01-worker-kill.md` in the same commit as this log:

1. **ARRANGE is wrong about site creation.** The runbook says "the site-creation
   trigger/observer normally handles this — verify `$u->site` is non-null". There is **no such
   trigger and no such observer**: `pg_trigger` on `core.users` holds only
   `set_timestamp_users` and the two handle-alias triggers, and `Site::create` appears nowhere
   in `app/`. Sites are provisioned application-side by
   `SiteProvisioningService::createSiteWithRetry`, called from `UserBootstrapService` and
   `PreAccountBuildService`. A factory-made user has **no site**. Corrected with the explicit
   provisioning snippet.
2. **ARRANGE omits the `auth.users` FK.** `core.users.auth_user_id` has a real FK to Supabase's
   `auth.users`; `User::factory()->create()` alone fails with SQLSTATE 23503 on a real Postgres
   local stack. (The SQLite test suite never sees this.) Corrected with the prerequisite insert.
3. **Scenario B step 5 prescribes an impossible ordering** — "watch `zrange … :reserved` empty
   back into the main list first", *then* start a fresh worker. The reserved set cannot drain
   without a worker (Finding 1). Corrected to: start the worker first, then watch convergence.
4. **Add the KV-mode warning.** The runbook presents real-KV as simply the better option. It
   must state that prod and dev share one KV namespace, so real-KV mode writes into
   production's namespace and is forbidden here.

## Next run due

On material change to job/queue plumbing, `SyncSubdomainToKvJob`, media jobs, or Horizon config.
Re-run in **real-KV mode** if and when a dev-only KV namespace exists (Finding 5) — only then
does the drill's headline question become answerable.
