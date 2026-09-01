<?php

/**
 * A4: ResolveSiteAccentJob wires SiteAccentResolver's priority chain into the
 * existing fill-if-empty DesignKitAccentApplier — dispatched (twice, see
 * ScanPreviousWebsiteContentJob) so the deferred logo/gallery palette tiers
 * get a real shot once their async processing has had time to land.
 */

use App\Jobs\Platforms\ResolveSiteAccentJob;
use App\Models\Core\Site\SiteMedia;
use App\Services\WebsiteScan\DesignKitAccentApplier;
use App\Services\WebsiteScan\SiteAccentResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupDesignKitsTable();
    setupMediaTables();
});

function dkColumn($site, string $col)
{
    return DB::connection('pgsql')->table('site.design_kits')->where('site_id', $site->id)->value($col);
}

function setDkColumn($site, string $col, $value): void
{
    DB::connection('pgsql')->table('site.design_kits')->updateOrInsert(['site_id' => $site->id], [$col => $value]);
}

it('fills color_accent from the resolved chain when empty, never overwriting manual', function () {
    $user = createTenant('accent-job-fill');
    DB::connection('pgsql')->table('site.site_media')->insert([
        'id' => (string) Str::uuid(), 'site_id' => $user->site->id,
        'pool' => SiteMedia::POOL_CONTENT, 'purpose' => null, 'path' => 'x', 'sort_order' => 0,
        'is_active' => true, 'media_type' => SiteMedia::MEDIA_TYPE_IMAGE,
        'processing_state' => SiteMedia::PROCESSING_STATE_READY, 'dominant_color' => '#ab3516',
        'created_at' => now()->toDateTimeString(), 'updated_at' => now()->toDateTimeString(),
    ]);

    (new ResolveSiteAccentJob($user->site->id, themeColor: null, faviconColor: null))
        ->handle(app(SiteAccentResolver::class), app(DesignKitAccentApplier::class));

    expect(dkColumn($user->site, 'color_accent'))->toBe('#ab3516');

    // manual override then re-run: must NOT change (fill-if-empty is inherited
    // from DesignKitAccentApplier, not reimplemented here)
    setDkColumn($user->site, 'color_accent', '#0000ff');
    (new ResolveSiteAccentJob($user->site->id, null, null))
        ->handle(app(SiteAccentResolver::class), app(DesignKitAccentApplier::class));
    expect(dkColumn($user->site, 'color_accent'))->toBe('#0000ff');
});

it('prefers the passed-in theme-color over a gallery palette', function () {
    $user = createTenant('accent-job-theme');
    DB::connection('pgsql')->table('site.site_media')->insert([
        'id' => (string) Str::uuid(), 'site_id' => $user->site->id,
        'pool' => SiteMedia::POOL_CONTENT, 'purpose' => null, 'path' => 'x', 'sort_order' => 0,
        'is_active' => true, 'media_type' => SiteMedia::MEDIA_TYPE_IMAGE,
        'processing_state' => SiteMedia::PROCESSING_STATE_READY, 'dominant_color' => '#ab3516',
        'created_at' => now()->toDateTimeString(), 'updated_at' => now()->toDateTimeString(),
    ]);

    (new ResolveSiteAccentJob($user->site->id, themeColor: '#7a1fa2', faviconColor: null))
        ->handle(app(SiteAccentResolver::class), app(DesignKitAccentApplier::class));

    expect(dkColumn($user->site, 'color_accent'))->toBe('#7a1fa2');
});

it('cleanly no-ops when nothing in the chain resolves', function () {
    $user = createTenant('accent-job-nothing');

    (new ResolveSiteAccentJob($user->site->id, themeColor: null, faviconColor: null))
        ->handle(app(SiteAccentResolver::class), app(DesignKitAccentApplier::class));

    expect(dkColumn($user->site, 'color_accent'))->toBeNull();
});

it('a low-quality theme-color candidate does not block the gallery fallback', function () {
    $user = createTenant('accent-job-fallthrough');
    DB::connection('pgsql')->table('site.site_media')->insert([
        'id' => (string) Str::uuid(), 'site_id' => $user->site->id,
        'pool' => SiteMedia::POOL_CONTENT, 'purpose' => null, 'path' => 'x', 'sort_order' => 0,
        'is_active' => true, 'media_type' => SiteMedia::MEDIA_TYPE_IMAGE,
        'processing_state' => SiteMedia::PROCESSING_STATE_READY, 'dominant_color' => '#e0491f',
        'created_at' => now()->toDateTimeString(), 'updated_at' => now()->toDateTimeString(),
    ]);

    (new ResolveSiteAccentJob($user->site->id, themeColor: '#808080', faviconColor: null))
        ->handle(app(SiteAccentResolver::class), app(DesignKitAccentApplier::class));

    expect(dkColumn($user->site, 'color_accent'))->toBe('#e0491f');
});
