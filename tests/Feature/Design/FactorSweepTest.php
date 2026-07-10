<?php

/**
 * Golden-profiles sweep (spec §1c) — the coherence regression net the "be more
 * anal" bar demands. It constructs ~8 archetypal IdentityEvidence bags and, for
 * each, runs the WHOLE evidence-factor stack the way the resolver does (priority
 * merge, higher band wins a column, ties break by source asc) and asserts:
 *   • the expected launch recipe fires (or, for weak/conflicting bags, that it
 *     correctly ABSTAINS), and
 *   • the merged kit contains NO incoherent column combination — never a soft bg
 *     with a sharp radius and a heavy weight together, etc.
 *
 * This is what proves a FUTURE factor change didn't make some archetype ugly: if
 * someone adds a factor that stamps a contradictory axis, one of these bags goes
 * red. It runs the real registered factors from the container, so it also guards
 * the registration + band ordering.
 *
 * In-memory evidence only (no DB): each bag is an IdentityEvidence built from
 * unsaved connections/user/shop relations, exactly like the unit tests. Factors
 * whose signal needs the DB (hours) or isn't stored (palette/genre) simply abstain
 * here, which is the correct behaviour for these bags.
 */

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\Site\ShopBrand;
use App\Models\Core\Site\ShopProduct;
use App\Models\Core\Site\Site;
use App\Models\Core\User\User;
use App\Services\Design\Presets\DesignFactorRegistry;
use App\Services\Design\Presets\Factors\LaunchRecipeFactor;
use App\Services\Design\Presets\IdentityEvidence;
use App\Services\Design\Presets\PresetTargetableColumns;
use Illuminate\Support\Collection;

// Feature tests inherit Tests\TestCase from tests/Pest.php — no per-file uses().

/** A plain connection. */
function swConnection(string $platform, array $payload = []): IntegrationConnection
{
    return new IntegrationConnection(['user_id' => 'u1', 'platform' => $platform, 'payload' => $payload]);
}

/** A shop connection carrying $prices (AUD), in memory. */
function swShop(array $prices): IntegrationConnection
{
    $connection = swConnection('shop', ['storage' => 'relational']);
    $brand = (new ShopBrand)->forceFill(['brand_id' => 'b1', 'currency' => 'AUD']);
    $brand->setRelation('products', new Collection(array_map(
        fn ($p) => (new ShopProduct)->forceFill(['data' => ['price' => (string) $p, 'currency' => 'AUD']]),
        $prices,
    )));
    $connection->setRelation('shopBrands', new Collection([$brand]));

    return $connection;
}

function swEvidence(Collection $connections, array $userAttrs = []): IdentityEvidence
{
    return new IdentityEvidence(
        (new User)->forceFill(array_merge(['id' => 'u1'], $userAttrs)),
        (new Site)->forceFill(['id' => 's1']),
        $connections,
        new Collection,
    );
}

/**
 * Merge every registered evidence factor over one bag the way DesignPresetResolver
 * does: run detect(), filter to targetable columns, and let the highest priority
 * win each column (ties → source asc). Returns [column => value].
 *
 * @return array{kit: array<string,string>, recipe: array<string,string>}
 */
function swResolve(IdentityEvidence $evidence): array
{
    $registry = app(DesignFactorRegistry::class);

    /** @var array<string, array{priority:int, source:string, value:string}> $winners */
    $winners = [];
    $recipe = [];

    foreach ($registry->evidenceFactors() as $factor) {
        try {
            $values = PresetTargetableColumns::filter($factor->detect($evidence));
        } catch (Throwable) {
            $values = [];
        }
        if ($factor instanceof LaunchRecipeFactor) {
            $recipe = $values;
        }
        $priority = $factor->priority();
        $source = $factor->key();
        foreach ($values as $var => $value) {
            $current = $winners[$var] ?? null;
            $wins = $current === null
                || $priority > $current['priority']
                || ($priority === $current['priority'] && $source < $current['source']);
            if ($wins) {
                $winners[$var] = ['priority' => $priority, 'source' => $source, 'value' => $value];
            }
        }
    }

    return [
        'kit' => array_map(static fn (array $w): string => $w['value'], $winners),
        'recipe' => $recipe,
    ];
}

/**
 * Assert a merged kit is internally coherent. The axes surface / radius /
 * weight / shadow must not pull in contradictory directions (backgrounds are
 * theme_mode-owned since 2026-07-10, so the material axis is effect_surface).
 * Concretely (spec §1c, re-based for the theme/surface era):
 *   • a GLASS surface (the soft material) must NOT co-occur with a HARD shadow
 *     — translucent panels with brutal drop shadows is the canonical mash-up.
 *   • a VERY-ROUNDED radius (1.5rem, the soft shape) must NOT co-occur with a
 *     HEAVY weight — soft shapes want light-to-regular type.
 * These are deliberately narrow, high-confidence contradiction checks — they
 * catch a factor stamping across another's coherent set, without over-constraining
 * legitimate combinations.
 *
 * @param  array<string, string>  $kit
 */
function assertCoherent(array $kit): void
{
    $surface = $kit['effect_surface'] ?? null;
    $shadow = $kit['effect_shadow_style'] ?? null;
    $radius = $kit['border_radius'] ?? null;
    $weight = $kit['weight_regular'] ?? null;

    $heavyWeight = in_array($weight, ['600', '700'], true);

    expect($surface === 'glass' && $shadow === 'hard')->toBeFalse(
        "incoherent: glass surface + hard shadow (weight {$weight}, radius {$radius})",
    );

    expect($radius === '1.5rem' && $heavyWeight)->toBeFalse(
        "incoherent: very-rounded radius ({$radius}) + heavy weight ({$weight})",
    );
}

// ── Golden bags ──────────────────────────────────────────────────────────────

it('beauty-soft archetype resolves to the soft recipe, coherently', function () {
    $r = swResolve(swEvidence(new Collection([
        swConnection('instagram', ['businessCategory' => 'Beauty, Cosmetic & Personal Care', 'fullName' => 'Petal & Bloom Skincare']),
        swConnection('google-business', ['category' => 'Beauty salon']),
    ])));

    expect($r['recipe'])->not->toBe([]);                       // recipe fired
    expect($r['kit']['border_radius'])->toBe('1.5rem')
        ->and($r['kit']['weight_regular'])->toBe('300')
        ->and($r['kit']['typography_font_family'])->toBe('melodrama')
        ->and($r['kit']['effect_surface'])->toBe('glass')
        ->and($r['kit']['effect_shadow_style'])->toBe('soft');
    assertCoherent($r['kit']);
});

it('fitness-bold archetype resolves to the bold recipe, coherently', function () {
    $r = swResolve(swEvidence(
        new Collection([
            swConnection('google-business', ['category' => 'Gym']),
            swConnection('instagram', ['businessCategory' => 'Gym/Physical Fitness Center', 'fullName' => 'Iron Forge Strength']),
        ]),
    ));

    expect($r['recipe'])->not->toBe([]);
    expect($r['kit']['weight_regular'])->toBe('600')
        ->and($r['kit']['border_radius'])->toBe('0.25rem')
        ->and($r['kit']['effect_surface'])->toBe('solid')
        ->and($r['kit']['effect_shadow_style'])->toBe('hard')
        ->and($r['kit']['color_accent'])->toBe('#c81e1e');
    assertCoherent($r['kit']);
});

it('hospitality-warm archetype resolves to the warm recipe, coherently', function () {
    $r = swResolve(swEvidence(new Collection([
        swConnection('google-business', ['category' => 'Cafe']),
        swConnection('instagram', ['businessCategory' => 'Restaurant']),
    ])));

    expect($r['recipe'])->not->toBe([]);
    expect($r['kit']['typography_font_family'])->toBe('young-serif')
        ->and($r['kit']['effect_surface'])->toBe('glass')
        ->and($r['kit']['effect_image_treatment'])->toBe('warm');
    assertCoherent($r['kit']);
});

it('fine-dining archetype resolves to the editorial recipe, coherently', function () {
    $r = swResolve(swEvidence(new Collection([
        swConnection('google-business', ['category' => 'Fine dining restaurant']),
        swShop([180, 220, 260, 300]),
    ])));

    // Dark-luxury without a bg column: gold accent + light weight + outline.
    expect($r['recipe'])->not->toBe([]);
    expect($r['kit']['typography_font_family'])->toBe('young-serif')
        ->and($r['kit']['color_accent'])->toBe('#c9a24b')
        ->and($r['kit']['weight_regular'])->toBe('300')
        ->and($r['kit']['effect_surface'])->toBe('outline')
        ->and($r['kit']['effect_image_treatment'])->toBe('duotone');
    assertCoherent($r['kit']);
});

it('creative archetype resolves to the creative recipe, coherently', function () {
    $r = swResolve(swEvidence(new Collection([
        swConnection('google-business', ['category' => 'Photographer']),
        swConnection('instagram', ['businessCategory' => 'Photographer']),
    ])));

    expect($r['recipe'])->not->toBe([]);
    expect($r['kit']['typography_font_family'])->toBe('geist')
        ->and($r['kit']['effect_surface'])->toBe('outline')
        ->and($r['kit']['effect_image_treatment'])->toBe('none'); // portfolio work is never filtered
    assertCoherent($r['kit']);
});

it('maker archetype resolves to the maker recipe, coherently', function () {
    $r = swResolve(swEvidence(new Collection([
        swConnection('instagram', ['businessCategory' => 'Handmade ceramics & pottery']),
        swShop([25, 30, 35, 38]),
    ])));

    expect($r['recipe'])->not->toBe([]);
    expect($r['kit']['typography_font_family'])->toBe('origin')
        ->and($r['kit']['border_radius'])->toBe('0.85rem')
        ->and($r['kit']['effect_surface'])->toBe('solid'); // tactile craft, not glass
    assertCoherent($r['kit']);
});

it('a bare single-connection profile abstains from any recipe and stays coherent', function () {
    // One Instagram beauty read — one group; no recipe should fire. The
    // individual factors (aesthetic-expression needs 2 concordant IG signals;
    // here only the category leans) may or may not contribute, but whatever
    // survives must be coherent and no recipe may fire.
    $r = swResolve(swEvidence(new Collection([
        swConnection('instagram', ['businessCategory' => 'Beauty', 'fullName' => 'Jordan Smith']),
    ])));

    expect($r['recipe'])->toBe([]); // no archetype from a single weak source
    assertCoherent($r['kit']);
});

it('a conflicting-signals profile abstains from any recipe and stays coherent', function () {
    // Beauty IG vs fitness Google — one group each, contradictory. No recipe
    // fires; the merged kit (from whatever individual factors contribute) must
    // still be coherent — never a soft+sharp+heavy mash-up.
    $r = swResolve(swEvidence(new Collection([
        swConnection('instagram', ['businessCategory' => 'Beauty, Cosmetic & Personal Care', 'fullName' => 'Bloom Beauty']),
        swConnection('google-business', ['category' => 'Gym']),
    ])));

    expect($r['recipe'])->toBe([]);
    assertCoherent($r['kit']);
});
