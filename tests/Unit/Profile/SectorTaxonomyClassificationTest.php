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
    'makeup' => ['Makeup studio', 'makeup-artist'],
    'make-up' => ['Make-up studio', 'makeup-artist'],
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

/**
 * The table above claims to pin the FULL ordered map. Without this, adding a
 * KEYWORD_SECTORS entry and forgetting to add its representative input leaves
 * that claim quietly false — which is worse than an openly partial table,
 * because a reorder regression would then slip through unnoticed.
 */
it('has one representative input for every KEYWORD_SECTORS entry', function () {
    $keywords = array_keys((new ReflectionClass(SectorTaxonomy::class))->getConstant('KEYWORD_SECTORS'));

    // The dataset keys of the representative table are the keyword names.
    $covered = [
        'barber', 'hair', 'nail', 'makeup', 'make-up', 'spa', 'tattoo', 'gym', 'fitness', 'yoga',
        'trainer', 'chiropractor', 'dentist', 'physio', 'sport', 'photographer', 'photo',
        'art gallery', 'gallery', 'music', 'real estate', 'accountant', 'lawyer', 'attorney',
        'consultant', 'clothing', 'florist', 'flower', 'jewel', 'gift shop', 'plumber',
        'electrician', 'clean', 'landscap', 'hotel', 'event venue', 'event planner', 'wedding',
        'car repair', 'auto repair', 'mechanic', 'car wash', 'car dealer', 'tutor',
        'dance school', 'dance', 'driving school', 'restaurant', 'cafe', 'coffee', 'bakery',
        'food truck', 'caterer', 'bar',
    ];

    expect(array_diff($keywords, $covered))->toBe([], 'KEYWORD_SECTORS entries with no representative input')
        ->and(array_diff($covered, $keywords))->toBe([], 'representative inputs for keywords that no longer exist');
});

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

/**
 * A bare "Artist" must stay NULL. It is one of Instagram's most generic
 * Facebook-taxonomy categories — tattooists, musicians, hairdressers and
 * photographers all pick it, and jesshairstylist (a hairdresser) carries it on
 * dev. Sector is sticky: IdentitySync::applySector returns early when
 * sector_source is set and isn't google-business, so an Instagram-stamped
 * guess would permanently lock Google Business out of correcting it.
 */
it('leaves a bare "Artist" category null rather than stamping a sticky guess', function () {
    expect(SectorTaxonomy::fromInstagramCategory('Artist'))->toBeNull()
        ->and(SectorTaxonomy::fromGoogleCategory('Artist'))->toBeNull();
});

it('classifies makeup artists to makeup-artist, both spellings', function () {
    expect(SectorTaxonomy::fromGoogleCategory('Make-up artist'))->toBe('makeup-artist')
        ->and(SectorTaxonomy::fromInstagramCategory('Makeup Artist'))->toBe('makeup-artist');
});

/**
 * "<speciality> Artist" strings must resolve on their SPECIALITY keyword. These
 * are the inputs that would break if a bare 'artist' key were ever added to
 * KEYWORD_SECTORS ahead of them, or if 'makeup' were moved after it.
 */
it('resolves speciality artist categories on their speciality keyword', function (string $input, ?string $expected) {
    expect(SectorTaxonomy::fromInstagramCategory($input))->toBe($expected);
})->with([
    'tattoo' => ['Tattoo Artist', 'tattoo-artist'],
    'nail' => ['Nail Artist', 'nail-technician'],
    'hair' => ['Hair Artist', 'hair-salon'],
    'makeup' => ['Makeup Artist', 'makeup-artist'],
    'gallery' => ['Art gallery', 'artist'],
    // No speciality keyword to hang on → null, not a guess.
    'bare' => ['Artist', null],
]);

/**
 * KEYWORD_SECTORS is tuned to Google Places primaryTypeDisplayName. Instagram
 * categories come from Facebook's Page taxonomy — a different, CLOSED
 * vocabulary — so fromInstagramCategory() checks an exact-match map first and
 * only then falls through to the shared substring classifier (F5, 2026-08-10).
 */
it('resolves Instagram-native categories the substring map gets wrong', function (string $input, string $expected) {
    expect(SectorTaxonomy::fromInstagramCategory($input))->toBe($expected);
})->with([
    // Corrections: the substring map returns a WRONG slug for these.
    'fitness trainer' => ['Fitness Trainer', 'personal-trainer'],
    'fitness coach' => ['Fitness Coach', 'personal-trainer'],
    'music school' => ['Music Lessons & Instruction School', 'music-teacher'],
    'music teacher' => ['Music Teacher', 'music-teacher'],

    // Gaps: the substring map returns null for these.
    'digital creator' => ['Digital Creator', 'content-creator'],
    'content creator' => ['Content Creator', 'content-creator'],
    'blogger' => ['Blogger', 'content-creator'],
    'videographer' => ['Videographer', 'videographer'],
    'video creator' => ['Video Creator', 'videographer'],
    'graphic designer' => ['Graphic Designer', 'graphic-designer'],
    'writer' => ['Writer', 'writer'],
    'skin care' => ['Skin Care Service', 'esthetician'],
    'waxing' => ['Waxing Service', 'esthetician'],
    'eyelash' => ['Eyelash Service', 'brows-lashes'],
    'eyebrow' => ['Eyebrow Service', 'brows-lashes'],
    'massage service' => ['Massage Service', 'spa'],
    'massage therapist' => ['Massage Therapist', 'spa'],
    'pilates' => ['Pilates Studio', 'yoga-instructor'],
    'life coach' => ['Life Coach', 'life-coach'],
    'nutritionist' => ['Nutritionist', 'nutritionist'],
    'dietitian' => ['Dietitian', 'nutritionist'],
    'physical therapist' => ['Physical Therapist', 'physiotherapist'],
    'consulting agency' => ['Consulting Agency', 'consultant'],
    'marketing agency' => ['Marketing Agency', 'marketing-agency'],
    'advertising agency' => ['Advertising Agency', 'marketing-agency'],
    'automotive repair' => ['Automotive Repair Shop', 'mechanic'],
    'plumbing service' => ['Plumbing Service', 'plumber'],
    'contractor' => ['Contractor', 'builder'],
    'general contractor' => ['General Contractor', 'builder'],
    'bed and breakfast' => ['Bed and Breakfast', 'accommodation'],
    'vacation rental' => ['Vacation Home Rental', 'accommodation'],
    'financial planner' => ['Financial Planner', 'financial-advisor'],
    'insurance agent' => ['Insurance Agent', 'insurance-broker'],
]);

it('matches Instagram categories case-insensitively and ignores surrounding whitespace', function () {
    expect(SectorTaxonomy::fromInstagramCategory('  DIGITAL CREATOR  '))->toBe('content-creator')
        ->and(SectorTaxonomy::fromInstagramCategory('digital creator'))->toBe('content-creator');
});

it('falls through to the shared substring classifier for unlisted Instagram categories', function (string $input, string $expected) {
    expect(SectorTaxonomy::fromInstagramCategory($input))->toBe($expected);
})->with([
    'hair stylist' => ['Hair Stylist', 'hair-salon'],
    'barber shop' => ['Barber Shop', 'barber'],
    'tattoo shop' => ['Tattoo & Piercing Shop', 'tattoo-artist'],
    'nail salon' => ['Nail Salon', 'nail-technician'],
    'restaurant' => ['Restaurant', 'restaurant'],
]);

/**
 * Deliberately unmapped. These Instagram categories are too vague to pick a
 * sector from, and sector drives both sitepage styling and the FOOD_SECTORS
 * capability gates — a wrong guess is worse than null, which the user can fix
 * from the dashboard picker.
 */
it('leaves genuinely ambiguous Instagram categories null', function (string $input) {
    expect(SectorTaxonomy::fromInstagramCategory($input))->toBeNull();
})->with([
    'Health/Beauty',
    'Beauty, Cosmetic & Personal Care',
    'Public Figure',
    'Personal Blog',
    'Entrepreneur',
    'Product/Service',
    'Local Business',
]);

it('never resolves a non-food Instagram category into a FOOD_SECTORS slug', function () {
    $nonFood = ['Artist', 'Digital Creator', 'Hair Stylist', 'Contractor', 'Life Coach', 'Public Figure'];

    foreach ($nonFood as $category) {
        $slug = SectorTaxonomy::fromInstagramCategory($category);
        expect(SectorTaxonomy::isFood($slug))->toBeFalse("'{$category}' resolved to food slug '{$slug}'");
    }
});

it('maps every Instagram category to a real sector slug, from a normalised key', function () {
    $map = (new ReflectionClass(SectorTaxonomy::class))
        ->getConstant('INSTAGRAM_CATEGORY_SECTORS');

    foreach ($map as $category => $slug) {
        expect(SectorTaxonomy::isValid($slug))->toBeTrue("'{$category}' maps to unknown slug '{$slug}'")
            ->and($category)->toBe(strtolower(trim($category)));
    }
});
