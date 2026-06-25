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

Schedule::command('partna:analytics:purge-raw-events')
    ->dailyAt('03:00')
    ->onOneServer()
    ->withoutOverlapping(30) // 30min lock — partition-scoped DELETE; daily cadence.
    ->runInBackground()
    ->onFailure($reportScheduledFailure('purge-raw-events'));

// Smart-link snapshot refresh — commerce stales at 6h, content weekly; the
// command picks the stalest rows each run (capped via --limit). Every 6h is
// frequent enough for the 6h commerce tier without hammering merchant sites.
Schedule::command('smartlinks:refresh')
    ->everySixHours()
    ->runInBackground()
    ->onOneServer()
    ->withoutOverlapping(60)
    ->onFailure($reportScheduledFailure('smartlinks:refresh'));

// Pilot platform refresh — re-fetch the auto-content platforms (YouTube latest,
// Eventbrite events, Apple latest release) daily so sitepages show fresh data
// without the user re-connecting. Static links + costly/multi-step platforms are
// excluded by the command (see PlatformRefresher).
Schedule::command('integrations:refresh')
    ->dailyAt('03:40')
    ->runInBackground()
    ->onOneServer()
    ->withoutOverlapping(60)
    ->onFailure($reportScheduledFailure('integrations:refresh'));

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

Schedule::command('feature-flags:prune-expired')
    ->dailyAt('03:30')
    ->withoutOverlapping(30)
    ->onOneServer()
    ->runInBackground()
    ->onFailure($reportScheduledFailure('feature-flags:prune-expired'));

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
Schedule::command('menu:retry-unavailable')
    ->everyFifteenMinutes()
    ->onOneServer()
    ->withoutOverlapping(30) // 30min lock — 2x the 15min cadence to prevent same-tick races.
    ->runInBackground()
    ->onFailure($reportScheduledFailure('menu:retry-unavailable'));
