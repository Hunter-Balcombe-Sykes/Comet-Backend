<?php

/**
 * A1/A3/A4: SiteAccentResolver replaces the single favicon/theme-color probe
 * with a priority chain over every available brand-colour source: theme-color
 * -> logo dominant -> favicon -> gallery dominant. Each candidate must pass
 * AccentQuality's gate; the first qualifying one wins. Logs when none do
 * (A1 — this used to be a silent no-op).
 */

use App\Models\Core\Site\SiteMedia;
use App\Services\WebsiteScan\SiteAccentResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

beforeEach(function () {
    setupMediaTables();
});

function seedAccentMediaRow(string $siteId, array $overrides = []): string
{
    $id = (string) Str::uuid();
    DB::connection('pgsql')->table('site.site_media')->insert(array_merge([
        'id' => $id, 'site_id' => $siteId, 'pool' => SiteMedia::POOL_GALLERY,
        'purpose' => null, 'path' => 'x', 'sort_order' => 0, 'is_active' => true,
        'media_type' => SiteMedia::MEDIA_TYPE_IMAGE, 'processing_state' => SiteMedia::PROCESSING_STATE_READY,
        'created_at' => now()->toDateTimeString(), 'updated_at' => now()->toDateTimeString(),
    ], $overrides));

    return $id;
}

it('falls back to the gallery palette when theme-color, logo, and favicon all fail', function () {
    $siteId = (string) Str::uuid();
    seedAccentMediaRow($siteId, ['pool' => SiteMedia::POOL_GALLERY, 'dominant_color' => '#ab3516']);

    $resolver = app(SiteAccentResolver::class);
    expect($resolver->resolve($siteId, themeColor: null, faviconColor: null))->toBe('#ab3516');
});

it('prefers theme-color over everything', function () {
    $siteId = (string) Str::uuid();
    seedAccentMediaRow($siteId, [
        'pool' => SiteMedia::POOL_DESIGN, 'purpose' => SiteMedia::PURPOSE_LOGO_FULL, 'dominant_color' => '#123456',
    ]);

    $result = app(SiteAccentResolver::class)->resolve($siteId, themeColor: '#7a1fa2', faviconColor: '#999000');
    expect($result)->toBe('#7a1fa2');
});

it('prefers the logo over the favicon when theme-color is absent', function () {
    $siteId = (string) Str::uuid();
    seedAccentMediaRow($siteId, [
        'pool' => SiteMedia::POOL_DESIGN, 'purpose' => SiteMedia::PURPOSE_LOGO_FULL, 'dominant_color' => '#123456',
    ]);

    $result = app(SiteAccentResolver::class)->resolve($siteId, themeColor: null, faviconColor: '#999000');
    expect($result)->toBe('#123456');
});

it('prefers logo_full over logo_square when both have a palette', function () {
    $siteId = (string) Str::uuid();
    seedAccentMediaRow($siteId, [
        'pool' => SiteMedia::POOL_DESIGN, 'purpose' => SiteMedia::PURPOSE_LOGO_SQUARE, 'dominant_color' => '#222222',
    ]);
    seedAccentMediaRow($siteId, [
        'pool' => SiteMedia::POOL_DESIGN, 'purpose' => SiteMedia::PURPOSE_LOGO_FULL, 'dominant_color' => '#e0491f',
    ]);

    expect(app(SiteAccentResolver::class)->resolve($siteId, null, null))->toBe('#e0491f');
});

it('falls back to logo_square when logo_full has no palette', function () {
    $siteId = (string) Str::uuid();
    seedAccentMediaRow($siteId, [
        'pool' => SiteMedia::POOL_DESIGN, 'purpose' => SiteMedia::PURPOSE_LOGO_FULL, 'dominant_color' => null,
    ]);
    seedAccentMediaRow($siteId, [
        'pool' => SiteMedia::POOL_DESIGN, 'purpose' => SiteMedia::PURPOSE_LOGO_SQUARE, 'dominant_color' => '#e0491f',
    ]);

    expect(app(SiteAccentResolver::class)->resolve($siteId, null, null))->toBe('#e0491f');
});

it('prefers the favicon over gallery when no logo palette exists', function () {
    $siteId = (string) Str::uuid();
    seedAccentMediaRow($siteId, ['pool' => SiteMedia::POOL_GALLERY, 'dominant_color' => '#334455']);

    expect(app(SiteAccentResolver::class)->resolve($siteId, null, '#e0491f'))->toBe('#e0491f');
});

it('skips a non-qualifying candidate and falls through to the next tier', function () {
    $siteId = (string) Str::uuid();
    seedAccentMediaRow($siteId, ['pool' => SiteMedia::POOL_GALLERY, 'dominant_color' => '#e0491f']);

    // theme-color is grey (fails AccentQuality) and favicon is near-white (fails too)
    // — both must be skipped, not just treated as "present so stop".
    $result = app(SiteAccentResolver::class)->resolve($siteId, themeColor: '#808080', faviconColor: '#fefefe');
    expect($result)->toBe('#e0491f');
});

it('logs and returns null when no candidate qualifies at all', function () {
    Log::spy();
    $siteId = (string) Str::uuid();

    $result = app(SiteAccentResolver::class)->resolve($siteId, themeColor: '#808080', faviconColor: null);

    expect($result)->toBeNull();
    Log::shouldHaveReceived('info')
        ->withArgs(fn ($message, $context) => $message === 'website_accent.no_candidate' && $context['site_id'] === $siteId)
        ->once();
});

it('never crosses tenants — a candidate on another site is never picked', function () {
    $siteId = (string) Str::uuid();
    $otherSiteId = (string) Str::uuid();
    seedAccentMediaRow($otherSiteId, ['pool' => SiteMedia::POOL_GALLERY, 'dominant_color' => '#e0491f']);

    expect(app(SiteAccentResolver::class)->resolve($siteId, null, null))->toBeNull();
});
