<?php

use App\Services\Professional\SectionVisibilityService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\FeatureFlags\SectionVisibilityTestCase;

beforeEach(function () {
    SectionVisibilityTestCase::boot();
});

function seedCountdownProAndSite(): array
{
    $proId = (string) Str::uuid();
    $siteId = (string) Str::uuid();

    DB::connection('pgsql')->table('core.users')->insert([
        'id' => $proId,
        'handle' => 'countdown-pro',
        'display_name' => 'Countdown Pro',
        'primary_email' => 'countdown@example.com',
        'status' => 'active',
    ]);

    return [$proId, $siteId];
}

function seedCountdownBlock(string $proId, string $siteId, array $settings = [], bool $isActive = false): void
{
    DB::connection('pgsql')->table('site.blocks')->insert([
        'id' => (string) Str::uuid(),
        'professional_id' => $proId,
        'site_id' => $siteId,
        'block_group' => 'sections',
        'block_type' => 'countdown',
        'settings' => json_encode($settings),
        'is_enabled' => 1,
        'is_active' => $isActive ? 1 : 0,
    ]);
}

it('rejects publishing a countdown with no stored block', function () {
    [$proId, $siteId] = seedCountdownProAndSite();

    [$canBeVisible, $reason] = app(SectionVisibilityService::class)
        ->checkVisibilityRequirements($proId, $siteId, 'countdown');

    expect($canBeVisible)->toBeFalse();
    expect($reason)->toContain('drop time');
});

it('rejects publishing a countdown with empty settings', function () {
    [$proId, $siteId] = seedCountdownProAndSite();
    seedCountdownBlock($proId, $siteId, []);

    [$canBeVisible, $reason] = app(SectionVisibilityService::class)
        ->checkVisibilityRequirements($proId, $siteId, 'countdown');

    expect($canBeVisible)->toBeFalse();
    expect($reason)->toContain('drop time');
});

it('rejects publishing a countdown with only drop_time', function () {
    [$proId, $siteId] = seedCountdownProAndSite();
    seedCountdownBlock($proId, $siteId, [
        'timeline' => ['drop_time' => '2099-01-01T00:00:00Z'],
    ]);

    [$canBeVisible, $reason] = app(SectionVisibilityService::class)
        ->checkVisibilityRequirements($proId, $siteId, 'countdown');

    expect($canBeVisible)->toBeFalse();
    expect($reason)->toContain('expiry');
});

it('rejects publishing a countdown whose expiry is in the past', function () {
    [$proId, $siteId] = seedCountdownProAndSite();
    seedCountdownBlock($proId, $siteId, [
        'timeline' => [
            'drop_time' => '2020-01-01T00:00:00Z',
            'expiry_time' => '2020-01-02T00:00:00Z',
        ],
    ]);

    [$canBeVisible, $reason] = app(SectionVisibilityService::class)
        ->checkVisibilityRequirements($proId, $siteId, 'countdown');

    expect($canBeVisible)->toBeFalse();
    expect($reason)->toContain('past');
});

it('rejects publishing a countdown where expiry is not after drop', function () {
    [$proId, $siteId] = seedCountdownProAndSite();
    seedCountdownBlock($proId, $siteId, [
        'timeline' => [
            'drop_time' => '2099-01-02T00:00:00Z',
            'expiry_time' => '2099-01-01T00:00:00Z',
        ],
    ]);

    [$canBeVisible, $reason] = app(SectionVisibilityService::class)
        ->checkVisibilityRequirements($proId, $siteId, 'countdown');

    expect($canBeVisible)->toBeFalse();
    expect($reason)->toContain('after');
});

it('allows publishing a countdown with a valid future timeline', function () {
    [$proId, $siteId] = seedCountdownProAndSite();
    seedCountdownBlock($proId, $siteId, [
        'timeline' => [
            'drop_time' => '2099-01-01T00:00:00Z',
            'expiry_time' => '2099-01-03T00:00:00Z',
        ],
    ]);

    [$canBeVisible, $reason] = app(SectionVisibilityService::class)
        ->checkVisibilityRequirements($proId, $siteId, 'countdown');

    expect($canBeVisible)->toBeTrue();
    expect($reason)->toBeNull();
});

it('allows publish-to-live when valid timeline is supplied via pending settings (first-time publish path)', function () {
    // Simulates the controller path where publication_state=live and settings
    // are sent in the same request — the stored block has no timeline yet,
    // but the pending settings carry a valid one. Without the pendingSettings
    // arg, this check would read stored (empty) settings and reject.
    [$proId, $siteId] = seedCountdownProAndSite();
    seedCountdownBlock($proId, $siteId, []);

    [$canBeVisible] = app(SectionVisibilityService::class)
        ->checkVisibilityRequirements($proId, $siteId, 'countdown', [
            'timeline' => [
                'drop_time' => '2099-01-01T00:00:00Z',
                'expiry_time' => '2099-01-03T00:00:00Z',
            ],
        ]);

    expect($canBeVisible)->toBeTrue();
});

it('merges pending settings over stored when checking (partial PATCH path)', function () {
    // Stored block already has a valid timeline; the incoming payload only
    // updates the title. Merged settings still have a valid timeline, so
    // the check passes.
    [$proId, $siteId] = seedCountdownProAndSite();
    seedCountdownBlock($proId, $siteId, [
        'timeline' => [
            'drop_time' => '2099-01-01T00:00:00Z',
            'expiry_time' => '2099-01-03T00:00:00Z',
        ],
    ]);

    [$canBeVisible] = app(SectionVisibilityService::class)
        ->checkVisibilityRequirements($proId, $siteId, 'countdown', [
            'title' => 'Updated title',
        ]);

    expect($canBeVisible)->toBeTrue();
});

it('preserves sibling state fields when array_replace_recursive merges a nested PATCH', function () {
    // The controller uses array_replace_recursive to PATCH-merge settings on
    // every upsert. This test locks in that a countdown-shaped nested PATCH
    // (updating only settings.states.live.headline) doesn't clobber siblings
    // (cta.label, cta.url, other states). Countdown is the first block type
    // with multi-level nesting inside states, so it's worth confirming the
    // merge semantics survive the shape we're introducing.
    $stored = [
        'title' => 'The Drop',
        'timeline' => [
            'drop_time' => '2099-01-01T00:00:00Z',
            'expiry_time' => '2099-01-03T00:00:00Z',
        ],
        'states' => [
            'live' => [
                'headline' => 'Original headline',
                'subtitle' => 'Original subtitle',
                'cta' => ['label' => 'Shop', 'url' => 'https://x.test'],
            ],
            'pre_drop' => [
                'headline' => 'Coming soon',
            ],
        ],
    ];

    $pending = [
        'states' => [
            'live' => ['headline' => 'Updated headline'],
        ],
    ];

    $merged = array_replace_recursive($stored, $pending);

    expect($merged['title'])->toBe('The Drop');
    expect($merged['timeline']['drop_time'])->toBe('2099-01-01T00:00:00Z');
    expect($merged['states']['live']['headline'])->toBe('Updated headline');
    expect($merged['states']['live']['subtitle'])->toBe('Original subtitle');
    expect($merged['states']['live']['cta']['label'])->toBe('Shop');
    expect($merged['states']['live']['cta']['url'])->toBe('https://x.test');
    expect($merged['states']['pre_drop']['headline'])->toBe('Coming soon');
});
