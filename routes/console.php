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
    ->onFailure(function (): void {
        \Illuminate\Support\Facades\Log::error('Scheduled task failed: purge-soft-deletes');
    });

Schedule::command('partna:prune-notifications', ['--days' => 30])
    ->dailyAt('03:25')
    ->onOneServer()
    ->withoutOverlapping(120) // 2h lock — bounded by retention-window batch size.
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
    ->onFailure(function (): void {
        \Illuminate\Support\Facades\Log::error('Scheduled task failed: purge-raw-events');
    });

Schedule::command('queue:prune-failed --hours=72')
    ->daily()
    ->onOneServer()
    ->withoutOverlapping(60) // 60min lock — proportional to failed_jobs table size.
    ->onFailure(function (): void {
        \Illuminate\Support\Facades\Log::error('Scheduled task failed: prune-failed-jobs');
    });

// Reads the previous hour's cache hit/miss Redis counters, logs structured metrics,
// and reports SLO violations (hot prefixes below 90% hit rate) to Nightwatch.
Schedule::job(new \App\Jobs\Cache\AggregateCacheMetricsJob)
    ->hourly()
    ->onOneServer()
    ->withoutOverlapping(10) // 10min lock — read-only Redis aggregation, completes in seconds.
    ->onFailure(function (): void {
        \Illuminate\Support\Facades\Log::error('Scheduled task failed: aggregate-cache-metrics');
    });

// Snapshots queue throughput / runtime metrics into Redis so the Horizon
// Metrics tab has data to render.
Schedule::command('horizon:snapshot')
    ->everyFiveMinutes()
    ->onOneServer()
    ->withoutOverlapping(10) // 10min lock — 2x everyFiveMinutes cadence safety ceiling.
    ->onFailure(function (): void {
        \Illuminate\Support\Facades\Log::error('Scheduled task failed: horizon-snapshot');
    });

// withoutOverlapping(5) gives a 2.5x ceiling over the everyTwoMinutes cadence. The
// prior value (2) equalled the cadence, creating a same-tick race: lock TTL expiry
// and the next dispatch happen at the same instant, so a slow run could collide
// with itself. N must exceed the cadence for high-frequency tasks.
Schedule::job(new \App\Jobs\Streaming\CheckStreamingLiveStatusJob)
    ->everyTwoMinutes()
    ->onOneServer()
    ->withoutOverlapping(5)
    ->onFailure(function (): void {
        \Illuminate\Support\Facades\Log::error('Scheduled task failed: check-streaming-live-status');
    });

// Handle/subdomain alias lifecycle: hard-deletes expired alias rows daily.
Schedule::command('handles:prune-expired-aliases')
    ->dailyAt('03:15')
    ->onOneServer()
    ->withoutOverlapping()
    ->runInBackground()
    ->onFailure(function (): void {
        \Illuminate\Support\Facades\Log::error('Scheduled task failed: prune-expired-aliases');
    });

// Notifies alias holders of upcoming expiry (T-3/T-1 day warnings).
Schedule::command('handles:notify-expiry')
    ->dailyAt('09:00')
    ->onOneServer()
    ->withoutOverlapping(60) // 60min lock — closes a race between application-level whereNull guards on the notified_t* stamp columns.
    ->runInBackground()
    ->onFailure(function (): void {
        \Illuminate\Support\Facades\Log::error('Scheduled task failed: handles-notify-expiry');
    });

Schedule::command('feature-flags:prune-expired')
    ->dailyAt('03:30')
    ->withoutOverlapping()
    ->onOneServer()
    ->onFailure(function (): void {
        \Illuminate\Support\Facades\Log::error('Scheduled task failed: feature-flags:prune-expired');
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
    ->withoutOverlapping()
    ->description('Weekly resync of Cloudflare KV subdomain routing entries')
    ->onFailure(function (): void {
        \Illuminate\Support\Facades\Log::error('Scheduled task failed: backfill-subdomain-kv');
    });

// QUEUE-5: hourly watchdog for SiteMedia rows orphaned in PROCESSING.
Schedule::command('media:cleanup-stuck-processing')
    ->hourly()
    ->onOneServer()
    ->withoutOverlapping(30) // 30min lock — Postgres lookup + queue dispatch, typically seconds.
    ->onFailure(function (): void {
        \Illuminate\Support\Facades\Log::error('Scheduled task failed: media:cleanup-stuck-processing');
    });

// Scan open/triaged/under_review moderation cases and log warnings for any approaching
// their SLA deadline. Threshold defaults to 120 min; configurable via
// partna.moderation.sla.breach_warning_min. withoutOverlapping(30) gives a 2x ceiling
// over the 15-min cadence with headroom for a slow Postgres scan.
Schedule::command('moderation:sla-scan')
    ->everyFifteenMinutes()
    ->onOneServer()
    ->withoutOverlapping(30) // 30min lock — 2x the 15min cadence to prevent same-tick races.
    ->onFailure(function (): void {
        \Illuminate\Support\Facades\Log::error('Scheduled task failed: moderation:sla-scan');
    });

// Poll Cloudflare CSAM scan status for site_media rows stuck in 'scanning'.
// Promotes clean objects from quarantine to the production bucket.
// withoutOverlapping(3) gives a 3x ceiling over the everyMinute cadence —
// the batch is bounded to 100 rows so a slow run won't cause same-tick races
// under normal load; the 3-min ceiling prevents queue buildup if CF is slow.
Schedule::job(new \App\Jobs\Moderation\PromoteCleanMediaJob)
    ->everyMinute()
    ->onOneServer()
    ->withoutOverlapping(3) // 3min lock — 3x the 1min cadence safety ceiling.
    ->onFailure(function (): void {
        \Illuminate\Support\Facades\Log::error('Scheduled task failed: moderation:promote-clean-media');
    });

// Hard-delete R2 quarantine binaries once the 90-day legal-hold window passes.
// Daily cadence; runInBackground() prevents blocking the per-minute tick for
// large batches. withoutOverlapping(600) guards against a slow R2 delete run
// carrying over to the next day.
Schedule::command('moderation:expire-csam-quarantine')
    ->dailyAt('03:00')
    ->onOneServer()
    ->withoutOverlapping(600) // 10h lock — R2 deletes can be slow for large batches.
    ->runInBackground()
    ->onFailure(function (): void {
        \Illuminate\Support\Facades\Log::error('Scheduled task failed: moderation:expire-csam-quarantine');
    });

// Verify that the quarantine R2 bucket has not drifted to public-access.
// Fires once daily; withoutOverlapping(10) is a safety ceiling — the HTTP
// check to the Cloudflare API completes in under a second under normal conditions.
Schedule::command('moderation:audit-quarantine-bucket')
    ->dailyAt('04:00')
    ->onOneServer()
    ->withoutOverlapping(10) // 10min lock — single HTTP round-trip, completes in seconds.
    ->onFailure(function (): void {
        \Illuminate\Support\Facades\Log::error('Scheduled task failed: moderation:audit-quarantine-bucket');
    });

// Re-dispatch FileCyberTipReportJob for NCMEC submissions stuck in pending/failed
// under the max-attempts ceiling. withoutOverlapping(3) gives a 3x safety margin
// over the everyFiveMinutes cadence — the query is bounded and dispatch is fast.
Schedule::command('moderation:retry-ncmec-submissions')
    ->everyFiveMinutes()
    ->onOneServer()
    ->withoutOverlapping(3) // 3min lock — bounded query + queue dispatch, completes in seconds.
    ->onFailure(function (): void {
        \Illuminate\Support\Facades\Log::error('Scheduled task failed: moderation:retry-ncmec-submissions');
    });
