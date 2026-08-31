<?php

use App\Jobs\Platforms\InstagramConnectJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Services\Platforms\InstagramAutoSync;
use App\Services\Platforms\InstagramConnectionSeeder;
use App\Services\Platforms\InstagramScraper;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\Media\FakeMediaBytes;

// SCALE-102: InstagramConnectionSeeder::mirrorOne() used to buffer the whole
// image response into a PHP string (`$response->body()`) before the size cap
// even ran. It now streams to a temp file — mirrorVideo's existing pattern —
// so a large or malicious response never holds a full copy in worker memory.
// MAX_IMAGE_BYTES is enforced two ways post-fix: a fast Content-Length
// precheck (unchanged — covered by the pre-existing "drops an oversized cover
// image" test in InstagramAsyncConnectTest.php) AND a backstop check on the
// bytes that actually landed on the sunk temp file. This file proves the
// backstop specifically (Content-Length absent, so only the on-disk size
// check can catch it), that a normal image survives streaming byte-for-byte,
// and that the temp file is cleaned up on a transport failure.

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
});

function igMirrorUser(string $h): User
{
    return User::create([
        'handle' => $h,
        'handle_lc' => strtolower($h),
        'display_name' => ucfirst($h),
        'first_name' => ucfirst($h),
        'account_type' => 'partna',
        'auth_user_id' => (string) Str::uuid(),
        'primary_email' => "{$h}@example.com",
    ]);
}

function igMirrorConnection(User $user): IntegrationConnection
{
    return IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'instagram',
        'resource_id' => 'instagram',
        'payload' => [],
        'is_active' => false,
        'last_refresh_status' => 'pending',
    ]);
}

it('rejects an oversized image via the on-disk size backstop when Content-Length is absent (cap survives streaming)', function () {
    Storage::fake('media');

    $bigUrl = 'https://scontent.cdninstagram.com/huge.jpg';
    $picUrl = 'https://scontent.cdninstagram.com/pic.jpg';

    // Deliberately NO Content-Length header — proves the backstop (actual
    // bytes written to the sunk temp file), distinct from the header
    // fast-path already covered by InstagramAsyncConnectTest's "drops an
    // oversized cover image" test. This is the path a naive streaming
    // refactor is most likely to drop.
    // A REAL jpeg, padded past the cap: since #W2-SEC-14 the mirror byte-sniffs
    // what landed, so an oversized blob of 'a's would now be dropped for the
    // wrong reason and this test would pass while proving nothing about the cap.
    $oversized = FakeMediaBytes::jpeg().str_repeat('a', 15_728_640 + 10);
    Http::fake([
        'scontent.cdninstagram.com/huge.jpg' => Http::response($oversized, 200, ['Content-Type' => 'image/jpeg']),
        'scontent.cdninstagram.com/pic.jpg' => Http::response(FakeMediaBytes::jpeg(), 200, ['Content-Type' => 'image/jpeg']),
    ]);

    $user = igMirrorUser('igstream_cap');
    $connection = igMirrorConnection($user);

    $scraper = Mockery::mock(InstagramScraper::class);
    $scraper->shouldReceive('fetchProfile')->once()->andReturn(['fullName' => 'Cap User']);
    $scraper->shouldReceive('latestMedia')->once()->andReturn([
        'photo' => ['thumbnailUrl' => $bigUrl, 'shortCode' => 'big'],
        'video' => null,
    ]);
    $scraper->shouldReceive('profilePicUrl')->once()->andReturn($picUrl);
    $scraper->shouldReceive('bioLinks')->once()->andReturn([]);
    // The seeder resolves its own InstagramScraper from the container — bind the
    // mock so seed() (called inside handle()) uses it too, not a real scraper.
    app()->instance(InstagramScraper::class, $scraper);

    (new InstagramConnectJob($user->id, 'testuser', $connection->id))
        ->handle($scraper, app(InstagramConnectionSeeder::class), app(InstagramAutoSync::class));

    $connection->refresh();
    $folder = 'platforms/instagram/'.$connection->created_at->timestamp;

    // Job still completes — one dropped image must not fail the whole job.
    expect($connection->last_refresh_status)->toBe('ok');
    // The oversized photo never reached storage.
    expect($connection->payload['images'])->toBe([]);
    expect(Storage::disk('media')->exists("{$folder}/photo.jpg"))->toBeFalse();
    // The normal-sized profile pic mirrored fine — the cap is per-request,
    // not a global kill switch tripped by the earlier rejection.
    expect($connection->payload['profilePicUrl'])->not->toBeNull();
    expect(Storage::disk('media')->exists("{$folder}/profile.jpg"))->toBeTrue();
});

it('mirrors a normal image end-to-end with the exact bytes landing at the expected path', function () {
    Storage::fake('media');

    $picUrl = 'https://scontent.cdninstagram.com/profile-exact.jpg';
    $exactBytes = FakeMediaBytes::jpeg(16, 16); // real jpeg (#W2-SEC-14 sniffs it), fixed content

    Http::fake([
        'scontent.cdninstagram.com/profile-exact.jpg' => Http::response($exactBytes, 200, ['Content-Type' => 'image/jpeg']),
    ]);

    $user = igMirrorUser('igstream_bytes');
    $connection = igMirrorConnection($user);

    $scraper = Mockery::mock(InstagramScraper::class);
    $scraper->shouldReceive('fetchProfile')->once()->andReturn(['fullName' => 'Bytes User']);
    $scraper->shouldReceive('latestMedia')->once()->andReturn(['photo' => null, 'video' => null]);
    $scraper->shouldReceive('profilePicUrl')->once()->andReturn($picUrl);
    $scraper->shouldReceive('bioLinks')->once()->andReturn([]);
    app()->instance(InstagramScraper::class, $scraper);

    (new InstagramConnectJob($user->id, 'testuser', $connection->id))
        ->handle($scraper, app(InstagramConnectionSeeder::class), app(InstagramAutoSync::class));

    $connection->refresh();
    $folder = 'platforms/instagram/'.$connection->created_at->timestamp;

    expect($connection->last_refresh_status)->toBe('ok');
    expect(Storage::disk('media')->exists("{$folder}/profile.jpg"))->toBeTrue();
    // The bytes on disk are EXACTLY what the CDN served — streaming through a
    // temp file must not truncate, duplicate, or corrupt the payload.
    expect(Storage::disk('media')->get("{$folder}/profile.jpg"))->toBe($exactBytes);
    expect($connection->payload['profilePicUrl'])->toBe(Storage::disk('media')->url("{$folder}/profile.jpg"));
});

// The next two tests peek at the filesystem FROM INSIDE the faked HTTP
// exchange — at the instant the request fires, mirrorOne's tempnam() must
// already have created its sink target (streaming), not just have a body
// string floating in memory (the old buffered path never touched the
// filesystem at all here). This is the genuine pre/post-fix discriminator:
// against the pre-fix buffered implementation these two would fail because
// no 'igimg*' file is ever created for the response to stream into.

it('streams to a temp file before the request completes, and cleans it up after a successful mirror', function () {
    Storage::fake('media');

    $picUrl = 'https://scontent.cdninstagram.com/tempfile-proof.jpg';
    $tempFileExistedDuringRequest = null;

    Http::fake([
        'scontent.cdninstagram.com/tempfile-proof.jpg' => function () use (&$tempFileExistedDuringRequest) {
            $tempFileExistedDuringRequest = (bool) glob(sys_get_temp_dir().'/igimg*');

            return Http::response(FakeMediaBytes::jpeg(), 200, ['Content-Type' => 'image/jpeg']);
        },
    ]);

    $user = igMirrorUser('igstream_tmp_ok');
    $connection = igMirrorConnection($user);

    $scraper = Mockery::mock(InstagramScraper::class);
    $scraper->shouldReceive('fetchProfile')->once()->andReturn(['fullName' => 'Tmp User']);
    $scraper->shouldReceive('latestMedia')->once()->andReturn(['photo' => null, 'video' => null]);
    $scraper->shouldReceive('profilePicUrl')->once()->andReturn($picUrl);
    $scraper->shouldReceive('bioLinks')->once()->andReturn([]);
    app()->instance(InstagramScraper::class, $scraper);

    (new InstagramConnectJob($user->id, 'testuser', $connection->id))
        ->handle($scraper, app(InstagramConnectionSeeder::class), app(InstagramAutoSync::class));

    expect($tempFileExistedDuringRequest)->toBeTrue();
    // Cleaned up after a successful mirror — no leaked temp files remain.
    expect(glob(sys_get_temp_dir().'/igimg*'))->toBeEmpty();
});

it('streams to a temp file before the request completes, and cleans it up when the CDN request fails at transport level', function () {
    Storage::fake('media');

    $picUrl = 'https://scontent.cdninstagram.com/tempfile-fail-proof.jpg';
    $tempFileExistedDuringRequest = null;

    Http::fake([
        'scontent.cdninstagram.com/tempfile-fail-proof.jpg' => function ($request) use (&$tempFileExistedDuringRequest) {
            $tempFileExistedDuringRequest = (bool) glob(sys_get_temp_dir().'/igimg*');

            // Simulates a transport-level failure (DNS/connect refused) —
            // mirrorOne's catch(Throwable) must swallow this AND still clean
            // up the temp file it created before the request went out.
            return Http::failedConnection()($request);
        },
    ]);

    $user = igMirrorUser('igstream_tmp_fail');
    $connection = igMirrorConnection($user);

    $scraper = Mockery::mock(InstagramScraper::class);
    $scraper->shouldReceive('fetchProfile')->once()->andReturn(['fullName' => 'Leak User']);
    $scraper->shouldReceive('latestMedia')->once()->andReturn(['photo' => null, 'video' => null]);
    $scraper->shouldReceive('profilePicUrl')->once()->andReturn($picUrl);
    $scraper->shouldReceive('bioLinks')->once()->andReturn([]);
    app()->instance(InstagramScraper::class, $scraper);

    // The job's own try/catch swallows the mirror failure — must not throw.
    (new InstagramConnectJob($user->id, 'testuser', $connection->id))
        ->handle($scraper, app(InstagramConnectionSeeder::class), app(InstagramAutoSync::class));

    expect($tempFileExistedDuringRequest)->toBeTrue();
    // No leaked temp file on the failure path.
    expect(glob(sys_get_temp_dir().'/igimg*'))->toBeEmpty();

    $connection->refresh();
    // The mirror failure is swallowed per-image — the job still completes.
    expect($connection->last_refresh_status)->toBe('ok');
    expect($connection->payload['profilePicUrl'])->toBeNull();
});

// ── #W2-SEC-14: the bytes decide, not the CDN's Content-Type header ──────────
//
// The fetch is host-allowlisted to the real Instagram/Facebook CDNs, so this is
// not a fully open upload path — but the CONTENT is third-party user media, it
// is stored on R2 and served publicly, and until now the only thing consulted
// about what it was was a header the origin wrote. MediaMirror has run
// ImagePixelBudget over its own Instagram bytes since #SEC-1; this path had not.

it('drops a mirrored image whose bytes are not an accepted image format, whatever the CDN calls them', function () {
    Storage::fake('media');

    $picUrl = 'https://scontent.cdninstagram.com/lying.jpg';
    Http::fake([
        // Served as image/jpeg. It is an HTML document — the shape that, stored
        // under a .jpg on a public media origin, is worth catching.
        'scontent.cdninstagram.com/lying.jpg' => Http::response(
            '<html><body><script>alert(1)</script></body></html>', 200, ['Content-Type' => 'image/jpeg'],
        ),
    ]);

    $user = igMirrorUser('igstream_lie');
    $connection = igMirrorConnection($user);

    $scraper = Mockery::mock(InstagramScraper::class);
    $scraper->shouldReceive('fetchProfile')->once()->andReturn(['fullName' => 'Lie User']);
    $scraper->shouldReceive('latestMedia')->once()->andReturn(['photo' => null, 'video' => null]);
    $scraper->shouldReceive('profilePicUrl')->once()->andReturn($picUrl);
    $scraper->shouldReceive('bioLinks')->once()->andReturn([]);
    app()->instance(InstagramScraper::class, $scraper);

    (new InstagramConnectJob($user->id, 'testuser', $connection->id))
        ->handle($scraper, app(InstagramConnectionSeeder::class), app(InstagramAutoSync::class));

    $connection->refresh();
    $folder = 'platforms/instagram/'.$connection->created_at->timestamp;

    // Nothing reached R2, and no URL was published for it. The connect itself
    // still succeeds — a failed mirror has always degraded, never thrown.
    expect(Storage::disk('media')->exists("{$folder}/profile.jpg"))->toBeFalse();
    expect($connection->payload['profilePicUrl'] ?? null)->toBeNull();
    expect($connection->last_refresh_status)->toBe('ok');
});

it('drops a GIF — an image, but not one of the three formats the house allowlist accepts', function () {
    Storage::fake('media');

    $picUrl = 'https://scontent.cdninstagram.com/anim.jpg';
    // A real 1x1 GIF. The point is that "is an image" is NOT the test:
    // ImagePixelBudget accepts jpeg/png/webp and nothing else, matching
    // ImageVariantService::ALLOWED_IMAGE_MIMES.
    $gif = base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
    Http::fake([
        'scontent.cdninstagram.com/anim.jpg' => Http::response($gif, 200, ['Content-Type' => 'image/gif']),
    ]);

    $user = igMirrorUser('igstream_gif');
    $connection = igMirrorConnection($user);

    $scraper = Mockery::mock(InstagramScraper::class);
    $scraper->shouldReceive('fetchProfile')->once()->andReturn(['fullName' => 'Gif User']);
    $scraper->shouldReceive('latestMedia')->once()->andReturn(['photo' => null, 'video' => null]);
    $scraper->shouldReceive('profilePicUrl')->once()->andReturn($picUrl);
    $scraper->shouldReceive('bioLinks')->once()->andReturn([]);
    app()->instance(InstagramScraper::class, $scraper);

    (new InstagramConnectJob($user->id, 'testuser', $connection->id))
        ->handle($scraper, app(InstagramConnectionSeeder::class), app(InstagramAutoSync::class));

    $connection->refresh();
    $folder = 'platforms/instagram/'.$connection->created_at->timestamp;

    expect(Storage::disk('media')->exists("{$folder}/profile.jpg"))->toBeFalse();
    expect($connection->payload['profilePicUrl'] ?? null)->toBeNull();
});
