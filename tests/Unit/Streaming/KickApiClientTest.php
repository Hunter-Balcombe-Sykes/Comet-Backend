<?php

/** @phpstan-ignore-all */

use App\Exceptions\Streaming\KickRateLimitException;
use App\Services\Streaming\KickApiClient;
use App\Services\Streaming\StreamingTokenManager;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

it('returns the subset of handles that are currently live', function () {
    $manager = Mockery::mock(StreamingTokenManager::class);
    $manager->shouldReceive('getToken')->with('kick')->andReturn('kick-token');

    Http::fake([
        'api.kick.com/public/v1/channels*' => Http::response([
            'data' => [
                ['slug' => 'shroud', 'stream' => ['is_live' => true]],
                ['slug' => 'xqc',    'stream' => ['is_live' => true]],
                ['slug' => 'offline', 'stream' => ['is_live' => false]],
            ],
        ], 200),
    ]);

    $client = new KickApiClient($manager);
    $liveHandles = $client->getLiveHandles(['shroud', 'xqc', 'offline']);

    expect($liveHandles)->toBe(['shroud', 'xqc']);
});

it('returns empty array when no handles are live', function () {
    $manager = Mockery::mock(StreamingTokenManager::class);
    $manager->shouldReceive('getToken')->with('kick')->andReturn('kick-token');

    Http::fake([
        'api.kick.com/public/v1/channels*' => Http::response(['data' => []], 200),
    ]);

    $client = new KickApiClient($manager);
    expect($client->getLiveHandles(['nobody']))->toBe([]);
});

it('throws KickRateLimitException on 429 with retry-after header', function () {
    $manager = Mockery::mock(StreamingTokenManager::class);
    $manager->shouldReceive('getToken')->with('kick')->andReturn('kick-token');

    Http::fake([
        'api.kick.com/public/v1/channels*' => Http::response([], 429, ['Retry-After' => '60']),
    ]);

    $client = new KickApiClient($manager);

    expect(fn () => $client->getLiveHandles(['anyuser']))
        ->toThrow(KickRateLimitException::class);
});

it('returns empty array and logs error on 5xx', function () {
    $manager = Mockery::mock(StreamingTokenManager::class);
    $manager->shouldReceive('getToken')->with('kick')->andReturn('kick-token');

    // B3/P2-11: Kick error responses can include free-text bodies; the log
    // context must record platform + status only, never the body.
    Http::fake([
        'api.kick.com/public/v1/channels*' => Http::response('user_email=pii@example.com', 500),
    ]);

    Log::shouldReceive('error')
        ->once()
        ->withArgs(function (string $message, array $context) {
            return $message === 'streaming.api_error'
                && $context['platform'] === 'kick'
                && $context['status'] === 500
                && ! array_key_exists('body', $context);
        });

    $client = new KickApiClient($manager);
    expect($client->getLiveHandles(['anyuser']))->toBe([]);
});

it('returns empty array and logs critical when token is unavailable', function () {
    $manager = Mockery::mock(StreamingTokenManager::class);
    $manager->shouldReceive('getToken')->with('kick')->andReturn(null);

    Log::shouldReceive('critical')->once()->with('streaming.auth_failure', Mockery::any());

    $client = new KickApiClient($manager);
    expect($client->getLiveHandles(['anyuser']))->toBe([]);
});

it('sends slug as repeated query parameters (not comma-joined)', function () {
    $manager = Mockery::mock(StreamingTokenManager::class);
    $manager->shouldReceive('getToken')->with('kick')->andReturn('kick-token');

    Http::fake(['api.kick.com/public/v1/channels*' => Http::response(['data' => []], 200)]);

    $client = new KickApiClient($manager);
    $client->getLiveHandles(['a', 'b']);

    Http::assertSent(function ($request) {
        // Laravel/Guzzle renders repeated array params as ?slug[0]=a&slug[1]=b or ?slug=a&slug=b
        // depending on HTTP build style. Decode first to normalise %5B%5D brackets, then match either.
        $url = urldecode($request->url());

        // Both bare (?slug=a) and indexed (?slug[0]=a) forms end with "=a" and "=b" in the query.
        return (str_contains($url, 'slug=a') || str_contains($url, 'slug[0]=a'))
            && (str_contains($url, 'slug=b') || str_contains($url, 'slug[1]=b'));
    });
});
