<?php

use App\Services\Profile\SectorTaxonomy;

it('maps a raw instagram business category to the closest curated sector', function () {
    expect(SectorTaxonomy::fromInstagramCategory('Hair Salon'))->toBe('hair-salon');
    expect(SectorTaxonomy::fromInstagramCategory('Italian Restaurant'))->toBe('restaurant');
});

it('returns null for an empty, none, or unmapped category', function () {
    expect(SectorTaxonomy::fromInstagramCategory(null))->toBeNull();
    expect(SectorTaxonomy::fromInstagramCategory(''))->toBeNull();
    expect(SectorTaxonomy::fromInstagramCategory('   '))->toBeNull();
    expect(SectorTaxonomy::fromInstagramCategory('None'))->toBeNull();
    expect(SectorTaxonomy::fromInstagramCategory('Something Totally Unrelated Xyz'))->toBeNull();
});

it('agrees with fromGoogleCategory on the same input, since both share the KEYWORD_SECTORS classifier', function () {
    $category = 'Barber Shop';
    expect(SectorTaxonomy::fromInstagramCategory($category))->toBe(SectorTaxonomy::fromGoogleCategory($category));
    expect(SectorTaxonomy::fromInstagramCategory($category))->toBe('barber');
});
