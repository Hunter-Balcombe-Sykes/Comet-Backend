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
use App\Services\Design\Presets\CategoryStylePresets;
use App\Services\Design\Presets\DesignFactorRegistry;
use App\Services\Design\Presets\Factors\AestheticExpressionFactor;
use App\Services\Design\Presets\Factors\CuisineFactor;
use App\Services\Design\Presets\Factors\HoursRhythmFactor;
use App\Services\Design\Presets\Factors\LaunchRecipeFactor;
use App\Services\Design\Presets\Factors\MusicGenreFactor;
use App\Services\Design\Presets\Factors\PlatformMixFactor;
use App\Services\Design\Presets\Factors\StorePricePointFactor;
use App\Services\Design\Presets\IdentityEvidence;
use App\Services\Design\Presets\LaunchRecipes;
use App\Services\Design\Presets\PresetTargetableColumns;
use App\Services\Design\Presets\StyleTiers;
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

    // Pill + hard shadow: every soft-shape emitter pairs 1.5rem with soft/flat
    // deliberately; a merged pill+hard can only be a cross-factor accident.
    // (Square + hard/solid is fine — that IS the poster register; outline +
    // hard is neubrutalist and legitimate; neither is banned.)
    expect($radius === '1.5rem' && $shadow === 'hard')->toBeFalse(
        "incoherent: pill radius ({$radius}) + hard shadow",
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
        ->and($r['kit']['typography_font_family'])->toBe('helvetica-now')
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
        ->and($r['kit']['border_radius'])->toBe('0') // square — the 2-signal poster archetype earns the extreme stop
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
    expect($r['kit']['typography_font_family'])->toBe('general-sans')
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
    expect($r['kit']['typography_font_family'])->toBe('helvetica-neue')
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
    expect($r['kit']['typography_font_family'])->toBe('forma-djr')
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

// ── Vocabulary sweep ─────────────────────────────────────────────────────────
//
// Every statically-enumerable emission — bucket, recipe, tier literal, factor
// overlay const — must sit EXACTLY on the locked design-kit vocabulary. This is
// the net that would have caught the pre-2026-07-10 corner drift (0.2/0.3/0.4/
// 0.5/0.6rem free-hand radii) at authoring time instead of migration time.

/** @return array<string, array<string, string>> every static emission set, keyed by a debug name */
function swAllStaticEmissions(): array
{
    $sets = [];

    foreach ((new ReflectionClass(CategoryStylePresets::class))->getConstants() as $const) {
        if (is_string($const)) {
            $sets["bucket:{$const}"] = CategoryStylePresets::forBucket($const);
        }
    }

    foreach (LaunchRecipes::all() as $recipe) {
        $sets["recipe:{$recipe['key']}"] = $recipe['values'];
    }

    // Every analyzer tier of every signal, through the real mapping.
    $tiers = (new ReflectionClass(StyleTiers::class))->getConstant('TIERS');
    foreach ($tiers as $signal => $tierMap) {
        foreach (array_keys($tierMap) as $tier) {
            $sets["tier:{$signal}:{$tier}"] = StyleTiers::columnsFromTiers([$signal => $tier]);
        }
    }

    // Factor overlay consts (the full static emission surface of the stack).
    foreach ([
        [HoursRhythmFactor::class, 'RHYTHM_TARGETS'],
        [AestheticExpressionFactor::class, 'DIRECTION_TARGETS'],
        [MusicGenreFactor::class, 'FAMILY_TARGETS'],
        [CuisineFactor::class, 'HINT_TARGETS'],
        [PlatformMixFactor::class, 'VIBE_TARGETS'],
        [StorePricePointFactor::class, 'BAND_TARGETS'],
    ] as [$class, $const]) {
        foreach ((new ReflectionClass($class))->getConstant($const) as $key => $values) {
            $sets[class_basename($class).":{$key}"] = $values;
        }
    }
    $sets['CuisineFactor:specific'] = (new ReflectionClass(CuisineFactor::class))->getConstant('SPECIFIC_TARGET');

    return $sets;
}

it('every statically-enumerable emission sits exactly on the locked vocabulary', function () {
    $vocab = [
        'text_body' => ['0.8rem', '0.85rem', '0.9rem'],                 // Small / Medium / Large body
        'weight_regular' => ['300', '400', '500', '600'],
        'border_radius' => ['0', '0.25rem', '0.85rem', '1.5rem'],       // Square / Soft / Rounded / Pill
        'border_thickness' => ['0.5px', '1px', '2px'],                  // hairline / standard / bold
        'space_regular' => ['0.8rem', '0.95rem', '1.15rem'],            // compact / regular / generous
        'motion_pace' => ['slow', 'normal', 'fast'],
        'effect_surface' => ['glass', 'solid', 'outline'],
        'effect_shadow_style' => ['flat', 'soft', 'hard'],
        'effect_link_style' => ['underline-hover', 'underline-always', 'plain'],
        'effect_image_treatment' => ['none', 'mono', 'duotone', 'warm', 'muted'],
    ];

    foreach (swAllStaticEmissions() as $name => $values) {
        foreach ($values as $column => $value) {
            expect(PresetTargetableColumns::isValid($column))->toBeTrue(
                "{$name} emits non-targetable column {$column}",
            );

            if ($column === 'color_accent') {
                expect((bool) preg_match('/^#[0-9a-f]{6}$/', $value))->toBeTrue(
                    "{$name} accent '{$value}' is not lowercase 6-digit hex",
                );

                continue;
            }

            if ($column === 'typography_font_family') {
                // Closed loop: an emitted font slug must be a live StyleTiers
                // font tier (the 7-font roster allowlist).
                expect(StyleTiers::columnsFromTiers(['font' => $value]))->toBe(
                    ['typography_font_family' => $value],
                    "{$name} font '{$value}' is not in the live roster",
                );

                continue;
            }

            expect(array_key_exists($column, $vocab))->toBeTrue(
                "{$name} emits {$column}, which has no vocabulary entry in this sweep",
            );
            expect(in_array($value, $vocab[$column], true))->toBeTrue(
                "{$name} emits {$column}='{$value}', off the locked vocabulary [".implode(', ', $vocab[$column]).']',
            );
        }
    }
});
