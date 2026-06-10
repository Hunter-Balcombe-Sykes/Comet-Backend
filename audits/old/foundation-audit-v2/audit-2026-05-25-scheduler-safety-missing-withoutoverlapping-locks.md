`★ Insight ─────────────────────────────────────`
- Laravel's `withoutOverlapping()` TTL is implemented via `Cache::lock()` — no heartbeat or liveness check, so a SIGKILL orphans the lock for the full TTL ceiling, not just until the process dies.
- The scheduler conventions comment block in this codebase is doing the right thing by documenting the anti-pattern — but three tasks were added without honouring it, suggesting contributors skimmed the boilerplate or copied from an older entry.
- `onFailure` in Laravel Scheduler fires via exit-code monitoring for commands run in background, and exception capture (via `?\Throwable $e`) is optional — but without it, you get a log line with no diagnostic context, which matters most precisely when you're in the middle of an incident.
`─────────────────────────────────────────────────`

# Scheduler Safety Audit — 2026-05-25

**Branch:** development
**Lens:** scheduler safety, missing withoutOverlapping locks, missing onOneServer, silent scheduled-task failures, missing critical schedules, frequency vs runtime mismatch
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
- routes/console.php
- app/Console/Commands/PruneExpiredHandleAliases.php
- app/Console/Commands/PruneExpiredFeatureFlagOverridesCommand.php
- app/Console/Commands/BackfillSubdomainKvCommand.php

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 2 complete
- P3 Low: 0 of 2 complete

---

## P2 — Should fix

- [ ] **#SCHED-2** · P2 — `feature-flags:prune-expired` bare `withoutOverlapping()` silently skips 24 hours after any crash
    - **Where:** routes/console.php:114–120
    - **Affects:** Feature flag override lifecycle — an expired override (e.g., a staff-issued flag forced ON for a user) can remain active for up to 24 extra hours if the scheduler process crashes or is OOM-killed mid-execution.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `->withoutOverlapping()` with `->withoutOverlapping(15)` — the command is a single `FeatureFlagOverride::where(...)->delete()` that completes in milliseconds; 15 minutes is 90,000× the actual runtime and provides ample safety headroom.
    - **Technical:** `Cache::lock()` has no liveness check — if the process holding the lock is SIGKILL'd or OOM-killed, the Redis key persists until TTL expiry. A bare `withoutOverlapping()` sets that TTL to 1440 minutes (24h), which the codebase's own scheduler conventions block (lines 11–22 of `console.php`) explicitly flags as "rarely the right ceiling." Because the underlying command (`PruneExpiredFeatureFlagOverridesCommand::handle()`) is a single Eloquent `delete()` call with sub-second runtime, an orphaned lock blocks every daily tick for a full day while stale overrides accumulate in the database. A 15-minute TTL is 1,800× longer than the command needs and costs nothing.
    - **Plain English:** Imagine a hotel DO NOT DISTURB sign that automatically reset itself each morning — but a software crash leaves it stuck for 24 hours, so housekeeping skips the room every day until it finally expires. The room only takes half a second to service; a 15-minute sign would be more than enough. Right now, one unlucky power blip means the room goes un-serviced for a full day, and any "temporary access" granted to a guest can't be revoked until the sign clears.
    - **Evidence:**
        ```php
        Schedule::command('feature-flags:prune-expired')
            ->dailyAt('03:30')
            ->withoutOverlapping()           // <-- defaults to 1440min (24h)
            ->onOneServer()
            ->onFailure(function (): void {
                \Illuminate\Support\Facades\Log::error('Scheduled task failed: feature-flags:prune-expired');
            });
        ```

- [ ] **#SCHED-1** · P2 — `handles:prune-expired-aliases` bare `withoutOverlapping()` blocks alias pruning for 24 hours after a crash
    - **Where:** routes/console.php:95–102
    - **Affects:** Handle alias redirect lifecycle — expired aliases that should be hard-deleted and re-synced to Cloudflare KV will accumulate for up to 24 extra hours if the scheduler process crashes. Old handle redirects stay active at the edge longer than the documented 90-day pool window promises.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `->withoutOverlapping()` with `->withoutOverlapping(120)` — the command snapshots alias IDs, runs a DB transaction, and dispatches `SyncSubdomainToKvJob` instances. Even with thousands of aliases, this completes in seconds; a 2-hour ceiling is thousands of times more generous than needed while being far more accurate than 24 hours.
    - **Technical:** Same root cause as SCHED-2 — bare `withoutOverlapping()` leaves a 1440-minute Redis lock that survives a process crash. The underlying `PruneExpiredHandleAliases::handle()` method: (1) snapshots expired IDs with two raw DB queries, (2) runs a single `pgsql` transaction to delete both alias tables, and (3) dispatches queue jobs for affected professionals. None of these justify a 24-hour lock. The consequence is that the alias prune silently no-ops on every subsequent daily tick until the orphaned lock expires, leaving stale `professional_handle_aliases` rows in Postgres. The Cloudflare Worker continues serving 301 redirects for handles that should have returned to the pool, which could redirect a just-claimed handle to the previous owner's canonical URL if `SyncSubdomainToKvJob` and `RetireSubdomainFromKvJob` lose the race against the stale KV entry.
    - **Plain English:** When someone changes their public handle on Partna, the old handle is kept in a redirect pool for 90 days, then released back for anyone to claim. This daily cleanup task removes expired old-handle entries. If the task crashes, the 24-hour default lock means it stops running for a full day, and expired entries pile up. Old redirects stay alive at Cloudflare's edge longer than expected — in a worst case, someone who just claimed a newly-released handle could find their new page briefly redirecting to the previous owner's site until the cleanup finally catches up.
    - **Evidence:**
        ```php
        Schedule::command('handles:prune-expired-aliases')
            ->dailyAt('03:15')
            ->onOneServer()
            ->withoutOverlapping()           // <-- defaults to 1440min (24h)
            ->runInBackground()
            ->onFailure(function (): void {
                \Illuminate\Support\Facades\Log::error('Scheduled task failed: prune-expired-aliases');
            });
        ```

---

## P3 — Nice to have

- [ ] **#SCHED-4** · P3 — 11 of 12 `onFailure` callbacks drop the exception — silent failures during incident triage
    - **Where:** routes/console.php:31–32, 50–51, 57–59, 67–69, 77–79, 89–91, 100–102, 110–112, 118–120, 128–130, 137–139
    - **Affects:** On-call engineer response time — when a scheduled task fails at 03:00 UTC, the Nightwatch log entry is just `"Scheduled task failed: purge-soft-deletes"` with no exception class or message. The actual exception is logged separately by Laravel's exception handler, requiring a second log correlation step to find the root cause.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Update all 11 minimal `onFailure` closures to match the pattern already established by `partna:prune-notifications`: accept `?\Throwable $e = null` and log `'exception' => $e ? get_class($e) : null` + `'message' => $e?->getMessage()`.
        - This is a single-pass find-and-update across `console.php`; no logic changes required.
    - **Technical:** Laravel's `Schedule::onFailure()` callback receives the caught `\Throwable` as its first argument. The one task that captures it (`partna:prune-notifications`, line 39) gets both exception class and message in the same log entry as the task identity — exactly what Nightwatch needs to surface a linked exception event. The other 11 tasks log only the task name. While the framework's own error handler does eventually surface the exception, the two log entries (task failure + exception trace) arrive without a shared correlation key, requiring manual time-window correlation during an incident. This is especially acute for `partna:purge-soft-deletes` (10h lock ceiling) and `media:cleanup-stuck-processing` (hourly), where a repeated silent failure could mask a persistent problem.
    - **Plain English:** Imagine a maintenance crew that files a report saying "Room 403 was not cleaned today" — but the report has no reason. Did the door jam? Was there a guest who refused entry? Did the cleaner slip? The correct pattern (already used for one task) would say "Room 403 not cleaned: door lock broken (KeyError)." Right now, when one of these maintenance tasks fails overnight, the on-call person sees a one-line "task failed" note and has to dig through a separate log pile to figure out why — wasted minutes during an incident.
    - **Evidence:**
        ```php
        // The one correct pattern (partna:prune-notifications, line 39):
        ->onFailure(function (?\Throwable $e = null): void {
            \Illuminate\Support\Facades\Log::error('Scheduled task failed: prune-notifications', [
                'exception' => $e ? get_class($e) : null,
                'message'   => $e?->getMessage(),
            ]);
        });

        // The pattern used by the other 11 tasks (e.g. handles:prune-expired-aliases, line 100):
        ->onFailure(function (): void {
            \Illuminate\Support\Facades\Log::error('Scheduled task failed: prune-expired-aliases');
        });
        ```

- [ ] **#SCHED-3** · P3 — `partna:backfill-subdomain-kv` bare `withoutOverlapping()` violates documented scheduler convention
    - **Where:** routes/console.php:123–130
    - **Affects:** Weekly Cloudflare KV routing resync — a scheduler crash orphans the lock and blocks re-dispatch for up to 24 hours (not the full week, but still violates the project's own documented constraint).
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `->withoutOverlapping()` with `->withoutOverlapping(180)` — the command iterates all professionals and dispatches `SyncSubdomainToKvJob` instances via the queue (`--queue` flag). Even at significant scale, dispatch-only execution completes in seconds; a 3-hour ceiling is generous while being meaningfully more accurate than 24 hours.
    - **Technical:** The weekly cadence makes the 24-hour default lock less operationally dangerous than the daily cases (SCHED-1, SCHED-2) — an orphaned lock only blocks re-dispatch for ~14% of the inter-run window. The finding is a convention violation rather than an active operational risk. The `console.php` conventions block (lines 9–22) explicitly requires an explicit `withoutOverlapping(N)` for every entry. The underlying `BackfillSubdomainKvCommand` dispatches `SyncSubdomainToKvJob` instances which already carry `ShouldBeUnique` with a 45-second uniqueness window — per-job correctness is not at risk from a duplicate weekly dispatch, only from unnecessary redundant work. A 3h TTL covers that scenario while restoring convention compliance.
    - **Plain English:** This task runs once a week to sync routing data to Cloudflare. A crash leaves it unable to re-run for up to 24 hours — but since the task only runs weekly anyway, losing one window doesn't cause the same daily pile-up problem as the other two findings. Still, the team's own rule says every scheduled task must pick a lock duration that matches how long the work actually takes. Breaking that rule for one task makes the scheduler harder to reason about during incidents, and a 3-hour lock would cost nothing while keeping the system consistent.
    - **Evidence:**
        ```php
        Schedule::command('partna:backfill-subdomain-kv', ['--all', '--queue'])
            ->weeklyOn(0, '04:00') // Sunday 04:00 UTC — off-peak for AU/NZ
            ->onOneServer()
            ->withoutOverlapping()           // <-- defaults to 1440min (24h)
            ->description('Weekly resync of Cloudflare KV subdomain routing entries')
            ->onFailure(function (): void {
                \Illuminate\Support\Facades\Log::error('Scheduled task failed: backfill-subdomain-kv');
            });
        ```

`★ Insight ─────────────────────────────────────`
- All three `withoutOverlapping()` violations are on tasks added after the existing well-formed entries — the pattern is consistent adoption lag, not a design gap. The fix is entirely mechanical: pick a TTL that matches the command's expected runtime ceiling.
- SCHED-1's impact extends to the Cloudflare edge because `PruneExpiredHandleAliases` feeds into `SyncSubdomainToKvJob` — a missed daily prune doesn't just affect Postgres, it delays KV correction for aliases that should have been evicted, making the handle pool stale at the edge too.
- The `onFailure` inconsistency (SCHED-4) is worth fixing in a single pass with a simple regex replacement across `console.php`; the return on investment during incident triage is high relative to the effort.
`─────────────────────────────────────────────────`
