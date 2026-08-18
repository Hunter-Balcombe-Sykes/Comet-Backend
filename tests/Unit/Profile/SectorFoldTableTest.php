<?php
// tests/Unit/Profile/SectorFoldTableTest.php

use App\Services\Profile\SectorTaxonomy;

// B3 (spec 2026-08-18-pipeline-assurance §5): every Google category and Instagram
// businessCategoryName we have SEEN (build waves 2026-08-05 → 08-18, RESULTS files)
// → the sector it folds to. Every group-1 expectation below is verified against
// KEYWORD_SECTORS / INSTAGRAM_CATEGORY_SECTORS in SectorTaxonomy.php, not assumed.
// Group 3 pins a real bug, not a mere naming gap: a generic keyword outranks a food
// keyword for two Google categories, so isFood() incorrectly goes false — see
// task-9-report.md for the review that surfaced this. Groups 4 and 5 pin gaps:
// categories that a human would call food/a real trade but that SectorTaxonomy folds
// to null today. All of these rows assert CURRENT behaviour and are named so the
// defect or gap is legible from the test output alone — changing them is a product
// decision, not a test fix.

it('folds a Google category to the expected sector', function (string $category, string $expected) {
    expect(SectorTaxonomy::fromGoogleCategory($category))->toBe($expected);
})->with([
    'Barber shop' => ['Barber shop', 'barber'],
    'Hair salon' => ['Hair salon', 'hair-salon'],
    'Tattoo shop' => ['Tattoo shop', 'tattoo-artist'],
    'Restaurant' => ['Restaurant', 'restaurant'],
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
    // "generic keyword shadows a specific one" mechanism as GROUP 3 below, in
    // the other direction — 'bar' matches before any dedicated juice-bar
    // keyword, but 'bar' is itself a FOOD_SECTORS slug, so the capability gate
    // still comes out correct here. (This row was moved OUT of the food-gap
    // group below because it is not actually a gap.)
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

// GROUP 3 — BUG, not a naming gap: fromGoogleCategory() has no exact-match tier
// (unlike fromInstagramCategory()'s INSTAGRAM_CATEGORY_SECTORS), so these two
// real food/drink venues lose their food gate to an earlier, more generic
// KEYWORD_SECTORS entry. classify() (:667-681) only lowercases/trims before a
// plain ordered str_contains() scan — it does NOT go through classifyText()'s
// non-alpha stripping. The consequence is real: FOOD_SECTORS is "the single
// source of truth for every food-derived capability" (SectorTaxonomy.php:26-29)
// — can_use_menu / can_use_reservations / can_use_booking / can_use_online_ordering
// all flip off, and the business gets a BEAUTY_PERSONAL_CARE styling preset
// instead of a food one. Cross-reference: GROUP 4 below is the food-gap group
// (fold to null); this group is folds to a wrong, non-food sector instead.
it('BUG: a generic keyword outranks the food keyword — isFood() goes false', function (string $category, string $expectedSector) {
    $sector = SectorTaxonomy::fromGoogleCategory($category);
    expect($sector)->toBe($expectedSector)
        ->and(SectorTaxonomy::isFood($sector))->toBeFalse(
            "'{$category}' now folds to sector '{$sector}' and IS food — the keyword-ordering bug is fixed; move this row out of the bug group and update the report",
        );
})->with([
    // 'spa' (KEYWORD_SECTORS:155) precedes 'restaurant' (:197); "spanish
    // restaurant" contains the literal substring "spa" (s-p-a-nish).
    'BUG: "Spanish restaurant" matches spa before restaurant — isFood() goes false' => ['Spanish restaurant', 'spa'],
    // 'sport' (KEYWORD_SECTORS:164) precedes 'bar' (:203); "sports bar"
    // contains "sport". Google-path only: INSTAGRAM_CATEGORY_SECTORS has an
    // exact-match override 'sports bar' => 'bar' (:242) that fires first on
    // the Instagram path, but fromGoogleCategory has no equivalent exact tier.
    // Empirically confirmed via tinker, not predicted from the map.
    'BUG: "Sports bar" matches gym (via sport) before bar — isFood() goes false' => ['Sports bar', 'gym'],
]);

// GROUP 4 — the food gap, pinned as current behaviour.
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

// GROUP 5 — a trade category that folds to null even though it names a real,
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
