<?php

/** @phpstan-ignore-all */

use App\Exceptions\Streaming\KickRateLimitException;
use App\Services\Streaming\KickApiClient;
use App\Services\Streaming\LiveStatusPoller;
use App\Services\Streaming\TwitchApiClient;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

beforeEach(fn () => Redis::flushdb());

it('kick_rate_limited_ttl config key exists with the correct default (300)', function () {
    expect(config('partna.streaming.kick_rate_limited_ttl'))->toBe(300);
});

it('poller uses the config value for the Kick rate-limit circuit-breaker TTL', function () {
    // Override the config to a small value so we can assert the Redis TTL.
    Config::set('partna.streaming.kick_rate_limited_ttl', 42);

    $twitch = Mockery::mock(TwitchApiClient::class);
    $kick = Mockery::mock(KickApiClient::class);
    $kick->shouldReceive('getLiveHandles')
        ->once()
        ->andThrow(new KickRateLimitException(60));

    $poller = new LiveStatusPoller($twitch, $kick);
    $poller->poll('kick', ['someuser']);

    $ttl = Redis::ttl('streaming:kick:rate_limited');
    // TTL should reflect the overridden value, not the hardcoded 300.
    expect($ttl)->toBeGreaterThan(0)->toBeLessThanOrEqual(42);
});
