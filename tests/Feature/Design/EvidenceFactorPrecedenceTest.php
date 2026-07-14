<?php

/**
 * Feature-level proof that the v2 evidence factors resolve through the REAL
 * DesignPresetResolver (write path + priority merge) and land in the right
 * bands: declared beats category beats ambient, and a manual design_kits value
 * beats everything. Exercises the whole rebuild (connections → contributions →
 * merged layer) against the SQLite mirror, the same way DesignPresetSystemTest
 * does for the v1 factors.
 */

use App\Models\Core\User\User;
use App\Services\Design\Presets\DesignFactorRegistry;
use App\Services\Design\Presets\DesignPresetResolver;
use App\Services\Design\Presets\Factors\AestheticExpressionFactor;
use App\Services\Design\Presets\Factors\GoogleBusinessTypeFactor;
use App\Services\Design\Presets\Factors\InstagramCategoryFactor;
use App\Services\Design\Presets\Factors\PlatformMixFactor;
use App\Services\Design\Presets\Factors\StorePricePointFactor;
use App\Services\Platforms\Registry\PlatformRegistry;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();              // also creates shop_brands / shop_products / platform_connections
    setupDesignKitsTable();
    setupDesignKitContributionsTable();
    Cache::flush();
});

/** Resolver wired with the v2 evidence factors + the two v1 category factors. */
function evpResolver(): DesignPresetResolver
{
    return new DesignPresetResolver(new DesignFactorRegistry(
        [
            new GoogleBusinessTypeFactor,
            new InstagramCategoryFactor,
        ],
        [],
        [
            new PlatformMixFactor(app(PlatformRegistry::class)),
            new StorePricePointFactor,
            new AestheticExpressionFactor,
        ],
    ));
}

function evpConnection(User $user, string $platform, array $payload = []): string
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

/** Seed a shop connection with $count products at a fixed AUD price. */
function evpShop(User $user, int $count, float $price): void
{
    $connId = evpConnection($user, 'shop', ['storage' => 'relational']);
    $brandId = (string) Str::uuid();
    $now = now()->toDateTimeString();
    DB::connection('pgsql')->table('site.shop_brands')->insert([
        'id' => $brandId,
        'connection_id' => $connId,
        'brand_id' => 'b1',
        'provider' => 'shopify',
        'currency' => 'AUD',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $rows = [];
    for ($i = 0; $i < $count; $i++) {
        $rows[] = [
            'id' => (string) Str::uuid(),
            'brand_id' => $brandId,
            'product_id' => 'p'.$i,
            'position' => $i,
            'data' => json_encode(['price' => (string) $price, 'currency' => 'AUD']),
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }
    DB::connection('pgsql')->table('site.shop_products')->insert($rows);
}

it('resolves evidence factors end-to-end: platform-mix ambient vibe lands', function () {
    $user = createTenant('mix-only');
    // Two social-lifestyle platforms with no category signal -> only the ambient
    // platform-mix factor concludes.
    evpConnection($user, 'instagram', ['username' => 'someone']); // no businessCategory
    evpConnection($user, 'tiktok');

    $changed = evpResolver()->resolveForUser($user);

    expect($changed)->toBeTrue();
    $layer = evpResolver()->presetLayer($user->site->id);
    expect($layer)->not->toHaveKey('effect_surface')       // social-lifestyle vibe
        ->and($layer['effect_shadow_style'])->toBe('soft');
});

it('lets a category factor (band C) beat the platform-mix ambient factor (band A) on motion_pace', function () {
    $user = createTenant('category-beats-ambient');
    // Instagram beauty category (band C, 30) sets motion normal via its bucket;
    // platform-mix here would lean social-lifestyle (normal) too, so to make the
    // contest visible we use a Google restaurant (fast) which is band C (40).
    evpConnection($user, 'google-business', ['category' => 'Restaurant']); // food_drink: motion fast
    evpConnection($user, 'shop'); // adds a retail vote; mix would want normal

    evpResolver()->resolveForUser($user);

    // Category (40) beats ambient mix (12): motion_pace is the bucket's 'fast'.
    expect(evpResolver()->presetLayer($user->site->id)['motion_pace'])->toBe('fast');
});

it('lets the declared aesthetic-expression factor (band E) beat a category factor (band C) on shared columns', function () {
    $user = createTenant('declared-beats-category');
    // Google restaurant -> food_drink bucket: border_radius 0.25rem, weight 300, band C (40).
    evpConnection($user, 'google-business', ['category' => 'Restaurant']);
    // Instagram beauty + a soft name -> expression concludes SOFT, band E (64):
    // border_radius 1.5rem, weight 300, color_bg #faf6f7.
    evpConnection($user, 'instagram', [
        'businessCategory' => 'Beauty, Cosmetic & Personal Care',
        'fullName' => 'Petal & Bloom Skincare',
    ]);

    evpResolver()->resolveForUser($user);
    $layer = evpResolver()->presetLayer($user->site->id);

    // Declared expression (64) wins border_radius + surface over both the
    // food_drink bucket (Google 40, solid) and the Instagram beauty bucket (30).
    expect($layer['border_radius'])->toBe('1.5rem')
        ->and($layer)->not->toHaveKey('effect_surface'); // SOFT lean's material
});

it('lets a manual design_kits value beat every evidence factor', function () {
    $user = createTenant('manual-beats-all');
    evpConnection($user, 'instagram', [
        'businessCategory' => 'Beauty, Cosmetic & Personal Care',
        'fullName' => 'Petal & Bloom Skincare',
    ]); // expression SOFT sets border_radius 1.5rem

    evpResolver()->resolveForUser($user);

    // User manually overrides the radius. (Was color_bg pre-2026-07-10; that
    // column is gone — theme_mode owns the background now.)
    DB::connection('pgsql')->table('site.design_kits')->insert([
        'site_id' => $user->site->id,
        'border_radius' => '3rem',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    $merged = evpResolver()->mergedFlatKit($user->site->id);
    expect($merged['border_radius'])->toBe('3rem'); // manual wins over the SOFT recipe's 1.5rem
});

it('resolves the store price-point factor from seeded products (luxury)', function () {
    $user = createTenant('lux-store');
    evpShop($user, count: 6, price: 200.0); // median 200 AUD -> luxury

    evpResolver()->resolveForUser($user);
    $layer = evpResolver()->presetLayer($user->site->id);

    expect($layer['space_regular'])->toBe('0.8rem')     // airy
        ->and($layer)->not->toHaveKey('effect_surface'); // hairline boutique restraint
});

it('freezes the one-shot aesthetic-expression contribution when IG data later changes', function () {
    $user = createTenant('frozen-expression');
    $connId = evpConnection($user, 'instagram', [
        'businessCategory' => 'Beauty, Cosmetic & Personal Care',
        'fullName' => 'Petal & Bloom Skincare',
    ]);
    evpResolver()->resolveForUser($user);
    // border_radius discriminates the lean (SOFT 1.5rem vs BOLD 0.25rem).
    expect(evpResolver()->presetLayer($user->site->id)['border_radius'])->toBe('1.5rem'); // SOFT

    // The account re-brands to a bold gym; the frozen one-shot must not move.
    DB::connection('pgsql')->table('site.platform_connections')->where('id', $connId)->update([
        'payload' => json_encode(['businessCategory' => 'Gym/Physical Fitness Center', 'fullName' => 'Iron Forge']),
    ]);

    $changed = evpResolver()->resolveForUser($user);
    expect($changed)->toBeFalse();
    expect(evpResolver()->presetLayer($user->site->id)['border_radius'])->toBe('1.5rem'); // still SOFT
});
