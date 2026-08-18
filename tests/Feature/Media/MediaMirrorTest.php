<?php

use App\Services\Media\MediaMirror;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
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

it('leaves the bytes columns untouched when the fetch fails', function () {
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

it('stores a reel mp4 as-is under its content hash with mime video/mp4 (R7)', function () {
    $user = createTenant('mir-'.Str::lower(Str::random(6)));
    $assetId = mirrorProjectedAsset($user->id, 'url-'.sha1('instagram:ABC123:video'));
    // A minimal ISO BMFF header: size + 'ftyp' box + brand, then junk.
    $mp4 = "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom".str_repeat("\x00", 512);
    Http::fake(['*' => Http::response($mp4, 200, ['Content-Type' => 'video/mp4'])]);

    $ok = app(MediaMirror::class)->mirror($user->id, $assetId, 'https://scontent.cdninstagram.com/v/reel.mp4');
    $row = DB::table('content.media_assets')->where('id', $assetId)->first();

    expect($ok)->toBeTrue()
        ->and($row->mime_type)->toBe('video/mp4')
        ->and($row->storage_path)->toEndWith('.mp4')
        ->and(Storage::disk('media')->exists($row->storage_path))->toBeTrue()
        ->and(Storage::disk('media')->get($row->storage_path))->toBe($mp4);
});

// ── R8: the unmirrored tail must be readable from the row, not inferred ──────
//
// `storage_path IS NULL` used to collapse four states into one value — never
// dispatched, queued, running, and permanently dead all looked identical. The
// 2026-08-18 build wave left 32 unmirrored assets behind exactly one warning
// line, and no amount of log reading could tell which of the four each one was.

it('stamps the failure reason and increments the attempt counter', function () {
    $user = createTenant('mir-'.Str::lower(Str::random(6)));
    $assetId = mirrorProjectedAsset($user->id, 'url-'.sha1('instagram:ABC123:0'));
    Http::fake(['*' => Http::response('', 404)]);

    app(MediaMirror::class)->mirror($user->id, $assetId, 'https://scontent.cdninstagram.com/gone.jpg');

    $row = DB::table('content.media_assets')->where('id', $assetId)->first();

    expect((int) $row->mirror_attempts)->toBe(1)
        ->and($row->mirror_last_reason)->toBe('fetch_failed')
        ->and($row->mirror_last_attempt_at)->not->toBeNull();
});

it('counts consecutive failures rather than overwriting the previous attempt', function () {
    $user = createTenant('mir-'.Str::lower(Str::random(6)));
    $assetId = mirrorProjectedAsset($user->id, 'url-'.sha1('instagram:ABC123:0'));
    Http::fake(['*' => Http::response('', 404)]);

    app(MediaMirror::class)->mirror($user->id, $assetId, 'https://scontent.cdninstagram.com/gone.jpg');
    app(MediaMirror::class)->mirror($user->id, $assetId, 'https://scontent.cdninstagram.com/gone.jpg');

    expect((int) DB::table('content.media_assets')->where('id', $assetId)->value('mirror_attempts'))->toBe(2);
});

it('records the specific reason, not a generic failure flag', function () {
    // Each reason has a different remedy: undecodable is a dead asset,
    // store_failed is our infrastructure. Collapsing them loses the remedy.
    $user = createTenant('mir-'.Str::lower(Str::random(6)));
    $assetId = mirrorProjectedAsset($user->id, 'url-'.sha1('instagram:BAD:0'));
    Http::fake(['*' => Http::response('MZ not-an-image', 200, ['Content-Type' => 'image/jpeg'])]);

    app(MediaMirror::class)->mirror($user->id, $assetId, 'https://scontent.cdninstagram.com/bad.jpg');

    expect(DB::table('content.media_assets')->where('id', $assetId)->value('mirror_last_reason'))->toBe('undecodable');
});

it('clears the failure state when a later attempt succeeds', function () {
    // A CDN outage must not leave a permanent scar on a row that later worked.
    $user = createTenant('mir-'.Str::lower(Str::random(6)));
    $assetId = mirrorProjectedAsset($user->id, 'url-'.sha1('instagram:ABC123:0'));
    DB::table('content.media_assets')->where('id', $assetId)
        ->update(['mirror_attempts' => 3, 'mirror_last_reason' => 'fetch_failed']);
    Http::fake(['*' => Http::response(mirrorImageBytes(320, 320), 200, ['Content-Type' => 'image/jpeg'])]);

    app(MediaMirror::class)->mirror($user->id, $assetId, 'https://scontent.cdninstagram.com/v/photo.jpg');

    $row = DB::table('content.media_assets')->where('id', $assetId)->first();

    expect((int) $row->mirror_attempts)->toBe(0)
        ->and($row->mirror_last_reason)->toBeNull()
        ->and($row->mirror_last_attempt_at)->not->toBeNull();
});

it('clears the failure state when a reel mp4 later succeeds', function () {
    // The video branch returns before the image UPDATE — it needs its own
    // reset or a reel that recovers keeps a stale reason forever.
    $user = createTenant('mir-'.Str::lower(Str::random(6)));
    $assetId = mirrorProjectedAsset($user->id, 'url-'.sha1('instagram:ABC123:video'));
    DB::table('content.media_assets')->where('id', $assetId)
        ->update(['mirror_attempts' => 2, 'mirror_last_reason' => 'fetch_failed']);
    $mp4 = "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom".str_repeat("\x00", 512);
    Http::fake(['*' => Http::response($mp4, 200, ['Content-Type' => 'video/mp4'])]);

    app(MediaMirror::class)->mirror($user->id, $assetId, 'https://scontent.cdninstagram.com/v/reel.mp4');

    $row = DB::table('content.media_assets')->where('id', $assetId)->first();

    expect((int) $row->mirror_attempts)->toBe(0)
        ->and($row->mirror_last_reason)->toBeNull();
});

it('warns media_mirror.gave_up on the attempt that crosses the cap', function () {
    // The operator event R8 wanted and never got: one line, at warning, on the
    // transition — not a per-sync repeat of a link that is simply dead.
    config()->set('partna.media_mirror_max_attempts', 3);
    $user = createTenant('mir-'.Str::lower(Str::random(6)));
    $assetId = mirrorProjectedAsset($user->id, 'url-'.sha1('instagram:ABC123:0'));
    DB::table('content.media_assets')->where('id', $assetId)->update(['mirror_attempts' => 2]);
    Http::fake(['*' => Http::response('', 404)]);
    Log::spy();

    app(MediaMirror::class)->mirror($user->id, $assetId, 'https://scontent.cdninstagram.com/gone.jpg');

    Log::shouldHaveReceived('warning')
        ->withArgs(fn ($message, $context) => $message === 'media_mirror.gave_up'
            && $context['asset_id'] === $assetId
            && $context['attempts'] === 3
            && $context['reason'] === 'fetch_failed')
        ->once();
});

it('does not warn gave_up while the asset is still under the cap', function () {
    config()->set('partna.media_mirror_max_attempts', 3);
    $user = createTenant('mir-'.Str::lower(Str::random(6)));
    $assetId = mirrorProjectedAsset($user->id, 'url-'.sha1('instagram:ABC123:0'));
    Http::fake(['*' => Http::response('', 404)]);
    Log::spy();

    app(MediaMirror::class)->mirror($user->id, $assetId, 'https://scontent.cdninstagram.com/gone.jpg');

    // TWO matchers, because Log::warning() is called with two arguments — a
    // one-element expectation could never match the real call and would pass
    // whether or not the line fired. `media_mirror.failed` still fires here and
    // is correctly not matched. Non-vacuity proven by setting the cap to 1:
    // this assertion then fails, as it must.
    Log::shouldNotHaveReceived('warning', ['media_mirror.gave_up', Mockery::any()]);
    expect((int) DB::table('content.media_assets')->where('id', $assetId)->value('mirror_attempts'))->toBe(1);
});

it('still warns gave_up when the counter overshoots the cap', function () {
    // A strict `=== max` misses the line whenever the counter steps past the
    // boundary — two in-flight jobs incrementing concurrently once the
    // ShouldBeUnique lock has lapsed, or a cap someone lowered. Missing the
    // give-up line is the exact failure class this whole change exists to end,
    // so overshoot must still speak. Duplicates are bounded: dispatchMirrors
    // stops queuing at the cap, so only already-queued jobs can get here.
    config()->set('partna.media_mirror_max_attempts', 3);
    $user = createTenant('mir-'.Str::lower(Str::random(6)));
    $assetId = mirrorProjectedAsset($user->id, 'url-'.sha1('instagram:ABC123:0'));
    DB::table('content.media_assets')->where('id', $assetId)->update(['mirror_attempts' => 6]);
    Http::fake(['*' => Http::response('', 404)]);
    Log::spy();

    app(MediaMirror::class)->mirror($user->id, $assetId, 'https://scontent.cdninstagram.com/gone.jpg');

    Log::shouldHaveReceived('warning')
        ->withArgs(fn ($message, $context) => $message === 'media_mirror.gave_up' && $context['attempts'] === 7)
        ->once();
});
