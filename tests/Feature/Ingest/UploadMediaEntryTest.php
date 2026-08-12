<?php

use App\Ingest\Projection\ProjectionWriter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Slice 1a §3.4: a media entry may carry site_media_id instead of url/ref.
// The fingerprint lives INSIDE the existing url- namespace ('upload:{id}'
// is minted only here), so the uniqueness constraint keeps one meaning.

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupIngestTables();
    setupContentTables();
});

function uploadEntry(string $siteMediaId, array $overrides = []): array
{
    return array_merge([
        'role' => 'cover',
        'site_media_id' => $siteMediaId,
        'alt' => 'A shopfront',
        'width' => 1200,
        'height' => 800,
        'mime_type' => 'image/webp',
    ], $overrides);
}

it('mints one asset with site_media_id, measured dims, and null source_url', function () {
    $userId = createTenant('up-'.Str::lower(Str::random(6)))->id;
    $siteMediaId = (string) Str::uuid();

    app(ProjectionWriter::class)->writeManualItem($userId, 'manual:'.$siteMediaId, [
        'kind' => 'media',
        'headline' => 'Shopfront',
        'media' => [uploadEntry($siteMediaId)],
    ]);

    $asset = DB::table('content.media_assets')->where('user_id', $userId)->first();

    expect($asset->fingerprint)->toBe('url-'.sha1('upload:'.$siteMediaId))
        ->and($asset->site_media_id)->toBe($siteMediaId)
        ->and($asset->source_url)->toBeNull()
        ->and($asset->dims_confidence)->toBe('measured')
        ->and((int) $asset->width)->toBe(1200)
        ->and((int) $asset->height)->toBe(800)
        ->and($asset->mime_type)->toBe('image/webp');
});

it('mints ONE asset when two items reference the same site_media', function () {
    $userId = createTenant('up-'.Str::lower(Str::random(6)))->id;
    $siteMediaId = (string) Str::uuid();
    $writer = app(ProjectionWriter::class);

    foreach (['manual:a-'.$siteMediaId, 'manual:b-'.$siteMediaId] as $coord) {
        $writer->writeManualItem($userId, $coord, [
            'kind' => 'media',
            'headline' => 'Shared upload',
            'media' => [uploadEntry($siteMediaId)],
        ]);
    }

    expect(DB::table('content.media_assets')->where('user_id', $userId)->count())->toBe(1)
        ->and(DB::table('content.item_media')->count())->toBe(2);
});

it('leaves url/ref entries in the same batch completely untouched', function () {
    $userId = createTenant('up-'.Str::lower(Str::random(6)))->id;
    $siteMediaId = (string) Str::uuid();

    app(ProjectionWriter::class)->writeManualItem($userId, 'manual:mixed-'.$siteMediaId, [
        'kind' => 'media',
        'headline' => 'Mixed frames',
        'media' => [
            uploadEntry($siteMediaId),
            ['role' => 'gallery', 'url' => 'https://cdn.example.com/other.jpg'],
        ],
    ]);

    $assets = DB::table('content.media_assets')->where('user_id', $userId)->orderBy('fingerprint')->get();

    expect($assets)->toHaveCount(2);
    $urlAsset = $assets->firstWhere('source_url', 'https://cdn.example.com/other.jpg');
    expect($urlAsset->site_media_id)->toBeNull()
        ->and($urlAsset->dims_confidence)->toBeNull();
});
