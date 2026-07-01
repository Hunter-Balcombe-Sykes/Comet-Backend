<?php

/** @phpstan-ignore-all */

use App\Services\Streaming\LiveStatusInjector;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

beforeEach(fn () => Redis::flushdb());

// Phase 2: platform + live_check_enabled are promoted columns emitted as top-level
// block keys by the public-site views. handle stays in settings.

it('injects is_live=true into a streaming block whose handle is live in Redis', function () {
    config(['partna.streaming_platforms' => ['twitch', 'kick']]);
    Redis::set('streaming:live:twitch:shroud', '1', 'EX', 180);

    $blocks = [[
        'platform' => 'twitch',
        'live_check_enabled' => true,
        'settings' => [
            'handle' => 'shroud',
        ],
    ]];

    $injector = new LiveStatusInjector;
    $result = $injector->injectIntoBlocks($blocks);

    expect($result[0]['settings']['is_live'])->toBeTrue();
});

it('injects is_live=false when Redis key is missing', function () {
    config(['partna.streaming_platforms' => ['twitch', 'kick']]);
    // No Redis key set

    $blocks = [[
        'platform' => 'twitch',
        'live_check_enabled' => true,
        'settings' => [
            'handle' => 'offlineuser',
        ],
    ]];

    $injector = new LiveStatusInjector;
    $result = $injector->injectIntoBlocks($blocks);

    expect($result[0]['settings']['is_live'])->toBeFalse();
});

it('does not add is_live to blocks where live_check_enabled is false', function () {
    config(['partna.streaming_platforms' => ['twitch', 'kick']]);
    Redis::set('streaming:live:twitch:shroud', '1', 'EX', 180);

    $blocks = [[
        'platform' => 'twitch',
        'live_check_enabled' => false,
        'settings' => [
            'handle' => 'shroud',
        ],
    ]];

    $injector = new LiveStatusInjector;
    $result = $injector->injectIntoBlocks($blocks);

    expect(array_key_exists('is_live', $result[0]['settings']))->toBeFalse();
});

it('does not add is_live to non-streaming platform blocks', function () {
    config(['partna.streaming_platforms' => ['twitch', 'kick']]);

    $blocks = [[
        'platform' => 'instagram',
        'live_check_enabled' => true,
        'settings' => [
            'handle' => 'someone',
        ],
    ]];

    $injector = new LiveStatusInjector;
    $result = $injector->injectIntoBlocks($blocks);

    expect(array_key_exists('is_live', $result[0]['settings']))->toBeFalse();
});

it('passes through non-link blocks unchanged', function () {
    config(['partna.streaming_platforms' => ['twitch', 'kick']]);

    $blocks = [['block_group' => 'sections', 'type' => 'gallery']];

    $injector = new LiveStatusInjector;
    $result = $injector->injectIntoBlocks($blocks);

    expect($result)->toBe($blocks);
});

it('injects is_live into both links and blocks in the full payload', function () {
    config(['partna.streaming_platforms' => ['twitch', 'kick']]);
    Redis::set('streaming:live:twitch:shroud', '1', 'EX', 180);

    $block = [
        'block_group' => 'links',
        'platform' => 'twitch',
        'live_check_enabled' => true,
        'settings' => [
            'handle' => 'shroud',
        ],
    ];

    $payload = [
        'links' => [$block],
        'blocks' => [$block],
        'other' => 'unchanged',
    ];

    $injector = new LiveStatusInjector;
    $result = $injector->injectIntoPayload($payload);

    expect($result['links'][0]['settings']['is_live'])->toBeTrue();
    expect($result['blocks'][0]['settings']['is_live'])->toBeTrue();
    expect($result['other'])->toBe('unchanged');
});
