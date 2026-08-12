<?php

use App\Ingest\Projection\ProjectionWriter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Slice 1b D6. resolveMediaAssets() is hot code on every projection run for
// every connector, so the attribution write gets its own coverage rather than
// riding on the projector's.
//
// Mint-only, deliberately: a Google ref rotates every fetch, so a rotated photo
// arrives as a NEW row carrying its own credit. There is no update path to keep
// in sync.

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupIngestTables();
    setupContentTables();
});

it('writes attribution as jsonb on a freshly minted asset', function () {
    $userId = createTenant('attr-'.Str::lower(Str::random(6)))->id;
    $ref = 'places/ChIJtest/photos/AWCwydtoken';

    app(ProjectionWriter::class)->writeManualItem($userId, 'manual:'.$ref, [
        'kind' => 'media',
        'headline' => null,
        'media' => [[
            'role' => 'gallery',
            'ref' => $ref,
            'url' => 'https://lh3.googleusercontent.com/place-photos/AG9NLjtest',
            'attribution' => [
                'authors' => [['name' => 'Jo Rivera', 'uri' => null]],
                'maps_uri' => 'https://maps.google.com/p/1',
            ],
        ]],
    ]);

    $row = DB::table('content.media_assets')
        ->where('user_id', $userId)
        ->where('fingerprint', 'url-'.sha1($ref))
        ->first();

    expect($row)->not->toBeNull()
        ->and(json_decode($row->attribution, true)['authors'][0]['name'])->toBe('Jo Rivera')
        ->and(json_decode($row->attribution, true)['maps_uri'])->toBe('https://maps.google.com/p/1');
});

it('leaves attribution null when the entry carries none', function () {
    $userId = createTenant('attr-'.Str::lower(Str::random(6)))->id;
    $ref = 'places/ChIJtest/photos/AWCwydnoattr';

    app(ProjectionWriter::class)->writeManualItem($userId, 'manual:'.$ref, [
        'kind' => 'media',
        'headline' => null,
        'media' => [['role' => 'gallery', 'ref' => $ref]],
    ]);

    $row = DB::table('content.media_assets')
        ->where('user_id', $userId)
        ->where('fingerprint', 'url-'.sha1($ref))
        ->first();

    expect($row->attribution)->toBeNull();
});

it('treats an empty attribution block as absent, not as an empty object', function () {
    // D6's known gap: only ~60 of 110 live Google photos carry authors. An
    // empty object would render as a credit with no name in it.
    $userId = createTenant('attr-'.Str::lower(Str::random(6)))->id;
    $ref = 'places/ChIJtest/photos/AWCwydempty';

    app(ProjectionWriter::class)->writeManualItem($userId, 'manual:'.$ref, [
        'kind' => 'media',
        'headline' => null,
        'media' => [['role' => 'gallery', 'ref' => $ref, 'attribution' => []]],
    ]);

    $row = DB::table('content.media_assets')
        ->where('user_id', $userId)
        ->where('fingerprint', 'url-'.sha1($ref))
        ->first();

    expect($row->attribution)->toBeNull();
});

it('does not disturb the upload shape 1a established', function () {
    // Regression guard: this task edits the same insert array 1a's upload
    // branch writes. An upload must still mint with site_media_id, measured
    // dims and a null source_url — and now a null attribution.
    $userId = createTenant('attr-'.Str::lower(Str::random(6)))->id;
    $siteMediaId = (string) Str::uuid();

    app(ProjectionWriter::class)->writeManualItem($userId, 'manual:'.$siteMediaId, [
        'kind' => 'media',
        'headline' => 'Shopfront',
        'media' => [[
            'role' => 'gallery',
            'site_media_id' => $siteMediaId,
            'width' => 1200,
            'height' => 800,
            'mime_type' => 'image/webp',
        ]],
    ]);

    $row = DB::table('content.media_assets')
        ->where('user_id', $userId)
        ->where('fingerprint', 'url-'.sha1('upload:'.$siteMediaId))
        ->first();

    expect($row->site_media_id)->toBe($siteMediaId)
        ->and($row->dims_confidence)->toBe('measured')
        ->and($row->source_url)->toBeNull()
        ->and($row->attribution)->toBeNull();
});
