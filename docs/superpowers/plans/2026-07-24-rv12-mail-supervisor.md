# RV-12 — Split transactional mail into its own Horizon supervisor

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give transactional email a dedicated Horizon lane so a long-running job on the shared `supervisor-1` pool can never park a confirmation, a moderation notice, or a GDPR deletion email.

**Architecture:** Add a fourth supervisor (`supervisor-mail`) to `horizon.defaults`, move the `notifications` and `mail` queue names onto it, and pin the change with per-queue coverage tests plus an explicit JOB-103 per-job timeout assertion. No new queue names, no dispatcher changes, no migration.

**Tech Stack:** Laravel 12, Laravel Horizon v5.48.1, Redis (Valkey), Pest 4.

## Global Constraints

- **Precondition satisfied 2026-07-24:** the dev Worker cluster (`inst-a251ae1e-7c2c-4525-83a3-7867646e119c`) is `flex-2gb` / 2048 MiB, verified by `cloud instance:list development`. `usesScheduler=true` and replicas 1–1 preserved across the resize.
- `horizon.defaults` **unions into every environment** (`ProvisioningPlan::applyDefaultOptions` → `array_replace_recursive`). Any supervisor added there runs in *every* deployed env. Size `memory` for the smallest box.
- `balance => false` on every supervisor. Non-negotiable house rule; see Task 3 for the verified reason.
- No Laravel migration files. No `git stash`. `php artisan pint --dirty` only.
- Tests: `COMPOSER_PROCESS_TIMEOUT=0 composer test`. Read the `Tests:` summary line, never a piped exit code.
- Branch `audit-fix/rv12-mail-supervisor-2026-07-24`. Do not merge or push.

---

## Verified traffic map (research output — do not re-derive)

**`notifications` queue** — routed via `config('partna.queues.notifications', 'notifications')`:

| Job | `$timeout` | `$tries` | Shape |
|---|---|---|---|
| `SendEnquiryConfirmationJob` | 30 | 3 | user-facing confirmation |
| `SendEnquiryNotificationJob` | 30 | 3 | user-facing |
| `SendSubscriptionConfirmationJob` | 30 | 3 | user-facing |
| `SendFeedbackEmailJob` | 30 | 3 | user-facing |
| `SyncCustomerMarketingOptInJob` | 30 | 3 | side-effect |
| `SendTransactionalNotificationEmailJob` (ctor default) | 30 | 3 | critical escalation |
| `DispatchEnquiryNotificationsJob` | 30 | 3 | fan-out coordinator (`$this->queue =`) |
| `NotifyStaffOfCaseUpdateJob` | 30 | 3 | moderation (`$this->queue =`) |
| `NotifyReporterJob` | 60 | — | moderation (`$this->queue =`) |
| `NotifyReportedUserJob` | 60 | — | moderation (`$this->queue =`) |
| **`SendStaffBroadcastEmailsJob`** | **120** | — | **broadcast coordinator, `chunkById(500)` walk over `EmailSubscription`** |

**`mail` queue**:

| Dispatcher | Job | `$timeout` | Shape |
|---|---|---|---|
| `NotificationPublisher::publish()` `->onQueue('mail')` (`:144`) | `SendTransactionalNotificationEmailJob` | 30 | **single critical** |
| `NotificationPublisher::publishMany()` `Bus::batch()->onQueue('mail')` (`:297`) | `SendTransactionalNotificationEmailJob` | 30 | **bulk**, chunks of 200 |
| `SendStaffBroadcastEmailsJob` `Bus::batch()->onQueue(config('partna.queues.mail'))` (`:94`) | `SendStaffBroadcastEmailToSubscriberJob` | 30, `$tries = 0` + `retryUntil(2h)` | **bulk**, chunks of 200 |

**Longest `$timeout` on the combined lane: 120s** (`SendStaffBroadcastEmailsJob`).

### Why both queues move, not just `mail`

Moving `mail` alone does **not** solve the stated problem. Every user-facing confirmation email — enquiry, subscription, feedback — is routed to `notifications`, not `mail`. Leaving `notifications` on `supervisor-1` would leave exactly the traffic RV-12 exists to protect still sharing two processes with ten other queues. Both move.

The `notifications` lane also carries the three moderation notify jobs. They share the latency-sensitivity (a reporter waiting on a case update) and are small, so they travel with the lane rather than being split out.

### Residual risk, stated not hidden

The `mail` queue mixes a **single critical** transactional email with **bulk** fan-out (200-job batches from `publishMany()` and from broadcast). Inside the new lane, a large broadcast can still head-of-line-block a single critical email. Two processes bound that, but do not eliminate it. The clean fix is a `mail_bulk` lane ordered last, mirroring the existing `cloudflare` / `cloudflare_bulk` split (R3-CACHE-1) — that requires a new config key plus dispatcher changes in `NotificationPublisher::publishMany()` and `SendStaffBroadcastEmailsJob`, which is **out of scope for this unit**. Recorded here as the follow-up.

---

## File Structure

- **Modify:** `config/horizon.php` — add `supervisor-mail` to `defaults`, remove two queue names from `supervisor-1`, add the lane to all three environment blocks, update the "Worker Lanes" docblock arithmetic.
- **Modify:** `tests/Unit/Jobs/HorizonQueueCoverageTest.php` — per-env coverage for `mail` + `notifications`, lane-ordering invariant, explicit JOB-103 per-job assertion for the 120s coordinator.

---

### Task 1: Pin the new lane's invariants with failing tests

**Files:**
- Test: `tests/Unit/Jobs/HorizonQueueCoverageTest.php`

**Interfaces:**
- Consumes: existing helpers `envCoversQueue(string $env, string $queue): bool` and `discoveredQueueNames(): array` — both already defined at the bottom of this file. Do not redefine them.
- Produces: nothing consumed by later tasks; Task 2 makes these pass.

- [x] **Step 1: Write the failing tests**

Insert immediately after the `platform_connect` coverage tests (currently ending at the block that closes with `'platform_connect queue must appear in at least one local supervisor queue list'`), before the `// Generic sweep:` comment:

```php
// RV-12: transactional mail moved off the shared supervisor-1 pool onto its own
// lane so a long job elsewhere (a ~180s Cloudflare purge, a ~300s logo job) can
// never park a confirmation or a moderation notice. Pinned per-env because
// horizon.defaults unions into every environment — a future env-block edit that
// drops the lane would strand every outbound email silently.
it('mail queue is covered in every Horizon environment (RV-12)', function () {
    foreach (['production', 'development', 'local'] as $env) {
        expect(envCoversQueue($env, 'mail'))->toBeTrue(
            "mail queue must appear in at least one {$env} supervisor queue list"
        );
    }
});

it('notifications queue is covered in every Horizon environment (RV-12)', function () {
    foreach (['production', 'development', 'local'] as $env) {
        expect(envCoversQueue($env, 'notifications'))->toBeTrue(
            "notifications queue must appear in at least one {$env} supervisor queue list"
        );
    }
});

// The split is only worth anything if the two queues leave supervisor-1 entirely.
// A "tidy up the queue list" that re-adds either name would silently restore the
// head-of-line blocking this unit removed, and every coverage test above would
// still pass.
it('mail and notifications are NOT drained by supervisor-1 (RV-12)', function () {
    $horizon = require base_path('config/horizon.php');
    $shared = (array) $horizon['defaults']['supervisor-1']['queue'];

    expect($shared)->not->toContain('mail')
        ->and($shared)->not->toContain('notifications');
});

// Bulk fan-out (publishMany batches, broadcast leaf batches) lands on 'mail';
// 'notifications' carries the single user-facing confirmations. Under
// balance=>false the supervisor drains in strict listed order, so listing
// notifications first keeps a 200-job broadcast batch from delaying an enquiry
// confirmation.
it('notifications is listed BEFORE mail on the dedicated lane (RV-12)', function () {
    $horizon = require base_path('config/horizon.php');
    $queue = (array) $horizon['defaults']['supervisor-mail']['queue'];

    $notificationsIndex = array_search('notifications', $queue, true);
    $mailIndex = array_search('mail', $queue, true);

    expect($notificationsIndex)->not->toBeFalse()
        ->and($mailIndex)->not->toBeFalse()
        ->and($mailIndex)->toBeGreaterThan($notificationsIndex);
});

// JOB-103, per-job. The generic supervisor-level guard below only compares each
// supervisor's own timeout against its connection retry_after; its docblock
// admits it cannot instantiate every job to read $timeout. SendStaffBroadcastEmailsJob
// is the longest job on the new lane at 120s (a chunkById(500) walk over
// EmailSubscription), so it gets the same explicit treatment MenuFetchJob has on
// the scraping lane.
it('the longest mail-lane job stays under its connection retry_after and supervisor timeout (JOB-103)', function () {
    $horizon = require base_path('config/horizon.php');
    $defaults = $horizon['defaults'];

    $supervisorName = null;
    foreach ($defaults as $name => $supervisorConfig) {
        if (in_array('mail', (array) ($supervisorConfig['queue'] ?? []), true)) {
            $supervisorName = $name;
            break;
        }
    }

    expect($supervisorName)->not->toBeNull('no Horizon default supervisor consumes the mail queue');

    $connection = $defaults[$supervisorName]['connection'];
    $retryAfter = config("queue.connections.{$connection}.retry_after");
    $supervisorTimeout = $defaults[$supervisorName]['timeout'];

    $job = new SendStaffBroadcastEmailsJob('00000000-0000-0000-0000-000000000001');

    // Strictly LESS THAN retry_after: an equal value races Horizon's SIGKILL
    // against Redis's re-queue at the same instant, which would double-send a
    // broadcast to every subscriber.
    expect($job->timeout)->toBeLessThan($retryAfter)
        ->and($job->timeout)->toBeLessThanOrEqual($supervisorTimeout);
});
```

Add the import beside the existing job imports at the top of the file:

```php
use App\Jobs\Notifications\SendStaffBroadcastEmailsJob;
```

> **Implementer note:** `SendStaffBroadcastEmailsJob`'s constructor signature must be confirmed before writing that last test — read `app/Jobs/Notifications/SendStaffBroadcastEmailsJob.php` and pass whatever it actually requires. If it needs a model rather than a string id, assert against `(new ReflectionClass(SendStaffBroadcastEmailsJob::class))->getDefaultProperties()['timeout']` instead of instantiating, and say so in the test comment.

- [x] **Step 2: Run the tests to verify they fail**

```bash
COMPOSER_PROCESS_TIMEOUT=0 ./vendor/bin/pest tests/Unit/Jobs/HorizonQueueCoverageTest.php
```

Expected: FAIL. The `supervisor-mail` lookups fail on an undefined array key; the "NOT drained by supervisor-1" test fails because both names are still there.

- [x] **Step 3: Commit the failing tests**

```bash
git add tests/Unit/Jobs/HorizonQueueCoverageTest.php
git commit -m "test(horizon): pin RV-12 dedicated mail lane invariants (failing)"
```

---

### Task 2: Add `supervisor-mail` and move the two queues

**Files:**
- Modify: `config/horizon.php` — `defaults` block (~lines 119-178), `environments` block (~lines 195-215)

**Interfaces:**
- Consumes: the invariants pinned in Task 1.
- Produces: `horizon.defaults['supervisor-mail']` with keys `connection`, `queue`, `balance`, `maxProcesses`, `maxTime`, `maxJobs`, `memory`, `tries`, `timeout`, `nice` — same key set as every other supervisor.

- [ ] **Step 1: Remove the two queue names from `supervisor-1`**

Replace `supervisor-1`'s `queue` line with:

```php
            'queue' => ['moderation_high', 'default', 'cloudflare', 'cache-warm', 'analytics', 'images', 'streaming', 'platform_refresh', 'platform_connect', 'cloudflare_bulk'],
```

Ten queues, down from twelve. `moderation_high` stays first; `cloudflare_bulk` stays last (the R3-CACHE-1 invariant, pinned by its own test).

- [ ] **Step 2: Add the new supervisor after `supervisor-1`**

```php
        // RV-12: transactional mail lane. Split out of supervisor-1 because that
        // pool drains ten queues with two processes under strict priority — two
        // concurrent long jobs (a ~180s Cloudflare purge, a ~300s logo job) stalled
        // every outbound email until one finished. Priority order within the lane
        // matters: 'notifications' carries the single user-facing confirmations,
        // 'mail' carries the bulk fan-out (publishMany batches, broadcast leaf
        // batches of 200), so notifications is drained first.
        // Longest job on this lane is SendStaffBroadcastEmailsJob at $timeout=120,
        // so the redis connection's retry_after=360 clears it comfortably. nice=0:
        // this lane is latency-sensitive and must not be deprioritised.
        'supervisor-mail' => [
            'connection' => 'redis',
            'queue' => ['notifications', 'mail'],
            'balance' => false,
            'maxProcesses' => 1,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 192,
            'tries' => 1,
            'timeout' => 180,
            'nice' => 0,
        ],
```

- [ ] **Step 3: Name the lane in every environment block**

```php
        'production' => [
            'supervisor-1' => ['maxProcesses' => 2],
            'supervisor-mail' => ['maxProcesses' => 2],
            'supervisor-long' => ['maxProcesses' => 1],
            'supervisor-videos' => ['maxProcesses' => 1],
        ],

        'development' => [
            'supervisor-1' => ['maxProcesses' => 2],
            'supervisor-mail' => ['maxProcesses' => 2],
            'supervisor-long' => ['maxProcesses' => 1],
            'supervisor-videos' => ['maxProcesses' => 1],
        ],

        'local' => [
            'supervisor-1' => ['maxProcesses' => 3],
            'supervisor-mail' => ['maxProcesses' => 1],
            'supervisor-long' => ['maxProcesses' => 2],
            'supervisor-videos' => ['maxProcesses' => 1],
        ],
```

- [ ] **Step 4: Run the tests to verify they pass**

```bash
COMPOSER_PROCESS_TIMEOUT=0 ./vendor/bin/pest tests/Unit/Jobs/HorizonQueueCoverageTest.php
```

Expected: PASS, all tests in the file.

- [ ] **Step 5: Commit**

```bash
git add config/horizon.php
git commit -m "fix(horizon): RV-12 — dedicated supervisor-mail lane for transactional email"
```

---

### Task 3: Correct the "Worker Lanes" docblock arithmetic

**Files:**
- Modify: `config/horizon.php` — the "Worker Lanes" docblock (~lines 91-117) and the "Environments" docblock (~lines 180-193)

**Interfaces:**
- Consumes: the four-lane layout from Task 2.
- Produces: nothing. Comment-only.

**⚠ Scope change from the RV-12 prompt — read before editing.** The prompt's Part B asked for the `balance => false` justification to be rewritten on the grounds that it is factually wrong. **It is not wrong.** Verified against the installed `laravel/horizon` **v5.48.1** in this worktree:

- `Supervisor::createProcessPools()` (`vendor/laravel/horizon/src/Supervisor.php:88-93`) branches on `$this->options->balancing()`: `'simple'`/`'auto'` → `createProcessPoolPerQueue()` (one pool **per comma-separated queue**), `false` → `createSingleProcessPool()` (exactly one pool).
- `Supervisor::scale()` (`:135-139`) then does `$this->options->maxProcesses = max($this->options->maxProcesses, $processes, count($this->processPools));` — under a balancing strategy that is `max(2, …, 10)` = **10**. The existing comment's claim that `maxProcesses` is silently raised to the pool count is **correct**.
- The prompt further claimed `scale()` is reached only from the manual `horizon:scale` path. **Also incorrect:** `Console/SupervisorCommand::start()` calls `$supervisor->scale(...)` at line 100, on ordinary supervisor startup. The automatic path reaches it too.

So the conclusion *and* the justification stand. Do **not** rewrite them to say "starvation, not a floor of one" — that would replace a correct comment with an incorrect one. What this task changes is the stale arithmetic only, plus source citations so the next reader can check the claim instead of trusting it.

- [ ] **Step 1: Update the queue-count and OOM-history references**

In the "Worker Lanes" docblock, make these substitutions and no others:

- The `balance` justification sentence gains a citation. Replace:
  `'simple'/'auto' floor at one worker PER QUEUE (Supervisor::scale raises`
  `maxProcesses to the pool count).`
  with:
  `'simple'/'auto' floor at one worker PER QUEUE — Supervisor::createProcessPools()`
  `builds one pool per comma-separated queue under a balancing strategy, and`
  `Supervisor::scale() raises maxProcesses to max(maxProcesses, $processes,`
  `count($processPools)). Verified against laravel/horizon v5.48.1:`
  `src/Supervisor.php:88-93 and :135-139; reached on ordinary startup via`
  `Console/SupervisorCommand::start():100, not only via horizon:scale.`

- [ ] **Step 2: Update the "Environments" docblock footprint arithmetic**

Replace the footprint sentence with:

```
    | Process-count overrides per environment. Footprint = 1 master + one
    | middleman process per lane + workers, ~90 MiB each: idle deployed
    | footprint is 11 procs (~990 MiB) against the 2048 MiB flex-2gb Worker
    | box (RV-4 resize, 2026-07-24). Permitted worker heap sums to
    | 2×256 (supervisor-1) + 2×192 (supervisor-mail) + 256 (supervisor-long)
    | + 512 (supervisor-videos) = 1664 MiB. Every environment must name all
    | four lanes explicitly — that is what HorizonQueueCoverageTest walks to
    | prove every dispatchable queue has a consumer in every env.
```

- [ ] **Step 3: Run pint and the full file's tests**

```bash
php artisan pint --dirty
COMPOSER_PROCESS_TIMEOUT=0 ./vendor/bin/pest tests/Unit/Jobs/HorizonQueueCoverageTest.php
```

Expected: pint reports only `config/horizon.php`; tests PASS.

- [ ] **Step 4: Commit**

```bash
git add config/horizon.php
git commit -m "docs(horizon): RV-12 — four-lane footprint arithmetic + verified balance citation"
```

---

### Task 4: Full-suite verification

- [ ] **Step 1: Run the full suite**

```bash
COMPOSER_PROCESS_TIMEOUT=0 composer test
```

Expected: PASS. Read the `Tests:` summary line. Do **not** run this while any other agent is running tests.

- [ ] **Step 2: If anything fails, verify it is pre-existing**

Check out the merge-base and re-run the same failing test there before claiming "pre-existing". Never assert it without that evidence.

---

## What cannot be proved here, and must be checked at deploy time

The suite runs SQLite with no Horizon and no Redis. This plan **cannot** demonstrate clean supervisor startup, real process counts, or actual RSS. What it does prove: every queue still has a consuming supervisor in every env, the two queues left `supervisor-1`, lane priority order is right, and `retry_after > timeout` holds for the longest job on the new lane.

**Josh must verify after deploy:**
1. Horizon dashboard shows **four** supervisors, not three.
2. Worker instance memory graph: idle floor ~990 MiB against 2048, not climbing toward the ceiling.
3. `cloud env:logs partna development --minutes 15` shows no supervisor restart loop.
4. Trigger a staff broadcast and confirm an enquiry confirmation still lands promptly while it drains.
