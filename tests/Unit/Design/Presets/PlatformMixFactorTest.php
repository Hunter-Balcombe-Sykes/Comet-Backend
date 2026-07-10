<?php

/**
 * Unit tests for PlatformMixFactor — the ambient "vibe from the mix of connected
 * platform TYPES" factor. Drives detect() directly against an in-memory
 * IdentityEvidence built from unsaved connections; uses the REAL PlatformRegistry
 * (resolved from the booted container) so the slug→category→vibe classification
 * is exercised against production platform metadata.
 */

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\Site\Site;
use App\Models\Core\User\User;
use App\Services\Design\Presets\Factors\PlatformMixFactor;
use App\Services\Design\Presets\IdentityEvidence;
use App\Services\Platforms\Registry\PlatformRegistry;
use Illuminate\Support\Collection;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

function mixFactor(): PlatformMixFactor
{
    return new PlatformMixFactor(app(PlatformRegistry::class));
}

/** IdentityEvidence over a set of unsaved connections, one per platform slug. */
function mixEvidence(array $slugs): IdentityEvidence
{
    $connections = new Collection(array_map(
        fn (string $slug) => new IntegrationConnection(['user_id' => 'u1', 'platform' => $slug]),
        $slugs,
    ));

    return new IdentityEvidence(
        (new User)->forceFill(['id' => 'u1']),
        (new Site)->forceFill(['id' => 's1']),
        $connections,
    );
}

it('maps a creator-AV-heavy mix to fast motion + hard shadows + solid surface', function () {
    $out = mixFactor()->detect(mixEvidence(['youtube', 'twitch', 'vimeo']));

    expect($out)->toBe([
        'motion_pace' => 'fast',
        'effect_shadow_style' => 'hard',
        'effect_surface' => 'solid',
    ]);
});

it('maps a social-lifestyle mix to normal motion + soft shadows + glass surface', function () {
    $out = mixFactor()->detect(mixEvidence(['instagram', 'tiktok', 'spotify']));

    expect($out)->toBe([
        'motion_pace' => 'normal',
        'effect_shadow_style' => 'soft',
        'effect_surface' => 'glass',
    ]);
});

it('maps a local-service mix to slow motion + soft shadows + glass surface', function () {
    $out = mixFactor()->detect(mixEvidence(['google-business', 'fresha', 'opentable']));

    expect($out)->toBe([
        'motion_pace' => 'slow',
        'effect_shadow_style' => 'soft',
        'effect_surface' => 'glass',
    ]);
});

it('maps a retail-only mix to normal motion + flat shadows + outline surface', function () {
    // The vibe tally counts DISTINCT platform types, and retail's sole slug is
    // `shop` — so retail only leads when the store is the only categorised
    // platform. (A `custom` link classifies as content → creator-av, so it would
    // tie; this is the clean retail-dominant case.)
    $out = mixFactor()->detect(mixEvidence(['shop']));

    expect($out)->toBe([
        'motion_pace' => 'normal',
        'effect_shadow_style' => 'flat',
        'effect_surface' => 'outline',
    ]);
});

it('abstains when two vibes tie for the lead', function () {
    // 1 creator-av (youtube) vs 1 local-service (google-business) — no plurality.
    expect(mixFactor()->detect(mixEvidence(['youtube', 'google-business'])))->toBe([]);
});

it('abstains when nothing is connected', function () {
    expect(mixFactor()->detect(mixEvidence([])))->toBe([]);
});

it('ignores unregistered / uncategorised slugs when tallying', function () {
    // 'custom' has no design vibe; two social platforms still carry the read.
    $out = mixFactor()->detect(mixEvidence(['custom', 'instagram', 'tiktok']));

    expect($out['effect_surface'])->toBe('glass');
});

it('is registered in band A (ambient) as an auto factor', function () {
    $factor = mixFactor();
    expect($factor->priority())->toBe(12)
        ->and($factor->mode()->value)->toBe('auto')
        ->and($factor->key())->toBe('platform-mix:vibe');
});
