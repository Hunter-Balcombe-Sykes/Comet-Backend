<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command(
    'partna:normalize-professional-types '.
    '{--dry-run : Show count only without updating} '.
    '{--confirm-large : Acknowledge a count > 1000 and proceed}',
    function () {
        $allowed = ['professional', 'influencer', 'brand'];

        $query = DB::table('professionals')->where(function ($builder) use ($allowed): void {
            $builder
                ->whereNull('professional_type')
                ->orWhereRaw('LOWER(TRIM(professional_type)) NOT IN (?, ?, ?)', $allowed);
        });

        $count = (clone $query)->count();

        if ((bool) $this->option('dry-run')) {
            $this->info("Would normalize {$count} professional record(s) to professional_type=professional.");

            return 0;
        }

        if ($count === 0) {
            $this->info('No professional records required normalization.');

            return 0;
        }

        // Safety guard: a future schema change that adds a new professional_type
        // value would be silently squashed here without this guard. Require an
        // explicit ack when the count exceeds expectations.
        if ($count > 1000 && ! (bool) $this->option('confirm-large')) {
            $this->error(
                "Refusing to normalize {$count} records — that's more than expected. ".
                'Investigate: a new professional_type may have been added to the schema. '.
                'Re-run with --confirm-large to proceed if this is intentional.'
            );

            return 1;
        }

        $updated = $query->update([
            'professional_type' => 'professional',
            'updated_at' => now(),
        ]);

        $this->info("Normalized {$updated} professional record(s) to professional_type=professional.");

        return 0;
    }
)->purpose('Normalize legacy professional_type values to professional.');

Schedule::command('partna:purge-soft-deletes')
    ->dailyAt('03:20')
    ->withoutOverlapping(600)
    ->onFailure(function (): void {
        \Illuminate\Support\Facades\Log::error('Scheduled task failed: purge-soft-deletes');
    });

Schedule::command('partna:prune-notifications', ['--days' => 30])
    ->dailyAt('03:25')
    ->onFailure(function (?\Throwable $e = null): void {
        \Illuminate\Support\Facades\Log::error('Scheduled task failed: prune-notifications', [
            'exception' => $e ? get_class($e) : null,
            'message' => $e?->getMessage(),
        ]);
    });

Schedule::job(new \App\Jobs\Stripe\ProcessCommissionPayoutsJob)
    ->hourly()
    ->withoutOverlapping()
    ->onFailure(function (): void {
        \Illuminate\Support\Facades\Log::error('Scheduled task failed: process-commission-payouts');
    });

// STRP-3: daily Stripe round-trip for payouts stuck in 'processing' beyond the BECS T+2 window.
// Runs after the main sweep so any payouts the sweep just advanced are excluded from the stale query.
Schedule::job(new \App\Jobs\Stripe\ReconcileStuckPayoutsJob)
    ->dailyAt('02:00')
    ->timezone('UTC')
    ->onOneServer()
    ->withoutOverlapping()
    ->onFailure(function (): void {
        \Illuminate\Support\Facades\Log::error('Scheduled task failed: reconcile-stuck-payouts');
    });

// Daily at 06:00 UTC. Voids stale commissions (affiliate-side and brand-side)
// and emits grace-period warnings. Operates on 30-day windows, so hourly cadence
// was wasted load — once a day is the right granularity.
Schedule::job(new \App\Jobs\Stripe\VoidableCommissionsAndWarningsJob)
    ->dailyAt('06:00')
    ->timezone('UTC')
    ->onOneServer()
    ->withoutOverlapping()
    ->onFailure(function (): void {
        \Illuminate\Support\Facades\Log::error('Scheduled task failed: voidable-commissions-and-warnings');
    });

// Closes #CR-003: enforces the 60-day payout grace window the UI promises.
// Runs daily after payout processing. Voiding is keyed off void_at (60-day
// window) so hourly cadence would be noise — once daily is sufficient.
Schedule::job(new \App\Jobs\Stripe\VoidExpiredPayoutsJob)
    ->dailyAt('07:00')
    ->withoutOverlapping(600)
    ->onFailure(function (): void {
        \Illuminate\Support\Facades\Log::error('Scheduled task failed: void-expired-payouts');
    });

// STRP-D: Reconciles payouts stuck in 'processing' that lost their
// payment_intent.succeeded webhook (Stripe retry window exhausted or transient
// handler failure that delete-on-failure couldn't recover from). Daily at 04:00
// UTC — runs AFTER ProcessCommissionPayoutsJob's hourly sweep so any auto-recovery
// from a re-delivered webhook happens first. 3-day threshold (set in the job
// itself) = BECS T+2 + 1 buffer day.
Schedule::job(new \App\Jobs\Stripe\ReconcileStuckPayoutsJob)
    ->dailyAt('04:00')
    ->timezone('UTC')
    ->onOneServer()
    ->withoutOverlapping()
    ->onFailure(function (): void {
        \Illuminate\Support\Facades\Log::error('Scheduled task failed: reconcile-stuck-payouts');
    });

// LIFE-1: Daily reconciliation for Shopify integrations whose app/uninstalled
// webhook was lost. HEAD-checks the Admin API token for every connected
// integration; on 401 or shop-domain mismatch, auto-heals to Disconnected.
// 02:03 UTC chosen to avoid collision with the 02:00 stripe-reconcile slot.
Schedule::job(new \App\Jobs\Shopify\ReconcileStuckShopifyIntegrationsJob)
    ->dailyAt('02:03')
    ->timezone('UTC')
    ->onOneServer()
    ->withoutOverlapping()
    ->onFailure(function (): void {
        \Illuminate\Support\Facades\Log::error('Scheduled task failed: reconcile-stuck-shopify-integrations');
    });

// Pattern A Step 5 (embedded-rework): silent-drift detector for integrations
// where ReconcileStuckShopifyIntegrationsJob nulled the access_token but the
// brand_profile.brand_status update silently no-op'd. Runs 30 minutes after
// the reconcile job so any successful heal in the same daily window is
// observed first. Routes alerts via Nightwatch's exception path; no
// onFailure handler because the command exits clean by design.
Schedule::command('partna:report-stuck-shopify-integrations')
    ->dailyAt('02:30')
    ->timezone('UTC')
    ->onOneServer()
    ->withoutOverlapping();

Schedule::command('partna:analytics:purge-raw-events')
    ->dailyAt('03:00')
    ->withoutOverlapping()
    ->onFailure(function (): void {
        \Illuminate\Support\Facades\Log::error('Scheduled task failed: purge-raw-events');
    });

Schedule::job(new \App\Jobs\Notifications\InviteExpirySweepJob)
    ->dailyAt('08:00')
    ->withoutOverlapping()
    ->onFailure(function (): void {
        \Illuminate\Support\Facades\Log::error('Scheduled task failed: invite-expiry-sweep');
    });

Schedule::job(new \App\Jobs\Notifications\NudgeStuckOnboardingJob)
    ->dailyAt('09:00')
    ->withoutOverlapping(600)
    ->onFailure(function (): void {
        \Illuminate\Support\Facades\Log::error('Scheduled task failed: nudge-stuck-onboarding');
    });

Schedule::job(new \App\Jobs\Notifications\SendWeeklyAnalyticsNotificationJob)
    ->weeklyOn(1, '09:00') // Monday 9 AM UTC
    ->withoutOverlapping()
    ->onFailure(function (): void {
        \Illuminate\Support\Facades\Log::error('Scheduled task failed: send-weekly-analytics-notification');
    });

Schedule::command('queue:prune-failed --hours=72')
    ->daily()
    ->withoutOverlapping()
    ->onFailure(function (): void {
        \Illuminate\Support\Facades\Log::error('Scheduled task failed: prune-failed-jobs');
    });

// Reads the previous hour's cache hit/miss Redis counters, logs structured metrics,
// and reports SLO violations (hot prefixes below 90% hit rate) to Nightwatch.
Schedule::job(new \App\Jobs\Cache\AggregateCacheMetricsJob)
    ->hourly()
    ->withoutOverlapping()
    ->onFailure(function (): void {
        \Illuminate\Support\Facades\Log::error('Scheduled task failed: aggregate-cache-metrics');
    });

// Snapshots queue throughput / runtime metrics into Redis so the Horizon
// Metrics tab has data to render. Without this, "Jobs per minute" and
// "Runtime" graphs stay flat-empty. Five minutes is Horizon's standard cadence.
Schedule::command('horizon:snapshot')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->onFailure(function (): void {
        \Illuminate\Support\Facades\Log::error('Scheduled task failed: horizon-snapshot');
    });

// withoutOverlapping(2) matches the every-2-min cadence: if a run exceeds 2 min
// (should be rare now that both Twitch and Kick use batch endpoints), the next
// scheduler tick skips exactly one cycle rather than stacking.
Schedule::job(new \App\Jobs\Streaming\CheckStreamingLiveStatusJob)
    ->everyTwoMinutes()
    ->withoutOverlapping(2)
    ->onFailure(function (): void {
        \Illuminate\Support\Facades\Log::error('Scheduled task failed: check-streaming-live-status');
    });

// Daily digest of CommissionPayouts with needs_manual_refund=true (mid-flight
// refund + post-payout clawback failure cases). Emits one Log::warning per run
// listing open payouts so ops can triage via Nightwatch alerts.
Schedule::job(new \App\Jobs\Stripe\MonitorManualRefundQueueJob)
    ->dailyAt('08:00')
    ->timezone('UTC')
    ->onOneServer()
    ->withoutOverlapping()
    ->onFailure(function (): void {
        \Illuminate\Support\Facades\Log::error('Scheduled task failed: monitor-manual-refund-queue');
    });

// Phase 3 backstop reconciler. Cron expression is env-overridable: set
// PARTNA_RECONCILER_SCHEDULE='0 * * * *' for the first 60 days post-launch,
// then revert to the default daily-at-3am value.
Schedule::command('feature-flags:prune-expired')
    ->dailyAt('03:30')
    ->withoutOverlapping()
    ->onOneServer()
    ->onFailure(function (): void {
        \Illuminate\Support\Facades\Log::error('Scheduled task failed: feature-flags:prune-expired');
    });

Schedule::command('partna:reconcile-shopify-orders')
    ->cron(config('partna.reconciler.schedule', '0 3 * * *'))
    ->withoutOverlapping(60 * 60) // 1h overlap guard
    ->description('Backstop reconcile of Shopify orders against commerce.orders (Phase 3)')
    ->onFailure(function (): void {
        \Log::error('partna:reconcile-shopify-orders schedule failure');
    });

// Cloudflare KV subdomain routing backstop. The observer keeps KV in sync
// on legitimate handle changes, but anything that bypasses Eloquent (raw
// SQL data fixes, Supabase migrations, manual ops) leaves KV stale. Weekly
// resync is cheap (dispatches one SyncSubdomainToKvJob per pro via the
// queue) and idempotent — each job writes the current state regardless of
// what's already in KV.
Schedule::command('partna:backfill-subdomain-kv', ['--all', '--queue'])
    ->weeklyOn(0, '04:00') // Sunday 04:00 UTC — off-peak for AU/NZ
    ->onOneServer()
    ->withoutOverlapping()
    ->description('Weekly resync of Cloudflare KV subdomain routing entries')
    ->onFailure(function (): void {
        \Illuminate\Support\Facades\Log::error('Scheduled task failed: backfill-subdomain-kv');
    });

// Async commission export maintenance. sweep-stuck flips processing rows older
// than 60 min to failed so the UI never shows a permanently-spinning export.
// prune-expired deletes R2 parts past their TTL and clears file_path so signed
// URLs stop working before the bucket objects disappear.
Schedule::command('commission-exports:sweep-stuck')
    ->hourly()
    ->withoutOverlapping()
    ->onFailure(function (): void {
        \Illuminate\Support\Facades\Log::error('Scheduled task failed: commission-exports:sweep-stuck');
    });

Schedule::command('commission-exports:prune-expired')
    ->dailyAt('03:30')
    ->withoutOverlapping()
    ->onFailure(function (): void {
        \Illuminate\Support\Facades\Log::error('Scheduled task failed: commission-exports:prune-expired');
    });

// QUEUE-5: hourly watchdog for SiteMedia rows orphaned in PROCESSING when an
// image/video variant worker is SIGKILL'd inside its held Redis lock window.
// Flips rows past the worst-case job duration to FAILED so the dashboard never
// shows a permanently-spinning upload.
Schedule::command('media:cleanup-stuck-processing')
    ->hourly()
    ->withoutOverlapping()
    ->onFailure(function (): void {
        \Illuminate\Support\Facades\Log::error('Scheduled task failed: media:cleanup-stuck-processing');
    });
