<?php

use App\Jobs\Notifications\FanOutBrandStatusNotificationJob;
use App\Jobs\Notifications\NudgeStuckOnboardingJob;
use App\Jobs\Notifications\SendBrandStatusNotificationJob;
use App\Jobs\Notifications\SendTransactionalNotificationEmailJob;
use App\Jobs\Notifications\SendWeeklyAnalyticsNotificationJob;
use App\Services\Accounts\AccountCapabilities;
use App\Services\Notifications\NotificationPublisher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

// Tests for the capability-gating sweep (PR #7 of §28.11).
// Each gated job has: (a) a test that the gate fires on wrong type (no publish)
// and (b) a test that the gate passes on correct type (publish fires).

beforeEach(function () {
    AccountCapabilities::flushCache();
    setupProfessionalsTable();
    attachTestSchemas();
    setupNotificationEmailPoliciesTable();
    setupNotificationEmailPreferencesTable();

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

    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS brand.brand_affiliate_invites (
        id TEXT PRIMARY KEY,
        brand_professional_id TEXT NULL,
        email TEXT NULL,
        first_name TEXT NULL,
        status TEXT NULL,
        expires_at TEXT NULL,
        created_at TEXT NULL,
        updated_at TEXT NULL
    )');

    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS brand.brand_partner_links (
        id TEXT PRIMARY KEY,
        brand_professional_id TEXT NULL,
        affiliate_professional_id TEXT NULL,
        deleted_at TEXT NULL,
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

afterEach(function () {
    AccountCapabilities::flushCache();
});

// ─── Helper ──────────────────────────────────────────────────────────────────

function insertPro(string $accountType, ?string $id = null): string
{
    $id ??= (string) Str::uuid();
    $now = now()->toDateTimeString();
    DB::connection('pgsql')->table('core.professionals')->insert([
        'id' => $id,
        'handle' => 'pro-'.substr($id, 0, 8),
        'handle_lc' => 'pro-'.substr($id, 0, 8),
        'display_name' => ucfirst($accountType).' Pro',
        'professional_type' => $accountType === 'brand' ? 'brand' : 'affiliate',
        'account_type' => $accountType,
        'status' => 'active',
        'primary_email' => 'pro-'.$accountType.'@example.test',
        'has_historical_partner_links' => 0,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return $id;
}

// ─── FanOutBrandStatusNotificationJob ────────────────────────────────────────

it('FanOutBrandStatusNotificationJob: skips fan-out when sender is not a brand', function () {
    Log::spy();

    $proId = insertPro('individual');

    $job = new FanOutBrandStatusNotificationJob($proId, 'live');
    $job->handle();

    Log::shouldHaveReceived('warning')
        ->atLeast()->once()
        ->withArgs(fn (string $msg, array $ctx) => str_contains($msg, 'sender is not a brand')
            && $ctx['brand_professional_id'] === $proId);
});

it('FanOutBrandStatusNotificationJob: proceeds when sender is a brand', function () {
    $brandId = insertPro('brand');

    // No affiliates linked — fan-out runs but dispatches no batches.
    // Test proves the brand-identity guard doesn't block a real brand.
    $job = new FanOutBrandStatusNotificationJob($brandId, 'live');

    // Should complete without exception and without warning about identity gate
    Log::spy();
    $job->handle();

    Log::shouldNotHaveReceived('warning', fn ($msg) => str_contains($msg, 'sender is not a brand'));
});

// ─── SendBrandStatusNotificationJob ──────────────────────────────────────────

it('SendBrandStatusNotificationJob: skips publish when affiliate lacks receives_brand_status_notifications', function () {
    Log::spy();

    // Individual has receives_brand_status_notifications = false (§9 matrix)
    $proId = insertPro('individual');

    $publisher = Mockery::mock(NotificationPublisher::class);
    $publisher->shouldNotReceive('publish');

    $job = new SendBrandStatusNotificationJob(
        affiliateProfessionalId: $proId,
        brandProfessionalId: (string) Str::uuid(),
        brandName: 'Test Brand',
        brandStatus: 'live',
        yearWeek: now()->format('o-W'),
    );
    $job->handle($publisher);

    Log::shouldHaveReceived('debug')
        ->atLeast()->once()
        ->withArgs(fn (string $msg, array $ctx) => str_contains($msg, 'capability gate skip')
            && $ctx['capability'] === 'receives_brand_status_notifications'
            && $ctx['professional_id'] === $proId);
});

it('SendBrandStatusNotificationJob: publishes when affiliate has receives_brand_status_notifications', function () {
    // Partner has receives_brand_status_notifications = true (§9 matrix)
    $proId = insertPro('partner');

    $publisher = Mockery::mock(NotificationPublisher::class);
    $publisher->shouldReceive('publish')->once();

    $job = new SendBrandStatusNotificationJob(
        affiliateProfessionalId: $proId,
        brandProfessionalId: (string) Str::uuid(),
        brandName: 'Test Brand',
        brandStatus: 'live',
        yearWeek: now()->format('o-W'),
    );
    $job->handle($publisher);
});

// ─── SendTransactionalNotificationEmailJob ────────────────────────────────────

it('SendTransactionalNotificationEmailJob: skips when individual lacks capability for gated category', function () {
    Log::spy();

    config(['partna.notifications.email_enabled' => true]);

    $proId = insertPro('individual');

    // `commissions` is a real registered category with a real mailable class
    // — no config monkeying needed. Individual lacks
    // receives_commission_notifications so the gate must fire.
    $job = new SendTransactionalNotificationEmailJob(
        notificationId: (string) Str::uuid(),
        category: 'commissions',
        professionalId: $proId,
    );
    $job->handle();

    Log::shouldHaveReceived('debug')
        ->atLeast()->once()
        ->withArgs(fn (string $msg, array $ctx) => str_contains($msg, 'capability gate')
            && ($ctx['capability'] ?? null) === 'receives_commission_notifications'
            && ($ctx['professional_id'] ?? null) === $proId);
});

it('SendTransactionalNotificationEmailJob: fails closed when professional is missing for gated category', function () {
    Log::spy();

    config(['partna.notifications.email_enabled' => true]);

    // Missing professional ID + gated category → silent skip; never reaches mailer.
    $job = new SendTransactionalNotificationEmailJob(
        notificationId: (string) Str::uuid(),
        category: 'commissions',
        professionalId: (string) Str::uuid(),
    );
    $job->handle();

    Log::shouldHaveReceived('debug')
        ->atLeast()->once()
        ->withArgs(fn (string $msg, array $ctx) => str_contains($msg, 'capability gate')
            && ($ctx['professional_found'] ?? null) === false);
});

it('SendTransactionalNotificationEmailJob: does not apply gate for ungated category', function () {
    Log::spy();

    // email_enabled ON, mailable registered → execution reaches the gate map.
    // analytics_weekly is intentionally ungated → no capability-gate log fires.
    // Job will eventually fail() on missing notification row; we only assert
    // the gate didn't fire.
    config(['partna.notifications.email_enabled' => true]);
    $proId = insertPro('individual');

    $job = new SendTransactionalNotificationEmailJob(
        notificationId: (string) Str::uuid(),
        category: 'analytics_weekly',
        professionalId: $proId,
    );
    try {
        $job->handle();
    } catch (\Throwable) {
        // downstream failures (missing notification row, missing mailable args)
        // are irrelevant — we only care the gate did not fire.
    }

    Log::shouldNotHaveReceived('debug', fn ($msg, $ctx) => isset($ctx['capability']) && str_contains($msg, 'capability gate'));
});

// ─── SendWeeklyAnalyticsNotificationJob ──────────────────────────────────────

it('SendWeeklyAnalyticsNotificationJob: does not notify individual professionals', function () {
    $indId = insertPro('individual');
    $now = now()->toDateTimeString();

    // Give the individual an order that would trigger a notification if queried
    DB::connection('pgsql')->table('commerce.orders')->insert([
        'id' => (string) Str::uuid(),
        'affiliate_professional_id' => $indId,
        'brand_professional_id' => (string) Str::uuid(),
        'status' => 'paid',
        'commission_cents' => 500,
        'occurred_at' => now()->subDays(3)->toDateTimeString(),
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $publisher = Mockery::mock(NotificationPublisher::class);
    $publisher->shouldNotReceive('publish');

    $job = new SendWeeklyAnalyticsNotificationJob;
    $job->handle($publisher);
});

it('SendWeeklyAnalyticsNotificationJob: notifies partner professionals with activity', function () {
    $partnerId = insertPro('partner');
    $now = now()->toDateTimeString();

    DB::connection('pgsql')->table('commerce.orders')->insert([
        'id' => (string) Str::uuid(),
        'affiliate_professional_id' => $partnerId,
        'brand_professional_id' => (string) Str::uuid(),
        'status' => 'paid',
        'commission_cents' => 1000,
        'occurred_at' => now()->subDays(3)->toDateTimeString(),
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $publisher = Mockery::mock(NotificationPublisher::class);
    $publisher->shouldReceive('publish')->once();

    $job = new SendWeeklyAnalyticsNotificationJob;
    $job->handle($publisher);
});

// ─── NudgeStuckOnboardingJob ──────────────────────────────────────────────────

it('NudgeStuckOnboardingJob: reads account_type column for brand filter', function () {
    $brandId = insertPro('brand');

    // Created exactly 3 days ago to hit the day-3 milestone window
    $created = now()->subDays(4)->toDateTimeString(); // subDays(3+1) = windowStart
    DB::connection('pgsql')->table('core.professionals')
        ->where('id', $brandId)
        ->update(['created_at' => $created]);

    $publisher = Mockery::mock(NotificationPublisher::class);
    $publisher->shouldReceive('publish')->once();

    $job = new NudgeStuckOnboardingJob;
    $job->handle($publisher);
});

it('NudgeStuckOnboardingJob: does not nudge individual professionals', function () {
    $indId = insertPro('individual');
    $created = now()->subDays(4)->toDateTimeString();
    DB::connection('pgsql')->table('core.professionals')
        ->where('id', $indId)
        ->update(['created_at' => $created]);

    $publisher = Mockery::mock(NotificationPublisher::class);
    $publisher->shouldNotReceive('publish');

    $job = new NudgeStuckOnboardingJob;
    $job->handle($publisher);
});
