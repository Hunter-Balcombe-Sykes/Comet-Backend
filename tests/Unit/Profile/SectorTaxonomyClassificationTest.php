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
    // M-10: Google's snake_case type body_art_service humanizes to this when
    // the primary type is a generic bucket (Vic Market Tattoo live shape).
    'body art' => ['Body art service', 'tattoo-artist'],
    'piercing' => ['Piercing shop', 'tattoo-artist'],
    // M-11: DOH (B6 live) — "Donut Shop" synced no sector at all.
    'donut' => ['Donut Shop', 'bakery'],
    'doughnut' => ['Doughnut Shop', 'bakery'],
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
        'barber', 'hair', 'nail', 'makeup', 'make-up', 'spa', 'tattoo', 'body art', 'piercing', 'gym', 'fitness', 'yoga',
        'trainer', 'chiropractor', 'dentist', 'physio', 'sport', 'photographer', 'photo',
        'art gallery', 'gallery', 'music', 'real estate', 'accountant', 'lawyer', 'attorney',
        'consultant', 'clothing', 'florist', 'flower', 'jewel', 'gift shop', 'plumber',
        'electrician', 'clean', 'landscap', 'hotel', 'event venue', 'event planner', 'wedding',
        'car repair', 'auto repair', 'mechanic', 'car wash', 'car dealer', 'tutor',
        'dance school', 'dance', 'driving school', 'restaurant', 'cafe', 'coffee', 'bakery', 'donut', 'doughnut',
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

// ── compound categories (2026-08-11) ─────────────────────────────────────────
//
// Instagram joins multiple categories with a comma and emits "None" as a real
// segment — `hungryjacksau` returns "None,Fast food restaurant" with complete
// data. The substring pass already coped (it scans the whole string), but the
// EXACT pass could not: every exact-map-only category was silently lost the
// moment it arrived compound.

it('resolves an exact-map category that arrives as a compound segment', function (string $input, string $expected) {
    expect(SectorTaxonomy::fromInstagramCategory($input))->toBe($expected);
})->with([
    'digital creator' => ['None,Digital Creator', 'content-creator'],
    'graphic designer' => ['None,Graphic Designer', 'graphic-designer'],
    'pilates' => ['None,Pilates Studio', 'yoga-instructor'],
    'physical therapist' => ['None,Physical Therapist', 'physiotherapist'],
    'trailing placeholder' => ['Digital Creator,None', 'content-creator'],
]);

it('still resolves compound categories the substring pass already handled', function (string $input, string $expected) {
    expect(SectorTaxonomy::fromInstagramCategory($input))->toBe($expected);
})->with([
    'fast food' => ['None,Fast food restaurant', 'restaurant'],
    'hair salon' => ['None,Hair Salon', 'hair-salon'],
]);

it('returns null when every segment of a compound category is a placeholder', function (string $input) {
    expect(SectorTaxonomy::fromInstagramCategory($input))->toBeNull();
})->with(['None,N/A', 'None,None', 'none, -', 'None,']);

/**
 * A genuine category name may itself contain a comma. Splitting must never
 * turn one of these into a wrong match — "Beauty, Cosmetic & Personal Care" is
 * deliberately unmapped, and must stay that way rather than resolving off its
 * "Beauty" fragment.
 */
it('does not mis-resolve a genuine category name that contains a comma', function () {
    expect(SectorTaxonomy::fromInstagramCategory('Beauty, Cosmetic & Personal Care'))->toBeNull();
});

/**
 * PRIMACY. Facebook pages carry up to three categories with the PRIMARY first,
 * so an exact-map hit on a secondary segment must never outrank a
 * substring-map hit on the primary one. Getting this backwards is not a
 * cosmetic mis-slug: a restaurant that also lists "Digital Creator" would land
 * on content-creator, and SectorTaxonomy::isFood() would then silently switch
 * off can_use_menu / can_use_reservations / can_use_online_ordering — and
 * sector is sticky once Instagram stamps it, so nothing corrects it later.
 */
it('resolves a compound category on its FIRST matching segment, whichever map matches', function (string $input, string $expected) {
    expect(SectorTaxonomy::fromInstagramCategory($input))->toBe($expected);
})->with([
    'substring primary beats exact secondary' => ['Restaurant, Digital Creator', 'restaurant'],
    'barber before writer' => ['Barber Shop, Writer', 'barber'],
    'hair before trainer' => ['Hair Salon, Fitness Trainer', 'hair-salon'],
    'cafe before blogger' => ['Cafe, Blogger', 'cafe'],
    'tattoo before creator' => ['Tattoo & Piercing Shop, Digital Creator', 'tattoo-artist'],
    'restaurant before contractor' => ['Restaurant, Contractor', 'restaurant'],
    // ...and the reverse order still resolves on ITS first segment.
    'exact primary still wins' => ['Digital Creator, Restaurant', 'content-creator'],
    // A placeholder primary is skipped, not treated as a match.
    'placeholder primary skipped' => ['None, Restaurant, Digital Creator', 'restaurant'],
]);

it('keeps a food business on a food slug when it also lists a creator category', function (string $input) {
    expect(SectorTaxonomy::isFood(SectorTaxonomy::fromInstagramCategory($input)))->toBeTrue();
})->with([
    'Restaurant, Digital Creator',
    'Cafe, Blogger',
    'Bakery, Content Creator',
    'None,Fast food restaurant',
]);

/**
 * categorySegments() splits on commas, so a KEYWORD_SECTORS key containing one
 * could never match a segment. No key does today; this pins that assumption,
 * because the per-segment pass is now the ONLY substring pass.
 */
it('has no KEYWORD_SECTORS key containing a comma', function () {
    $keywords = array_keys((new ReflectionClass(SectorTaxonomy::class))->getConstant('KEYWORD_SECTORS'));

    expect(array_filter($keywords, fn (string $k) => str_contains($k, ',')))->toBe([]);
});

// ── categories the substring map gets wrong (2026-08-12) ─────────────────────
//
// All six reach a wrong slug through KEYWORD_SECTORS' trailing 'bar' and
// 'sport' entries. Three land on the FOOD slug 'bar', which flips
// can_use_booking OFF for a business — a barre studio and a mobile bartender
// are exactly the accounts that need booking on.
it('exact-maps categories the substring classifier gets wrong', function (string $input, string $expected) {
    expect(SectorTaxonomy::fromInstagramCategory($input))->toBe($expected);
})->with([
    ['Sports Bar', 'bar'],
    ['Juice Bar', 'cafe'],
    ['Bartender', 'bartender'],
    ['Barre Studio', 'yoga-instructor'],
    ['Sportswear Store', 'clothing-boutique'],
    ['Hair Removal Service', 'esthetician'],
]);

// ── free-text classification over handles and display names (2026-08-12) ─────

it('classifies a trade from a handle or display name', function (string $input, string $expected) {
    expect(SectorTaxonomy::classifyText($input))->toBe($expected);
})->with([
    'dotted handle' => ['jess.hair.stylist', 'hair-salon'],
    'run-together handle' => ['crucibletattooco', 'tattoo-artist'],
    'display name' => ['Melbourne Barre Pilates', 'yoga-instructor'],
    'underscored' => ['sam_the_plumber', 'plumber'],
    'chiropractic' => ['baysidechiropractic', 'chiropractor'],
    'plumbing suffix' => ['abcplumbing', 'plumber'],
    'carpenter' => ['joethecarpenter', 'builder'],
]);

// Each of these was a real false positive in an earlier draft of the map.
it('does not manufacture a match across a separator or a surname', function (string $input, ?string $expected) {
    expect(SectorTaxonomy::classifyText($input))->toBe($expected);
})->with([
    // <name ending in h> + <word starting "air"> — a productive AU handle pattern.
    'airbnb host' => ['beth.airbnb', null],
    'hvac tradie' => ['sarah.airconditioning', null],
    'spray tanner' => ['leah.airbrushtanning', 'esthetician'],
    'airbnb host 2' => ['hannah.airbnbhost', null],
    'pet groomer' => ['hairyhounds', null],
    // Surnames and qualifiers must lose to the trade actually named.
    'Barber the surname' => ['sarahbarberphotography', 'photographer'],
    'real estate niche' => ['realestatephotography', 'photographer'],
    'Baker the surname' => ['jessbakerphotography', 'photographer'],
    // 'spa' is absent from the map precisely so this cannot happen.
    'Spartan' => ['Spartan Fitness', 'gym'],
    'barber not bakery' => ['bakerstreetbarbers', 'barber'],
    'not a painter' => ['facepainting.co', null],
    'furniture' => ['mrchairs.furniture', null],
    'not a barber' => ['thebarberlin', 'barber'],
    'IT consultant' => ['coffeeandcode', null],
    'ironing not a chiro' => ['Arch Ironing Services', null],
    'plumbago not a plumber' => ['Kim Plumbago Florals', null],
    // ACCEPTED false positive, like thebarberlin: 'massage' is the trade word
    // itself and has no safe substring variant. A wrong NON-food slug is
    // correctable — google-business and manual both outrank instagram now.
    'agency not a spa' => ['Amass Agency', 'spa'],
]);

it('never resolves free text to a FOOD_SECTORS slug', function () {
    $map = (new ReflectionClass(SectorTaxonomy::class))->getConstant('TEXT_KEYWORD_SECTORS');

    foreach ($map as $keyword => $slug) {
        expect(SectorTaxonomy::isFood($slug))->toBeFalse("'{$keyword}' maps to food slug '{$slug}'");
    }
});

it('maps every free-text keyword to a real sector slug', function () {
    $map = (new ReflectionClass(SectorTaxonomy::class))->getConstant('TEXT_KEYWORD_SECTORS');

    foreach ($map as $keyword => $slug) {
        expect(SectorTaxonomy::isValid($slug))->toBeTrue("'{$keyword}' maps to unknown slug '{$slug}'")
            ->and($keyword)->toBe(strtolower($keyword))
            ->and(preg_match('/^[a-z]+$/', $keyword))->toBe(1, "'{$keyword}' must be lowercase a-z only (normalisation strips everything else)");
    }
});

// ── fromInstagramProfile: the live classifier (2026-08-12) ───────────────────

// PRIMACY, migrated from fromInstagramCategory. After this change the live
// path is fromInstagramProfile, so the primacy guarantee must be pinned HERE.
// Tier 1 delegates to fromInstagramCategory precisely so these cannot regress:
// resolving exact-matches across all segments before keyword-matches lets a
// SECONDARY category outrank the primary one, and three of these become a
// food -> non-food demotion that silently kills can_use_menu.
it('keeps the primary category when a secondary one also matches', function (string $category, string $expected) {
    expect(SectorTaxonomy::fromInstagramProfile([$category], null, null))->toBe($expected);
})->with([
    ['Restaurant, Digital Creator', 'restaurant'],
    ['Barber Shop, Writer', 'barber'],
    ['Hair Salon, Fitness Trainer', 'hair-salon'],
    ['Cafe, Blogger', 'cafe'],
    ['Tattoo & Piercing Shop, Digital Creator', 'tattoo-artist'],
    ['Restaurant, Contractor', 'restaurant'],
    ['None, Restaurant, Digital Creator', 'restaurant'],
    ['Bakery, Content Creator', 'bakery'],
    ['Digital Creator, Restaurant', 'content-creator'],
]);

it('prefers the category over the handle', function () {
    // The category tier must beat free text. A restaurant whose handle mentions
    // fitness is a restaurant.
    expect(SectorTaxonomy::fromInstagramProfile(['Restaurant'], 'fitzroyfitnesskitchen', null))->toBe('restaurant');
    expect(SectorTaxonomy::fromInstagramProfile(['Nail Salon'], 'sarahsbeautyandhair', null))->toBe('nail-technician');
});

it('falls back to the handle, then the display name, when no category maps', function () {
    // The motivating case: a Prahran hairdresser whose Instagram category is "Artist".
    expect(SectorTaxonomy::fromInstagramProfile(['Artist'], 'jess.hair.stylist', null))->toBe('hair-salon');
    // Handle beats display name.
    expect(SectorTaxonomy::fromInstagramProfile(['Artist'], 'crucibletattooco', 'Jane Photography'))->toBe('tattoo-artist');
    // Display name used when the handle says nothing.
    expect(SectorTaxonomy::fromInstagramProfile(['Artist'], 'jd_official', 'Jane Photography'))->toBe('photographer');
});

it('falls back to an ambiguous category only when nothing else matches', function () {
    expect(SectorTaxonomy::fromInstagramProfile(['Artist'], 'jd_official', null))->toBe('artist');
    // Per SEGMENT, not whole-string: Instagram emits its literal "None" as a
    // real segment, so "None,Artist" must still reach the ambiguous map.
    expect(SectorTaxonomy::fromInstagramProfile(['None,Artist'], null, null))->toBe('artist');
});

it('resolves the first candidate that maps, not the first that is non-null', function () {
    // The figue actor nulls business_category_name and fills category_name.
    expect(SectorTaxonomy::fromInstagramProfile(['None', null, 'Hair Stylist'], null, null))->toBe('hair-salon');
});

it('returns null when every tier misses', function () {
    expect(SectorTaxonomy::fromInstagramProfile(['Public Figure'], 'jd_official', 'JD'))->toBeNull();
    expect(SectorTaxonomy::fromInstagramProfile([], null, null))->toBeNull();
});

it('never resolves an instagram profile to a food slug from free text alone', function () {
    $handles = [
        'beth.airbnb', 'sarah.airconditioning', 'hairyhounds', 'sarahbarberphotography',
        'realestatephotography', 'jessbakerphotography', 'coffeeandcode', 'Spartan Fitness',
        'bakerstreetbarbers', 'facepainting.co', 'mrchairs.furniture', 'thebarberlin',
    ];

    foreach ($handles as $handle) {
        $slug = SectorTaxonomy::fromInstagramProfile(['Artist'], $handle, null);
        expect(SectorTaxonomy::isFood($slug))->toBeFalse("'{$handle}' resolved to food slug '{$slug}'");
    }
});

it('maps every ambiguous category to a real, non-food sector slug', function () {
    $map = (new ReflectionClass(SectorTaxonomy::class))->getConstant('AMBIGUOUS_CATEGORY_SECTORS');

    foreach ($map as $category => $slug) {
        expect(SectorTaxonomy::isValid($slug))->toBeTrue("'{$category}' maps to unknown slug '{$slug}'")
            ->and(SectorTaxonomy::isFood($slug))->toBeFalse("'{$category}' maps to food slug '{$slug}'")
            ->and($category)->toBe(strtolower(trim($category)));
    }
});

// ── word-boundary matching on the CATEGORY path (2026-08-19) ─────────────────
//
// KEYWORD_SECTORS is matched against SPACED category strings — Google's
// primaryTypeDisplayName and Instagram's businessCategoryName — never against
// a run-together handle (that is classifyText's TEXT_KEYWORD_SECTORS, which
// keeps bare substring matching by design). Bare str_contains therefore let a
// short key capture the START of an unrelated word: 'spa' took "SPAnish
// restaurant" and "SPAce rental", 'bar' took "BARbecue"/"BARrister", and
// 'sport' took "tranSPORT service". Every one of those left the correct slug —
// and the first two left FOOD_SECTORS, which dark-switches can_use_menu /
// can_use_reservations / can_use_online_ordering on a synced business.

it('does not classify a keyword that only appears inside another word', function (string $input, ?string $expected) {
    expect(SectorTaxonomy::fromGoogleCategory($input))->toBe($expected);
})->with([
    'Spanish restaurant is not a spa' => ['Spanish restaurant', 'restaurant'],
    'Space rental is not a spa' => ['Space rental', null],
    'Transport service is not a gym' => ['Transport service', null],
    'Barbecue joint is not a bar' => ['Barbecue joint', null],
    'Barrister chambers is not a bar' => ['Barrister chambers', null],
]);

// Both keys genuinely appear as whole words in "Sports bar". The map's own
// ordering discipline decides it: the head noun beats the leading qualifier,
// so the generic 'sport' must sit after every key it can co-occur with.
// The Instagram path already returned 'bar' via INSTAGRAM_CATEGORY_SECTORS'
// exact entry; Google had no such shield.
it('resolves the head noun, not the leading qualifier, when both are keywords', function (string $input, string $expected) {
    expect(SectorTaxonomy::fromGoogleCategory($input))->toBe($expected);
})->with([
    'Sports bar' => ['Sports bar', 'bar'],
    'Sports cafe' => ['Sports cafe', 'cafe'],
]);

// The counterweight: boundary matching must not cost the stem keys their
// prefix matches, nor 'sport' the categories it legitimately owns.
it('still classifies the stem and whole-word keywords it always did', function (string $input, string $expected) {
    expect(SectorTaxonomy::fromGoogleCategory($input))->toBe($expected);
})->with([
    'Day spa' => ['Day spa', 'spa'],
    'Spa' => ['Spa', 'spa'],
    'Sports centre' => ['Sports centre', 'gym'],
    'Sports club' => ['Sports club', 'gym'],
    'Wine bar' => ['Wine bar', 'bar'],
    'Restaurant and Bar' => ['Restaurant and Bar', 'restaurant'],
    'Landscaping services' => ['Landscaping services', 'landscaper'],
    'Cleaning service' => ['Cleaning service', 'cleaner'],
    'Jewelry store' => ['Jewelry store', 'jewellery'],
    'Physio clinic' => ['Physio clinic', 'physiotherapist'],
]);

// A whole-word anchor must not swallow the plural. Google emits singular type
// names, but nothing guarantees that of every source, and losing the slug is a
// silent downgrade to "no sector" rather than a visible wrong one.
it('still matches a whole-word keyword in its plural form', function (string $input, string $expected) {
    expect(SectorTaxonomy::fromGoogleCategory($input))->toBe($expected);
})->with([
    'Spas' => ['Spas', 'spa'],
    'Day spas' => ['Day spas', 'spa'],
    'Bars' => ['Bars', 'bar'],
    'Wine bars' => ['Wine bars', 'bar'],
]);

// The Instagram path shares classify(), so the same inputs must fold the same
// way there — the exact map only shields the handful of names it lists.
it('folds the same category identically on the Instagram path', function (string $input, ?string $expected) {
    expect(SectorTaxonomy::fromInstagramCategory($input))->toBe($expected);
})->with([
    'Spanish restaurant' => ['Spanish restaurant', 'restaurant'],
    'Sports bar' => ['Sports bar', 'bar'],
    'Transport service' => ['Transport service', null],
]);
