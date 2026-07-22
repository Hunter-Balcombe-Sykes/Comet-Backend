<?php

/**
 * Pins SectorTaxonomy's category classifier (re-homed from the deleted
 * CategoryStylePresets) — the specific-before-generic ordering contract in
 * KEYWORD_SECTORS, exercised through the public folding entrypoints
 * IdentitySync uses.
 */

use App\Services\Profile\SectorTaxonomy;

it('classifies "Barber shop" as barber, not bar', function () {
    expect(SectorTaxonomy::fromGoogleCategory('Barber shop'))->toBe('barber')
        ->and(SectorTaxonomy::fromInstagramCategory('Barber Shop'))->toBe('barber');
});

it('still classifies a plain "Cocktail bar" as bar', function () {
    expect(SectorTaxonomy::fromGoogleCategory('Cocktail bar'))->toBe('bar');
});

it('returns null for empty and unmatched categories', function () {
    expect(SectorTaxonomy::fromGoogleCategory(''))->toBeNull()
        ->and(SectorTaxonomy::fromGoogleCategory('Locksmith'))->toBeNull();
});
