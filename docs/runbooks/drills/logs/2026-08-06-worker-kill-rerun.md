# Drill log — 01 Worker kill (RE-RUN)

- **Date:** 2026-08-06 (AEST), same day as the earlier run
- **Runbook:** [../01-worker-kill.md](../01-worker-kill.md)
- **Purpose:** re-run on Josh's request after drill 03 was re-drilled, to see whether the DB↔KV
  PARTIAL still reproduces.
- **Operator:** Claude (Opus 5), driven by Josh
- **Code under test:** `development` @ `5bf6fe448`
- **Environment:** LOCAL — worktree `.claude/worktrees/drill-wk-2026-08-06`, local Supabase
  (:54322), Homebrew Redis :6379, `REDIS_QUEUE_RETRY_AFTER=90`.
- **KV mode:** **unconfigured** — `services.cloudflare.{account_id,kv_namespace_id,api_token}` all
  empty, asserted before starting.

## The PARTIAL cannot be cleared by re-running — stated up front

Real-KV mode is **forbidden on this project** and that has not changed: prod and dev share one
`SUBDOMAIN_KV` namespace behind a single zone-wide Worker, so drill keys would be written into the
namespace **production reads from**. Unconfigured mode is the only permitted mode, and in it
`CloudflareKvService` no-ops, so every KV assertion is vacuous by construction.

**The DB↔KV divergence question is therefore still unanswered, for the fourth run running.** It is
blocked on infrastructure, not on code, and no amount of re-running moves it. The only thing that
does is a separate dev `SUBDOMAIN_KV` namespace. Verdict stays **PASS / PARTIAL**, unchanged.

What this run *does* re-verify is the queue/crash/retry semantics against current `development`,
and it turned up a new runbook defect (finding 1) worth the time on its own.

## Preconditions — verified

| Precondition | Value |
|---|---|
| `APP_ENV` | **`local`** — asserted, NOT `staging`. This is the trap that broke the earlier run: under `staging` `guardUnconfigured()` throws and every job fails, which reads exactly like a Pass B failure. |
| `queue.default` | `redis` |
| `retry_after` | **90s** (shortened for the session, reverted with the worktree) |
| CF credentials | all three **empty** ⇒ unconfigured mode |
| Horizon before Scenario A | master 1, supervisors 5 |

Drill users: `drill-wk-0806r-1` … `-6`, each with an explicitly provisioned published site
(nothing auto-creates one), plus one `core.user_handle_aliases` row on user 1 so `bulkPut` has work.

## Scenario A — graceful SIGTERM under sustained dispatch

Sustained dispatch for 8s with the signal at the midpoint, per the runbook — a one-shot batch
cannot enter the race (pickup latency ~3s against a ~5ms job).

```
master pid = 1735
SIGTERM sent to master at t+4051.7ms
dispatched = 834 over 8.02s
```

| Observation | Result |
|---|---|
| `RUNNING` vs `DONE` vs `FAIL` | **15 / 15 / 0 — exact pairing** |
| `DONE`s continuing after the signal | yes — last at 11:35:27, after the 11:35:22 signal |
| Horizon master after SIGTERM | exited gracefully (master 0, supervisors 0) |
| Jobs still on the ready list | **6 — queued for the next worker, not vanished** |
| `queue:failed` | empty |
| 834 dispatches → 15 executions | `ShouldBeUniqueUntilProcessing` collapsing as designed |

**Pass A: PASS.** Every job either completed or remained queued; nothing was lost with a
half-applied write.

## Scenario B — SIGKILL mid-job

Bare single worker (Horizon blurs PID targeting), tight-loop phpredis watcher.

```
killed worker 3623 at +0.2ms after reservation
KILL_T=11:40:29
reserved after kill: 1
```

**Divergence window:** atomic sample `ready=0, reserved=1` — the job sits in the reserved set.
Unique lock present on **Redis DB 4** with **TTL 32s**.

**Silent-drop reproduced.** The kill landed 0.2ms after reservation — the pre-handler window where
`ShouldBeUniqueUntilProcessing`'s lock is still held. A re-dispatch inside `uniqueFor` returned
**no error and produced no queue entry** (`ready` stayed 0). This is the documented incident-response
hazard: after a crash in that window, a human re-dispatching within 45s gets silence.

**Convergence:** worker started in the foreground; `SyncSubdomainToKvJob` re-delivered and `DONE`
at **11:41:59** — exactly **90s** after the 11:40:29 kill, matching `retry_after`. Afterwards
`ready=0, reserved=0`, `queue:failed` empty, no manual intervention.

**Pass B: PASS**, with the same scope caveat as every previous run — the literal "KV == DB" half
stays unevidenced because KV writes are no-ops in the only permitted mode.

## Verdict

**Overall: PASS** for queue/crash/retry semantics. **PARTIAL** on the headline DB↔KV divergence
question — unchanged, and unchangeable until the namespaces are split.

## Findings

1. **🟡 P2 (drill tooling) — the runbook's own atomic-sample command reads the WRONG Redis key on
   this project, and produces the exact false P1 it was written to prevent.**
   `01-worker-kill.md` hardcodes `laravel_database_queues:cloudflare` in the recommended
   `redis-cli eval` snippet. This project's prefix is **`partna_database_`**, so that key does not
   exist and both counters return 0.

   **Failure scenario, and it is not hypothetical — this run walked into it.** After Scenario A's
   SIGTERM I sampled with the runbook's key and read `ready=0, reserved=0`. The runbook calls that
   reading "job vanished from both the queue and the reserved set", its **most serious FAIL
   signal**. The correct key showed `ready=6, reserved=0` — six jobs properly held for the next
   worker, i.e. a clean Pass A. A one-character-prefix mismatch inverts a PASS into a P1.

   The irony is sharp: that snippet was added *because* a previous run produced a false P1 from
   non-atomic sampling. It fixed the atomicity and hardcoded an unreachable key.

   Same defect in the unique-lock path: the runbook says
   `laravel_database_laravel-cache-laravel_unique_job:…`; the real key is
   `partna_database_partna-cache-laravel_unique_job:…`. Scanning the documented key makes the lock
   look absent and the silent-drop behaviour inexplicable.

   **Fix:** derive the key rather than hardcode it —
   `redis-cli -n 0 --scan --pattern '*queues:cloudflare'` — which the runbook already tells you to
   do one step earlier, then contradicts itself by pasting a literal key into the next snippet.

2. **⚪ Still unanswered — DB↔KV divergence**, fourth run running, same blocker: prod and dev share
   one `SUBDOMAIN_KV`. This remains the single thing preventing drill 01 from being complete, and
   it is an infrastructure decision, not a drilling problem.

3. **🟢 The `APP_ENV=staging` trap did not recur.** Asserted `local` before Scenario B, per the
   correction added after the earlier run. Zero failed jobs across both scenarios.

## RESTORE

Six drill users `forceDelete`d (exercising the retire path), alias row deleted, six `auth.users`
rows removed, queue drained, Horizon and all workers stopped, worktree and branch removed,
`REDIS_QUEUE_RETRY_AFTER=90` discarded with the worktree's `.env`. Redis healthy.
