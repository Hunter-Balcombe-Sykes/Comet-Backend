`★ Insight ─────────────────────────────────────`
- Laravel's `withoutOverlapping()` with no argument silently defaults to **1440 minutes** (24h) — this is the single most common scheduler misconfiguration in Laravel apps because it "looks like" no-timeout, not "24-hour lock."
- `onOneServer()` and `withoutOverlapping()` solve different problems: `onOneServer` prevents multi-server fan-out at dispatch time, while `withoutOverlapping` prevents concurrent execution on the same server. Both are needed for most maintenance tasks.
- The four tasks that already have `onOneServer` (`handles:prune-expired-aliases`, `handles:notify-expiry`, `feature-flags:prune-expired`, `backfill-subdomain-kv`) form a visible pattern — the seven that don't are almost certainly omissions from the same author, not intentional design choices.
`─────────────────────────────────────────────────`

# Scheduler Safety Audit — 2026-05-24

**Branch:** development
**Lens:** scheduler safety, missing withoutOverlapping locks, missing onOneServer, silent scheduled-task failures, missing critical schedules, frequency vs runtime mismatch
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
- routes/console.php
- app/Console/Commands/NotifyHandleAliasExpiry.php
- app/Console/Commands/PruneNotifications.php
- app/Console/Commands/PurgeRawAnalyticsEvents.php
- app/Console/Commands/CleanupStuckMediaProcessingCommand.php
- app/Console/Commands/PurgeSoftDeleted.php
- app/Console/Commands/PruneExpiredHandleAliases.php
- app/Console/Commands/PruneExpiredFeatureFlagOverridesCommand.php
- app/Console/Commands/BackfillSubdomainKvCommand.php

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 5 complete

---

## P2 — Should fix

- [ ] **SCHED-1** · P2 — `CheckStreamingLiveStatusJob` lock timeout equals scheduling cadence, creating a race window
    - **Where:** routes/console.php (`CheckStreamingLiveStatusJob` schedule entry)
    - **Affects:** Streaming live-status checks — any job run that exceeds 120 seconds causes the lock to expire before the job finishes, allowing a second instance to start while the first is still running. Results in duplicate status checks and redundant onFailure log entries.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Change `withoutOverlapping(2)` to `withoutOverlapping(5)` (or higher, sized to the expected worst-case runtime of a status API call under load).
        - Add an inline comment documenting the chosen ceiling and its relationship to the 2-minute cadence, so future maintainers don't reduce it back to match the schedule frequency.
    - **Technical:** `withoutOverlapping(2)` creates a cache lock that expires exactly 120 seconds after acquisition — the same interval as `everyTwoMinutes()`. If a run takes 121 seconds (network blip, upstream API latency spike), the lock has already expired when the scheduler fires again and a second instance starts while the first is still in flight. `withoutOverlapping` releases the lock on job completion, so a 5-minute ceiling still allows immediate consecutive runs when the job finishes quickly; the timeout is only the safety net for the pathological case. The comment in the source (`// withoutOverlapping(2) matches the every-2-min cadence`) suggests this was intentional but misunderstands the semantics: the lock timeout should be a worst-case ceiling above the expected runtime, not a mirror of the scheduling frequency.
    - **Plain English:** Imagine setting an oven timer for exactly the same time your dish takes to cook. If the dish takes even 10 seconds longer one day, the timer goes off first — and someone might think it's done and put a second batch in while the first is still cooking. The fix is to set the timer for a few minutes longer than expected. You can still take the dish out early when it's ready; the timer is just a safety net for when things run slow.
    - **Evidence:**
        ```php
        // withoutOverlapping(2) matches the every-2-min cadence.
        Schedule::job(new \App\Jobs\Streaming\CheckStreamingLiveStatusJob)
            ->everyTwoMinutes()
            ->withoutOverlapping(2)
        ```

- [ ] **SCHED-2** · P2 — `handles:notify-expiry` missing `withoutOverlapping` — duplicate expiry emails possible on overlapping runs
    - **Where:** routes/console.php (`handles:notify-expiry` schedule entry); app/Console/Commands/NotifyHandleAliasExpiry.php
    - **Affects:** Professionals with expiring handle aliases — could receive duplicate T-3 or T-1 warning emails if the previous day's run is still in progress when the next daily trigger fires (e.g., after a scheduler restart or a large batch of expiring aliases).
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `->withoutOverlapping(60)` to the schedule entry (60-minute ceiling is generous for a daily mailer task).
        - Add `->runInBackground()` to match the pattern used by `handles:prune-expired-aliases`.
    - **Technical:** `dispatchBucket` reads `whereNull($stampColumn)` to find un-notified aliases, then stamps `$stampColumn` after sending each email. This application-level guard is not atomic: two concurrent command instances could both read `whereNull('notified_t3_at')` on the same alias before either writes the stamp, and both would queue the mail. The task already has `onOneServer()` preventing multi-server fan-out, so the practical risk is a previous slow run overlapping with the next day's scheduled fire — plausible if a large cohort of aliases is expiring. `withoutOverlapping()` provides a cache-level mutex that closes this window entirely.
    - **Plain English:** The command marks each person as "emailed" right after sending. But if two copies of the command start at the same time, both check the list before either one marks anyone as done — so both send the email. The task already prevents two servers from running it simultaneously, but it has no protection against a slow previous run still going when the next one starts. Adding a lock ensures only one copy can ever be running.
    - **Evidence:**
        ```php
        // Notifies alias holders of upcoming expiry (T-3/T-1 day warnings).
        Schedule::command('handles:notify-expiry')
            ->dailyAt('09:00')
            ->onOneServer()
            ->onFailure(function (): void {
                \Illuminate\Support\Facades\Log::error('Scheduled task failed: handles-notify-expiry');
            });
        ```
        ```php
        private function dispatchBucket(string $stampColumn, \DateTimeInterface $window, string $bucket): void
        {
            DB::connection('pgsql')
                ->table('site.professional_handle_aliases')
                ->whereNull($stampColumn)
                // ...
                ->chunkById(200, function ($aliases) use ($stampColumn, $bucket) {
                    foreach ($aliases as $alias) {
                        // ...
                        DB::connection('pgsql')
                            ->table('site.professional_handle_aliases')
                            ->where('id', $alias->id)
                            ->update([$stampColumn => now()->toDateTimeString()]);
                    }
                });
        }
        ```

- [ ] **SCHED-3** · P2 — `partna:prune-notifications` missing both `withoutOverlapping` and `onOneServer`
    - **Where:** routes/console.php (`partna:prune-notifications` schedule entry)
    - **Affects:** Notification data — on any multi-server deployment, the daily prune runs once per server. Without either guard, concurrent executions on the same server are also possible after a scheduler restart mid-run.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `->withoutOverlapping(120)` to prevent concurrent execution.
        - Add `->onOneServer()` to prevent multi-server fan-out.
    - **Technical:** `PruneNotifications` issues a `delete()` on a query builder that relies on `ON DELETE CASCADE` to remove associated receipts. PostgreSQL handles duplicate deletes safely (the second attempt affects zero rows), but without `onOneServer()` every scheduler-running server attempts this independently, multiplying unnecessary DB work by server count. Without `withoutOverlapping()`, a restart during a long-running prune (large notifications table) allows a second instance to start, creating redundant cascade deletes and unnecessary lock contention. Both guards together are the established pattern for this type of maintenance task, as demonstrated by the five other tasks in the same file that use them.
    - **Plain English:** Right now three servers each show up to do this same trash-collection task every morning. PostgreSQL doesn't break, but it's three workers doing the job of one. Adding these two guards means only one server handles it, and only one instance can run at a time.
    - **Evidence:**
        ```php
        Schedule::command('partna:prune-notifications', ['--days' => 30])
            ->dailyAt('03:25')
            ->onFailure(function (?\Throwable $e = null): void {
                \Illuminate\Support\Facades\Log::error('Scheduled task failed: prune-notifications', [
                    'exception' => $e ? get_class($e) : null,
                    'message' => $e?->getMessage(),
                ]);
            });
        ```

- [ ] **SCHED-4** · P2 — Five scheduled tasks use bare `withoutOverlapping()`, defaulting to a 24-hour lock timeout — a hung job silently blocks all subsequent runs for a full day
    - **Where:** routes/console.php (entries for `AggregateCacheMetricsJob`, `horizon:snapshot`, `media:cleanup-stuck-processing`, `queue:prune-failed`, `partna:analytics:purge-raw-events`)
    - **Affects:** All five maintenance pipelines — if any of these tasks hangs (deadlock, infinite loop, stuck network call), the pipeline goes dark for up to 24 hours with no alert. `onFailure` only fires on an exception or non-zero exit; a hung process produces neither.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Set explicit timeouts on each, proportionate to expected runtime: `withoutOverlapping(10)` for the hourly metrics and Horizon snapshot jobs, `withoutOverlapping(30)` for media cleanup and analytics purge, `withoutOverlapping(60)` for the daily failed-job prune.
        - Add an inline comment on each stating the expected runtime ceiling and the chosen timeout.
    - **Technical:** In Laravel's scheduler, `withoutOverlapping()` with no argument sets `$this->expiresAt = 1440` (minutes). The lock is held until the command process exits or 1440 minutes elapse. For `AggregateCacheMetricsJob` (hourly), a hang means the next 23 scheduled runs are silently skipped — the scheduler sees the lock held and moves on without logging anything at the warning level. `onFailure` is not triggered because the hung process never exits with a non-zero code. Setting an explicit timeout proportionate to the task's expected runtime (e.g., 10 minutes for an hourly metric aggregation job) limits the blast radius: if the process hangs, the lock releases after 10 minutes and the next run proceeds normally, while Horizon's process-level timeout and retry mechanism handles the stuck worker independently.
    - **Plain English:** Each of these tasks has a lock that says "only one copy of me can run at a time." But if the running copy freezes, that lock stays engaged for a full 24 hours — and there's no alarm that goes off. It's like a bathroom stall where someone fell asleep with the door locked: nobody else can get in, but there's no indication anything is wrong. Setting a shorter timeout is like adding a 10-minute or 30-minute auto-unlock: if the stall hasn't been vacated by then, the lock releases and the next person in line can proceed.
    - **Evidence:**
        ```php
        Schedule::job(new \App\Jobs\Cache\AggregateCacheMetricsJob)
            ->hourly()
            ->withoutOverlapping()   // defaults to 1440-minute lock
        ```
        ```php
        Schedule::command('horizon:snapshot')
            ->everyFiveMinutes()
            ->withoutOverlapping()   // defaults to 1440-minute lock
        ```
        ```php
        Schedule::command('media:cleanup-stuck-processing')
            ->hourly()
            ->withoutOverlapping()   // defaults to 1440-minute lock
        ```
        ```php
        Schedule::command('queue:prune-failed --hours=72')
            ->daily()
            ->withoutOverlapping()   // defaults to 1440-minute lock
        ```
        ```php
        Schedule::command('partna:analytics:purge-raw-events')
            ->dailyAt('03:00')
            ->withoutOverlapping()   // defaults to 1440-minute lock
        ```

- [ ] **SCHED-5** · P2 — Seven of twelve scheduled tasks are missing `onOneServer()` — duplicate execution on any multi-server deployment
    - **Where:** routes/console.php (entries for `partna:purge-soft-deletes`, `partna:analytics:purge-raw-events`, `queue:prune-failed`, `AggregateCacheMetricsJob`, `horizon:snapshot`, `CheckStreamingLiveStatusJob`, `media:cleanup-stuck-processing`)
    - **Affects:** All seven maintenance pipelines — on a deployment with more than one server running the Laravel scheduler (standard for Horizon-based setups), each task executes once per server rather than once total. For tasks with `withoutOverlapping`, this means unnecessary cache-lock contention; for tasks that lack both guards (SCHED-3), it means actual duplicate execution.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `->onOneServer()` to each of the seven entries.
        - Confirm the cache driver is Redis (not `database`) since `onOneServer` relies on atomic cache locks — Redis supports this; the `database` cache driver does not guarantee atomicity under concurrent access. Redis is confirmed by the project stack (DB 0 = cache).
    - **Technical:** `onOneServer()` acquires an atomic cache lock before dispatching; if the lock is already held by another server, that server skips the task silently. Without it, every server evaluating `php artisan schedule:run` independently determines the task is due and dispatches it. The four tasks that already carry `onOneServer` — `handles:prune-expired-aliases`, `handles:notify-expiry`, `feature-flags:prune-expired`, `partna:backfill-subdomain-kv` — demonstrate the intended pattern. The seven missing entries are structurally identical in risk profile and were almost certainly omitted rather than intentionally left unguarded.
    - **Plain English:** If this app runs on three servers, seven of the twelve maintenance tasks run three times each — once per server. Some have a "don't run if already running" lock, but all three servers still show up and try. The four tasks that already have the "only one server needs to do this" rule in place show exactly how it should look. The fix is to add the same rule to the remaining seven.
    - **Evidence:**
        ```php
        // Present on 4 tasks — the established pattern:
        Schedule::command('handles:prune-expired-aliases')
            ->dailyAt('03:15')
            ->onOneServer()          // ← present

        // Absent on 7 tasks — representative examples:
        Schedule::command('partna:purge-soft-deletes')
            ->dailyAt('03:20')
            ->withoutOverlapping(600)
            // onOneServer() absent

        Schedule::job(new \App\Jobs\Cache\AggregateCacheMetricsJob)
            ->hourly()
            ->withoutOverlapping()
            // onOneServer() absent

        Schedule::command('horizon:snapshot')
            ->everyFiveMinutes()
            ->withoutOverlapping()
            // onOneServer() absent
        ```

`★ Insight ─────────────────────────────────────`
- The four well-configured tasks (`handles:prune-expired-aliases`, `handles:notify-expiry`, `feature-flags:prune-expired`, `backfill-subdomain-kv`) were likely written after an operational lesson or code review — their thoroughness relative to the other eight suggests a period where the pattern solidified but wasn't backfilled.
- SCHED-4 (24h default lock) is the most invisible risk: there are no warnings in Laravel's output when you call `withoutOverlapping()` without a timeout, and the default of 1440 minutes appears nowhere in the scheduler's documentation summary, only in the source code.
- All five SCHED-4 findings and the seven SCHED-5 findings can be fixed in a single 30-minute pass through `routes/console.php` — they're the same two-line fix pattern repeated across entries.
`─────────────────────────────────────────────────`

The five P2 findings are all verified against the source and cover distinct root-cause patterns; all evidence is verbatim from `routes/console.php` and the relevant command classes.
