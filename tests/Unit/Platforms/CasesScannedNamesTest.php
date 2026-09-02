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
it('keeps connector words lowercase mid-name', function (string $in, string $out) {
    expect(ScrapedNameCasing::titleCase($in))->toBe($out);
})->with([
    ['Just a Few Locs', 'Just a Few Locs'],
    ['Toner with Color', 'Toner with Color'],
    ['Curly Hair Treat and Cut', 'Curly Hair Treat and Cut'],
    ['Restyle with consultation', 'Restyle with Consultation'],
    ['Junior Zero and skin fade', 'Junior Zero and Skin Fade'],
    ['Blow Wave Short Or with Color', 'Blow Wave Short Or with Color'],
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
