<?php

// tests/Unit/Profile/SectorFoldTableTest.php

use App\Services\Profile\SectorTaxonomy;

// B3 (spec 2026-08-18-pipeline-assurance §5): every Google category and Instagram
// businessCategoryName we have SEEN (build waves 2026-08-05 → 08-18, RESULTS files)
// → the sector it folds to. Every group-1 expectation below is verified against
// KEYWORD_SECTORS / INSTAGRAM_CATEGORY_SECTORS in SectorTaxonomy.php, not assumed.
// The former group 3 pinned a real bug — a generic keyword outranking a food keyword
// for two Google categories, so isFood() incorrectly went false (surfaced by the review
// in task-9-report.md). Development fixed it in e4958277d; both rows now sit in group 1
// asserting the corrected fold. Groups 4 and 5 pin gaps:
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
    // "generic keyword shadows a specific one" mechanism as the two former
    // GROUP 3 rows below, in the other direction — 'bar' matches before any
    // dedicated juice-bar
    // keyword, but 'bar' is itself a FOOD_SECTORS slug, so the capability gate
    // still comes out correct here. (This row was moved OUT of the food-gap
    // group below because it is not actually a gap.)
    'Juice bar (generic bar keyword, still food-positive)' => ['Juice bar', 'bar'],
    // Both rows below were GROUP 3 — a pinned BUG — when this branch was written:
    // classify() scanned KEYWORD_SECTORS with a bare str_contains, so 'spa'
    // matched the "spa" in "SPAnish" and 'sport' the "sport" in "SPORTs bar",
    // and neither 'spa' nor 'gym' is in FOOD_SECTORS — the food gate silently
    // went false. Fixed on development by e4958277d (leading word-boundary match
    // + WHOLE_WORD_KEYWORDS = ['spa', 'bar'] anchoring the tail), so they now
    // fold correctly and are asserted as such. The bug group is gone with them.
    'Spanish restaurant (was: matched spa before restaurant)' => ['Spanish restaurant', 'restaurant'],
    'Sports bar (was: matched gym via sport before bar)' => ['Sports bar', 'bar'],
    // Was GROUP 4, the food gap. Closed by the F5 mapping wave (2026-08-31):
    // gelato-messina-darlinghurst arrived with exactly this category, folded to
    // nothing, and its can_use_menu therefore read false.
    'Ice cream shop (was: the gelato gap)' => ['Ice cream shop', 'cafe'],
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

// GROUP 4 — the food gap, pinned as current behaviour. One row shorter since
// 2026-08-31: "Ice cream shop" is the category gelato-messina-darlinghurst
// actually carried, so F5 mapped it and it moved up to group 1. The two
// remaining rows are the shapes no live account has produced yet — they stay
// here as gaps rather than being mapped on speculation.
it('KNOWN GAP: an obviously-food category is not food to the gate', function (string $category) {
    $sector = SectorTaxonomy::fromGoogleCategory($category);
    expect(SectorTaxonomy::isFood($sector))->toBeFalse(
        "'{$category}' now folds to food sector '{$sector}' — the gelato gap closed; move this row to group 1 and update the report",
    );
})->with([
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
