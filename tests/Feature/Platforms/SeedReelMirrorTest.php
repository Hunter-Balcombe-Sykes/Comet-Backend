<?php

// 9d (2026-09-01): the hero reel's mp4 leaves seed()'s wall clock. These are
// the handoff mechanics under Queue::fake — the row is written video-less and
// SeedReelMirrorJob carries the mp4 — plus the job's own merge/drop behaviour.
// The full chain (seed → job → payload swap) is proven end-to-end by
// InstagramAsyncConnectTest's reel test, where the sync driver runs the job
// inline at dispatch.

use App\Jobs\Platforms\InstagramConnectJob;
use App\Jobs\Platforms\SeedReelMirrorJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Services\Platforms\InstagramAutoSync;
use App\Services\Platforms\InstagramConnectionSeeder;
use App\Services\Platforms\InstagramScraper;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\Media\FakeMediaBytes;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    Storage::fake('media');
});

function reelMirrorUser(string $h): User
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

function reelMirrorConnection(User $user): IntegrationConnection
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

it('seed() writes the row video-less and hands the reel to SeedReelMirrorJob', function () {
    Queue::fake();
    Http::fake([
        'scontent.cdninstagram.com/*' => Http::response(FakeMediaBytes::jpeg(), 200, ['Content-Type' => 'image/jpeg']),
    ]);

    $user = reelMirrorUser('reelhandoff1');
    $connection = reelMirrorConnection($user);

    $video = [
        'thumbnailUrl' => 'https://scontent.cdninstagram.com/cover.jpg',
        'videoUrl' => 'https://scontent.cdninstagram.com/reel.mp4',
        'shortCode' => 'reel',
    ];

    $scraper = Mockery::mock(InstagramScraper::class);
    $scraper->shouldReceive('fetchProfile')->andReturn(['fullName' => 'Reel Handoff']);
    $scraper->shouldReceive('latestMedia')->once()->andReturn([
        'photo' => ['thumbnailUrl' => 'https://scontent.cdninstagram.com/photo.jpg', 'shortCode' => 'pic'],
        'video' => $video,
    ]);
    $scraper->shouldReceive('profilePicUrl')->once()->andReturn(null);
    $scraper->shouldReceive('bioLinks')->once()->andReturn([]);
    app()->instance(InstagramScraper::class, $scraper);

    (new InstagramConnectJob($user->id, 'reelhandoff1', $connection->id))
        ->handle($scraper, app(InstagramConnectionSeeder::class), app(InstagramAutoSync::class));

    $connection->refresh();

    // First paint: photo mirrored, no video on the row yet.
    expect($connection->last_refresh_status)->toBe('ok')
        ->and(count($connection->payload['images']))->toBe(1)
        ->and($connection->payload['videoUrl'])->toBeNull()
        ->and($connection->payload['videoPoster'])->toBeNull();

    // No mp4 bytes were fetched inline — the reel rides the media-mirror lane.
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'reel.mp4'));
    Queue::assertPushed(SeedReelMirrorJob::class, fn (SeedReelMirrorJob $job) => $job->connectionId === (string) $connection->id
        && $job->video === $video);
});

it('SeedReelMirrorJob merges the mirrored mp4 and poster into the payload', function () {
    Http::fake(fn ($request) => str_contains($request->url(), 'reel.mp4')
        ? Http::response(FakeMediaBytes::mp4(512), 200, ['Content-Type' => 'video/mp4'])
        : Http::response(FakeMediaBytes::jpeg(), 200, ['Content-Type' => 'image/jpeg']));

    $user = reelMirrorUser('reelswap1');
    $connection = reelMirrorConnection($user);
    $connection->update(['payload' => ['username' => 'reelswap1', 'images' => ['existing.jpg'], 'videoUrl' => null, 'videoPoster' => null]]);

    (new SeedReelMirrorJob((string) $connection->id, [
        'thumbnailUrl' => 'https://scontent.cdninstagram.com/cover.jpg',
        'videoUrl' => 'https://scontent.cdninstagram.com/reel.mp4',
        'shortCode' => 'reel',
    ], 'platforms/instagram/'.$connection->id))->handle(app(InstagramConnectionSeeder::class));

    $connection->refresh();

    // Merge, not overwrite: the rest of the selection survives the swap.
    expect($connection->payload['videoUrl'])->not->toBeNull()
        ->and($connection->payload['videoPoster'])->not->toBeNull()
        ->and($connection->payload['images'])->toBe(['existing.jpg'])
        ->and($connection->payload['username'])->toBe('reelswap1');
});

it('SeedReelMirrorJob leaves the payload untouched when the mp4 drops', function () {
    Http::fake(['scontent.cdninstagram.com/*' => Http::response('', 404)]);

    $user = reelMirrorUser('reeldrop1');
    $connection = reelMirrorConnection($user);
    $connection->update(['payload' => ['username' => 'reeldrop1', 'videoUrl' => null, 'videoPoster' => null]]);
    $before = $connection->fresh()->payload;

    (new SeedReelMirrorJob((string) $connection->id, [
        'thumbnailUrl' => 'https://scontent.cdninstagram.com/cover.jpg',
        'videoUrl' => 'https://scontent.cdninstagram.com/reel.mp4',
        'shortCode' => 'reel',
    ], 'platforms/instagram/'.$connection->id))->handle(app(InstagramConnectionSeeder::class));

    expect($connection->fresh()->payload)->toBe($before);
});

it('SeedReelMirrorJob is a no-op when the connection is gone', function () {
    Http::fake();

    (new SeedReelMirrorJob((string) Str::uuid(), [
        'thumbnailUrl' => null,
        'videoUrl' => 'https://scontent.cdninstagram.com/reel.mp4',
        'shortCode' => 'reel',
    ], 'platforms/instagram/ghost'))->handle(app(InstagramConnectionSeeder::class));

    Http::assertNothingSent();
});
