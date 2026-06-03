<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
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

Schedule::command('partna:purge-soft-deletes')
    ->dailyAt('03:20')
    ->onOneServer()
    ->withoutOverlapping(600) // 10h lock — historical purges on large tables can run long.
    ->runInBackground()
    ->onFailure(function (?\Throwable $e = null): void {
        \Illuminate\Support\Facades\Log::error('Scheduled task failed: purge-soft-deletes', [
            'exception' => $e ? get_class($e) : null,
            'message' => $e?->getMessage(),
        ]);
    });

Schedule::command('partna:prune-notifications', ['--days' => 30])
    ->dailyAt('03:25')
    ->onOneServer()
    ->withoutOverlapping(120) // 2h lock — bounded by retention-window batch size.
    ->runInBackground()
    ->onFailure(function (?\Throwable $e = null): void {
        \Illuminate\Support\Facades\Log::error('Scheduled task failed: prune-notifications', [
            'exception' => $e ? get_class($e) : null,
            'message' => $e?->getMessage(),
        ]);
    });

Schedule::command('partna:analytics:purge-raw-events')
    ->dailyAt('03:00')
    ->onOneServer()
    ->withoutOverlapping(30) // 30min lock — partition-scoped DELETE; daily cadence.
    ->runInBackground()
    ->onFailure(function (?\Throwable $e = null): void {
        \Illuminate\Support\Facades\Log::error('Scheduled task failed: purge-raw-events', [
            'exception' => $e ? get_class($e) : null,
            'message' => $e?->getMessage(),
        ]);
    });

// Smart-link snapshot refresh — commerce stales at 6h, content weekly; the
// command picks the stalest rows each run (capped via --limit). Every 6h is
// frequent enough for the 6h commerce tier without hammering merchant sites.
Schedule::command('smartlinks:refresh')
    ->everySixHours()
    ->runInBackground()
    ->onOneServer()
    ->withoutOverlapping(60)
    ->onFailure(function (?\Throwable $e = null): void {
        \Illuminate\Support\Facades\Log::error('Scheduled task failed: smartlinks:refresh', [
            'exception' => $e ? get_class($e) : null,
            'message' => $e?->getMessage(),
        ]);
    });

// Pilot platform refresh — re-fetch the auto-content platforms (YouTube latest,
// Eventbrite events, Apple latest release) daily so sitepages show fresh data
// without the user re-connecting. Static links + costly/multi-step platforms are
// excluded by the command (see PlatformRefresher).
Schedule::command('integrations:refresh')
    ->dailyAt('03:40')
    ->runInBackground()
    ->onOneServer()
    ->withoutOverlapping(60)
    ->onFailure(function (?\Throwable $e = null): void {
        \Illuminate\Support\Facades\Log::error('Scheduled task failed: integrations:refresh', [
            'exception' => $e ? get_class($e) : null,
            'message' => $e?->getMessage(),
        ]);
    });

Schedule::command('queue:prune-failed --hours=72')
    ->daily()
    ->onOneServer()
    ->withoutOverlapping(60) // 60min lock — proportional to failed_jobs table size.
    ->runInBackground()
    ->onFailure(function (?\Throwable $e = null): void {
        \Illuminate\Support\Facades\Log::error('Scheduled task failed: prune-failed-jobs', [
            'exception' => $e ? get_class($e) : null,
            'message' => $e?->getMessage(),
        ]);
    });

// Reads the previous hour's cache hit/miss Redis counters, logs structured metrics,
// and reports SLO violations (hot prefixes below 90% hit rate) to Nightwatch.
Schedule::job(new \App\Jobs\Cache\AggregateCacheMetricsJob)
    ->hourly()
    ->onOneServer()
    ->withoutOverlapping(10) // 10min lock — read-only Redis aggregation, completes in seconds.
    ->onFailure(function (?\Throwable $e = null): void {
        \Illuminate\Support\Facades\Log::error('Scheduled task failed: aggregate-cache-metrics', [
            'exception' => $e ? get_class($e) : null,
            'message' => $e?->getMessage(),
        ]);
    });

// Snapshots queue throughput / runtime metrics into Redis so the Horizon
// Metrics tab has data to render.
Schedule::command('horizon:snapshot')
    ->everyFiveMinutes()
    ->onOneServer()
    ->withoutOverlapping(10) // 10min lock — 2x everyFiveMinutes cadence safety ceiling.
    ->onFailure(function (?\Throwable $e = null): void {
        \Illuminate\Support\Facades\Log::error('Scheduled task failed: horizon-snapshot', [
            'exception' => $e ? get_class($e) : null,
            'message' => $e?->getMessage(),
        ]);
    });

// withoutOverlapping(5) gives a 2.5x ceiling over the everyTwoMinutes cadence. The
// prior value (2) equalled the cadence, creating a same-tick race: lock TTL expiry
// and the next dispatch happen at the same instant, so a slow run could collide
// with itself. N must exceed the cadence for high-frequency tasks.
Schedule::job(new \App\Jobs\Streaming\CheckStreamingLiveStatusJob)
    ->everyTwoMinutes()
    ->onOneServer()
    ->withoutOverlapping(5)
    ->onFailure(function (?\Throwable $e = null): void {
        \Illuminate\Support\Facades\Log::error('Scheduled task failed: check-streaming-live-status', [
            'exception' => $e ? get_class($e) : null,
            'message' => $e?->getMessage(),
        ]);
    });

// Handle/subdomain alias lifecycle: hard-deletes expired alias rows daily.
Schedule::command('handles:prune-expired-aliases')
    ->dailyAt('03:15')
    ->onOneServer()
    ->withoutOverlapping(120)
    ->runInBackground()
    ->onFailure(function (?\Throwable $e = null): void {
        \Illuminate\Support\Facades\Log::error('Scheduled task failed: prune-expired-aliases', [
            'exception' => $e ? get_class($e) : null,
            'message' => $e?->getMessage(),
        ]);
    });

// Notifies alias holders of upcoming expiry (T-3/T-1 day warnings).
Schedule::command('handles:notify-expiry')
    ->dailyAt('09:00')
    ->onOneServer()
    ->withoutOverlapping(60) // 60min lock — closes a race between application-level whereNull guards on the notified_t* stamp columns.
    ->runInBackground()
    ->onFailure(function (?\Throwable $e = null): void {
        \Illuminate\Support\Facades\Log::error('Scheduled task failed: handles-notify-expiry', [
            'exception' => $e ? get_class($e) : null,
            'message' => $e?->getMessage(),
        ]);
    });

Schedule::command('feature-flags:prune-expired')
    ->dailyAt('03:30')
    ->withoutOverlapping(30)
    ->onOneServer()
    ->runInBackground()
    ->onFailure(function (?\Throwable $e = null): void {
        \Illuminate\Support\Facades\Log::error('Scheduled task failed: feature-flags:prune-expired', [
            'exception' => $e ? get_class($e) : null,
            'message' => $e?->getMessage(),
        ]);
    });

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
        \Illuminate\Support\Facades\Http::timeout(3)->retry(1, 200)->get($url);
    } catch (\Throwable $e) {
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
    ->onFailure(function (?\Throwable $e = null): void {
        \Illuminate\Support\Facades\Log::error('Scheduled task failed: backfill-subdomain-kv', [
            'exception' => $e ? get_class($e) : null,
            'message' => $e?->getMessage(),
        ]);
    });

// P2-14: daily watchdog for ExportUserDataJob rows orphaned in PROCESSING by SIGKILL.
// failed() only fires on retry exhaustion, never on a hard kill, so a worker death
// between markProcessing() and completion leaves the audit row stuck forever.
Schedule::command('gdpr:sweep-stale-exports')
    ->dailyAt('03:35')
    ->onOneServer()
    ->withoutOverlapping(60) // 60min lock — export audit table is tiny; completes in seconds.
    ->runInBackground()
    ->onFailure(function (?\Throwable $e = null): void {
        \Illuminate\Support\Facades\Log::error('Scheduled task failed: gdpr:sweep-stale-exports', [
            'exception' => $e ? get_class($e) : null,
            'message' => $e?->getMessage(),
        ]);
    });

// QUEUE-5: hourly watchdog for SiteMedia rows orphaned in PROCESSING.
Schedule::command('media:cleanup-stuck-processing')
    ->hourly()
    ->onOneServer()
    ->withoutOverlapping(30) // 30min lock — Postgres lookup + queue dispatch, typically seconds.
    ->onFailure(function (?\Throwable $e = null): void {
        \Illuminate\Support\Facades\Log::error('Scheduled task failed: media:cleanup-stuck-processing', [
            'exception' => $e ? get_class($e) : null,
            'message' => $e?->getMessage(),
        ]);
    });

// Scan open/triaged/under_review moderation cases and log warnings for any approaching
// their SLA deadline. Threshold defaults to 120 min; configurable via
// partna.moderation.sla.breach_warning_min. withoutOverlapping(30) gives a 2x ceiling
// over the 15-min cadence with headroom for a slow Postgres scan.
Schedule::command('moderation:sla-scan')
    ->everyFifteenMinutes()
    ->onOneServer()
    ->withoutOverlapping(30) // 30min lock — 2x the 15min cadence to prevent same-tick races.
    ->onFailure(function (?\Throwable $e = null): void {
        \Illuminate\Support\Facades\Log::error('Scheduled task failed: moderation:sla-scan', [
            'exception' => $e ? get_class($e) : null,
            'message' => $e?->getMessage(),
        ]);
    });
