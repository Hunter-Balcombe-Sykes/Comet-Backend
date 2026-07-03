<?php

/**
 * Pure-logic unit tests for CategoryStylePresets::classify() — no DB, no
 * HTTP, no container. classify() is a static function over a caller-supplied
 * ORDERED keyword=>bucket map; the contract under test is that callers order
 * specific keywords before generic ones so a specific match short-circuits
 * before a generic substring can swallow it (e.g. 'barber' before 'bar').
 *
 * Rather than hand-write a keyword map (which could drift from reality and
 * mask a real reordering bug), these tests pull the REAL private
 * KEYWORD_BUCKETS constants straight out of the two actual callers —
 * GoogleBusinessTypeFactor and InstagramCategoryFactor — via reflection.
 * ReflectionClass::getConstant() ignores visibility, so this works without
 * any production code change.
 *
 * A brute-force scan of both real maps (every keyword pair, in declared
 * order) found exactly one cross-bucket collision: 'barber' (earlier,
 * beauty_personal_care) contains 'bar' (later, food_drink) as a substring —
 * present in BOTH factors' maps. The other same-text-contains-text pairs
 * ('photographer'/'photo', 'art gallery'/'gallery', 'dance school'/'dance')
 * all resolve to the SAME bucket on both sides, so their order is safe but
 * not a "specific-vs-generic" collision — covered here anyway as a sanity
 * check that ordering-safe pairs still classify correctly.
 */

use App\Services\Design\Presets\CategoryStylePresets;
use App\Services\Design\Presets\Factors\GoogleBusinessTypeFactor;
use App\Services\Design\Presets\Factors\InstagramCategoryFactor;

/** @return array<string, string> */
function realKeywordMap(string $factorClass): array
{
    return (new ReflectionClass($factorClass))->getConstant('KEYWORD_BUCKETS');
}

// ── specific-before-generic: the collision the finding is about ────────────

it('classifies "Barber shop" as beauty via Google\'s real map, not food_drink via the generic "bar"', function () {
    $bucket = CategoryStylePresets::classify('Barber shop', realKeywordMap(GoogleBusinessTypeFactor::class));

    expect($bucket)->toBe(CategoryStylePresets::BEAUTY_PERSONAL_CARE);
});

it('classifies "Barber shop" as beauty via Instagram\'s real map, not food_drink via the generic "bar"', function () {
    $bucket = CategoryStylePresets::classify('Barber shop', realKeywordMap(InstagramCategoryFactor::class));

    expect($bucket)->toBe(CategoryStylePresets::BEAUTY_PERSONAL_CARE);
});

it('still classifies a plain "Cocktail bar" as food_drink when "barber" does not match', function () {
    // Contrast case: proves 'bar' itself still works as a keyword — the
    // ordering fix only protects the 'barber' collision, it doesn't disable
    // 'bar' for genuine bar/pub categories.
    $bucket = CategoryStylePresets::classify('Cocktail bar', realKeywordMap(GoogleBusinessTypeFactor::class));

    expect($bucket)->toBe(CategoryStylePresets::FOOD_DRINK);
});

// ── same-bucket "collisions" (order-safe, but exercised for completeness) ──

it('classifies "Art gallery" as creative_entertainment regardless of the gallery/art-gallery overlap', function () {
    $bucket = CategoryStylePresets::classify('Art gallery', realKeywordMap(GoogleBusinessTypeFactor::class));

    expect($bucket)->toBe(CategoryStylePresets::CREATIVE_ENTERTAINMENT);
});

it('classifies "Dance school" as education_coaching regardless of the dance/dance-school overlap', function () {
    $bucket = CategoryStylePresets::classify('Dance school', realKeywordMap(GoogleBusinessTypeFactor::class));

    expect($bucket)->toBe(CategoryStylePresets::EDUCATION_COACHING);
});

// ── realistic examples straight from the two factors' own doc comments ────

it('classifies the Google Places example "Italian restaurant" as food_drink', function () {
    $bucket = CategoryStylePresets::classify('Italian restaurant', realKeywordMap(GoogleBusinessTypeFactor::class));

    expect($bucket)->toBe(CategoryStylePresets::FOOD_DRINK);
});

it('classifies the Instagram example "Beauty, Cosmetic & Personal Care" as beauty_personal_care', function () {
    $bucket = CategoryStylePresets::classify(
        'Beauty, Cosmetic & Personal Care',
        realKeywordMap(InstagramCategoryFactor::class)
    );

    expect($bucket)->toBe(CategoryStylePresets::BEAUTY_PERSONAL_CARE);
});

// ── no-match fallback (documented: blank / "none" / non-matching text) ─────

it('returns null for a blank category string', function () {
    expect(CategoryStylePresets::classify('', realKeywordMap(GoogleBusinessTypeFactor::class)))->toBeNull();
});

it('returns null for whitespace-only input (trimmed to blank)', function () {
    expect(CategoryStylePresets::classify('   ', realKeywordMap(GoogleBusinessTypeFactor::class)))->toBeNull();
});

it('treats the literal "None" (case-insensitive) as no match, per Instagram\'s documented behaviour', function () {
    expect(CategoryStylePresets::classify('None', realKeywordMap(InstagramCategoryFactor::class)))->toBeNull();
});

it('returns null when no keyword in the real map matches at all', function () {
    $bucket = CategoryStylePresets::classify('Submarine periscope repair', realKeywordMap(GoogleBusinessTypeFactor::class));

    expect($bucket)->toBeNull();
});

it('matching is case-insensitive against the real map', function () {
    $bucket = CategoryStylePresets::classify('BARBER SHOP', realKeywordMap(GoogleBusinessTypeFactor::class));

    expect($bucket)->toBe(CategoryStylePresets::BEAUTY_PERSONAL_CARE);
});
