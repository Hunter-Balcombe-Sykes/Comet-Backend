<?php

use App\Services\Media\MediaUrlResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

// Slice 1a §3.3: the ONE url seam for media assets. Precedence
// storage_path → variant (via site_media_id) → source_url → omitted.
// Batched: sits on the public profile hot path behind the 60s cache.

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupMediaTables();
    Storage::fake('media');
});

function assetRow(array $overrides = []): object
{
    return (object) array_merge([
        'id' => (string) Str::uuid(),
        'source_url' => null,
        'storage_path' => null,
        'site_media_id' => null,
        'width' => null,
        'height' => null,
    ], $overrides);
}

function variantFor(string $siteMediaId, string $key, int $width = 1200, int $height = 800): void
{
    DB::table('site.media_variants')->insert([
        'id' => (string) Str::uuid(), 'media_id' => $siteMediaId,
        'variant_key' => $key, 'artifact_type' => 'webp', 'disk' => 'media',
        'path' => "variants/{$siteMediaId}/{$key}.webp", 'mime' => 'image/webp',
        'width' => $width, 'height' => $height, 'created_at' => now(), 'updated_at' => now(),
    ]);
}

it('serves storage_path off the media disk, first in precedence', function () {
    $asset = assetRow(['storage_path' => 'mirrored/abc.webp', 'width' => 640, 'height' => 480,
        'site_media_id' => (string) Str::uuid()]); // even with a pointer present

    $out = app(MediaUrlResolver::class)->resolve([$asset]);

    expect($out)->toHaveKey($asset->id)
        ->and($out[$asset->id]['url'])->toContain('mirrored/abc.webp')
        ->and($out[$asset->id]['width'])->toBe(640)
        ->and($out[$asset->id]['height'])->toBe(480);
});

it('serves the optimized webp variant for a site_media pointer, with the VARIANT row dims', function () {
    $siteMediaId = (string) Str::uuid();
    variantFor($siteMediaId, 'maximized', 4000, 2600);
    variantFor($siteMediaId, 'optimized', 2400, 1560);
    $asset = assetRow(['site_media_id' => $siteMediaId, 'width' => 9999, 'height' => 9999]);

    $out = app(MediaUrlResolver::class)->resolve([$asset]);

    expect($out[$asset->id]['url'])->toContain('optimized')
        ->and($out[$asset->id]['width'])->toBe(2400)
        ->and($out[$asset->id]['height'])->toBe(1560);
});

it('falls back to any webp tier when optimized is missing', function () {
    $siteMediaId = (string) Str::uuid();
    variantFor($siteMediaId, 'maximized');
    $asset = assetRow(['site_media_id' => $siteMediaId]);

    $out = app(MediaUrlResolver::class)->resolve([$asset]);

    expect($out[$asset->id]['url'])->toContain('maximized');
});

it('passes source_url through unchanged, last in precedence', function () {
    $asset = assetRow(['source_url' => 'https://i.ytimg.com/vi/x/hqdefault.jpg', 'width' => 480, 'height' => 360]);

    $out = app(MediaUrlResolver::class)->resolve([$asset]);

    expect($out[$asset->id])->toBe(['url' => 'https://i.ytimg.com/vi/x/hqdefault.jpg', 'width' => 480, 'height' => 360]);
});

it('omits a raw Instagram / Facebook CDN source_url until the mirror lands', function () {
    $ig = assetRow(['id' => 'ig', 'source_url' => 'https://scontent-bos5-1.cdninstagram.com/o1/v/t2/x.jpg']);
    $fb = assetRow(['id' => 'fb', 'source_url' => 'https://scontent-lga3-1.xx.fbcdn.net/v/t51/y.jpg']);

    expect(app(MediaUrlResolver::class)->resolve([$ig, $fb]))->toBe([]);
});

it('omits an asset that resolves to nothing — absent, never null', function () {
    $pointerless = assetRow();
    $deadPointer = assetRow(['site_media_id' => (string) Str::uuid()]); // no variants exist

    $out = app(MediaUrlResolver::class)->resolve([$pointerless, $deadPointer]);

    expect($out)->toBe([]);
});
