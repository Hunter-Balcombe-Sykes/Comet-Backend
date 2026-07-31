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
- `ShouldBeUnique` with `$uniqueFor` 45s — **a SIGKILL leaves the unique lock held until it
  expires**, so a re-dispatch within ~45s of the kill is silently dropped. Observe this.
- Writes: `put(<handle>)` → `put(domain:<custom>)` (if any) → `bulkPut(<aliases>)`. A kill
  between these is the divergence window.

`config/queue.php`: `redis` connection `retry_after` = 360s (`REDIS_QUEUE_RETRY_AFTER`).

## Preconditions

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

1. Start Horizon in its own terminal: `php artisan horizon`
2. Dispatch, then immediately terminate:

```php
// tinker
\App\Jobs\Cloudflare\SyncSubdomainToKvJob::dispatch($u->id);
```

```bash
php artisan horizon:terminate   # fire within ~1s of the dispatch
```

3. **Observe:**
   - Horizon terminal: workers should drain — the in-flight job completes before exit.
   - `php artisan queue:failed` → expect empty.
   - Real-KV mode: `kv_get drill-wk-<YYYYMMDD>` → expect `{"type":"individual"}`.
     `kv_get drill-wk-old-<YYYYMMDD>` → expect the alias redirect entry.

**Pass A:** job completed (or was never started and remains queued for the next worker) —
either is fine; the failure would be a half-applied write with the job *gone*.

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
while [ "$(redis-cli -n 0 llen "$QKEY")" -eq 0 ]; do sleep 0.05; done  # wait for enqueue
while [ "$(redis-cli -n 0 llen "$QKEY")" -gt 0 ]; do sleep 0.02; done  # wait for reserve
kill -9 $WORKER_PID && echo "killed mid-job"
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
     `redis-cli -n 0 llen "$QKEY"` stays 0 (silently dropped by `ShouldBeUnique`). Record.
     ⚠️ Incident-response consequence: after a crash, a human re-dispatching inside the 45s
     `uniqueFor` window gets **no queue entry and no error**. Wait out `uniqueFor`, or rely on
     re-delivery — do not re-dispatch and assume it took.

5. **Observe convergence:**
   - 🔴 **Start the worker FIRST, then watch.** Do not wait for `:reserved` to empty before
     starting a worker — it never will. `migrateExpiredJobs()` runs inside the worker's
     `pop()` loop; there is no background reaper, so with zero workers a crashed job stays
     reserved indefinitely no matter how long `retry_after` has elapsed (verified: still
     reserved at t+178s with `retry_after`=90s).
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
