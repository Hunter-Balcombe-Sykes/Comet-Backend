<?php

use App\Jobs\Design\ResolveDesignPresetsJob;
use App\Models\Core\Site\DesignKitContribution;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Services\Design\Presets\CategoryStylePresets;
use App\Services\Design\Presets\DesignFactorRegistry;
use App\Services\Design\Presets\DesignPresetResolver;
use App\Services\Design\Presets\Factors\GoogleBusinessTypeFactor;
use App\Services\Design\Presets\Factors\InstagramCategoryFactor;
use App\Services\Design\Presets\PresetTargetableColumns;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();              // also creates site.platform_connections
    setupDesignKitsTable();
    setupDesignKitContributionsTable();
});

/** A resolver wired with both category factors, matching production registration. */
function dkPresetResolver(): DesignPresetResolver
{
    return new DesignPresetResolver(new DesignFactorRegistry([
        new GoogleBusinessTypeFactor,
        new InstagramCategoryFactor,
    ]));
}

/** Raw-insert a platform connection (bypasses model events). */
function dkSeedConnection(User $user, array $payload, string $platform = 'google-business', bool $active = true): string
{
    $id = (string) Str::uuid();
    $now = now()->toDateTimeString();
    DB::connection('pgsql')->table('site.platform_connections')->insert([
        'id' => $id,
        'user_id' => $user->id,
        'platform' => $platform,
        'resource_id' => 'res-'.Str::random(6),
        'payload' => json_encode($payload),
        'is_active' => $active ? 1 : 0,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return $id;
}

it('applies the restaurant preset for a Google Business restaurant', function () {
    $user = createTenant('joes-diner');
    dkSeedConnection($user, ['category' => 'Italian restaurant', 'name' => "Joe's"]);

    $changed = dkPresetResolver()->resolveForUser($user);

    expect($changed)->toBeTrue();
    $layer = dkPresetResolver()->presetLayer($user->site->id);
    expect($layer['color_accent'])->toBe('#e0491f')
        ->and($layer['text_xs'])->toBe('0.8rem')
        ->and($layer['weight_regular'])->toBe('300')
        ->and($layer['border_radius'])->toBe('0.25rem')
        ->and($layer['typography_font_family'])->toBe('young-serif')
        ->and($layer['motion_pace'])->toBe('fast')
        ->and($layer['effect_surface'])->toBe('solid');
});

it('contributes nothing for a Google Business type with no matching bucket', function () {
    // 'Cafe' now legitimately matches food_drink under the bucket system —
    // this test needs a type with no keyword match at all.
    $user = createTenant('janes-locksmith');
    dkSeedConnection($user, ['category' => 'Locksmith']);

    dkPresetResolver()->resolveForUser($user);

    expect(dkPresetResolver()->presetLayer($user->site->id))->toBe([]);
    expect(DesignKitContribution::query()->where('site_id', $user->site->id)->count())->toBe(0);
});

it('contributes nothing when the Google Business category is missing', function () {
    $user = createTenant('no-category');
    dkSeedConnection($user, ['name' => 'Mystery Co']);

    dkPresetResolver()->resolveForUser($user);

    expect(dkPresetResolver()->presetLayer($user->site->id))->toBe([]);
});

it('freezes the one-shot contribution when the category later changes', function () {
    $user = createTenant('was-a-restaurant');
    $connId = dkSeedConnection($user, ['category' => 'Restaurant']);
    dkPresetResolver()->resolveForUser($user);
    expect(dkPresetResolver()->presetLayer($user->site->id)['color_accent'])->toBe('#e0491f');

    // The business re-categorises to a cafe; the frozen one-shot must not move.
    DB::connection('pgsql')->table('site.platform_connections')
        ->where('id', $connId)->update(['payload' => json_encode(['category' => 'Cafe'])]);

    $changed = dkPresetResolver()->resolveForUser($user);

    expect($changed)->toBeFalse();
    expect(dkPresetResolver()->presetLayer($user->site->id)['color_accent'])->toBe('#e0491f');
});

it('sweeps contributions when the integration disconnects', function () {
    $user = createTenant('closing-down');
    $connId = dkSeedConnection($user, ['category' => 'Restaurant']);
    dkPresetResolver()->resolveForUser($user);
    expect(DesignKitContribution::query()->where('site_id', $user->site->id)->count())->toBeGreaterThan(0);

    // Soft-delete = disconnect.
    DB::connection('pgsql')->table('site.platform_connections')
        ->where('id', $connId)->update(['deleted_at' => now()->toDateTimeString()]);

    $changed = dkPresetResolver()->resolveForUser($user);

    expect($changed)->toBeTrue();
    expect(dkPresetResolver()->presetLayer($user->site->id))->toBe([]);
    expect(DesignKitContribution::query()->where('site_id', $user->site->id)->count())->toBe(0);
});

it('lets a manual design_kit value win over the preset layer', function () {
    $user = createTenant('custom-diner');
    dkSeedConnection($user, ['category' => 'Restaurant']);
    dkPresetResolver()->resolveForUser($user);

    // User manually set their own accent. (Was color_bg pre-2026-07-10; that
    // column is gone — theme_mode owns the background now.)
    DB::connection('pgsql')->table('site.design_kits')->insert([
        'site_id' => $user->site->id,
        'color_accent' => '#123456',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    $merged = dkPresetResolver()->mergedFlatKit($user->site->id);

    expect($merged['color_accent'])->toBe('#123456')                 // manual wins over the preset's #e0491f
        ->and($merged['typography_font_family'])->toBe('young-serif'); // preset fills the rest
});

it('resolves the highest-priority contribution per column, order-independent', function () {
    $user = createTenant('priority-test');
    $siteId = $user->site->id;

    // Two sources set the accent; higher priority wins regardless of row order.
    foreach ([['a-source', 40, '#aaaaaa'], ['z-source', 60, '#bbbbbb']] as [$source, $prio, $val]) {
        DesignKitContribution::query()->create([
            'site_id' => $siteId,
            'source' => $source,
            'integration' => 'test',
            'priority' => $prio,
            'mode' => 'one_shot',
            'target_var' => 'color_accent',
            'value' => $val,
        ]);
    }

    expect(dkPresetResolver()->presetLayer($siteId)['color_accent'])->toBe('#bbbbbb');
});

it('is a no-op when no factors are registered (dark launch)', function () {
    $user = createTenant('dark-launch');
    dkSeedConnection($user, ['category' => 'Restaurant']);

    $emptyResolver = new DesignPresetResolver(new DesignFactorRegistry([]));
    $changed = $emptyResolver->resolveForUser($user);

    expect($changed)->toBeFalse();
    expect(DesignKitContribution::query()->where('site_id', $user->site->id)->count())->toBe(0);
});

it('dispatches a preset resolve job when a connection is created', function () {
    Queue::fake();
    $user = createTenant('dispatch-test');

    IntegrationConnection::query()->create([
        'user_id' => $user->id,
        'platform' => 'google-business',
        'resource_id' => 'r1',
        'payload' => ['category' => 'Restaurant'],
        'is_active' => true,
    ]);

    Queue::assertPushed(ResolveDesignPresetsJob::class);
});

// ── Category buckets ───────────────────────────────────────────────────────

it('classifies a range of Google Business types into their expected bucket', function (string $category, string $bucket) {
    $user = createTenant('gb-bucket-'.Str::slug($category).'-'.Str::random(4));
    dkSeedConnection($user, ['category' => $category]);

    dkPresetResolver()->resolveForUser($user);

    // Buckets emit only targetable columns since the 2026-07-10 factor pass;
    // the filter() wrap is belt-and-braces (it must be an identity here).
    expect(dkPresetResolver()->presetLayer($user->site->id))
        ->toEqualCanonicalizing(PresetTargetableColumns::filter(CategoryStylePresets::forBucket($bucket)))
        ->toEqualCanonicalizing(CategoryStylePresets::forBucket($bucket));
})->with([
    'Barber shop' => ['Barber shop', CategoryStylePresets::BEAUTY_PERSONAL_CARE],   // collision guard: NOT food_drink via 'bar'
    'Hair salon' => ['Hair salon', CategoryStylePresets::BEAUTY_PERSONAL_CARE],
    'Gym' => ['Gym', CategoryStylePresets::HEALTH_FITNESS],
    'Yoga studio' => ['Yoga studio', CategoryStylePresets::HEALTH_FITNESS],
    'Sports Club' => ['Sports Club', CategoryStylePresets::HEALTH_FITNESS],
    'Real estate agency' => ['Real estate agency', CategoryStylePresets::PROFESSIONAL_SERVICES],
    'Clothing store' => ['Clothing store', CategoryStylePresets::RETAIL_SHOPPING],
    'Plumber' => ['Plumber', CategoryStylePresets::HOME_SERVICES],
    'Hotel' => ['Hotel', CategoryStylePresets::HOSPITALITY],
    'Car repair' => ['Car repair', CategoryStylePresets::AUTOMOTIVE],
    'Art gallery' => ['Art gallery', CategoryStylePresets::CREATIVE_ENTERTAINMENT],
    'Dance school' => ['Dance school', CategoryStylePresets::EDUCATION_COACHING],
    'Pizza Restaurant' => ['Pizza Restaurant', CategoryStylePresets::FOOD_DRINK],
]);

it('classifies a range of Instagram business categories into their expected bucket', function (string $category, string $bucket) {
    $user = createTenant('ig-bucket-'.Str::slug($category).'-'.Str::random(4));
    dkSeedConnection($user, ['businessCategory' => $category], 'instagram');

    dkPresetResolver()->resolveForUser($user);

    expect(dkPresetResolver()->presetLayer($user->site->id))
        ->toEqualCanonicalizing(CategoryStylePresets::forBucket($bucket));
})->with([
    'Beauty, Cosmetic & Personal Care' => ['Beauty, Cosmetic & Personal Care', CategoryStylePresets::BEAUTY_PERSONAL_CARE],
    'Gym/Physical Fitness Center' => ['Gym/Physical Fitness Center', CategoryStylePresets::HEALTH_FITNESS],
    'Photographer' => ['Photographer', CategoryStylePresets::CREATIVE_ENTERTAINMENT],
    'Business Consultant' => ['Business Consultant', CategoryStylePresets::PROFESSIONAL_SERVICES],
    'Life Coach' => ['Life Coach', CategoryStylePresets::EDUCATION_COACHING],
    'Shopping & Retail' => ['Shopping & Retail', CategoryStylePresets::RETAIL_SHOPPING],
    'Restaurant' => ['Restaurant', CategoryStylePresets::FOOD_DRINK],
]);

it('contributes nothing for Instagram categories with no real signal', function (string $category) {
    $user = createTenant('ig-nomatch-'.Str::slug((string) $category).'-'.Str::random(4));
    dkSeedConnection($user, ['businessCategory' => $category], 'instagram');

    dkPresetResolver()->resolveForUser($user);

    expect(dkPresetResolver()->presetLayer($user->site->id))->toBe([]);
})->with([
    'None' => ['None'],           // the literal sentinel the scraper actually emits
    'Personal Blog' => ['Personal Blog'],
    'Public Figure' => ['Public Figure'],
    'Just For Fun' => ['Just For Fun'],
    'Community' => ['Community'],
    'Local Business' => ['Local Business'],
]);

// ── Cross-platform priority (Google outranks Instagram) ────────────────────

it('lets Instagram fill the preset layer when Google is not connected', function () {
    $user = createTenant('ig-only');
    dkSeedConnection($user, ['businessCategory' => 'Beauty, Cosmetic & Personal Care'], 'instagram');

    dkPresetResolver()->resolveForUser($user);

    expect(dkPresetResolver()->presetLayer($user->site->id))
        ->toEqualCanonicalizing(CategoryStylePresets::forBucket(CategoryStylePresets::BEAUTY_PERSONAL_CARE));
});

it('lets Instagram fill the layer when Google is connected but does not match a bucket', function () {
    $user = createTenant('ig-fills-gap');
    dkSeedConnection($user, ['category' => 'Some Unrecognised Place Type'], 'google-business');
    dkSeedConnection($user, ['businessCategory' => 'Gym/Physical Fitness Center'], 'instagram');

    dkPresetResolver()->resolveForUser($user);

    expect(dkPresetResolver()->presetLayer($user->site->id))
        ->toEqualCanonicalizing(CategoryStylePresets::forBucket(CategoryStylePresets::HEALTH_FITNESS));
});

it('fully overrides Instagram with Google when both match DIFFERENT buckets (every column overlaps)', function () {
    $user = createTenant('gb-wins');
    dkSeedConnection($user, ['category' => 'Restaurant'], 'google-business');                                  // -> food_drink
    dkSeedConnection($user, ['businessCategory' => 'Beauty, Cosmetic & Personal Care'], 'instagram');           // -> beauty_personal_care

    dkPresetResolver()->resolveForUser($user);

    // Every bucket sets the same 7 keys, so Google's priority (40 > 30) wins
    // ALL of them — not a partial blend of the two buckets.
    expect(dkPresetResolver()->presetLayer($user->site->id))
        ->toEqualCanonicalizing(CategoryStylePresets::forBucket(CategoryStylePresets::FOOD_DRINK));
});

it('produces the identical merged result regardless of which platform connects first', function () {
    $userA = createTenant('order-a');
    dkSeedConnection($userA, ['category' => 'Restaurant'], 'google-business');
    dkSeedConnection($userA, ['businessCategory' => 'Beauty, Cosmetic & Personal Care'], 'instagram');
    dkPresetResolver()->resolveForUser($userA);

    $userB = createTenant('order-b');
    dkSeedConnection($userB, ['businessCategory' => 'Beauty, Cosmetic & Personal Care'], 'instagram');
    dkSeedConnection($userB, ['category' => 'Restaurant'], 'google-business');
    dkPresetResolver()->resolveForUser($userB);

    expect(dkPresetResolver()->presetLayer($userA->site->id))
        ->toBe(dkPresetResolver()->presetLayer($userB->site->id));
});
