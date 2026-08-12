<?php

use App\Services\Media\MediaMirror;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

// Slice 1b D8/D9. Owned-class bytes onto the row ProjectionWriter already
// minted — never a second row of its own.

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupContentTables();
    config()->set('partna.media_disk', 'media');
    Storage::fake('media');
});

/** A real, decodable PNG of the given size, seeded so two calls differ. */
function mirrorImageBytes(int $w, int $h, string $seed = 'a'): string
{
    $img = imagecreatetruecolor($w, $h);
    $c = crc32($seed);
    imagefill($img, 0, 0, imagecolorallocate($img, $c % 255, ($c >> 8) % 255, ($c >> 16) % 255));
    ob_start();
    imagepng($img);
    $bytes = (string) ob_get_clean();
    imagedestroy($img);

    return $bytes;
}

/** A ProjectionWriter-shaped asset row: url-prefixed fingerprint, no bytes yet. */
function mirrorProjectedAsset(string $userId, string $fingerprint): string
{
    $id = (string) Str::uuid();
    DB::table('content.media_assets')->insert([
        'id' => $id,
        'user_id' => $userId,
        'fingerprint' => $fingerprint,
        'source_url' => 'https://scontent.cdninstagram.com/v/photo.jpg',
        'created_at' => now(),
    ]);

    return $id;
}

it('writes storage_path onto the existing asset row without minting a second', function () {
    // D9: the fingerprint collision trap. BrandAssetPipeline keys on a bare
    // content hash; ProjectionWriter keys on url-sha1(...). A mirror that
    // minted its own row would leave two assets for one photo, with item_media
    // pointing at whichever it happened to link.
    $user = createTenant('mir-'.Str::lower(Str::random(6)));
    $fingerprint = 'url-'.sha1('instagram:ABC123:0');
    $assetId = mirrorProjectedAsset($user->id, $fingerprint);
    Http::fake(['*' => Http::response(mirrorImageBytes(1080, 1350), 200, ['Content-Type' => 'image/jpeg'])]);

    $before = DB::table('content.media_assets')->where('user_id', $user->id)->count();

    $ok = app(MediaMirror::class)->mirror($user->id, $assetId, 'https://scontent.cdninstagram.com/v/photo.jpg');

    $row = DB::table('content.media_assets')->where('id', $assetId)->first();

    expect($ok)->toBeTrue()
        ->and(DB::table('content.media_assets')->where('user_id', $user->id)->count())->toBe($before)
        ->and($row->fingerprint)->toBe($fingerprint)
        ->and($row->storage_path)->not->toBeNull()
        ->and($row->mime_type)->toBe('image/webp')
        ->and($row->dims_confidence)->toBe('measured')
        ->and($row->variant_family)->toBe('native')
        ->and((int) $row->width)->toBe(1080)
        ->and((int) $row->height)->toBe(1350);
});

it('does not downsample a gallery photo to the brand-logo edge', function () {
    // BrandAssetPipeline's encoder caps the long edge at 512, which is right
    // for a logo and a visible quality regression for a gallery photo. The
    // shared encoder takes the edge as a parameter for exactly this reason;
    // 1600 here must survive intact.
    $user = createTenant('mir-'.Str::lower(Str::random(6)));
    $assetId = mirrorProjectedAsset($user->id, 'url-'.sha1('instagram:BIG:0'));
    Http::fake(['*' => Http::response(mirrorImageBytes(1600, 900), 200, ['Content-Type' => 'image/jpeg'])]);

    app(MediaMirror::class)->mirror($user->id, $assetId, 'https://scontent.cdninstagram.com/v/big.jpg');

    expect((int) DB::table('content.media_assets')->where('id', $assetId)->value('width'))->toBe(1600);
});

it('content-addresses the path so changed bytes cannot overwrite in place', function () {
    // D8: InstagramConnectionSeeder:82 writes every refresh to
    // platforms/instagram/<connection_created_ts>/photo.jpg — one fixed path,
    // overwritten forever, because the timestamp is the CONNECTION's and never
    // changes. This must not reproduce that.
    $user = createTenant('mir-'.Str::lower(Str::random(6)));
    $assetA = mirrorProjectedAsset($user->id, 'url-'.sha1('instagram:ABC123:0'));
    $assetB = mirrorProjectedAsset($user->id, 'url-'.sha1('instagram:DEF456:0'));

    Http::fake(['*/a.jpg' => Http::response(mirrorImageBytes(100, 100, 'aaa'), 200, ['Content-Type' => 'image/jpeg']),
        '*/b.jpg' => Http::response(mirrorImageBytes(100, 100, 'bbb'), 200, ['Content-Type' => 'image/jpeg'])]);

    app(MediaMirror::class)->mirror($user->id, $assetA, 'https://scontent.cdninstagram.com/a.jpg');
    app(MediaMirror::class)->mirror($user->id, $assetB, 'https://scontent.cdninstagram.com/b.jpg');

    $paths = DB::table('content.media_assets')->whereIn('id', [$assetA, $assetB])->pluck('storage_path');

    expect($paths->filter()->unique())->toHaveCount(2);
});

it('is idempotent — mirroring the same bytes twice writes one object and one path', function () {
    $user = createTenant('mir-'.Str::lower(Str::random(6)));
    $assetId = mirrorProjectedAsset($user->id, 'url-'.sha1('instagram:ABC123:0'));
    Http::fake(['*' => Http::response(mirrorImageBytes(640, 640, 'same'), 200, ['Content-Type' => 'image/jpeg'])]);

    app(MediaMirror::class)->mirror($user->id, $assetId, 'https://scontent.cdninstagram.com/v/photo.jpg');
    $first = DB::table('content.media_assets')->where('id', $assetId)->value('storage_path');

    app(MediaMirror::class)->mirror($user->id, $assetId, 'https://scontent.cdninstagram.com/v/photo.jpg');
    $second = DB::table('content.media_assets')->where('id', $assetId)->value('storage_path');

    expect($second)->toBe($first)
        ->and(Storage::disk('media')->allFiles())->toHaveCount(1);
});

it('leaves the row untouched when the fetch fails', function () {
    $user = createTenant('mir-'.Str::lower(Str::random(6)));
    $assetId = mirrorProjectedAsset($user->id, 'url-'.sha1('instagram:ABC123:0'));
    Http::fake(['*' => Http::response('', 404)]);

    $ok = app(MediaMirror::class)->mirror($user->id, $assetId, 'https://scontent.cdninstagram.com/gone.jpg');

    $row = DB::table('content.media_assets')->where('id', $assetId)->first();

    expect($ok)->toBeFalse()
        ->and($row->storage_path)->toBeNull()
        ->and($row->variant_family)->toBeNull();
});

it('refuses bytes that do not decode as an image', function () {
    // Re-encoding through GD is the sanitising step as much as the variant
    // step. Undecodable bytes behind an image content-type is the shape a
    // disguised payload takes.
    $user = createTenant('mir-'.Str::lower(Str::random(6)));
    $assetId = mirrorProjectedAsset($user->id, 'url-'.sha1('instagram:BAD:0'));
    Http::fake(['*' => Http::response('MZ not-an-image', 200, ['Content-Type' => 'image/jpeg'])]);

    $ok = app(MediaMirror::class)->mirror($user->id, $assetId, 'https://scontent.cdninstagram.com/bad.jpg');

    expect($ok)->toBeFalse()
        ->and(DB::table('content.media_assets')->where('id', $assetId)->value('storage_path'))->toBeNull()
        ->and(Storage::disk('media')->allFiles())->toHaveCount(0);
});

it('never rewrites the fingerprint, even across a bytes change', function () {
    // The fingerprint is identity. ProjectionWriter owns it; the mirror only
    // ever attaches bytes to a row that already exists.
    $user = createTenant('mir-'.Str::lower(Str::random(6)));
    $fingerprint = 'url-'.sha1('instagram:ABC123:0');
    $assetId = mirrorProjectedAsset($user->id, $fingerprint);

    Http::fake(['*' => Http::response(mirrorImageBytes(200, 200, 'one'), 200, ['Content-Type' => 'image/jpeg'])]);
    app(MediaMirror::class)->mirror($user->id, $assetId, 'https://scontent.cdninstagram.com/v/photo.jpg');

    Http::fake(['*' => Http::response(mirrorImageBytes(200, 200, 'two'), 200, ['Content-Type' => 'image/jpeg'])]);
    app(MediaMirror::class)->mirror($user->id, $assetId, 'https://scontent.cdninstagram.com/v/photo.jpg');

    expect(DB::table('content.media_assets')->where('id', $assetId)->value('fingerprint'))->toBe($fingerprint);
});
