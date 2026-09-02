<?php

use App\Services\Platforms\CasesScannedNames;
use App\Services\Platforms\ScrapedNameCasing;

// B5 / backend-fixes item 3a — the spec's own examples, pinned.

$caser = new class
{
    use CasesScannedNames;

    public function case(?string $name): ?string
    {
        return $this->scanTitleCase($name);
    }
};

it('title-cases uniformly-uppercase scanned names', function () use ($caser) {
    expect($caser->case('FORCE DUALITY COFFEE COLLECTION'))->toBe('Force Duality Coffee Collection')
        ->and($caser->case('EXPRESS LUNCH'))->toBe('Express Lunch')
        ->and($caser->case('STRAWBERRY ICED MATCHA LATTE'))->toBe('Strawberry Iced Matcha Latte');
});

it('title-cases uniformly-lowercase scanned names', function () use ($caser) {
    expect($caser->case('house special pasta'))->toBe('House Special Pasta');
});

it('leaves a mixed-case vendor name untouched', function () use ($caser) {
    expect($caser->case('McMuffin Deluxe'))->toBe('McMuffin Deluxe')
        ->and($caser->case('iSnack 2.0'))->toBe('iSnack 2.0');
});

it('keeps connector words lowercase mid-name but capitalizes them at the edges', function () use ($caser) {
    expect($caser->case('SAVE ON SELECT ITEMS'))->toBe('Save on Select Items')
        ->and($caser->case('THE WORKS'))->toBe('The Works')
        ->and($caser->case('SURF AND TURF'))->toBe('Surf and Turf');
});

it('preserves the uppercase allowlist — AU states and dietary marks', function () use ($caser) {
    expect($caser->case("'23 DEEP WOODS CHARDONNAY WA"))->toBe("'23 Deep Woods Chardonnay WA")
        ->and($caser->case('PUMPKIN SOUP GF'))->toBe('Pumpkin Soup GF')
        ->and($caser->case('lentil curry vg'))->toBe('Lentil Curry VG');
});

it('passes unit tokens through untouched', function () use ($caser) {
    expect($caser->case('COLD BREW 1.2L'))->toBe('Cold Brew 1.2L')
        ->and($caser->case('beans 225g'))->toBe('Beans 225g')
        ->and($caser->case('CAPSULES 7pk'))->toBe('CAPSULES 7pk'); // mixed case → untouched
});

it('capitalizes hyphenated parts', function () use ($caser) {
    expect($caser->case('CHOC-CHIP COOKIE'))->toBe('Choc-Chip Cookie');
});

it('handles null, empty and letterless strings without inventing case', function () use ($caser) {
    expect($caser->case(null))->toBeNull()
        ->and($caser->case('  '))->toBeNull()
        ->and($caser->case('123'))->toBe('123');
});

// CONNECTORS is documented as the vocabulary both re-casers share
// (ScrapedNameCasing docblock), but titleCase() never read it — so the ingest
// lane published "Just A Few Locs" and "Toner With Color". Six real dev names
// were affected (2026-09-02).
// CONNECTORS also carries '&', but titleCase()'s token regex is \p{L}+ and
// '&' is not a letter — the callback never sees it as a run, so it is
// unreachable through this method and gets no row here. Not a gap.
it('keeps connector words lowercase mid-name', function (string $in, string $out) {
    expect(ScrapedNameCasing::titleCase($in))->toBe($out);
})->with([
    ['Just a Few Locs', 'Just a Few Locs'],
    ['Toner with Color', 'Toner with Color'],
    ['Curly Hair Treat and Cut', 'Curly Hair Treat and Cut'],
    ['Restyle with consultation', 'Restyle with Consultation'],
    ['Junior Zero and skin fade', 'Junior Zero and Skin Fade'],
    ['Blow Wave Short Or with Color', 'Blow Wave Short Or with Color'],
    ['Bowl of Broth', 'Bowl of Broth'],
    ['Sticky Pork on Rice', 'Sticky Pork on Rice'],
    ['Corn on the Cob', 'Corn on the Cob'],
    ['Orin Swift, 8 Years in the Desert', 'Orin Swift, 8 Years in the Desert'],
]);

// First and last word always capitalise, even when they are connector words.
it('still capitalises a connector word at either edge', function () {
    expect(ScrapedNameCasing::titleCase('the works'))->toBe('The Works')
        ->and(ScrapedNameCasing::titleCase('walk in'))->toBe('Walk In');
});

// A connector after '-', '(' or '/' opens a new clause the source capitalised
// on purpose — "Manicure - With Gel Polish" sits beside "Manicure - No Gel
// Polish" in live dev data, and downcasing only one of the pair is wrong.
it('capitalises a connector that opens a new clause', function () {
    expect(ScrapedNameCasing::titleCase('Manicure - With Gel Polish'))->toBe('Manicure - With Gel Polish');
});

// ACCEPTED, DELIBERATE cost of the per-token gate (2026-09-02 fix wave
// measurement): titleCase() gates per TOKEN, so it also processes mixed-case
// strings, unlike scanTitleCase() below which gates on the WHOLE STRING and
// leaves any already mixed-case input alone. A vendor's standalone capital
// "A" — "Vitamin A Facial", not the English article "a" — falls to the same
// rule that correctly produces "Just a Few Locs". Measured against all 3895
// distinct dev `content.items` menu names (kind='menu_item'): 2 real names hit
// this exact standalone-capital-letter class ("… A …" downcased to "… a …"
// where the source meant a letter, not "a"), out of 230 names titleCase()
// changes overall — not a bug, a known and accepted narrow cost.
it('downcases a standalone capital connector letter even in a mixed-case name — accepted cost', function () use ($caser) {
    expect(ScrapedNameCasing::titleCase('Vitamin A Facial'))->toBe('Vitamin a Facial')
        ->and($caser->case('Vitamin A Facial'))->toBe('Vitamin A Facial');
});
