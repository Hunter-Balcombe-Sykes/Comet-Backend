<?php

use App\Jobs\Notifications\NudgeStuckOnboardingJob;
use App\Jobs\Notifications\SendWeeklyAnalyticsNotificationJob;
use App\Services\Cache\CacheLockService;
use App\Services\Notifications\CommerceNotificationService;
use App\Services\Notifications\NotificationPublisher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Str;

// Verifies that per-record exceptions in notification sweeps are reported to
// Nightwatch (report($e)) rather than logged-and-swallowed silently.
//
// Before the fix: Log::warning(...) only — invisible to Nightwatch alerts.
// After: report($e) alongside the warning log.

beforeEach(function () {
    setupProfessionalsTable();
    attachTestSchemas();
    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS notifications.in_app_notifications (
        id TEXT PRIMARY KEY,
        professional_id TEXT NULL,
        frontend_type TEXT NULL,
        category TEXT NULL,
        title TEXT NULL,
        body TEXT NULL,
        dedupe_key TEXT NULL,
        cta_url TEXT NULL,
        primary_action_label TEXT NULL,
        retention_config_key TEXT NULL,
        is_read INTEGER NULL DEFAULT 0,
        read_at TEXT NULL,
        expires_at TEXT NULL,
        created_at TEXT NULL,
        updated_at TEXT NULL
    )');
    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS brand.brand_profiles (
        id TEXT PRIMARY KEY,
        professional_id TEXT NULL,
        brand_status TEXT NULL,
        created_at TEXT NULL,
        updated_at TEXT NULL
    )');
    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS commerce.orders (
        id TEXT PRIMARY KEY,
        affiliate_professional_id TEXT NULL,
        brand_professional_id TEXT NULL,
        status TEXT NULL,
        occurred_at TEXT NULL,
        commission_cents INTEGER NULL DEFAULT 0,
        created_at TEXT NULL,
        updated_at TEXT NULL
    )');
});

it('reports the exception when CommerceNotificationService notifyBookingCompleted fails', function () {
    Exceptions::fake();

    $publisher = Mockery::mock(NotificationPublisher::class);
    $publisher->shouldReceive('publish')->andThrow(new \RuntimeException('DB write failed'));

    $cacheLock = app(CacheLockService::class);
    $service = new CommerceNotificationService($publisher, $cacheLock);

    // Should not throw — exception is caught internally
    $service->notifyBookingCompleted([
        'professional_id' => (string) Str::uuid(),
        'booking_event_id' => (string) Str::uuid(),
    ]);

    Exceptions::assertReported(\RuntimeException::class);
});

it('reports per-record exception in NudgeStuckOnboardingJob without killing the sweep', function () {
    Exceptions::fake();

    $proId = (string) Str::uuid();
    $now = now()->toDateTimeString();
    $created = now()->subDays(3)->toDateTimeString();

    DB::connection('pgsql')->table('core.professionals')->insert([
        'id' => $proId,
        'handle' => 'brand-nudge-'.substr($proId, 0, 8),
        'handle_lc' => 'brand-nudge-'.substr($proId, 0, 8),
        'display_name' => 'Nudge Brand',
        'professional_type' => 'brand',
        'status' => 'active',
        'primary_email' => 'nudge@example.test',
        'created_at' => $created,
        'updated_at' => $now,
    ]);

    $publisher = Mockery::mock(NotificationPublisher::class);
    $publisher->shouldReceive('publish')->andThrow(new \RuntimeException('Notification write failed'));

    $job = new NudgeStuckOnboardingJob;

    // Should not throw — per-record exceptions must be isolated
    $job->handle($publisher);

    Exceptions::assertReported(\RuntimeException::class);
});

it('reports per-professional exception in SendWeeklyAnalyticsNotificationJob without killing the sweep', function () {
    Exceptions::fake();

    $proId = (string) Str::uuid();
    $now = now()->toDateTimeString();

    DB::connection('pgsql')->table('core.professionals')->insert([
        'id' => $proId,
        'handle' => 'pro-weekly-'.substr($proId, 0, 8),
        'handle_lc' => 'pro-weekly-'.substr($proId, 0, 8),
        'display_name' => 'Weekly Brand',
        'professional_type' => 'brand',
        'account_type' => 'brand',
        'status' => 'active',
        'primary_email' => 'weekly@example.test',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    // Insert an order so the professional gets a non-zero metrics row
    // (the job only notifies when orders > 0 or commissionCents > 0)
    $orderId = (string) Str::uuid();
    DB::connection('pgsql')->table('commerce.orders')->insert([
        'id' => $orderId,
        'affiliate_professional_id' => $proId,
        'brand_professional_id' => (string) Str::uuid(),
        'status' => 'paid',
        'commission_cents' => 1000,
        'occurred_at' => now()->subDays(3)->toDateTimeString(),
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $publisher = Mockery::mock(NotificationPublisher::class);
    $publisher->shouldReceive('publish')->andThrow(new \RuntimeException('Notification publish failed'));

    $job = new SendWeeklyAnalyticsNotificationJob;

    // Should not throw — per-professional exceptions must not kill the whole sweep
    $job->handle($publisher);

    Exceptions::assertReported(\RuntimeException::class);
});
