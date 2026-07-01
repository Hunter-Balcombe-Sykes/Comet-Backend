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

it('writes settings.category=other for a custom link with explicit category', function () {
    $fields = buildLinkBlockFields([
        'title' => 'My link',
        'url' => 'https://example.com',
        'icon_key' => 'link',
        'category' => 'other',
    ]);

    // Phase 1 dual-write: settings mirror kept for unchanged views/resource/injector.
    expect($fields['settings']['category'])->toBe('other');
    // Promoted column also populated.
    expect($fields['category'])->toBe('other');
    expect($fields['live_check_enabled'])->toBeFalse();
});

it('writes settings.category=booking from platform default (calendly)', function () {
    $fields = buildLinkBlockFields([
        'platform' => 'calendly',
        'handle' => 'joshhunter',
    ]);

    // Phase 1 dual-write: settings mirror kept.
    expect($fields['settings']['category'])->toBe('booking');
    expect($fields['settings']['platform'])->toBe('calendly');
    // Promoted columns also populated.
    expect($fields['category'])->toBe('booking');
    expect($fields['platform'])->toBe('calendly');
    expect($fields['live_check_enabled'])->toBeFalse();
});

it('respects an explicit category override on a platform link', function () {
    $fields = buildLinkBlockFields([
        'platform' => 'instagram',
        'handle' => 'joshhunter',
        'category' => 'events',
    ]);

    // Phase 1 dual-write: settings mirror kept.
    expect($fields['settings']['category'])->toBe('events');
    expect($fields['settings']['platform'])->toBe('instagram');
    // Promoted columns also populated.
    expect($fields['category'])->toBe('events');
    expect($fields['platform'])->toBe('instagram');
    expect($fields['live_check_enabled'])->toBeFalse();
});

it('populates live_check_enabled column from nested settings input', function () {
    $fields = buildLinkBlockFields([
        'platform' => 'twitch',
        'handle' => 'streamer',
        'settings' => ['live_check_enabled' => true],
    ]);

    expect($fields['live_check_enabled'])->toBeTrue();
    // Settings mirror still has the key so unchanged views keep working.
    expect($fields['settings']['live_check_enabled'] ?? null)->toBeTrue();
});

it('throws when a custom link omits category (defensive guard)', function () {
    expect(fn () => buildLinkBlockFields([
        'title' => 'My link',
        'url' => 'https://example.com',
    ]))->toThrow(InvalidArgumentException::class);
});
