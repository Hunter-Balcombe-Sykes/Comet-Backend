<?php

use App\Models\Core\User\User;
use App\Services\Design\Presets\DesignFactorRegistry;
use App\Services\Design\Presets\DesignPresetResolver;
use App\Services\Design\Presets\Factors\GoogleBusinessAttributesFactor;
use App\Services\Design\Presets\Factors\GoogleBusinessTypeFactor;
use App\Services\Design\Presets\Factors\SectorFactor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupDesignKitsTable();
    setupDesignKitContributionsTable();
});

/** A resolver wired like production for the identity factors under test. */
function identityResolver(): DesignPresetResolver
{
    return new DesignPresetResolver(new DesignFactorRegistry(
        [
            new GoogleBusinessTypeFactor,
            new GoogleBusinessAttributesFactor,
        ],
        [
            new SectorFactor,
        ],
    ));
}

/** Raw-insert a platform connection (bypasses model events). */
function identitySeedConnection(User $user, array $payload, string $platform = 'google-business'): string
{
    $id = (string) Str::uuid();
    $now = now()->toDateTimeString();
    DB::connection('pgsql')->table('site.platform_connections')->insert([
        'id' => $id,
        'user_id' => $user->id,
        'platform' => $platform,
        'resource_id' => 'res-'.Str::random(6),
        'payload' => json_encode($payload),
        'is_active' => 1,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return $id;
}

function setSector(User $user, string $slug, string $source): void
{
    DB::connection('pgsql')->table('core.users')->where('id', $user->id)->update([
        'sector' => $slug,
        'sector_source' => $source,
    ]);
    $user->refresh();
}

it('applies the sector bucket preset for a manually declared sector', function () {
    $user = createTenant('declared-therapist');
    setSector($user, 'therapist', 'manual');

    identityResolver()->resolveForUser($user);

    $layer = identityResolver()->presetLayer($user->site->id);
    // therapist → HEALTH_FITNESS bucket
    expect($layer['color_accent'])->toBe('#2f6b57')
        ->and($layer['typography_font_family'])->toBe('oswald')
        ->and($layer['motion_entrance'])->toBe('rise');
});

it('contributes nothing for a google-derived sector (GB type already covers it)', function () {
    $user = createTenant('google-sourced');
    setSector($user, 'restaurant', 'google');

    identityResolver()->resolveForUser($user);

    expect(identityResolver()->presetLayer($user->site->id))->toBe([]);
});

it('lets a declared sector outrank the Google Business category on contested columns', function () {
    $user = createTenant('barber-turned-coach');
    // Google says barber (beauty bucket) …
    identitySeedConnection($user, ['category' => 'Barber shop']);
    // … but the user declares themselves a personal trainer (health bucket).
    setSector($user, 'personal-trainer', 'manual');

    identityResolver()->resolveForUser($user);

    $layer = identityResolver()->presetLayer($user->site->id);
    // Declared identity (band E, 60) wins over the scraped category (band C, 40).
    expect($layer['color_accent'])->toBe('#2f6b57')
        ->and($layer['typography_font_family'])->toBe('oswald');
});

it('refines a bucket with nightlife attributes — stagger + fast override, colours intact', function () {
    $user = createTenant('cocktail-bar');
    identitySeedConnection($user, [
        'category' => 'Bar',
        'amenities' => ['liveMusic' => true],
    ]);

    identityResolver()->resolveForUser($user);

    $layer = identityResolver()->presetLayer($user->site->id);
    // Bucket (food_drink) colours/font survive …
    expect($layer['color_accent'])->toBe('#e0491f')
        ->and($layer['typography_font_family'])->toBe('young-serif')
        // … while the attribute refinement (band D, 52 > 40) owns motion.
        ->and($layer['motion_entrance'])->toBe('stagger')
        ->and($layer['motion_pace'])->toBe('fast');
});

it('refines an upscale listing to the composed treatment', function () {
    $user = createTenant('fine-diner');
    identitySeedConnection($user, [
        'category' => 'Restaurant',
        'priceLevel' => 'PRICE_LEVEL_VERY_EXPENSIVE',
    ]);

    identityResolver()->resolveForUser($user);

    $layer = identityResolver()->presetLayer($user->site->id);
    expect($layer['motion_entrance'])->toBe('fade')
        ->and($layer['motion_pace'])->toBe('slow')
        ->and($layer['weight_regular'])->toBe('300');
});

it('contributes no attribute refinement when no signal is present', function () {
    $user = createTenant('plain-listing');
    identitySeedConnection($user, ['category' => 'Locksmith', 'priceLevel' => 'PRICE_LEVEL_MODERATE']);

    identityResolver()->resolveForUser($user);

    // Locksmith matches no bucket and moderate price is no refinement signal.
    expect(identityResolver()->presetLayer($user->site->id))->toBe([]);
});
