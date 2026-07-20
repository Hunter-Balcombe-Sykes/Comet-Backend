<?php

use App\Jobs\Cache\AggregateCacheMetricsJob;
use App\Jobs\Streaming\CheckStreamingLiveStatusJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;

/*
 * Scheduler conventions — every entry below MUST honour these:
 *   • ->onOneServer()           — elects one host per tick on multi-server deploys
 *                                  (Redis atomic lock; no-op on a single host).
 *   • ->withoutOverlapping(N)   — prefer an explicit lock TTL in minutes. A bare
 *                                  withoutOverlapping() defaults to 1440 (24h), which
 *                                  is rarely the right ceiling. N must exceed the
 *                                  task's expected runtime AND, for sub-hourly
 *                                  cadences, must also exceed the cadence itself —
 *                                  otherwise a slow run races the next tick on the
 *                                  instant the lock TTL expires.
 *   • ->onFailure(...)          — surfaces silent maintenance failures to Nightwatch.
 *   • ->runInBackground()       — for daily/cron-scale tasks that shouldn't block
 *                                  the per-minute scheduler tick.
 */

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Shared onFailure handler — report() is what reaches Nightwatch (a Log::error alone
// does not raise an alert). Each scheduled task passes its own label.
$reportScheduledFailure = fn (string $task) => function (?Throwable $e = null) use ($task): void {
    if ($e !== null) {
        report($e);
    }
    Log::error("Scheduled task failed: {$task}", [
        'exception' => $e ? get_class($e) : null,
        'message' => $e?->getMessage(),
    ]);
};

Schedule::command('partna:purge-soft-deletes')
    ->dailyAt('03:20')
    ->onOneServer()
    ->withoutOverlapping(600) // 10h lock — historical purges on large tables can run long.
    ->runInBackground()
    ->onFailure($reportScheduledFailure('purge-soft-deletes'));

Schedule::command('partna:prune-notifications', ['--days' => 30])
    ->dailyAt('03:25')
    ->onOneServer()
    ->withoutOverlapping(120) // 2h lock — bounded by retention-window batch size.
    ->runInBackground()
    ->onFailure($reportScheduledFailure('prune-notifications'));

// OV-H: weekly in-app "your week on Partna" summary (non-critical, Info). Dedupe-per-week
// makes a re-run idempotent. Monday 08:00 UTC — start-of-week nudge, off the daily 03:xx sweeps.
Schedule::command('partna:notify-weekly-summary')
    ->weeklyOn(1, '08:00')
    ->onOneServer()
    ->withoutOverlapping(120) // 2h lock — bounded by the active-users fan-out size.
    ->runInBackground()
    ->onFailure($reportScheduledFailure('notify-weekly-summary'));

Schedule::command('partna:analytics:purge-raw-events')
    ->dailyAt('03:00')
    ->onOneServer()
    ->withoutOverlapping(30) // 30min lock — partition-scoped DELETE; daily cadence.
    ->runInBackground()
    ->onFailure($reportScheduledFailure('purge-raw-events'));

// Recompute content popularity scores from raw events (pages + scored items).
// Reads only section_views / link_clicks / item_views.
//
// CADENCE (2026-07-09): every 15 min while validating the ONE theme, so page +
// item scores reflect real browsing without a manual trigger. ⚠️ REVISIT before
// real prod scale — this full-sweeps EVERY published site each run (wasteful at
// scale; should scope to sites with recent events), and the 0.7/0.3 hysteresis
// blend + 90-day half-life were tuned for a DAILY cadence (at 15-min the blend
// barely smooths). Was: ->dailyAt('02:40'). The daily 03:00 purge still bounds
// the retained window this reads.
Schedule::command('analytics:compute-popularity')
    ->everyFifteenMinutes()
    ->onOneServer()
    ->withoutOverlapping(14) // 14min lock (< 15min cadence): releases immediately on a normal run; a stuck run's lock clears before the next tick.
    ->runInBackground()
    ->onFailure($reportScheduledFailure('compute-popularity'));

// Dispatcher: fan out a queued RefreshConnectionJob per due connection. Hourly so
// connections are picked up close to their TTL (the heavy work is on the queue, not
// here). Lock < cadence so a slow run can't overlap the next tick.
Schedule::command('integrations:refresh')
    ->hourly()
    ->runInBackground()
    ->onOneServer()
    ->withoutOverlapping(50)
    ->onFailure($reportScheduledFailure('integrations:refresh'));

// Staleness alarm: page when too many connections fall overdue (SCALE-1).
Schedule::command('integrations:refresh-backlog')
    ->hourly()
    ->runInBackground()
    ->onOneServer()
    ->withoutOverlapping(50)
    ->onFailure($reportScheduledFailure('integrations:refresh-backlog'));

Schedule::command('queue:prune-failed --hours=72')
    ->daily()
    ->onOneServer()
    ->withoutOverlapping(60) // 60min lock — proportional to failed_jobs table size.
    ->runInBackground()
    ->onFailure($reportScheduledFailure('prune-failed-jobs'));

// Reads the previous hour's cache hit/miss Redis counters, logs structured metrics,
// and reports SLO violations (hot prefixes below 90% hit rate) to Nightwatch.
Schedule::job(new AggregateCacheMetricsJob)
    ->hourly()
    ->onOneServer()
    ->withoutOverlapping(10) // 10min lock — read-only Redis aggregation, completes in seconds.
    ->onFailure($reportScheduledFailure('aggregate-cache-metrics'));

// Snapshots queue throughput / runtime metrics into Redis so the Horizon
// Metrics tab has data to render.
Schedule::command('horizon:snapshot')
    ->everyFiveMinutes()
    ->onOneServer()
    ->withoutOverlapping(10) // 10min lock — 2x everyFiveMinutes cadence safety ceiling.
    ->onFailure($reportScheduledFailure('horizon-snapshot'));

// withoutOverlapping(5) gives a 2.5x ceiling over the everyTwoMinutes cadence. The
// prior value (2) equalled the cadence, creating a same-tick race: lock TTL expiry
// and the next dispatch happen at the same instant, so a slow run could collide
// with itself. N must exceed the cadence for high-frequency tasks.
Schedule::job(new CheckStreamingLiveStatusJob)
    ->everyTwoMinutes()
    ->onOneServer()
    ->withoutOverlapping(5)
    ->onFailure($reportScheduledFailure('check-streaming-live-status'));

// Handle/subdomain alias lifecycle: hard-deletes expired alias rows daily.
Schedule::command('handles:prune-expired-aliases')
    ->dailyAt('03:15')
    ->onOneServer()
    ->withoutOverlapping(120)
    ->runInBackground()
    ->onFailure($reportScheduledFailure('prune-expired-aliases'));

// Notifies alias holders of upcoming expiry (T-3/T-1 day warnings).
Schedule::command('handles:notify-expiry')
    ->dailyAt('09:00')
    ->onOneServer()
    ->withoutOverlapping(60) // 60min lock — closes a race between application-level whereNull guards on the notified_t* stamp columns.
    ->runInBackground()
    ->onFailure($reportScheduledFailure('handles-notify-expiry'));

// PRIV-2: enforces config('partna.handle.audit_retention_years') (default 7y) on
// the append-only audit.handle_change_log table via the SECURITY DEFINER
// audit.prune_handle_change_log() RPC (app_backend cannot DELETE directly).
Schedule::command('handles:prune-audit-logs')
    ->dailyAt('03:25')
    ->onOneServer()
    ->withoutOverlapping(120)
    ->runInBackground()
    ->onFailure($reportScheduledFailure('handles-prune-audit-logs'));

Schedule::command('feature-flags:prune-expired')
    ->dailyAt('03:30')
    ->withoutOverlapping(30)
    ->onOneServer()
    ->runInBackground()
    ->onFailure($reportScheduledFailure('feature-flags:prune-expired'));

// Pre-account sites: hard-deletes expired unclaimed builds (+ stale failed
// builds past failed_prune_hours). Teardown-ordering mirrors
// AccountDeletionService::purge() — see PruneExpiredPreAccountBuilds.
Schedule::command('builds:prune-expired')
    ->dailyAt('03:40')
    ->onOneServer()
    ->withoutOverlapping(120)
    ->runInBackground()
    ->onFailure($reportScheduledFailure('prune-expired-pre-account-builds'));

// Keep Laravel Cloud warm. Fires a 3-second HTTP request to the local /up
// health endpoint every minute so the autoscaler doesn't park the web
// pod between visitor bursts. Cold starts (when the pod has been parked)
// can run 5-10s on Laravel Cloud and were tripping the Astro Worker's
// fetchProfile timeout — manifesting as random "profile not found" for
// the visitor. The keep-alive eliminates those blips at the cost of
// ~1440 lightweight requests per day.
Schedule::call(function () {
    try {
        $url = rtrim((string) config('app.url'), '/').'/up';
        Http::timeout(3)->retry(1, 200)->get($url);
    } catch (Throwable $e) {
        // Silent — keep-alive failures aren't actionable.
    }
})
    // description() / name() must precede onOneServer() — Laravel uses the
    // string as the cluster-wide mutex key for the closure event.
    ->name('keep-alive-ping')
    ->everyMinute()
    ->onOneServer()
    ->withoutOverlapping(2);

// Cloudflare KV subdomain routing backstop.
Schedule::command('partna:backfill-subdomain-kv', ['--all', '--queue'])
    ->weeklyOn(0, '04:00') // Sunday 04:00 UTC — off-peak for AU/NZ
    ->onOneServer()
    ->withoutOverlapping(120)
    ->description('Weekly resync of Cloudflare KV subdomain routing entries')
    ->onFailure($reportScheduledFailure('backfill-subdomain-kv'));

// P2-14: daily watchdog for ExportUserDataJob rows orphaned in PROCESSING by SIGKILL.
// failed() only fires on retry exhaustion, never on a hard kill, so a worker death
// between markProcessing() and completion leaves the audit row stuck forever.
Schedule::command('gdpr:sweep-stale-exports')
    ->dailyAt('03:35')
    ->onOneServer()
    ->withoutOverlapping(60) // 60min lock — export audit table is tiny; completes in seconds.
    ->runInBackground()
    ->onFailure($reportScheduledFailure('gdpr:sweep-stale-exports'));

// QUEUE-5: hourly watchdog for SiteMedia rows orphaned in PROCESSING.
Schedule::command('media:cleanup-stuck-processing')
    ->hourly()
    ->onOneServer()
    ->withoutOverlapping(30) // 30min lock — Postgres lookup + queue dispatch, typically seconds.
    ->onFailure($reportScheduledFailure('media:cleanup-stuck-processing'));

// P1-08 (ledger sweep): daily recovery for video R2 artifacts orphaned when a
// DeleteMediaArtifactsJob exhausted its retries during an R2 outage and the owning
// account was then hard-deleted. Reads paths from EVENT_PURGED audit rows — cheap,
// precise, GDPR-timely. Daily so erasure completes within the next-day window.
Schedule::command('gdpr:sweep-purged-video-artifacts')
    ->dailyAt('03:45')
    ->onOneServer()
    ->withoutOverlapping(60)
    ->runInBackground()
    ->onFailure($reportScheduledFailure('gdpr:sweep-purged-video-artifacts'));

// PRIV-8: weekly hard-delete of early_access_signups rows from non-converting applicants
// older than the retention window (default 730d). Staggered Sunday 04:30 UTC — last of the
// weekly Sunday sweeps, after the 04:10 unsubscribed-subscriptions prune and 04:20 video GC.
Schedule::command('early-access:prune-old-signups')
    ->weeklyOn(0, '04:30')
    ->onOneServer()
    ->withoutOverlapping(60) // 60min lock — single bulk delete; completes in seconds.
    ->runInBackground()
    ->onFailure($reportScheduledFailure('early-access:prune-old-signups'));

// DINT-1 / PRIV-7 Gap 2: weekly hard-delete of unsubscribed email_subscriptions older than
// the retention window (default 365d). The whole row is deleted — email and email_lc are both
// PII and NOT NULL, so there is no skeleton worth keeping; child broadcast_email_receipts
// cascade via the DINT-2 FK. Cadence: Sunday 04:10 UTC (off-peak for AU/NZ, after the daily
// gdpr:* sweeps). withoutOverlapping(60) — a single bulk delete finishes in seconds; the 60min
// ceiling is headroom for future growth.
Schedule::command('notifications:prune-unsubscribed-subscriptions')
    ->weeklyOn(0, '04:10')
    ->onOneServer()
    ->withoutOverlapping(60)
    ->runInBackground()
    ->onFailure($reportScheduledFailure('notifications:prune-unsubscribed-subscriptions'));

// PRIV-2: enforce the 30-day GDPR export file retention window. Deletes R2 ZIP
// artifacts and nulls the file columns on audit.data_export_audit rows whose
// created_at exceeds the retention window. The audit row itself is KEPT —
// GDPR requires the record that an export happened. Runs after the other
// gdpr:* sweeps (03:35 stale, 03:45 video) so they all finish before the
// log rotation window closes.
Schedule::command('gdpr:prune-completed-exports')
    ->dailyAt('03:50')
    ->onOneServer()
    ->withoutOverlapping(60) // 60min lock — export table is tiny; completes in seconds.
    ->runInBackground()
    ->onFailure($reportScheduledFailure('gdpr:prune-completed-exports'));

// P1-08 (prefix GC backstop): weekly garbage collection of video R2 objects with
// no backing site_media row, from ANY cause (failed upload, crashed transcode,
// pre-ledger orphans). Heavier — a full videos/ prefix LIST — so it runs weekly,
// off-peak, age-guarded against in-flight uploads.
Schedule::command('media:gc-orphaned-video-artifacts')
    ->weeklyOn(0, '04:20') // Sunday 04:20 UTC — off-peak for AU/NZ.
    ->onOneServer()
    ->withoutOverlapping(120)
    ->runInBackground()
    ->onFailure($reportScheduledFailure('media:gc-orphaned-video-artifacts'));

// DINT-6: weekly erasure of non-account reporter PII (reporter_email, reason_details,
// signal_data) on case_signals whose parent case resolved more than 90 days ago.
// Account-reporter PII is handled at deletion time by AccountDeletionService::purgeCaseSignalPii().
// Sunday 04:40 UTC — after the other Sunday weekly sweeps (04:00 KV, 04:10 subs, 04:20 video GC, 04:30 early access).
// withoutOverlapping(60) — single bulk update on a small T&S table; completes in seconds.
Schedule::command('moderation:prune-resolved-signal-pii')
    ->weeklyOn(0, '04:40')
    ->onOneServer()
    ->withoutOverlapping(60)
    ->runInBackground()
    ->onFailure($reportScheduledFailure('moderation:prune-resolved-signal-pii'));

// PRIV-8: weekly hard-delete of core.feedback submissions older than the retention
// window (default 365d, any triage status) — nothing else ages this table out.
// Sunday 04:50 UTC — last of the Sunday weekly sweeps. withoutOverlapping(60) —
// batched delete on a small T&S-adjacent table; expected to complete in seconds.
Schedule::command('feedback:prune-old-submissions')
    ->weeklyOn(0, '04:50')
    ->onOneServer()
    ->withoutOverlapping(60)
    ->runInBackground()
    ->onFailure($reportScheduledFailure('feedback:prune-old-submissions'));

// Scan open/triaged/under_review moderation cases and log warnings for any approaching
// their SLA deadline. Threshold defaults to 120 min; configurable via
// partna.moderation.sla.breach_warning_min. withoutOverlapping(30) gives a 2x ceiling
// over the 15-min cadence with headroom for a slow Postgres scan.
Schedule::command('moderation:sla-scan')
    ->everyFifteenMinutes()
    ->onOneServer()
    ->withoutOverlapping(30) // 30min lock — 2x the 15min cadence to prevent same-tick races.
    ->onFailure($reportScheduledFailure('moderation:sla-scan'));

// Self-heal transient menu scrapes: re-dispatch a forced MenuFetchJob for menus
// whose Uber Eats / DoorDash scrape came back 'unavailable' (flaky bot-block),
// bounded to a recent window so a dead store isn't retried forever. Every 15 min
// gives a transient block several chances to clear within the window.
// SCALE-4: budget-paced (stops when ApifyBudget::remaining('menu') = 0) and
// staggered (6s/job) to avoid bursting the Apify queue; default limit is 50.
Schedule::command('menu:retry-unavailable')
    ->everyFifteenMinutes()
    ->onOneServer()
    ->withoutOverlapping(30) // 30min lock — 2x the 15min cadence to prevent same-tick races.
    ->runInBackground()
    ->onFailure($reportScheduledFailure('menu:retry-unavailable'));
