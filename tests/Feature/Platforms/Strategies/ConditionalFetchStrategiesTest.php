<?php

// tests/Feature/Platforms/Strategies/ConditionalFetchStrategiesTest.php
//
// 304 behaviour for the three wired strategies. Scrapers are mocked (hermetic — no
// DNS/HTTP): the mock flips the passed ConditionalContext to simulate a 304, and the
// strategy must translate that into a FetchNotModifiedException.

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Services\Platforms\ConditionalContext;
use App\Services\Platforms\OEmbedService;
use App\Services\Platforms\Strategies\Fetch\FetchNotModifiedException;
use App\Services\Platforms\Strategies\Fetch\OEmbedFetch;
use App\Services\Platforms\Strategies\Fetch\YoutubeMusicFetch;
use App\Services\Platforms\YoutubeScraper;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    config()->set('partna.refresh.conditional.enabled', true);
});

function condStratUser(string $h): User
{
    return User::create([
        'handle' => $h, 'handle_lc' => $h, 'display_name' => $h,
        'account_type' => 'partna', 'auth_user_id' => (string) Str::uuid(),
        'primary_email' => $h.'@example.com',
    ]);
}

it('YoutubeMusicFetch raises FetchNotModifiedException on a 304', function () {
    $this->mock(YoutubeScraper::class, function ($m) {
        $m->shouldReceive('fetchUploadsFeed')->andReturnUsing(function ($channelId, $limit, ?ConditionalContext $cond) {
            $cond->notModified = true;

            return null;
        });
    });

    $conn = IntegrationConnection::create([
        'user_id' => condStratUser('ym304')->id, 'platform' => 'youtube-music', 'resource_id' => 'youtube-music',
        'payload' => ['channelId' => 'UC123'], 'refresh_etag' => '"e"',
    ]);

    expect(fn () => (new YoutubeMusicFetch(app(YoutubeScraper::class)))->fetch($conn))
        ->toThrow(FetchNotModifiedException::class);
});

it('OEmbedFetch raises FetchNotModifiedException on a 304', function () {
    $this->mock(OEmbedService::class, function ($m) {
        $m->shouldReceive('resolve')->andReturnUsing(function ($endpoint, ?ConditionalContext $cond) {
            $cond->notModified = true;

            return null;
        });
    });

    $conn = IntegrationConnection::create([
        'user_id' => condStratUser('oe304')->id, 'platform' => 'spotify', 'resource_id' => 'spotify',
        'payload' => ['link' => 'https://open.spotify.com/artist/x'], 'refresh_etag' => '"e"',
    ]);

    $strategy = new OEmbedFetch(app(OEmbedService::class), fn (string $l) => 'https://open.spotify.com/oembed?url='.$l, 'spotify');

    expect(fn () => $strategy->fetch($conn))->toThrow(FetchNotModifiedException::class);
});
