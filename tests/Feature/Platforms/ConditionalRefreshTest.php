<?php

// tests/Feature/Platforms/ConditionalRefreshTest.php
//
// Exercises the 304 spine end-to-end through PlatformRefresher → ScheduledRefresh →
// YoutubeMusicFetch → ConditionalContext, WITHOUT real DNS/HTTP: the YoutubeScraper
// mock simulates a 304 by flipping the passed context's notModified flag.

use App\Jobs\Cloudflare\CloudflareCachePurgeJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Services\Platforms\ConditionalContext;
use App\Services\Platforms\PlatformRefresher;
use App\Services\Platforms\YoutubeScraper;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    config()->set('partna.refresh.conditional.enabled', true);
});

function condUser(): User
{
    return User::create([
        'handle' => 'cond', 'handle_lc' => 'cond', 'display_name' => 'Cond',
        'account_type' => 'individual', 'auth_user_id' => (string) Str::uuid(),
        'primary_email' => 'cond@example.com',
    ]);
}

it('a 304 bumps last_refreshed_at quietly — no payload write, no cache purge', function () {
    Queue::fake();
    $user = condUser();

    // Mock BEFORE create (SEC-1 saving-guard eager-wires the scraper). The mock
    // simulates a 304 by flipping the passed ConditionalContext.
    $this->mock(YoutubeScraper::class, function ($m) {
        $m->shouldReceive('fetchUploadsFeed')->andReturnUsing(function ($channelId, $limit, ?ConditionalContext $cond) {
            $cond->notModified = true; // 304 Not Modified

            return null;
        });
    });

    $conn = IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'youtube-music', 'resource_id' => 'youtube-music',
        'payload' => ['channelId' => 'UC123', 'name' => 'Cached Artist', 'items' => [['videoId' => 'v1']]],
        'last_refreshed_at' => now()->subWeek(),
        'last_refresh_status' => 'ok',
        'consecutive_failures' => 2,
        'refresh_etag' => '"stored"',
    ]);

    app(PlatformRefresher::class)->refresh($conn);

    $conn->refresh();
    expect($conn->last_refresh_status)->toBe('ok')
        ->and($conn->consecutive_failures)->toBe(0)                 // healthy hit → reset
        ->and($conn->payload['name'])->toBe('Cached Artist')       // payload untouched
        ->and($conn->last_refreshed_at->gt(now()->subMinute()))->toBeTrue(); // bumped

    Queue::assertNotPushed(CloudflareCachePurgeJob::class);         // 304 ⇒ no purge
});

it('a 200 persists freshly-captured validators alongside the payload', function () {
    $user = condUser();

    // The mock captures a fresh ETag onto the context (as the real handle() would on
    // a 200) and returns a normal feed.
    $this->mock(YoutubeScraper::class, function ($m) {
        $m->shouldReceive('fetchUploadsFeed')->andReturnUsing(function ($channelId, $limit, ?ConditionalContext $cond) {
            $cond->handle(['status' => 200, 'etag' => '"fresh"', 'lastModified' => 'D']);

            return ['title' => 'New Name', 'videos' => [
                ['videoId' => 'v9', 'name' => 'Song', 'thumbnail' => 't', 'link' => 'l', 'date' => null],
            ]];
        });
    });

    $conn = IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'youtube-music', 'resource_id' => 'youtube-music',
        'payload' => ['channelId' => 'UC123', 'name' => 'Old'],
        'last_refreshed_at' => now()->subWeek(),
        'refresh_etag' => '"stored"',
    ]);

    app(PlatformRefresher::class)->refresh($conn);

    $conn->refresh();
    expect($conn->last_refresh_status)->toBe('ok')
        ->and($conn->refresh_etag)->toBe('"fresh"')
        ->and($conn->refresh_last_modified)->toBe('D');
});
