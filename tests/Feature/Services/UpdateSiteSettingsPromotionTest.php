<?php

// FOUND-16 Phase 2: verifies that UpdateSiteAction hoists the 5 promoted
// settings.* keys into their typed columns AND strips them from the settings
// JSONB. Columns are the sole write target after migration 20260701200000.

use App\Services\Cache\SiteCacheService;
use App\Services\Site\UpdateSiteAction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    // createTenant calls tenantHelpersEnsureTables() which sets up all required tables.
    // Stub the cache service so the SiteObserver's invalidateSite() on save is a
    // no-op, and fake the queue so the observer's KV-sync dispatches don't run.
    $cache = Mockery::mock(SiteCacheService::class)->shouldIgnoreMissing();
    app()->instance(SiteCacheService::class, $cache);
    Queue::fake();

    Carbon::setTestNow('2026-07-01 10:00:00');
});

afterEach(function () {
    Carbon::setTestNow();
});

it('hoists the 5 settings.* keys into typed columns and strips them from JSONB', function () {
    $pro = createTenant('promote-owner');

    app(UpdateSiteAction::class)->execute($pro, [
        'settings' => [
            'show_branding' => false,
            'charlie_enabled' => true,
            'services_auto_sync_enabled' => true,
            'booking_mode' => 'none',
            'manual_booking_url' => 'https://example.com/manual',
        ],
    ]);

    $row = DB::connection('pgsql')->table('site.sites')->where('id', $pro->site->id)->first();

    // Columns are the sole write target (Phase 2 — strip active).
    expect((int) $row->show_branding)->toBe(0)
        ->and((int) $row->charlie_enabled)->toBe(1)
        ->and((int) $row->services_auto_sync_enabled)->toBe(1)
        ->and($row->booking_mode)->toBe('none')
        ->and($row->manual_booking_url)->toBe('https://example.com/manual');

    // Phase 2 strip: the 5 keys must NOT appear in the settings JSONB.
    // The views re-inject from columns (migration 20260701200000), so the
    // wire is byte-identical — but the physical JSONB no longer carries them.
    $settings = json_decode($row->settings, true) ?? [];
    expect($settings)->not->toHaveKey('booking_mode')
        ->and($settings)->not->toHaveKey('show_branding')
        ->and($settings)->not->toHaveKey('charlie_enabled')
        ->and($settings)->not->toHaveKey('services_auto_sync_enabled')
        ->and($settings)->not->toHaveKey('manual_booking_url');
});

it('leaves unmentioned columns untouched when only some keys are sent', function () {
    $pro = createTenant('promote-partial');

    // First write — set show_branding and booking_mode
    app(UpdateSiteAction::class)->execute($pro, [
        'settings' => ['show_branding' => false, 'booking_mode' => 'manual'],
    ]);

    // Second write — only change booking_mode; show_branding column must keep false
    $pro->refresh()->load('site');
    app(UpdateSiteAction::class)->execute($pro, [
        'settings' => ['booking_mode' => 'none'],
    ]);

    $row = DB::connection('pgsql')->table('site.sites')->where('id', $pro->site->id)->first();
    expect($row->booking_mode)->toBe('none')
        ->and((int) $row->show_branding)->toBe(0);
});
