Good. I have everything I need. Verification confirms:
- 5 daily `Schedule::command()` tasks without `->runInBackground()` (SCHED-1 ✅)
- 2 bare `withoutOverlapping()` on daily tasks (SCHED-2 ✅) + 1 on weekly (SCHED-3 ✅)  
- **12 of 13** `onFailure` handlers discard the exception (DeepSeek said 11 — corrected) (SCHED-4 ✅)
- `Schedule::job()` entries (AggregateCacheMetricsJob, CheckStreamingLiveStatusJob) dispatch to queue and are exempt from the blocking concern

`★ Insight ─────────────────────────────────────`
`Schedule::job()` vs `Schedule::command()` is a crucial distinction for scheduler blocking analysis: job-based entries dispatch to Horizon immediately and return; command-based entries without `runInBackground()` spawn a child process the scheduler *waits* for. The blocking is per-tick (the 03:00 `schedule:run` invocation), not across all future ticks — each new minute spawns a fresh `schedule:run` process that can run other tasks normally.
`─────────────────────────────────────────────────`

# Scheduler Safety Audit — 2026-05-31

**Branch:** development
**Lens:** Scheduler safety, missing withoutOverlapping locks, missing onOneServer, silent scheduled-task failures, missing critical schedules, frequency vs runtime mismatch
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
- `routes/console.php`
- `app/Console/Commands/PurgeSoftDeleted.php`
- `app/Console/Commands/PruneNotifications.php`
- `app/Console/Commands/PurgeRawAnalyticsEvents.php`
- `app/Console/Commands/PruneExpiredHandleAliases.php`
- `app/Console/Commands/PruneExpiredFeatureFlagOverridesCommand.php`
- `app/Console/Commands/BackfillSubdomainKvCommand.php`
- `app/Console/Commands/Moderation/ModerationSlaScanCommand.php`
- `app/Console/Commands/CleanupStuckMediaProcessingCommand.php`
- `app/Jobs/Cache/AggregateCacheMetricsJob.php`
- `app/Jobs/Streaming/CheckStreamingLiveStatusJob.php`

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 2 complete
- P3 Low: 0 of 2 complete

---

## P2 — Should fix

- [ ] **#SCHED-2** · P2 — Two daily tasks use bare `withoutOverlapping()`, risking a missed run after a crash
    - **Where:** `routes/console.php:98` (`handles:prune-expired-aliases`) and `routes/console.php:116` (`feature-flags:prune-expired`)
    - **Affects:** Handle-alias expiry lifecycle (expired aliases linger an extra day) and feature-flag-override table bloat (stale overrides survive an extra day) when either command crashes before releasing its mutex.
    - **Effort:** S (~0.5h)
    - **What to do:**
        - Replace bare `->withoutOverlapping()` on `handles:prune-expired-aliases` with `->withoutOverlapping(120)` — 2× the expected runtime of a DB delete + KV re-sync batch, well under the 24h cadence.
        - Replace bare `->withoutOverlapping()` on `feature-flags:prune-expired` with `->withoutOverlapping(30)` — the command is a single-table delete that completes in seconds; 30 minutes gives ample headroom.
    - **Technical:** `withoutOverlapping()` with no argument defaults to a 1440-minute (24-hour) mutex TTL in Laravel's `CacheEventMutex`. If the task is SIGKILL'd or OOM-killed before the after-callback releases the mutex, the lock persists for exactly 24 hours. For a `dailyAt` task, this means the lock expires at approximately the same moment the next day's tick fires — clock skew of a few seconds, or a slightly extended runtime, is enough to make the next run find the mutex still held and silently skip. An explicit TTL of 30–120 minutes guarantees the lock is long gone by the following morning regardless of crash timing. The project's own scheduler conventions header calls this out explicitly: "A bare `withoutOverlapping()` defaults to 1440 (24h), which is rarely the right ceiling."
    - **Plain English:** Think of the mutex as a sticky note on a door that says "do not enter until tomorrow." If the job crashes, that note stays up for exactly 24 hours — and if the cleaner shows up 10 seconds early the next morning, the door is still locked. A shorter expiry (like 2 hours) means the note is long gone by the time the morning crew arrives.
    - **Evidence:**
        ```php
        // routes/console.php — handles:prune-expired-aliases (dailyAt 03:15)
        Schedule::command('handles:prune-expired-aliases')
            ->dailyAt('03:15')
            ->onOneServer()
            ->withoutOverlapping()       // <-- bare: 1440 min default, equals 24h cadence
            ->runInBackground()
            ->onFailure(function (): void { ... });

        // routes/console.php — feature-flags:prune-expired (dailyAt 03:30)
        Schedule::command('feature-flags:prune-expired')
            ->dailyAt('03:30')
            ->withoutOverlapping()       // <-- bare: 1440 min default, equals 24h cadence
            ->onOneServer()
            ->onFailure(function (): void { ... });
        ```

- [ ] **#SCHED-1** · P2 — Five daily `Schedule::command()` tasks missing `->runInBackground()` block co-scheduled tasks in the same tick
    - **Where:** `routes/console.php:27` (`partna:purge-soft-deletes`), `:46` (`partna:analytics:purge-raw-events`), `:54` (`queue:prune-failed`), `:35` (`partna:prune-notifications`), `:114` (`feature-flags:prune-expired`)
    - **Affects:** Tasks co-scheduled in the same `schedule:run` invocation as any of these commands. `purge-raw-events` (03:00) shares a tick with `keep-alive-ping`, `horizon:snapshot`, and `moderation:sla-scan`. `purge-soft-deletes` (03:20, 600-minute lock) shares a tick with `keep-alive-ping`. Each affected task is delayed for that specific tick only — subsequent minutes spawn fresh `schedule:run` processes unaffected — but the 03:20 keep-alive ping could be delayed long enough to allow a pod park on Laravel Cloud if the purge-soft-deletes command stalls.
    - **Effort:** S (~0.5h)
    - **What to do:**
        - Add `->runInBackground()` to `partna:purge-soft-deletes` (highest priority — the 600-minute overlap lock signals this can run for many hours).
        - Add `->runInBackground()` to `partna:analytics:purge-raw-events`, `queue:prune-failed`, `partna:prune-notifications`, and `feature-flags:prune-expired`.
        - Note: `Schedule::job()` entries (`AggregateCacheMetricsJob`, `CheckStreamingLiveStatusJob`) are already exempt — they dispatch to the Horizon queue immediately and never block the scheduler process.
    - **Technical:** `Schedule::command()` without `->runInBackground()` runs the child process synchronously inside the per-minute `schedule:run` invocation: the scheduler calls `Process::run()` (blocking) and only proceeds to the next due event after the process exits. Because each minute spawns a new `schedule:run` process, the blocking is confined to the single tick that co-fires with the long-running command. `handles:prune-expired-aliases` and `handles:notify-expiry` already follow the convention with `->runInBackground()`. The scheduler conventions header in `routes/console.php` explicitly requires `->runInBackground()` "for daily/cron-scale tasks that shouldn't block the per-minute scheduler tick."
    - **Plain English:** The Laravel scheduler fires once every minute. When it fires at 03:20, it starts the daily data-cleanup truck (no parallel lane), then waits for it to finish before running the keep-alive car that prevents cold-start delays for visitors. The truck might take hours. Adding `runInBackground()` gives the cleanup truck its own lane so the scheduler can immediately start the keep-alive car in parallel.
    - **Evidence:**
        ```php
        // routes/console.php — scheduler conventions header (the explicit project rule)
        // • ->runInBackground()  — for daily/cron-scale tasks that shouldn't block
        //                          the per-minute scheduler tick.

        // The five offending entries — all Schedule::command() with no runInBackground():
        Schedule::command('partna:purge-soft-deletes')
            ->dailyAt('03:20')
            ->onOneServer()
            ->withoutOverlapping(600) // 10h lock — historical purges on large tables can run long.
            ->onFailure(function (): void { ... });
        // NOTE: no ->runInBackground()

        Schedule::command('partna:analytics:purge-raw-events')
            ->dailyAt('03:00')
            ->onOneServer()
            ->withoutOverlapping(30)
            ->onFailure(function (): void { ... });
        // no runInBackground()

        Schedule::command('queue:prune-failed --hours=72')
            ->daily()
            ->onOneServer()
            ->withoutOverlapping(60)
            ->onFailure(function (): void { ... });
        // no runInBackground()

        Schedule::command('partna:prune-notifications', ['--days' => 30])
            ->dailyAt('03:25')
            ->onOneServer()
            ->withoutOverlapping(120)
            ->onFailure(function (?\Throwable $e = null): void { ... });
        // no runInBackground()

        Schedule::command('feature-flags:prune-expired')
            ->dailyAt('03:30')
            ->withoutOverlapping()
            ->onOneServer()
            ->onFailure(function (): void { ... });
        // no runInBackground()

        // By contrast, the two tasks that already follow the convention:
        Schedule::command('handles:prune-expired-aliases')
            ->dailyAt('03:15')
            ->onOneServer()
            ->withoutOverlapping()
            ->runInBackground()  // ← correct
            ->onFailure(function (): void { ... });
        ```

## P3 — Nice to have

- [ ] **#SCHED-3** · P3 — Weekly KV backfill uses bare `withoutOverlapping()` — convention inconsistency, no practical risk
    - **Where:** `routes/console.php:148` (`partna:backfill-subdomain-kv`)
    - **Affects:** Convention consistency only. For a weekly task the 1440-minute default lock expires 6 days before the next run — there is no crash-lock collision risk.
    - **Effort:** S (~0.1h)
    - **What to do:**
        - Replace bare `->withoutOverlapping()` with `->withoutOverlapping(120)` to align with the project's scheduler conventions and make the intent explicit.
    - **Technical:** With a 10080-minute weekly cadence, a 1440-minute crash-lock TTL clears 6 days before the next fire. The risk is zero. This is purely a conventions alignment: every other task in the file uses an explicit TTL except for this entry and the two daily tasks in SCHED-2. A consistent file is easier to audit and means new entries can copy any nearby entry as a template without accidentally inheriting the bare default.
    - **Plain English:** A "do not disturb" sign that auto-expires in 24 hours is no problem when housekeeping only comes once a week — the sign is long gone. This is purely about keeping all the signs consistent so the next person setting up a new task can copy any existing one safely.
    - **Evidence:**
        ```php
        // routes/console.php
        Schedule::command('partna:backfill-subdomain-kv', ['--all', '--queue'])
            ->weeklyOn(0, '04:00') // Sunday 04:00 UTC — off-peak for AU/NZ
            ->onOneServer()
            ->withoutOverlapping()       // <-- bare: 1440 min vs 10080 min weekly cadence
            ->description('Weekly resync of Cloudflare KV subdomain routing entries')
            ->onFailure(function (): void { ... });
        ```

- [ ] **#SCHED-4** · P3 — Twelve of thirteen `onFailure` callbacks discard the `\Throwable` instance, reducing Nightwatch signal
    - **Where:** `routes/console.php` — all `onFailure` closures except `partna:prune-notifications` (line 39)
    - **Affects:** On-call engineers responding to overnight scheduler failures. Without the exception class and message in the log context, every alert requires a manual second step (checking Horizon's failed-jobs tab) to identify the root cause.
    - **Effort:** S (~0.5h)
    - **What to do:**
        - Update the twelve bare `function (): void` signatures to `function (?\Throwable $e = null): void` and include `'exception' => $e ? get_class($e) : null, 'message' => $e?->getMessage()` in the `Log::error()` context array, matching the pattern already used by `partna:prune-notifications`.
        - The `keep-alive-ping` `Schedule::call()` closure deliberately swallows failures (comment: "keep-alive failures aren't actionable") — leave it as-is; it intentionally has no `->onFailure()`.
    - **Technical:** Laravel passes the exception that caused the task failure as the first argument to `onFailure` closures. `partna:prune-notifications` already captures it correctly with `get_class($e)` and `$e?->getMessage()`. The other twelve log a bare string (e.g., `'Scheduled task failed: purge-soft-deletes'`), which Nightwatch surfaces as an error event with no exception detail. Corrected count: DeepSeek reported eleven; source inspection shows twelve of the thirteen `onFailure` handlers discard the throwable.
    - **Plain English:** Twelve of the thirteen scheduler alarms beep loudly but only say "something broke" with no detail about what or why. One alarm (the notification pruner) already tells you the room and the fire type. The fix is one line per handler — give the other twelve the same detail so the overnight on-call can act immediately instead of digging.
    - **Evidence:**
        ```php
        // The pattern used by 12 of 13 handlers (exception discarded):
        ->onFailure(function (): void {
            \Illuminate\Support\Facades\Log::error('Scheduled task failed: purge-soft-deletes');
        });

        // The single handler that already captures the exception correctly:
        ->onFailure(function (?\Throwable $e = null): void {
            \Illuminate\Support\Facades\Log::error('Scheduled task failed: prune-notifications', [
                'exception' => $e ? get_class($e) : null,
                'message'   => $e?->getMessage(),
            ]);
        });
        ```
