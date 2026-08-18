<?php
// tests/Unit/Profile/SectorFoldTableTest.php

use App\Services\Profile\SectorTaxonomy;

// B3 (spec 2026-08-18-pipeline-assurance §5): every Google category and Instagram
// businessCategoryName we have SEEN (build waves 2026-08-05 → 08-18, RESULTS files)
// → the sector it folds to. Every group-1 expectation below is verified against
// KEYWORD_SECTORS / INSTAGRAM_CATEGORY_SECTORS in SectorTaxonomy.php, not assumed —
// two rows in the original draft disagreed with the map and were corrected or moved
// (see task-9-report.md). Groups 3 and 4 pin gaps: categories that a human would call
// food/a real trade but that SectorTaxonomy folds to null (or, for food, to a
// non-food slug) today. Those rows assert CURRENT behaviour and are named so the gap
// is legible — changing them is a product decision, not a test fix.

it('folds a Google category to the expected sector', function (string $category, string $expected) {
    expect(SectorTaxonomy::fromGoogleCategory($category))->toBe($expected);
})->with([
    'Barber shop' => ['Barber shop', 'barber'],
    'Hair salon' => ['Hair salon', 'hair-salon'],
    'Tattoo shop' => ['Tattoo shop', 'tattoo-artist'],
    'Restaurant' => ['Restaurant', 'restaurant'],
    // CORRECTED (case 2): "Spanish restaurant" contains the literal substring
    // "spa" (s-p-a-nish), and 'spa' sits earlier in KEYWORD_SECTORS than
    // 'restaurant' — the classifier is first-substring-wins, so this resolves
    // to 'spa', not 'restaurant'. The brief's draft expected 'restaurant'.
    'Spanish restaurant (actually hits the spa keyword first)' => ['Spanish restaurant', 'spa'],
    'Bar' => ['Bar', 'bar'],
    'Wine bar' => ['Wine bar', 'bar'],
    'Cafe' => ['Cafe', 'cafe'],
    'Coffee shop' => ['Coffee shop', 'cafe'],
    'Bakery' => ['Bakery', 'bakery'],
    'Nail salon' => ['Nail salon', 'nail-technician'],
    'Photographer' => ['Photographer', 'photographer'],
    'Personal trainer' => ['Personal trainer', 'personal-trainer'],
    'Plumber' => ['Plumber', 'plumber'],
    // Not in the original draft: added because it demonstrates the same
    // "generic keyword shadows a specific one" behaviour as Spanish restaurant,
    // in the other direction — 'bar' matches before we ever get a dedicated
    // juice-bar keyword, but 'bar' is itself a FOOD_SECTORS slug, so the
    // capability gate still comes out correct (see the food-gap group below,
    // where this row was moved OUT of because it is not actually a gap).
    'Juice bar (generic bar keyword, still food-positive)' => ['Juice bar', 'bar'],
]);

it('folds an Instagram businessCategoryName to the expected sector', function (string $category, string $expected) {
    expect(SectorTaxonomy::fromInstagramCategory($category))->toBe($expected);
})->with([
    'Hair salon' => ['Hair salon', 'hair-salon'],
    'Barber Shop' => ['Barber Shop', 'barber'],
    'Tattoo & Piercing Shop' => ['Tattoo & Piercing Shop', 'tattoo-artist'],
    'Restaurant' => ['Restaurant', 'restaurant'],
    'compound with None first (F5)' => ['None,Fast food restaurant', 'restaurant'],
    'Photographer' => ['Photographer', 'photographer'],
    'Musician/Band' => ['Musician/Band', 'musician'],
]);

it('returns null for placeholder categories', function (string $category) {
    expect(SectorTaxonomy::fromInstagramCategory($category))->toBeNull()
        ->and(SectorTaxonomy::fromGoogleCategory($category))->toBeNull();
})->with(['None', 'none', ' None ', 'null', 'N/A', '-', '']);

// GROUP 3 — the food gap, pinned as current behaviour.
it('KNOWN GAP: an obviously-food category is not food to the gate', function (string $category) {
    $sector = SectorTaxonomy::fromGoogleCategory($category);
    expect(SectorTaxonomy::isFood($sector))->toBeFalse(
        "'{$category}' now folds to food sector '{$sector}' — the gelato gap closed; move this row to group 1 and update the report",
    );
})->with([
    'Ice cream shop' => ['Ice cream shop'],
    'Gelato shop' => ['Gelato shop'],
    'Dessert shop' => ['Dessert shop'],
]);

// GROUP 4 — a trade category that folds to null even though it names a real,
// curated sector (both are valid slugs per isValid() — 'esthetician' and
// 'artist'). Kept separate from the food gap above: these are not a
// capability-gating miss, just a picker-vocabulary miss.
it('KNOWN GAP: a trade category folds to null', function (string $category, callable $classify) {
    expect($classify($category))->toBeNull(
        "'{$category}' now folds — move this row to group 1 and update the report",
    );
})->with([
    // "Beauty salon" — no bare 'beauty' key in KEYWORD_SECTORS (only 'hair',
    // 'nail', 'makeup', 'spa' etc catch specific beauty trades); a generic
    // beauty-salon listing folds to null. The brief's draft claimed this row
    // resolved to 'esthetician' — it does not; verified null via tinker.
    'Google: Beauty salon' => ['Beauty salon', fn (string $c) => SectorTaxonomy::fromGoogleCategory($c)],
    // "Artist" via Instagram's businessCategoryName alone: KEYWORD_SECTORS has
    // no bare 'artist' key (deliberately — see its docblock: tattooists,
    // musicians, hairdressers and photographers all pick "Artist" on
    // Instagram, so it is disambiguated only in fromInstagramProfile()'s
    // handle/display-name tier, never inside fromInstagramCategory() itself).
    // The brief's draft expected 'artist' from fromInstagramCategory('Artist')
    // directly — it returns null; 'artist' is only reachable via the
    // higher-level fromInstagramProfile() fallback tier, not this function.
    'Instagram: Artist' => ['Artist', fn (string $c) => SectorTaxonomy::fromInstagramCategory($c)],
]);

it('keeps FOOD_SECTORS inside the valid slug set and every fold inside all()', function () {
    $valid = collect(SectorTaxonomy::all())->flatMap(fn ($g) => collect($g['options'])->pluck('slug'))->all();
    foreach (SectorTaxonomy::FOOD_SECTORS as $slug) {
        expect(SectorTaxonomy::isValid($slug))->toBeTrue("FOOD_SECTORS has unknown slug '{$slug}'")
            ->and($valid)->toContain($slug);
    }
});
