<?php

// FOUND-16: verifies that UpdateSiteAction hoists the 10 promoted settings.*
// keys into their typed columns (dual-write Phase 1), so columns and the
// settings JSONB are both populated after a write.

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

it('hoists the 10 settings.* keys into typed columns (dual-write)', function () {
    $pro = createTenant('promote-owner');

    app(UpdateSiteAction::class)->execute($pro, [
        'settings' => [
            'hero_title' => 'Hi',
            'hero_subtitle' => 'Sub',
            'primary_button_text' => 'Book',
            'primary_button_url' => 'https://example.com/book',
            'bio_text' => 'About me',
            'show_branding' => false,
            'charlie_enabled' => true,
            'services_auto_sync_enabled' => true,
            'booking_mode' => 'none',
            'manual_booking_url' => 'https://example.com/manual',
        ],
    ]);

    $row = DB::connection('pgsql')->table('site.sites')->where('id', $pro->site->id)->first();

    expect($row->hero_title)->toBe('Hi')
        ->and($row->hero_subtitle)->toBe('Sub')
        ->and($row->primary_button_text)->toBe('Book')
        ->and($row->primary_button_url)->toBe('https://example.com/book')
        ->and($row->bio_text)->toBe('About me')
        ->and((int) $row->show_branding)->toBe(0)
        ->and((int) $row->charlie_enabled)->toBe(1)
        ->and((int) $row->services_auto_sync_enabled)->toBe(1)
        ->and($row->booking_mode)->toBe('none')
        ->and($row->manual_booking_url)->toBe('https://example.com/manual');

    // Dual-write: the JSONB settings key must also still be populated (Phase 1).
    $settings = json_decode($row->settings, true);
    expect($settings['booking_mode'])->toBe('none')
        ->and($settings['hero_title'])->toBe('Hi');
});

it('leaves unmentioned columns untouched when only some keys are sent', function () {
    $pro = createTenant('promote-partial');

    // First write — set hero_title and booking_mode
    app(UpdateSiteAction::class)->execute($pro, [
        'settings' => ['hero_title' => 'First', 'booking_mode' => 'manual'],
    ]);

    // Second write — only change booking_mode; hero_title column must keep 'First'
    $pro->refresh()->load('site');
    app(UpdateSiteAction::class)->execute($pro, [
        'settings' => ['booking_mode' => 'none'],
    ]);

    $row = DB::connection('pgsql')->table('site.sites')->where('id', $pro->site->id)->first();
    expect($row->booking_mode)->toBe('none')
        ->and($row->hero_title)->toBe('First');
});
