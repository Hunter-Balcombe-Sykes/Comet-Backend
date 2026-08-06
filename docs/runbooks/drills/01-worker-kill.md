# Drill 01 — Worker kill mid-job

**Question:** when a queue worker dies while `SyncSubdomainToKvJob` is executing, do the
DB and Cloudflare KV diverge — and does retry converge them without help?

Two scenarios, because "worker dies" happens two ways with different semantics:

| Scenario | Simulates | Signal | Expected behavior |
|----------|-----------|--------|-------------------|
| A — graceful | Deploy / `horizon:terminate` | SIGTERM | Worker finishes the in-flight job, then exits. Nothing lost. |
| B — crash | OOM-kill / hard crash | SIGKILL (`kill -9`) | Job left in the reserved set; re-delivered after `retry_after` (360s on the `redis` connection); retry converges KV to DB state. |

## Target job facts (verify still true before running)

`app/Jobs/Cloudflare/SyncSubdomainToKvJob.php`:
- Queue `cloudflare` (redis connection), `$timeout` 30s.
- Retry policy from `HasCloudflareRetryPolicy`: `$tries` 3, `$backoff` [10, 30, 60], `$maxExceptions` 2.
- `ShouldBeUniqueUntilProcessing` with `$uniqueFor` 45s — Laravel releases this lock when the
  handler *starts*, not when it finishes (`CallQueuedHandler::dispatchThroughMiddleware()`'s
  `->then()`, right before `dispatchNow()` —
  `vendor/laravel/framework/src/Illuminate/Queue/CallQueuedHandler.php:72,125-130`). **A SIGKILL
  landing between reservation and handler entry leaves the lock held until it expires**, so a
  re-dispatch within ~45s of that kind of kill is silently dropped. A kill mid-`handle()` hits
  after the lock already released, so a re-dispatch there queues normally. Observe which one
  this run produces.
- Writes: `put(<handle>)` → `put(domain:<custom>)` (if any) → `bulkPut(<aliases>)`. A kill
  between these is the divergence window.

`config/queue.php`: `redis` connection `retry_after` = 360s (`REDIS_QUEUE_RETRY_AFTER`).

## Preconditions

- [ ] 🔴 **A crashed job needs a live worker to recover — elapsed time alone does nothing.**
  `migrateExpiredJobs()` runs inside the worker's `pop()` loop; there is no background reaper.
  With zero workers, a reserved job stays reserved indefinitely no matter how far past
  `retry_after` the clock runs (2026-08-05 run: still reserved at **145s** against
  `retry_after`=90s, converged in **1s** once a worker started). Read this before Scenario B
  step 5 — don't spend the drill watching `:reserved` empty on its own; start the worker first,
  then watch it converge.
- [ ] Local stack up: Herd site + local Redis + local `.env` with `QUEUE_CONNECTION=redis`.
- [ ] **Decide the KV mode up front:**
  - 🔴 **Real KV is currently FORBIDDEN on this project.** There is no dev-only KV namespace —
    prod and dev share one `SUBDOMAIN_KV` behind a single zone-wide Worker. Real-KV mode would
    write `drill-*` handle keys into the namespace **production** reads from. Use it only once
    a separate dev namespace exists.
  - **Real KV (full drill, blocked — see above):** `services.cloudflare.{account_id,
    kv_namespace_id, api_token}` set in local `.env`. Only this mode can show real divergence,
    which is why the DB↔KV divergence question stays unanswered until the namespaces are split.
  - **Unconfigured (queue-semantics-only):** `CloudflareKvService` no-ops without
    credentials (`guardUnconfigured`) — the drill still proves SIGTERM/SIGKILL/retry
    semantics, but KV checks below become no-ops. Note the mode in the log.

    🔴 **This mode requires `APP_ENV` to be `local`/`dev`/`test`. Under `staging` or
    `production` the guard THROWS, by design** — "Refusing to silently no-op put in staging"
    (`CloudflareKvService::guardUnconfigured()`; missing creds must page outside local, or an
    unrouted subdomain stays hidden until a user reports it). A worker started under `staging`
    therefore fails **every** `SyncSubdomainToKvJob` with a `RuntimeException` and writes a
    `failed_jobs` row — which this runbook lists below as a **FAIL signal**. The result is a
    drill that reports a P1 queue-integrity failure against a perfectly healthy system.

    This is not hypothetical, and it is easy to walk into: drill 03 mandates `APP_ENV=staging`,
    so running 03 then 01 in the same session inherits it. The 2026-08-06 run hit exactly this.
    Worse, it can disagree with itself — Horizon started under `local` (per drill 03's own
    ordering trap) keeps no-opping happily while a freshly-started bare worker under `staging`
    fails the same job. **Assert before Scenario B:**

    ```bash
    php artisan tinker --execute='echo config("app.env");'   # must NOT be staging/production
    ```
- [ ] Optional but recommended: shrink the crash-recovery wait for the session —
  `REDIS_QUEUE_RETRY_AFTER=90` in `.env`, then `php artisan config:clear`. **Revert after.**
- [ ] Export helpers for the KV curl checks (real-KV mode):

```bash
export CF_ACCT=<account_id> CF_NS=<kv_namespace_id> CF_TOKEN=<api_token>
kv_get() { curl -s -H "Authorization: Bearer $CF_TOKEN" \
  "https://api.cloudflare.com/client/v4/accounts/$CF_ACCT/storage/kv/namespaces/$CF_NS/values/$1"; echo; }
```

## ARRANGE — drill user

⚠️ Two things bite here on a real Postgres local stack that the SQLite test suite never sees:
`core.users.auth_user_id` has a **real FK to `auth.users`**, and **nothing auto-creates a site**.

In `php artisan tinker`:

```php
use Illuminate\Support\Str;

// 1. auth.users row first — the FK is real; the factory alone fails with SQLSTATE 23503.
//    Only `id` is NOT NULL without a default.
$authId = (string) Str::uuid();
DB::statement('insert into auth.users (id, email, aud, role) values (?, ?, ?, ?)', [
    $authId, 'drill-wk-<YYYYMMDD>@drill.local', 'authenticated', 'authenticated',
]);

$u = \App\Models\Core\User\User::factory()->create([
    'handle'       => 'drill-wk-<YYYYMMDD>',
    'auth_user_id' => $authId,
]);
$u->id; // note it

// 2. Provision the site EXPLICITLY. There is no trigger and no observer that does this:
//    pg_trigger on core.users holds only set_timestamp_users + the two handle-alias
//    triggers, and `Site::create` appears nowhere in app/. Sites are created application-side
//    by SiteProvisioningService, called from UserBootstrapService / PreAccountBuildService.
$svc  = app(\App\Services\User\SiteProvisioningService::class);
$site = $svc->createSiteWithRetry($u->id, $svc->subdomainBaseFromHandle($u->handle), true);
```

Sanity: `$u->isActive()` must be true, and after the step above `$u->refresh()->site` must be
non-null and `is_published` true.

Optionally add an alias row so `bulkPut` has something to write (widens the divergence window):

```php
DB::table('core.user_handle_aliases')->insert([
    'id' => (string) Str::uuid(), 'user_id' => $u->id,
    'handle' => 'drill-wk-old-<YYYYMMDD>',
    'reclaim_until' => now()->addDays(14), 'expires_at' => now()->addDays(90),
    'created_at' => now(), 'updated_at' => now(),
]);
```

## Scenario A — graceful terminate (deploy semantics)

⚠️ **A single dispatch cannot enter this race window.** A ~10ms job is over before any
terminate command can boot, `horizon:terminate` included — the 2026-07-31 run terminated
167ms after a 32ms job and never actually raced it. Signal the Horizon master directly rather
than shelling out: `posix_kill()` landed the signal 12.2ms after the first dispatch in the
2026-08-05 run, and **7.5ms** in the 2026-08-06 run, vs. 167ms for `horizon:terminate`.

🔴 **…and a one-shot batch cannot enter it either — being FAST is not enough.** The 2026-08-06
run signalled at 7.5ms and again at 39.3ms; both times all six jobs were still sitting on the
ready list, nothing had been reserved, and the "in-flight job finishes after the signal" half of
Pass A was never exercised. Cause, measured: the supervisor covers **10 queues**, so its workers
cannot `BLPOP` on `cloudflare` alone and pickup latency is up to **~3s** — hundreds of times
longer than any achievable signal delay against a ~5ms job. The 2026-08-05 run caught one
in-flight job by luck, not by design.

✅ **Use sustained dispatch instead** — which is also the realistic deploy shape (a busy queue at
deploy time). Feed the queue continuously for ~4s, signal mid-stream, then keep feeding:

```php
// tinker or a small script; $users = the drill users
$masterPid = (int) trim(shell_exec("pgrep -f 'artisan horizon\$' | head -1"));
$t0 = microtime(true); $signalled = false;
while (microtime(true) - $t0 < 8.0) {
    foreach ($users as $u) { \App\Jobs\Cloudflare\SyncSubdomainToKvJob::dispatch($u->id); }
    if (! $signalled && microtime(true) - $t0 >= 4.0) { posix_kill($masterPid, SIGTERM); $signalled = true; }
    usleep(50000);
}
```

Expect the dispatch count to collapse hard — the 2026-08-06 run turned **888 dispatches into 12
queue entries** via `ShouldBeUniqueUntilProcessing`. Count `RUNNING` vs `DONE` vs `FAIL` lines in
the Horizon log: they must pair exactly, with `DONE`s continuing for a second or two *after* the
signal. That is the evidence Pass A actually wants.

1. Start Horizon in its own terminal: `php artisan horizon`, and note its master PID
   (`horizon:status` or `pgrep -f 'horizon'`).
2. Create a few more drill users with the ARRANGE recipe above (e.g.
   `drill-wk-<YYYYMMDD>-2` through `-6`) so there's a real batch to dispatch.
3. Dispatch one job per user, then signal the master immediately:

```php
// tinker
$users = [$u /* , $u2, $u3, … the batch */];
foreach ($users as $user) {
    \App\Jobs\Cloudflare\SyncSubdomainToKvJob::dispatch($user->id);
}
// Derived here rather than pasted from step 1's terminal — the actual 2026-08-05 run did this.
$masterPid = (int) trim(shell_exec("pgrep -f 'artisan horizon\$' | head -1"));
posix_kill($masterPid, SIGTERM);
```

4. **Observe:**
   - Horizon terminal: whichever job was already `RUNNING` at the moment of the signal should
     finish; jobs still on the ready list should stay queued for the next worker, not vanish.
   - `php artisan queue:failed` → expect empty.
   - Real-KV mode: `kv_get drill-wk-<YYYYMMDD>` → expect `{"type":"individual"}`.
     `kv_get drill-wk-old-<YYYYMMDD>` → expect the alias redirect entry.

**Pass A:** every job in the batch either completed or remained queued for the next worker —
never gone with a half-applied write.

## Scenario B — SIGKILL mid-job (crash semantics)

Horizon supervises its workers and would blur PID targeting — use a bare single worker so
the kill is deterministic. Stop Horizon first (Ctrl-C in its terminal).

1. Terminal 1 — single worker, cloudflare queue only:

```bash
php artisan queue:work redis --queue=cloudflare &
WORKER_PID=$!
echo $WORKER_PID
```

2. Terminal 2 — find the queue key, arm a watcher that kills the worker the instant the job
   is reserved (mid-HTTP-call for a real KV write, which takes ~0.5–2s):

```bash
# Discover the exact prefixed key first. Queue + Horizon live on Redis DB 0 (the
# connection named `default`) — NOT DB 3, despite that connection being named
# `queue`, and NOT DB 2, which is sessions. See config/queue.php + config/horizon.php.
redis-cli -n 0 --scan --pattern '*queues:cloudflare*'
QKEY='<the plain list key from above, not :notify / :reserved>'
```

🔴 **`sleep 0.02` + `redis-cli` polling is too coarse for a job this fast** — each `redis-cli`
invocation alone spawns a process, which costs more than the job's own runtime. Use a raw
phpredis tight-loop watcher instead (~50µs per `LLEN`, no process-spawn overhead); it landed
the kill in 1–2 polls in the 2026-08-05 run:

```php
<?php
// watcher.php — usage: php watcher.php <worker_pid> <queue_key>
$r = new Redis();
$r->connect('127.0.0.1', 6379);
$r->select(0);
while ($r->lLen($argv[2]) === 0) { /* tight loop: wait for enqueue */ }
while ($r->lLen($argv[2]) > 0)  { /* tight loop: wait for reserve */ }
posix_kill((int) $argv[1], SIGKILL);
echo "killed mid-job\n";
```

```bash
php watcher.php "$WORKER_PID" "$QKEY"
```

3. Tinker — dispatch (watcher fires automatically):

```php
\App\Jobs\Cloudflare\SyncSubdomainToKvJob::dispatch($u->id);
```

4. **Observe the divergence window** (before recovery):
   - `redis-cli -n 0 zrange "${QKEY}:reserved" 0 -1` → the job sits in the reserved set.
   - Real-KV mode: `kv_get` the handle and alias — depending on where the kill landed, the
     handle entry may exist while the alias entry doesn't (or neither). **This is the
     divergence — screenshot/copy it into the log.**
   - Unique-lock behavior: re-dispatch the same job within 45s of the kill →
     `redis-cli -n 0 llen "$QKEY"` stays 0 (silently dropped — the watcher kills right at
     reservation, before the handler starts, which is the one window where
     `ShouldBeUniqueUntilProcessing`'s lock is still held; see Target job facts above). Record.
     ⚠️ Incident-response consequence: after a crash landing in that pre-handler window, a human
     re-dispatching inside the 45s `uniqueFor` window gets **no queue entry and no error**. A
     crash mid-`handle()` instead (the more common real-world case — OOM, a deploy kill on a
     slow job) releases the lock first, so that re-dispatch *does* queue. Wait out `uniqueFor`,
     or rely on re-delivery, if you can't tell which happened — do not assume a re-dispatch
     failed just because nothing seemed to happen.

🔴 **Sample `ready` and `reserved` ATOMICALLY, and watch convergence in the FOREGROUND.** Two
separate `redis-cli` calls can straddle a job that is in flight between them and report
`ready=0 reserved=0` — which reads as "the job vanished from both the queue and the reserved
set", this runbook's most serious FAIL signal. Killing the worker right after that sample
compounds it. The 2026-08-06 run briefly produced exactly that false P1; it did not reproduce
under foreground instrumentation. Also: **`queue:work` prints nothing until a job completes**, so
an empty worker log is not evidence that nothing ran — it is the *normal* state of a worker that
is waiting. Prefer:

```bash
redis-cli -n 0 eval "return {redis.call('llen', KEYS[1]), redis.call('zcard', KEYS[2])}" 2 \
  laravel_database_queues:cloudflare laravel_database_queues:cloudflare:reserved
# and run the recovery worker in the foreground with a bounded lifetime:
php artisan queue:work redis --queue=cloudflare --max-time=110 -v
```

Note also that `ShouldBeUniqueUntilProcessing`'s lock lives on the **`cache_locks` connection,
Redis DB 4** (`laravel_database_laravel-cache-laravel_unique_job:<Job>:<id>`) — not DB 0 and not
the cache DB. Scanning the wrong DB makes the lock look absent and the silent-drop behaviour
inexplicable.

5. **Observe convergence:**
   - Start the worker FIRST, then watch — see the Preconditions warning above; `:reserved`
     never empties on its own.
   - Start a long-lived worker and leave it running:
     `php artisan queue:work redis --queue=cloudflare`. Expect re-delivery at
     ≈ `retry_after` + poll granularity (measured: **t+91s** with `retry_after`=90s).
   - Re-run the KV checks → handle, custom-domain, and alias entries must now ALL match DB
     state. `php artisan queue:failed` → empty.

**Pass B:** divergence exists only *between* kill and re-delivery; after re-delivery,
KV == DB with no manual intervention and no failed-jobs entry. The job is idempotent
(reconciles from current DB state), so re-running must be safe — verify nothing doubled.

**Fail signals:** job vanished from both the queue and reserved set without effect; retry
lands but KV still diverges; failed-jobs entry with exhausted retries on a healthy network.

## Optional secondary target — `ProcessVideoVariantsJob`

Only if video uploads are enabled locally (`SIDEST_VIDEO_UPLOADS_ENABLED`) and worth the
time. Facts: connection `redis_video`, queue `videos`, `$tries` 2, `$timeout` 720,
`retry_after` 3600 (`PARTNA_VIDEO_QUEUE_RETRY_AFTER`); `GuardsMediaProcessing` holds a lock
with TTL = timeout + 60s and **a retry landing inside the held window silently returns** —
so after a SIGKILL mid-encode, expect convergence only after lock expiry, not on first
retry. Kill mid-ffmpeg is easy to time (encodes run for many seconds):
`php artisan queue:work redis_video --queue=videos` + `kill -9` while ffmpeg is visible in
`ps`. Verify: no orphaned tmp files, media row not stuck in `processing` forever, second
attempt (post-lock-expiry) completes or fails cleanly to `failed` state.

## RESTORE

```php
// tinker — exercises the retire path as a bonus check
$u->forceDelete();   // observer dispatches a KV delete for the handle
```

- Real-KV mode: after the queue drains, `kv_get drill-wk-<YYYYMMDD>` → expect 404/empty.
  If the alias entry lingers, it has a TTL and will self-evict — note it, don't hand-delete.
- Delete the alias row if `forceDelete` didn't cascade it.
- Revert `.env` (`REDIS_QUEUE_RETRY_AFTER`, any CF credentials added for the session) +
  `php artisan config:clear`.
- Restart Horizon if you use it for normal local dev.

## Record

Copy `logs/TEMPLATE.md` → `logs/<YYYY-MM-DD>-worker-kill.md`. Must capture: KV mode
(real/unconfigured), where the kill landed, the divergence evidence, time-to-convergence,
unique-lock observation, verdicts for A and B.
