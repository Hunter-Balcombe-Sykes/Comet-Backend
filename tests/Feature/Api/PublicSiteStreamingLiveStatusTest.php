<?php

/** @phpstan-ignore-all */

use App\Services\Cache\SiteCacheService;
use Illuminate\Support\Facades\Redis;

beforeEach(fn () => Redis::flushdb());

// Phase 2: platform + live_check_enabled are promoted columns emitted as top-level
// block keys by the public-site views. handle stays in settings.

it('returns is_live=true for a live streaming link block on the public profile', function () {
    config(['partna.streaming_platforms' => ['twitch', 'kick']]);
    Redis::set('streaming:live:twitch:shroud', '1', 'EX', 180);

    $payload = [
        'links' => [[
            'block_group' => 'links',
            'platform' => 'twitch',
            'live_check_enabled' => true,
            'settings' => [
                'handle' => 'shroud',
            ],
        ]],
        'blocks' => [],
    ];

    $cache = Mockery::mock(SiteCacheService::class);
    $cache->shouldReceive('getPublicSitePayload')
        ->with('testsite')
        ->andReturn($payload);

    $this->app->instance(SiteCacheService::class, $cache);

    $response = $this->getJson('/api/public/site-by-slug', [
        'X-Site-Subdomain' => 'testsite',
    ]);

    $response->assertOk();
    $response->assertJsonPath('links.0.settings.is_live', true);
});

it('returns is_live=false when the handle is not in Redis', function () {
    config(['partna.streaming_platforms' => ['twitch', 'kick']]);
    // No Redis key for this handle

    $payload = [
        'links' => [[
            'block_group' => 'links',
            'platform' => 'twitch',
            'live_check_enabled' => true,
            'settings' => [
                'handle' => 'offlineuser',
            ],
        ]],
        'blocks' => [],
    ];

    $cache = Mockery::mock(SiteCacheService::class);
    $cache->shouldReceive('getPublicSitePayload')
        ->with('testsite')
        ->andReturn($payload);
    $this->app->instance(SiteCacheService::class, $cache);

    $response = $this->getJson('/api/public/site-by-slug', [
        'X-Site-Subdomain' => 'testsite',
    ]);

    $response->assertOk();
    $response->assertJsonPath('links.0.settings.is_live', false);
});
