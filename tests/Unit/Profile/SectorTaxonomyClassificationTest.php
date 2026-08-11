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

/**
 * One representative input per KEYWORD_SECTORS entry, pinning the FULL
 * ordered map — not just the barber/bar pair. Every input is chosen so it
 * resolves to the intended slug under the real first-substring-match
 * semantics: it deliberately avoids containing any keyword that sits
 * EARLIER in KEYWORD_SECTORS than the one under test, since an earlier
 * collision would otherwise win and mask a reorder regression. A future
 * reorder that lets a generic keyword shadow a more specific one will flip
 * one of these assertions.
 */
it('classifies a representative input for every KEYWORD_SECTORS entry to its intended slug', function (string $input, string $expectedSlug) {
    expect(SectorTaxonomy::fromGoogleCategory($input))->toBe($expectedSlug);
})->with([
    'barber' => ['Barber shop', 'barber'],
    'hair' => ['Hair Salon', 'hair-salon'],
    'nail' => ['Nail salon', 'nail-technician'],
    'spa' => ['Day spa', 'spa'],
    'tattoo' => ['Tattoo studio', 'tattoo-artist'],
    'gym' => ['Gym', 'gym'],
    'fitness' => ['Fitness center', 'gym'],
    'yoga' => ['Yoga studio', 'yoga-instructor'],
    'trainer' => ['Personal trainer', 'personal-trainer'],
    'chiropractor' => ['Chiropractor clinic', 'chiropractor'],
    'dentist' => ['Dentist office', 'dentist'],
    'physio' => ['Physio clinic', 'physiotherapist'],
    'sport' => ['Sports centre', 'gym'],
    'photographer' => ['Photographer studio', 'photographer'],
    'photo' => ['Photo booth', 'photographer'],
    'art gallery' => ['Art gallery', 'artist'],
    'gallery' => ['Local gallery', 'artist'],
    'music' => ['Music studio', 'musician'],
    'real estate' => ['Real estate agency', 'real-estate-agent'],
    'accountant' => ['Accountant office', 'accountant'],
    'lawyer' => ['Lawyer office', 'lawyer'],
    'attorney' => ['Attorney at law', 'lawyer'],
    'consultant' => ['Business consultant', 'consultant'],
    'clothing' => ['Clothing store', 'clothing-boutique'],
    'florist' => ['Florist shop', 'florist'],
    'flower' => ['Flower shop', 'florist'],
    'jewel' => ['Jewelry store', 'jewellery'],
    'gift shop' => ['Gift shop downtown', 'gift-shop'],
    'plumber' => ['Plumber services', 'plumber'],
    'electrician' => ['Electrician services', 'electrician'],
    'clean' => ['Cleaning service', 'cleaner'],
    'landscap' => ['Landscaping services', 'landscaper'],
    'hotel' => ['Boutique hotel', 'accommodation'],
    'event venue' => ['Event venue for hire', 'event-venue'],
    'event planner' => ['Event planner services', 'event-planner'],
    'wedding' => ['Wedding planner services', 'wedding-planner'],
    'car repair' => ['Car repair shop', 'mechanic'],
    'auto repair' => ['Auto repair centre', 'mechanic'],
    'mechanic' => ['Mechanic workshop', 'mechanic'],
    'car wash' => ['Car wash service', 'car-detailer'],
    'car dealer' => ['Car dealership', 'mechanic'],
    'tutor' => ['Tutor services', 'tutor'],
    'dance school' => ['Dance school for kids', 'dance-instructor'],
    'dance' => ['Dance studio', 'dance-instructor'],
    'driving school' => ['Driving school lessons', 'driving-instructor'],
    'restaurant' => ['Italian restaurant', 'restaurant'],
    'cafe' => ['Cosy cafe', 'cafe'],
    'coffee' => ['Coffee shop', 'cafe'],
    'bakery' => ['Local bakery', 'bakery'],
    'food truck' => ['Food truck vendor', 'food-truck'],
    'caterer' => ['Caterer services', 'caterer'],
    'bar' => ['Wine bar', 'bar'],
]);

it('distinguishes car repair, car wash, and car dealer — only car wash differs from the others', function () {
    expect(SectorTaxonomy::fromGoogleCategory('Car repair shop'))->toBe('mechanic')
        ->and(SectorTaxonomy::fromGoogleCategory('Car wash service'))->toBe('car-detailer')
        ->and(SectorTaxonomy::fromGoogleCategory('Car dealership'))->toBe('mechanic')
        ->and(SectorTaxonomy::fromInstagramCategory('Car wash service'))->toBe('car-detailer');
});

it('distinguishes event venue from event planner', function () {
    expect(SectorTaxonomy::fromGoogleCategory('Event venue for hire'))->toBe('event-venue')
        ->and(SectorTaxonomy::fromGoogleCategory('Event planner services'))->toBe('event-planner')
        ->and(SectorTaxonomy::fromInstagramCategory('Event planner services'))->toBe('event-planner');
});

it('classifies both "dance school" and plain "dance" as dance-instructor', function () {
    expect(SectorTaxonomy::fromGoogleCategory('Dance school for kids'))->toBe('dance-instructor')
        ->and(SectorTaxonomy::fromGoogleCategory('Dance studio'))->toBe('dance-instructor')
        ->and(SectorTaxonomy::fromInstagramCategory('Dance studio'))->toBe('dance-instructor');
});

it('classifies "hotel" as accommodation', function () {
    expect(SectorTaxonomy::fromGoogleCategory('Boutique hotel'))->toBe('accommodation')
        ->and(SectorTaxonomy::fromInstagramCategory('Boutique hotel'))->toBe('accommodation');
});

it('keeps restaurant/cafe/bakery/bar distinct, with restaurant winning over a trailing "bar"', function () {
    expect(SectorTaxonomy::fromGoogleCategory('Restaurant and Bar'))->toBe('restaurant')
        ->and(SectorTaxonomy::fromGoogleCategory('Italian restaurant'))->toBe('restaurant')
        ->and(SectorTaxonomy::fromGoogleCategory('Cosy cafe'))->toBe('cafe')
        ->and(SectorTaxonomy::fromGoogleCategory('Local bakery'))->toBe('bakery')
        ->and(SectorTaxonomy::fromGoogleCategory('Wine bar'))->toBe('bar');
});

/**
 * F4/F5 (2026-08-10 build wave): a degraded figue actor run stringifies
 * Python's None into businessCategoryName. Guard the whole placeholder set,
 * not just "none".
 */
it('returns null for every placeholder category string, in any casing', function (string $input) {
    expect(SectorTaxonomy::fromInstagramCategory($input))->toBeNull()
        ->and(SectorTaxonomy::fromGoogleCategory($input))->toBeNull();
})->with(['None', 'none', 'NONE', ' None ', 'null', 'NULL', 'N/A', 'n/a', '-']);
