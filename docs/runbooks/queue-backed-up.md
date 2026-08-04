# Queue Backed Up — Operator Runbook

## Scope

Horizon-managed Redis queues (`config/queue.php` `default` connection = `redis`) across the
five supervisor lanes defined in `config/horizon.php:210-333`. Both `development` and
`production` run a `flex-2gb` Worker cluster (min=max=1 replica) executing `php artisan
horizon`. **`production` is currently `stopped`** (checked 2026-07-30 via
`cloud environment:list partna`) — prod probes in this runbook will fail with a connection
error until someone starts it; that's expected, not a symptom of this incident.

**Two files still describe the pre-Horizon world and are wrong about current topology — don't
copy them, don't "fix" them (out of scope here), just don't propagate the error they contain:**
`docs/deploy/queue-worker-cutover.md:9` and `docs/runbooks/drills/README.md:38-39` both still
say the deployed `development` env runs `QUEUE_CONNECTION=sync` with zero Horizon masters. It
doesn't — Horizon is live, this is a real Horizon-backed queue, and this runbook exists
because of that.

## Symptoms — what Josh sees first

- Jobs visibly piling up — Horizon dashboard queue depth climbing, or a user-facing effect
  (KV sync stalls so a subdomain doesn't route, a scrape or media job never completes, mail
  doesn't go out).
- The **core diagnostic insight, lead with this**: every supervisor except `supervisor-ingest`
  runs `'balance' => false` (`config/horizon.php:210-333`). `balance=>false` means one
  `queue:work` process drains its comma-joined queue list in **strict listed priority order**
  — a lower-priority queue is only ever touched once every higher-priority queue is fully
  drained. On `supervisor-1`'s ten-queue list, that means `platform_refresh`,
  `platform_connect`, and `cloudflare_bulk` (last on the list) are the **first queues to
  starve** under any sustained load on `moderation_high`/`default`/`cloudflare` ahead of them
  — even though nothing is "broken." Check which queue is actually backed up before assuming
  a systemic failure; a starved tail queue with a healthy head is often working as designed
  and the fix is prioritization, not a restart.

## Confirm

**Which lane, and is it even running:**

```bash
cloud command:run development --cmd="php artisan horizon:status"
cloud command:run development --cmd="php artisan horizon:list"
cloud command:run development --cmd="php artisan horizon:supervisors"
```

Argument order matters — environment name is positional, `--cmd=` is the flag
(`cloud command:run --help` confirms `command:run [options] [--] [<environment>]` with a
`--cmd[=CMD]` option). **`docs/deploy/routine-deploy.md:108` shows the command with the
argument as a second bare positional value, not via `--cmd=`** — that line predates or
otherwise doesn't match the CLI's actual argument parsing; use the `--cmd=` form given here,
not that line's form.

**Dashboard:** `/horizon` (path from `config/horizon.php:9`), gated by
`AppServiceProvider::authorizeHorizonRequest()` (`app/Providers/AppServiceProvider.php:364-393`)
— on a deployed env this 403s unless `HORIZON_DASHBOARD_USERNAME`/`_PASSWORD` are set. Both
keys ARE present (confirmed 2026-07-30 via `cloud environment:list partna`) on dev and prod —
values not checked, just that the keys exist so the gate isn't sealed shut by omission.

**Queue depth:** keys live at `partna_database_queues:<name>` on Redis **DB 0** (the `default`
connection — this is also where Horizon's own state lives, see `CLAUDE.md`'s Cache/Queue row).
There's no Redis CLI access on Cloud, so go through Tinker — pass the **unprefixed** queue
name, the client applies the `partna_database_` prefix for you:

```bash
cloud tinker development --code='echo Redis::connection("default")->llen("queues:default");'
cloud tinker development --code='echo Redis::connection("default")->llen("queues:platform_refresh");'
```

Discovery pattern for the exact prefixed key name (same technique as
`docs/runbooks/drills/01-worker-kill.md:110-117`, adapted from local `redis-cli` to a remote
Tinker call) if you need to confirm a key exists at all rather than just its length.

**Alert delivery — check before assuming Josh got paged.** Long-wait thresholds live in
`config/horizon.php:90-154`; whether an alert is actually delivered depends on
`HORIZON_NOTIFICATION_EMAIL` / `HORIZON_NOTIFICATION_SLACK_WEBHOOK` being set
(`AppServiceProvider.php:317-323`). **Checked 2026-07-30:** `HORIZON_NOTIFICATION_EMAIL` key
exists on both dev and prod, but **UNVERIFIED — the value was never read; check with
`cloud environment:get <env> --json --fields=environmentVariables --show-sensitive`**. A key
that exists but holds an empty or wrong address alerts nobody, and looks identical from here. **`HORIZON_NOTIFICATION_SLACK_WEBHOOK` is NOT set on either
environment — the key itself is absent, not just empty.** So: email alerting may or may not
be reaching anyone (unverified value), and Slack alerting for queue backlogs is definitely
not wired up right now on either env. Don't assume a silent backlog paged anyone.

**No `queue:monitor` is scheduled** — `routes/console.php` only schedules `horizon:snapshot`
(line 189) and `queue:prune-failed --hours=72` (line 172). There is no automatic
"queue depth exceeded N" check outside the wait-time thresholds above.

**Redis internals are unreadable from the app.** The Redis ACL denies `CONFIG` and `INFO`, so
`maxmemory`, `evicted_keys`, and hit-rate stats can't be queried through Tinker or any app
code path — only the Laravel Cloud console surfaces them. Don't spend time trying to script
around this.

## Distinguish the four failure modes

This is the core diagnostic step — the fix is different for each.

1. **Workers dead.** `horizon:status` doesn't say "running", or the Worker instance itself is
   stopped. Fix: restart the instance / redeploy; this isn't a queue-logic problem. **A reserved
   job is recovered by a live worker, not by elapsed time.** `migrateExpiredJobs()` runs inside
   the worker's `pop()` loop — there is no background reaper. Drill 01 (2026-08-05) measured a
   job sitting reserved for **145s** against `retry_after`=90s with zero workers, then
   converging in **1s** once a worker appeared. If a deploy kills the last worker on a
   low-traffic queue, the outage is unbounded until a worker comes back, not `retry_after`-bounded.
2. **Alive but slow.** Workers are running, queue depth is climbing anyway. Check Horizon's
   Metrics tab (job runtime trend) and Nightwatch for slow-job flags. Fix: find what got
   slower (a vendor API, a DB query — see `docs/runbooks/db-pool-exhausted.md` if it's
   connection waits) rather than assuming a queue problem.
3. **Poison job — repeatedly failing and re-queuing.** Check
   `cloud command:run development --cmd="php artisan queue:failed"` and
   `cloud command:run development --cmd="php artisan horizon:failed"`. **Every `supervisor-*`
   lane sets `'tries' => 1`** (`config/horizon.php:210-333`) as the worker's default — but a
   job's own `$tries` property overrides that worker option. `RefreshConnectionJob` sets
   `$tries = 0` (unlimited) and is bounded by `$maxExceptions = 3` instead; Drill 02
   (2026-08-05) measured exactly 3 attempts at t+0 / +32s / +153s against its
   `[30, 120, 300]` backoff — genuine Laravel retry, not a Redis re-delivery artifact. So if
   you're seeing the *same* job reappear repeatedly, check the job's own `$tries`/
   `$maxExceptions` before assuming either cause: it could be that (job overrides the lane
   default), or it could be the job's `retry_after` window expiring before the job finished,
   with Redis handing it to a second worker while the first is still (or was) running it.
   `config/horizon.php` alone won't tell you which — look at whether the job is running longer
   than its lane's `retry_after` (`config/queue.php:70-125` — `redis` 360s, `redis_gdpr` 660s,
   `redis_scraping` 660s; `redis_video` is a separate connection at 3600s,
   `config/queue.php:87-96`) as well as the job class's own retry properties.
4. **Redis memory pressure.** One shared Valkey instance, **250 MB** total, spans DB 0
   (queue/Horizon), 1 (cache), 2 (sessions), 4 (cache locks) — `maxmemory-policy` is
   instance-wide and set to `volatile-lru` (confirmed on the Cloud dashboard 2026-07-24; also
   noted at `config/partna.php:1167`). Real precedent to check against: Nightwatch issue
   **#307**, `RedisException: read error on connection to
   tls://cache-….caches.laravel.cloud:6379`, first seen 2026-07-24, last seen 2026-07-28 — if
   you're seeing `RedisException` alongside queue backup, this has happened before and is
   worth checking Nightwatch for a recurrence of the same issue rather than treating it as new.

## Immediate mitigation

**Scaling is possible but constrained — read the constraint before touching `maxProcesses`:**

```bash
# Add worker capacity to an existing background process.
cloud background-process:update <process-id> --processes=N   # N between 1 and 10

# Resize or add replicas to the underlying instance.
cloud instance:update <instance-id> --size=<size> --max-replicas=<n>
```

**`config/horizon.php:356-368` documents a known ~194 MiB over-commit on the current 2048 MiB
box** — the permitted worker-heap ceiling across all five lanes already exceeds the box's
memory budget by design margin, tolerated only because `memory` is a per-worker restart
threshold, not a live reservation. **Do not raise `maxProcesses` — especially on
`supervisor-ingest` — without a box resize first.** Scaling `background-process:update` or
adding Horizon-lane `maxProcesses` on the *same* box makes the over-commit worse, not better.
If more throughput is genuinely needed, resize the instance first, then raise `maxProcesses`.

**Post-crash re-dispatch of `SyncSubdomainToKvJob` may or may not queue — it depends where the
kill landed, and you can't tell just by "nothing seemed to happen."** The job implements
`ShouldBeUniqueUntilProcessing` (`app/Jobs/Cloudflare/SyncSubdomainToKvJob.php:45`), and
Laravel releases that lock the moment the handler starts, not when it finishes
(`CallQueuedHandler::dispatchThroughMiddleware()`'s `->then()` callback, right before
`dispatchNow()` — `vendor/laravel/framework/src/Illuminate/Queue/CallQueuedHandler.php:72,
125-130`). A SIGKILL landing **between reservation and handler entry** (worker died before
printing `RUNNING` — what Drill 01, 2026-08-05, observed) leaves the lock held, so a
re-dispatch inside the `uniqueFor` window (45s) produces **no queue entry and no error**. A
crash **mid-`handle()`** (OOM, a deploy kill on a slow job — the likelier real incident) hits
after the lock already released, so a re-dispatch **does** queue normally. If you can't tell
which happened, wait out `uniqueFor` or rely on re-delivery once a worker is back (see failure
mode 1 above) rather than assuming either outcome.

**Requeue failed jobs**, once you know *why* they failed (don't blind-retry a poison job back
into the same failure):

```bash
cloud command:run development --cmd="php artisan queue:retry all"
```

**Know exactly what each destructive command deletes before running it:**

- `horizon:clear` — destroys **pending** (not-yet-run) jobs on a queue. Anything still queued
  is gone, not deferred.
- `horizon:forget <id>` / `queue:flush` — destroy **failed job records**. This clears the
  failed-jobs table/list, not anything still queued or running.

These are not interchangeable and neither is reversible — confirm which one you mean before
running it.

**HARD WARNING — never say or do "flush Redis" as a generic instruction.**
`Cache::flush()` issues a raw Redis `FLUSHDB`. Cache lives on **DB 1** specifically so that
command *cannot* reach Horizon/queue state on **DB 0** — this separation is deliberate (see
this repo's `CLAUDE.md`, Cache/Queue row). The only sanctioned way to clear cache during this
incident is:

```bash
cloud tinker development --code='Cache::flush();'
```

— and that only touches DB 1. If a fix ever seems to call for "just flush Redis" as a whole,
stop: that would need a `FLUSHDB`/`FLUSHALL` against DB 0 directly, which destroys Horizon's
own bookkeeping (not just queued jobs — supervisor state, metrics, everything), and there is
no code path in this app that's meant to do that. Don't generalize `Cache::flush()`'s safety
to "Redis" as a whole.

**The blast radius is bigger than Horizon.** DB 0 also carries the auth session blocklist and
session-tracking keyspace (`auth:revoked-session:*`, `auth:user-sessions:*`,
`auth:session-meta:*`, `auth:session-touch:*`), written via `TokenRevocationService::redis()`
(`Redis::connection('app')`) — a different connection *name* than the queue's `default`, but
the same DB 0 (`config/database.php`'s `app` connection uses `'database' => env('REDIS_DB', 0)`,
identical to `default`). A DB 0
`FLUSHDB` destroys those too — **every signed-out or revoked session becomes valid again** for
the remainder of its refresh token's life (up to 30 days). Same exposure applies under
eviction, not just an explicit flush: these keys all carry TTLs, so under `volatile-lru` memory
pressure from queue growth they are eviction candidates same as anything else on the shared
250 MB instance.

## Root cause

Work through the four failure modes above to find the actual bottleneck before applying a
fix — "the queue is backed up" is a symptom, not a diagnosis, and the wrong fix (e.g. scaling
workers into a memory over-commit, or retrying a poison job that will just fail again) can
make it worse. If the root cause turns out to be DB connection waits rather than anything
queue-side, see `docs/runbooks/db-pool-exhausted.md` — jobs blocking on a database connection
present exactly like a queue backup (rising depth, workers "alive but slow") while the actual
fix is on the Postgres/Supavisor side, not the queue side.

## Recovery + rollback

- **Workers dead:** restart/redeploy; nothing to roll back, the fix is bringing the process
  back up.
- **Poison job:** fix the underlying cause (bad data, a vendor outage, a code bug), THEN
  `queue:retry` the specific failed job ID — not `retry all` blind, if you know only one job
  type is poisoned, to avoid re-queuing everything else that failed for unrelated reasons.
- **Memory pressure:** once the underlying cause is addressed (see #307-style investigation
  above), queue state on DB 0 should recover on its own as connections stabilize — there's no
  destructive recovery step needed unless jobs were actually lost to eviction, in which case
  check `queue:failed` for what's missing and re-dispatch from the source of truth (DB rows),
  not from Redis, since Redis itself just lost state.
- **Scaled up during the incident:** once queue depth is back to normal, scale back down
  (`background-process:update` / `instance:update`) to avoid sitting permanently over the
  documented memory over-commit for longer than the incident required.

## How you know it's over

- `horizon:status` reports running, all expected supervisors present.
- Queue depth (`llen` per queue, via Tinker as above) back to near-zero across all lanes, not
  just the ones you were watching.
- `GET /api/health/scheduler` (`routes/api.php:207`) returns green — confirms the scheduler
  itself (which drives `horizon:snapshot` and other cron work) is still alive, not just Horizon.
- `GET /api/ready` (`routes/api.php:206`, backed by `HealthController::check`, which probes
  both DB and cache) returns `200`. **Do not use `/api/health` for this** — it's liveness-only
  and stays green even when the app is fully broken; `/api/ready` is the one that actually
  checks dependencies.
- No new Nightwatch job-failure issues opening in the incident window.
- `cloud env:logs partna development --minutes 10` clean of `RedisException` and repeated job
  exception entries.

## Verification commands

```bash
cloud command:run development --cmd="php artisan horizon:status"
cloud command:run development --cmd="php artisan horizon:list"
cloud tinker development --code='echo Redis::connection("default")->llen("queues:<name>");'
cloud env:logs partna development --minutes 10
curl -i https://dev-api.partna.au/api/ready
```

## What's deliberately NOT here

- **No automatic scaling policy.** Scaling is a manual, judgment-based decision per the memory
  over-commit constraint above — not something this runbook wires up to trigger on a
  threshold.
- **No fix for the two stale docs.** `docs/deploy/queue-worker-cutover.md:9` and
  `docs/runbooks/drills/README.md:38-39` are known wrong about current queue topology; fixing
  them is separate work, not part of this runbook.
- **No Redis Cluster migration plan.** The 250 MB single-instance ceiling and its
  `volatile-lru` policy are documented here as an operating constraint, not something this
  runbook proposes changing — see this repo's `CLAUDE.md` for the existing note on why a
  Cluster migration isn't a near-term plan.
- **Cross-reference: `docs/runbooks/db-pool-exhausted.md`.** If queue depth is climbing
  *together with* `SQLSTATE[08006]`/`EMAXCONNSESSION` in the logs, the queue backup is
  downstream of pool exhaustion, not the primary problem — fix the pool first, per that
  runbook's Immediate mitigation section, before scaling queue workers (which would only
  demand more DB connections you don't have).
