<?php

use App\Services\Site\LinkBlockFieldBuilder;
use App\Services\Site\SocialLinkNormalizer;
use Tests\TestCase;

// Bootstrap the Laravel app so config() is available — SocialLinkNormalizer reads
// the social_platforms registry from config('partna.*') during normalize().
uses(TestCase::class)->in(__FILE__);

function buildLinkBlockFields(array $data): array
{
    return (new LinkBlockFieldBuilder(new SocialLinkNormalizer))->build($data);
}

// Phase 2: platform/category/live_check_enabled are promoted columns only.
// The settings bag no longer carries these keys (stripped by migration 20260701180000).

it('writes category=other column for a custom link with explicit category', function () {
    $fields = buildLinkBlockFields([
        'title' => 'My link',
        'url' => 'https://example.com',
        'icon_key' => 'link',
        'category' => 'other',
    ]);

    // Promoted column populated; settings does NOT carry category.
    expect($fields['category'])->toBe('other');
    expect($fields['live_check_enabled'])->toBeFalse();
    expect($fields['settings'])->not->toHaveKey('category');
});

it('writes category=booking column from platform default (calendly)', function () {
    $fields = buildLinkBlockFields([
        'platform' => 'calendly',
        'handle' => 'joshhunter',
    ]);

    // Promoted columns populated; settings does NOT carry category or platform.
    expect($fields['category'])->toBe('booking');
    expect($fields['platform'])->toBe('calendly');
    expect($fields['live_check_enabled'])->toBeFalse();
    expect($fields['settings'])->not->toHaveKey('category');
    expect($fields['settings'])->not->toHaveKey('platform');
});

it('respects an explicit category override on a platform link', function () {
    $fields = buildLinkBlockFields([
        'platform' => 'instagram',
        'handle' => 'joshhunter',
        'category' => 'events',
    ]);

    // Promoted columns populated; settings does NOT carry category or platform.
    expect($fields['category'])->toBe('events');
    expect($fields['platform'])->toBe('instagram');
    expect($fields['live_check_enabled'])->toBeFalse();
    expect($fields['settings'])->not->toHaveKey('category');
    expect($fields['settings'])->not->toHaveKey('platform');
});

it('populates live_check_enabled column from top-level input (Phase 2)', function () {
    $fields = buildLinkBlockFields([
        'platform' => 'twitch',
        'handle' => 'streamer',
        'live_check_enabled' => true,
    ]);

    // Column set from top-level input; settings does NOT carry live_check_enabled.
    expect($fields['live_check_enabled'])->toBeTrue();
    expect($fields['settings'])->not->toHaveKey('live_check_enabled');
});

it('throws when a custom link omits category (defensive guard)', function () {
    expect(fn () => buildLinkBlockFields([
        'title' => 'My link',
        'url' => 'https://example.com',
    ]))->toThrow(InvalidArgumentException::class);
});
