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

// SEM-10: a stale promoted key can still be sitting in the on-disk settings
// JSONB (e.g. from before the Phase 2 strip, or a write race). The hoist
// loop used to read from $merged (existing ∪ incoming), so that stale value
// clobbered the typed column on every subsequent PATCH — even one that never
// mentioned the key. It must read from $incomingSettings (what THIS request
// actually sent) instead.
it('does not let a stale promoted key in the settings JSONB overwrite a newer typed-column value', function () {
    $pro = createTenant('promote-stale');

    // Simulate a straggler: the JSONB mirror still holds an old booking_mode,
    // but the typed column has since moved on to a newer value. This shape
    // cannot arise from UpdateSiteAction itself post-fix, but a pre-fix write
    // race or a direct DB fix-up could still leave it behind.
    DB::connection('pgsql')->table('site.sites')->where('id', $pro->site->id)->update([
        'settings' => json_encode(['booking_mode' => 'manual', 'keep_me' => 'yes']),
        'booking_mode' => 'none',
    ]);

    // PATCH an unrelated (but KNOWN, non-promoted — #W2-SEC-6 now drops
    // anything else) key — booking_mode is never mentioned by this request.
    app(UpdateSiteAction::class)->execute($pro->fresh()->load('site'), [
        'settings' => ['display_gallery_page' => false],
    ]);

    $row = DB::connection('pgsql')->table('site.sites')->where('id', $pro->site->id)->first();

    // The typed column must be untouched by the stale JSONB value.
    expect($row->booking_mode)->toBe('none');

    // The stale key is stripped from the JSONB mirror regardless (Phase 2:
    // the column is the sole write target), and both the pre-existing
    // unknown key and the new known key survive.
    $settings = json_decode($row->settings, true) ?? [];
    expect($settings)->not->toHaveKey('booking_mode')
        ->and($settings)->toMatchArray(['keep_me' => 'yes', 'display_gallery_page' => false]);
});
