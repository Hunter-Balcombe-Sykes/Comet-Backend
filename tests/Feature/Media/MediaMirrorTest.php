<?php

use App\Services\Media\ImagePixelBudget;
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

/**
 * A PNG whose IHDR DECLARES enormous dimensions while weighing almost nothing.
 *
 * This is the decompression bomb in its honest form, and it is why a byte cap
 * cannot stand in for a pixel cap: 20000x20000 is 400 million pixels — about
 * 1.6 GB once GD rasterises it to a truecolour canvas — in well under 100 bytes
 * on the wire. Built by rewriting a real 4x4 PNG's IHDR and recomputing that
 * chunk's CRC, so getimagesizefromstring() reads it as a genuine header rather
 * than refusing it as corrupt.
 */
function mirrorPixelBombBytes(int $w = 20000, int $h = 20000): string
{
    $img = imagecreatetruecolor(4, 4);
    ob_start();
    imagepng($img);
    $png = (string) ob_get_clean();
    imagedestroy($img);

    $start = 8;                                               // PNG signature
    $len = unpack('N', substr($png, $start, 4))[1];           // IHDR is always first
    $type = substr($png, $start + 4, 4);
    $data = pack('NN', $w, $h).substr($png, $start + 8 + 8, $len - 8);

    return substr($png, 0, $start)
        .pack('N', $len).$type.$data.pack('N', crc32($type.$data))
        .substr($png, $start + 8 + $len + 4);
}

it('refuses a decompression bomb before the decoder ever allocates for it (SEC-1)', function () {
    $user = createTenant('bomb-'.Str::lower(Str::random(6)));
    $assetId = mirrorProjectedAsset($user->id, 'url-'.sha1('instagram:BOMB:0'));
    $bomb = mirrorPixelBombBytes();

    // The premise, asserted rather than assumed: this sails past every check
    // MediaMirror had before SEC-1.
    expect(strlen($bomb))->toBeLessThan(15728640);
    expect(getimagesizefromstring($bomb)[0] * getimagesizefromstring($bomb)[1])
        ->toBeGreaterThan(ImagePixelBudget::maxPixels());

    Http::fake(['*' => Http::response($bomb, 200, ['Content-Type' => 'image/png'])]);
    Log::spy();

    $ok = app(MediaMirror::class)->mirror($user->id, $assetId, 'https://scontent.cdninstagram.com/v/bomb.png');

    $row = DB::table('content.media_assets')->where('id', $assetId)->first();

    expect($ok)->toBeFalse()
        ->and($row->storage_path)->toBeNull()
        // The specific reason, not a generic 'undecodable' — an operator
        // reading the row must be able to tell a hostile file from a broken one.
        ->and($row->mirror_last_reason)->toBe('pixel_budget')
        ->and((int) $row->mirror_attempts)->toBe(1);
});

it('still mirrors a large-but-legitimate photo, so the budget is a ceiling and not a blanket refusal', function () {
    $user = createTenant('big-'.Str::lower(Str::random(6)));
    $assetId = mirrorProjectedAsset($user->id, 'url-'.sha1('instagram:BIG:0'));

    // Comfortably the largest thing Instagram serves, and comfortably under
    // 24 MP. A guard that rejected this would be a regression, not a fix.
    Http::fake(['*' => Http::response(mirrorImageBytes(2048, 2048), 200, ['Content-Type' => 'image/jpeg'])]);

    expect(app(MediaMirror::class)->mirror($user->id, $assetId, 'https://scontent.cdninstagram.com/v/big.jpg'))->toBeTrue();
    expect(DB::table('content.media_assets')->where('id', $assetId)->value('storage_path'))->not->toBeNull();
});

it('honours a lowered partna.image_max_pixels rather than a hardcoded constant', function () {
    config()->set('partna.image_max_pixels', 1_000_000);   // 1 MP
    $user = createTenant('cfg-'.Str::lower(Str::random(6)));
    $assetId = mirrorProjectedAsset($user->id, 'url-'.sha1('instagram:CFG:0'));

    Http::fake(['*' => Http::response(mirrorImageBytes(1500, 1500), 200, ['Content-Type' => 'image/jpeg'])]); // 2.25 MP

    expect(app(MediaMirror::class)->mirror($user->id, $assetId, 'https://scontent.cdninstagram.com/v/cfg.jpg'))->toBeFalse();
    expect(DB::table('content.media_assets')->where('id', $assetId)->value('mirror_last_reason'))->toBe('pixel_budget');
});

it('will not land one user\'s bytes on another user\'s asset row, and says so rather than reporting success (SEC-5)', function () {
    $owner = createTenant('own-'.Str::lower(Str::random(6)));
    $other = createTenant('oth-'.Str::lower(Str::random(6)));
    $assetId = mirrorProjectedAsset($owner->id, 'url-'.sha1('instagram:OWN:0'));

    Http::fake(['*' => Http::response(mirrorImageBytes(400, 400), 200, ['Content-Type' => 'image/jpeg'])]);

    // A job dispatched with a mismatched (userId, assetId) pair. Before SEC-5
    // the UPDATE keyed on id alone and this wrote the bytes anyway.
    $ok = app(MediaMirror::class)->mirror($other->id, $assetId, 'https://scontent.cdninstagram.com/v/x.jpg');

    expect(DB::table('content.media_assets')->where('id', $assetId)->value('storage_path'))->toBeNull();

    // The half a review caught: scoping the UPDATE on user_id gave it a way to
    // match nothing, and returning true there would report a mirror that never
    // happened — no bytes, no reason, nothing to retry, nothing to see.
    expect($ok)->toBeFalse();

    // And the victim's row is untouched in every column, counter included:
    // bumping THEIR attempt counter would be the same cross-tenant write this
    // finding is about.
    $row = DB::table('content.media_assets')->where('id', $assetId)->first();
    expect((int) $row->mirror_attempts)->toBe(0)
        ->and($row->mirror_last_reason)->toBeNull();
});

/**
 * Bytes in a format GD may decode but `getimagesize*` cannot parse.
 *
 * GD2 is the specimen — but **GD2 support is a PHP BUILD OPTION**, present on a
 * stock macOS build and DISABLED on CI's (`imagegd2(): GD2 image support has
 * been disabled`). So the fixture is produced by `imagegd2()` where the build
 * allows it, and hand-written from the GD2 header format where it does not.
 *
 * That distinction is deliberate rather than a convenience: what the guard has
 * to do is REFUSE bytes finfo does not recognise as jpeg/png/webp, and that
 * obligation does not depend on whether the machine running the test happens to
 * be able to produce or decode the attack. Skipping the case where GD2 is
 * unavailable would disable the assertion precisely on CI, which is the one
 * place it needs to hold.
 *
 * @return array{0: string, 1: bool} the bytes, and whether GD can really decode them here
 */
function mirrorGd2Bytes(): array
{
    $depth = ob_get_level();
    $img = imagecreatetruecolor(64, 64);
    imagefill($img, 0, 0, imagecolorallocate($img, 1, 2, 3));

    $bytes = '';
    try {
        ob_start();
        $ok = @imagegd2($img);
        $bytes = $ok ? (string) ob_get_clean() : '';
        if (! $ok && ob_get_level() > $depth) {
            ob_end_clean();
        }
    } catch (Throwable) {
        // Laravel turns warnings into ErrorException; a build without GD2 write
        // support lands here rather than returning false.
        while (ob_get_level() > $depth) {
            ob_end_clean();
        }
        $bytes = '';
    } finally {
        imagedestroy($img);
    }

    if ($bytes === '') {
        // Minimal GD2 header: signature, version 2, 64x64, chunk size, format.
        // Enough for finfo to decline to call it an image, which is all the
        // allowlist is asked about.
        $bytes = "gd2\x00".pack('nnnnn', 2, 64, 64, 128, 2).str_repeat("\x00", 32);
    }

    $decoded = @imagecreatefromstring($bytes);
    $gdCanDecode = $decoded !== false;
    if ($decoded !== false) {
        imagedestroy($decoded);
    }

    return [$bytes, $gdCanDecode];
}

it('refuses a format getimagesize cannot read, whether or not GD can decode it (SEC-1, review follow-up)', function () {
    // The bypass an independent review found in the first cut of this guard.
    // imagecreatefromstring() decodes strictly MORE formats than
    // getimagesizefromstring() can parse — GD's own GD2 among them — so a guard
    // that treats "unreadable header" as "harmless" is bypassable by choosing a
    // format the header reader does not know. Measured at the time: a 12000x12000
    // GD2 image is ~854 KB on the wire, getimagesizefromstring() returns false
    // for it, and GD decodes it into 144 million pixels.
    //
    // Asserted at 64x64 deliberately: the fix is a FORMAT allowlist, so it
    // refuses GD2 at any size and this needs no half-gigabyte fixture.
    [$gd2, $gdCanDecode] = mirrorGd2Bytes();

    // Premise that holds on every build: the header reader cannot see it.
    expect(@getimagesizefromstring($gd2))->toBeFalse();

    // Premise that holds only where the build kept GD2 READ support. Where it
    // does, this is the bypass in its literal form — bytes GD would happily
    // rasterise. Where it does not, the refusal below still has to hold, which
    // is the whole point of asserting it unconditionally.
    if ($gdCanDecode) {
        expect(ImagePixelBudget::decodable($gd2))->toBeFalse();
    }

    $user = createTenant('gd2-'.Str::lower(Str::random(6)));
    $assetId = mirrorProjectedAsset($user->id, 'url-'.sha1('instagram:GD2:0'));
    // A lying content-type, because finfo byte-sniffs and does not trust it.
    Http::fake(['*' => Http::response($gd2, 200, ['Content-Type' => 'image/png'])]);

    $ok = app(MediaMirror::class)->mirror($user->id, $assetId, 'https://scontent.cdninstagram.com/v/x.gd2');

    expect($ok)->toBeFalse();
    expect(DB::table('content.media_assets')->where('id', $assetId)->value('storage_path'))->toBeNull();
    expect(DB::table('content.media_assets')->where('id', $assetId)->value('mirror_last_reason'))->toBe('undecodable');
});

it('the pixel-budget guard fails CLOSED on an allowlisted mime whose header will not parse', function () {
    // A truncated PNG: finfo still calls it image/png, getimagesizefromstring
    // cannot read dimensions from it. The old guard returned "not over budget"
    // and handed it to GD; it must refuse instead.
    $img = imagecreatetruecolor(8, 8);
    ob_start();
    imagepng($img);
    $png = (string) ob_get_clean();
    imagedestroy($img);
    $truncated = substr($png, 0, 20);

    expect(ImagePixelBudget::decodable($truncated))->toBeTrue();
    expect(@getimagesizefromstring($truncated))->toBeFalse();
    expect(ImagePixelBudget::exceeds($truncated))->toBeTrue();
    expect(ImagePixelBudget::safeToDecode($truncated))->toBeFalse();
});
