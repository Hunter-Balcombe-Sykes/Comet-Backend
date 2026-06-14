<?php

/** @phpstan-ignore-all */

use App\Services\Streaming\StreamingTokenManager;
use App\Services\Streaming\TwitchApiClient;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

it('returns handles that are currently live', function () {
    $manager = Mockery::mock(StreamingTokenManager::class);
    $manager->shouldReceive('getToken')->with('twitch')->andReturn('test-token');

    Http::fake([
        'api.twitch.tv/helix/streams*' => Http::response([
            'data' => [
                ['user_login' => 'shroud', 'type' => 'live'],
                ['user_login' => 'ninja', 'type' => 'live'],
            ],
        ], 200),
    ]);

    $client = new TwitchApiClient($manager);
    $liveHandles = $client->getLiveHandles(['shroud', 'ninja', 'offlineuser']);

    expect($liveHandles)->toBe(['shroud', 'ninja']);
});

it('returns empty array when no handles are live', function () {
    $manager = Mockery::mock(StreamingTokenManager::class);
    $manager->shouldReceive('getToken')->with('twitch')->andReturn('test-token');

    Http::fake([
        'api.twitch.tv/helix/streams*' => Http::response(['data' => []], 200),
    ]);

    $client = new TwitchApiClient($manager);
    $liveHandles = $client->getLiveHandles(['offline1', 'offline2']);

    expect($liveHandles)->toBe([]);
});

it('logs an error and returns empty array on 5xx response', function () {
    $manager = Mockery::mock(StreamingTokenManager::class);
    $manager->shouldReceive('getToken')->with('twitch')->andReturn('test-token');

    // B3/P2-11: Twitch error bodies can echo back unrelated session data; log
    // platform + status only — never the raw response body.
    Http::fake([
        'api.twitch.tv/helix/streams*' => Http::response('oauth_token=stale-token-value', 500),
    ]);

    Log::shouldReceive('error')
        ->once()
        ->withArgs(function (string $message, array $context) {
            return $message === 'streaming.api_error'
                && $context['platform'] === 'twitch'
                && $context['status'] === 500
                && ! array_key_exists('body', $context);
        });

    $client = new TwitchApiClient($manager);
    $liveHandles = $client->getLiveHandles(['someuser']);

    expect($liveHandles)->toBe([]);
});

it('logs error and returns empty array when token is unavailable', function () {
    $manager = Mockery::mock(StreamingTokenManager::class);
    $manager->shouldReceive('getToken')->with('twitch')->andReturn(null);

    // OBS-14: recoverable auth failure logs at error, not critical.
    Log::shouldReceive('error')->once()->with('streaming.auth_failure', Mockery::any());

    $client = new TwitchApiClient($manager);
    $liveHandles = $client->getLiveHandles(['someuser']);

    expect($liveHandles)->toBe([]);
});

it('sends the correct authorization headers', function () {
    $manager = Mockery::mock(StreamingTokenManager::class);
    $manager->shouldReceive('getToken')->with('twitch')->andReturn('bearer-token');

    config(['services.twitch.client_id' => 'my-client-id']);

    Http::fake([
        'api.twitch.tv/helix/streams*' => Http::response(['data' => []], 200),
    ]);

    $client = new TwitchApiClient($manager);
    $client->getLiveHandles(['anyuser']);

    Http::assertSent(function ($request) {
        return $request->hasHeader('Authorization', 'Bearer bearer-token')
            && $request->hasHeader('Client-ID', 'my-client-id');
    });
});
