<?php

use App\Ingest\Projection\ProjectionWriter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Slice 1a §3.1: the media fingerprint keys on the vendor-stable ref, not
// the (re-signing) URL. InstagramMediaProjector's docblock always claimed
// this; ProjectionWriter now actually does it. Ordered before 1b's Google
// URL pass-through so adding `url` beside `ref` cannot re-key an asset.

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupIngestTables();
    setupContentTables();
});

function fingerprintItem(string $userId, array $mediaEntry): ?object
{
    app(ProjectionWriter::class)->writeManualItem(
        $userId,
        'manual:'.sha1(json_encode($mediaEntry)),
        ['kind' => 'media', 'headline' => 'A photo', 'media' => [$mediaEntry]],
    );

    return DB::table('content.media_assets')->where('user_id', $userId)->first();
}

it('keys an entry carrying both url and ref off the ref', function () {
    $userId = createTenant('fp-'.Str::lower(Str::random(6)))->id;

    $asset = fingerprintItem($userId, [
        'role' => 'cover',
        'url' => 'https://scontent.cdninstagram.com/photo.jpg?oh=abc&oe=123',
        'ref' => 'instagram:SHORTCODE:0',
    ]);

    expect($asset->fingerprint)->toBe('url-'.sha1('instagram:SHORTCODE:0'));
});

it('keys a ref-only entry off the ref, unchanged from today', function () {
    $userId = createTenant('fp-'.Str::lower(Str::random(6)))->id;

    $asset = fingerprintItem($userId, [
        'role' => 'gallery',
        'ref' => 'places/ChIJx/photos/AXCi2',
    ]);

    expect($asset->fingerprint)->toBe('url-'.sha1('places/ChIJx/photos/AXCi2'))
        ->and($asset->source_url)->toBeNull();
});

it('keys a url-only entry off the minimised url, unchanged from today', function () {
    $userId = createTenant('fp-'.Str::lower(Str::random(6)))->id;

    $asset = fingerprintItem($userId, [
        'role' => 'cover',
        'url' => 'https://cdn.example.com/img.jpg',
    ]);

    expect($asset->fingerprint)->toBe('url-'.sha1('https://cdn.example.com/img.jpg'))
        ->and($asset->source_url)->toBe('https://cdn.example.com/img.jpg');
});

it('still stores the minimised url when the ref wins the key', function () {
    $userId = createTenant('fp-'.Str::lower(Str::random(6)))->id;

    $asset = fingerprintItem($userId, [
        'role' => 'cover',
        'url' => 'https://cdn.example.com/img.jpg',
        'ref' => 'stable-ref-1',
    ]);

    // Identity changed to the ref; source_url is untouched by the flip.
    expect($asset->fingerprint)->toBe('url-'.sha1('stable-ref-1'))
        ->and($asset->source_url)->toBe('https://cdn.example.com/img.jpg');
});
