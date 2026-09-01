<?php

/**
 * F5 (cold-build audit, 2026-08-31): 68 of 209 ready unclaimed accounts carried
 * sector NULL. Not one of them was missing a sector — the taxonomy already held
 * dentist, accommodation, mechanic and 73 others. What was missing was a
 * KEYWORD_SECTORS key for the Google category string each account actually
 * arrived with. Every row below is one of those strings, named with the account
 * it came from, so a future reorder that re-breaks one is a failing test rather
 * than another silently unclassified business.
 *
 * A null sector is not cosmetic. isFood() gates can_use_menu,
 * can_use_reservations and can_use_online_ordering, and its null arm is false —
 * so an unclassified food business is served the BOOKING capability set: a Book
 * button, no menu, no reservations, no ordering.
 */

use App\Services\Profile\SectorTaxonomy;

dataset('google categories', [
    ['Dental Clinic', 'dentist'],                    // bondi-junction-dental
    ['Massage', 'spa'],                              // lakshmi-thai-massage
    ['Pub', 'bar'],                                  // corner-hotel, exeter-hotel
    ['Brewery', 'bar'],                              // little-creatures-brewery-fremantle
    ['Winery', 'bar'],                               // chandon-australia
    ['Sandwich Shop', 'cafe'],                       // pret-a-manger
    ['Ice Cream Shop', 'cafe'],                      // gelato-messina-darlinghurst
    ['Bicycle Shop', 'retail-store'],                // curve-cycling
    ['Book Store', 'retail-store'],                  // readings-carlton
    ['Toy Store', 'retail-store'],                   // toyworld-central-docklands-melbourne
    ['Electronics Store', 'retail-store'],           // michaels-camera-video-digital
    ['Store', 'retail-store'],                       // milligram, northside-records
    ['Food Store', 'grocer'],                        // harper-blohm-cheese-shop
    ['Butcher Shop', 'grocer'],                      // peter-bouchier-toorak
    ['Liquor Store', 'liquor-store'],                // blackhearts-sparrows
    ['Veterinary Care', 'veterinarian'],             // lort-smith-animal-hospital
    ['Pet Care', 'pet-services'],                    // the-noble-hound-dog-grooming
    ['Museum', 'museum-gallery'],                    // tasmanian-museum-and-art-gallery
    ['Market', 'market'],                            // adelaide-central-market, perth-upmarket
    ['Laundry', 'laundry'],                          // sunshine-north-coin-laundry
    ['Locksmith', 'locksmith'],                      // mb-locksmiths-melbourne
    ['Medical Clinic', 'medical-clinic'],            // melbourne-acupuncture
    ['Health', 'medical-clinic'],                    // oscar-wylee-optometrist
    ['Garden Center', 'retail-store'],               // bulleen-art-garden
    ['School', 'tutor'],                             // melbourne-guitar-academy
    ['Educational Institution', 'tutor'],            // onroad-driving-education

    // Task 7.3 — a live-music venue is not a musician. northcote-social-club, a
    // pub with a bandroom, classified 'musician' and took the musician page
    // front (listen / events / watch / shop).
    ['Live Music Venue', 'event-venue'],             // northcote-social-club
    ['Bar & Grill', 'bar'],
]);

it('classifies the Google categories that produced a null sector', function (string $category, string $expected) {
    expect(SectorTaxonomy::fromGoogleCategory($category))->toBe($expected);
})->with('google categories');

it('keeps every new slug valid and bucketed', function () {
    foreach (['retail-store', 'grocer', 'liquor-store', 'veterinarian', 'pet-services', 'museum-gallery', 'market', 'laundry', 'locksmith', 'medical-clinic', 'optometrist'] as $slug) {
        expect(SectorTaxonomy::isValid($slug))->toBeTrue("{$slug} should be a valid sector")
            ->and(SectorTaxonomy::bucketFor($slug))->not->toBeNull("{$slug} needs a style bucket");
    }
});

/**
 * The four catch-alls ('market', 'store', 'health', 'school') are the broadest
 * keys in the map, and each one appears INSIDE a category above it. If a
 * reorder ever lifts one, the specific key it swallows goes silently missing —
 * "Liquor Store" becomes a generic shop, "Medical Clinic" becomes a clinic of
 * no kind. These are the collisions the ordering discipline exists for.
 */
it('resolves the specific category, not the catch-all it contains', function (string $input, string $expected) {
    expect(SectorTaxonomy::fromGoogleCategory($input))->toBe($expected);
})->with([
    'liquor store' => ['Liquor Store', 'liquor-store'],
    'food store' => ['Food Store', 'grocer'],
    'book store' => ['Book Store', 'retail-store'],
    'clothing store' => ['Clothing Store', 'clothing-boutique'],
    'jewelry store' => ['Jewelry Store', 'jewellery'],
    'health food store' => ['Health Food Store', 'grocer'],
    'medical clinic' => ['Medical Clinic', 'medical-clinic'],
    'dance school' => ['Dance School', 'dance-instructor'],
    'driving school' => ['Driving School', 'driving-instructor'],
    'marketing agency' => ['Marketing Agency', 'marketing-agency'],
]);

/**
 * 'pub' is a WHOLE_WORD keyword because the bare stem opens "PUBlic figure" —
 * the Facebook-taxonomy category a tattooist, a musician and a hairdresser all
 * sit in, pinned to null since 2026-08-10. A stem 'pub' would have stamped
 * every one of them a FOOD sector and switched their menu capabilities on.
 */
it('does not let the pub keyword capture public figure', function () {
    expect(SectorTaxonomy::fromGoogleCategory('Public figure'))->toBeNull()
        ->and(SectorTaxonomy::fromInstagramCategory('Public Figure'))->toBeNull()
        ->and(SectorTaxonomy::fromGoogleCategory('Pubs'))->toBe('bar');
});

/**
 * The counterweight to the 'health' catch-all. Instagram's "Health/Beauty" is a
 * whole DOMAIN, not a trade, and has been deliberately null since 2026-08-10 —
 * the 'health' stem would otherwise file every tattooist and hairdresser in it
 * as a medical clinic.
 */
it('still refuses to classify a domain-wide category', function () {
    expect(SectorTaxonomy::fromInstagramCategory('Health/Beauty'))->toBeNull()
        ->and(SectorTaxonomy::fromGoogleCategory('Health/Beauty'))->toBeNull();
});

/**
 * The audit's real cost, stated as a test: these accounts were not merely
 * menu-less. isFood() returns false for null, so can_use_menu went false and
 * can_use_booking went TRUE — a sandwich chain with a Book button.
 */
it('lands the food categories in FOOD_SECTORS, where the menu capability reads them', function (string $category) {
    expect(SectorTaxonomy::isFood(SectorTaxonomy::fromGoogleCategory($category)))->toBeTrue();
})->with(['Sandwich Shop', 'Ice Cream Shop', 'Pub', 'Brewery', 'Winery']);
