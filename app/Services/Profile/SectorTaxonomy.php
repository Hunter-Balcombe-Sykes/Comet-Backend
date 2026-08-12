<?php

namespace App\Services\Profile;

use App\Services\Design\SectorStylePresets;

/**
 * Curated, user-facing industry list for the profile "sector" field.
 *
 * Every entry carries a `bucket` = one of the ten SectorStylePresets bucket
 * constants, which ProfileDesignPresets reads at render time to auto-style a
 * user's sitepage (bucket base + optional per-slug refinement — see
 * SectorStylePresets). fromGoogleCategory()/fromInstagramCategory() fold a
 * connected platform's raw category text into this same curated slug
 * vocabulary, so a Google- or Instagram-sourced sector styles identically to
 * a manually-picked one.
 *
 * The list is deliberately mid-size (~70 entries) — broad enough that most
 * solo professionals find themselves, tight enough to stay a curated picker
 * rather than the full Google place-type enum.
 */
final class SectorTaxonomy
{
    /**
     * The "Food & Drink" group's slugs, exactly — the single source of truth
     * for every food-derived capability (AccountCapabilities::can_use_menu /
     * can_use_reservations / can_use_booking / can_use_online_ordering). Kept
     * as an explicit list rather than derived from SECTORS at call time so the
     * food set is a visible, reviewable contract, not an implicit side effect
     * of the picker's grouping.
     *
     * @var list<string>
     */
    public const FOOD_SECTORS = [
        'restaurant', 'cafe', 'bakery', 'bar', 'food-truck', 'caterer', 'personal-chef',
    ];

    /**
     * Values a scraper emits in place of a real category — the figue actor
     * stringifies Python's None. Never classified, and never stored: the
     * Instagram seeder reads this same list at its write seam, because
     * businessCategory is on the public wire (F4, 2026-08-10).
     *
     * @var list<string>
     */
    public const PLACEHOLDER_CATEGORIES = ['none', 'null', 'n/a', '-'];

    /**
     * Ordered sector rows. Order within a group is the display order in the
     * picker. `group` is the section header the picker renders.
     *
     * @var list<array{slug: string, label: string, group: string, bucket: string}>
     */
    private const SECTORS = [
        ['slug' => 'restaurant', 'label' => 'Restaurant', 'group' => 'Food & Drink', 'bucket' => SectorStylePresets::FOOD_DRINK],
        ['slug' => 'cafe', 'label' => 'Café / Coffee shop', 'group' => 'Food & Drink', 'bucket' => SectorStylePresets::FOOD_DRINK],
        ['slug' => 'bakery', 'label' => 'Bakery', 'group' => 'Food & Drink', 'bucket' => SectorStylePresets::FOOD_DRINK],
        ['slug' => 'bar', 'label' => 'Bar / Pub', 'group' => 'Food & Drink', 'bucket' => SectorStylePresets::FOOD_DRINK],
        ['slug' => 'food-truck', 'label' => 'Food truck / Street food', 'group' => 'Food & Drink', 'bucket' => SectorStylePresets::FOOD_DRINK],
        ['slug' => 'caterer', 'label' => 'Caterer', 'group' => 'Food & Drink', 'bucket' => SectorStylePresets::FOOD_DRINK],
        ['slug' => 'personal-chef', 'label' => 'Personal chef', 'group' => 'Food & Drink', 'bucket' => SectorStylePresets::FOOD_DRINK],

        ['slug' => 'hair-salon', 'label' => 'Hair salon', 'group' => 'Beauty & Personal Care', 'bucket' => SectorStylePresets::BEAUTY_PERSONAL_CARE],
        ['slug' => 'barber', 'label' => 'Barber', 'group' => 'Beauty & Personal Care', 'bucket' => SectorStylePresets::BEAUTY_PERSONAL_CARE],
        ['slug' => 'nail-technician', 'label' => 'Nail technician', 'group' => 'Beauty & Personal Care', 'bucket' => SectorStylePresets::BEAUTY_PERSONAL_CARE],
        ['slug' => 'makeup-artist', 'label' => 'Makeup artist', 'group' => 'Beauty & Personal Care', 'bucket' => SectorStylePresets::BEAUTY_PERSONAL_CARE],
        ['slug' => 'esthetician', 'label' => 'Esthetician / Skincare', 'group' => 'Beauty & Personal Care', 'bucket' => SectorStylePresets::BEAUTY_PERSONAL_CARE],
        ['slug' => 'spa', 'label' => 'Spa / Massage', 'group' => 'Beauty & Personal Care', 'bucket' => SectorStylePresets::BEAUTY_PERSONAL_CARE],
        ['slug' => 'tattoo-artist', 'label' => 'Tattoo artist', 'group' => 'Beauty & Personal Care', 'bucket' => SectorStylePresets::BEAUTY_PERSONAL_CARE],
        ['slug' => 'brows-lashes', 'label' => 'Brows & lashes', 'group' => 'Beauty & Personal Care', 'bucket' => SectorStylePresets::BEAUTY_PERSONAL_CARE],

        ['slug' => 'personal-trainer', 'label' => 'Personal trainer', 'group' => 'Health & Fitness', 'bucket' => SectorStylePresets::HEALTH_FITNESS],
        ['slug' => 'gym', 'label' => 'Gym / Studio', 'group' => 'Health & Fitness', 'bucket' => SectorStylePresets::HEALTH_FITNESS],
        ['slug' => 'yoga-instructor', 'label' => 'Yoga / Pilates instructor', 'group' => 'Health & Fitness', 'bucket' => SectorStylePresets::HEALTH_FITNESS],
        ['slug' => 'nutritionist', 'label' => 'Nutritionist / Dietitian', 'group' => 'Health & Fitness', 'bucket' => SectorStylePresets::HEALTH_FITNESS],
        ['slug' => 'physiotherapist', 'label' => 'Physiotherapist', 'group' => 'Health & Fitness', 'bucket' => SectorStylePresets::HEALTH_FITNESS],
        ['slug' => 'chiropractor', 'label' => 'Chiropractor', 'group' => 'Health & Fitness', 'bucket' => SectorStylePresets::HEALTH_FITNESS],
        ['slug' => 'therapist', 'label' => 'Therapist / Counsellor', 'group' => 'Health & Fitness', 'bucket' => SectorStylePresets::HEALTH_FITNESS],
        ['slug' => 'dentist', 'label' => 'Dentist', 'group' => 'Health & Fitness', 'bucket' => SectorStylePresets::HEALTH_FITNESS],

        ['slug' => 'accountant', 'label' => 'Accountant / Bookkeeper', 'group' => 'Professional Services', 'bucket' => SectorStylePresets::PROFESSIONAL_SERVICES],
        ['slug' => 'lawyer', 'label' => 'Lawyer / Solicitor', 'group' => 'Professional Services', 'bucket' => SectorStylePresets::PROFESSIONAL_SERVICES],
        ['slug' => 'financial-advisor', 'label' => 'Financial advisor', 'group' => 'Professional Services', 'bucket' => SectorStylePresets::PROFESSIONAL_SERVICES],
        ['slug' => 'consultant', 'label' => 'Consultant', 'group' => 'Professional Services', 'bucket' => SectorStylePresets::PROFESSIONAL_SERVICES],
        ['slug' => 'real-estate-agent', 'label' => 'Real estate agent', 'group' => 'Professional Services', 'bucket' => SectorStylePresets::PROFESSIONAL_SERVICES],
        ['slug' => 'insurance-broker', 'label' => 'Insurance broker', 'group' => 'Professional Services', 'bucket' => SectorStylePresets::PROFESSIONAL_SERVICES],
        ['slug' => 'mortgage-broker', 'label' => 'Mortgage broker', 'group' => 'Professional Services', 'bucket' => SectorStylePresets::PROFESSIONAL_SERVICES],
        ['slug' => 'marketing-agency', 'label' => 'Marketing / PR', 'group' => 'Professional Services', 'bucket' => SectorStylePresets::PROFESSIONAL_SERVICES],
        ['slug' => 'it-services', 'label' => 'IT / Tech services', 'group' => 'Professional Services', 'bucket' => SectorStylePresets::PROFESSIONAL_SERVICES],
        ['slug' => 'virtual-assistant', 'label' => 'Virtual assistant', 'group' => 'Professional Services', 'bucket' => SectorStylePresets::PROFESSIONAL_SERVICES],

        ['slug' => 'clothing-boutique', 'label' => 'Clothing / Fashion boutique', 'group' => 'Retail & Shopping', 'bucket' => SectorStylePresets::RETAIL_SHOPPING],
        ['slug' => 'jewellery', 'label' => 'Jewellery', 'group' => 'Retail & Shopping', 'bucket' => SectorStylePresets::RETAIL_SHOPPING],
        ['slug' => 'florist', 'label' => 'Florist', 'group' => 'Retail & Shopping', 'bucket' => SectorStylePresets::RETAIL_SHOPPING],
        ['slug' => 'gift-shop', 'label' => 'Gift shop', 'group' => 'Retail & Shopping', 'bucket' => SectorStylePresets::RETAIL_SHOPPING],
        ['slug' => 'homewares', 'label' => 'Homewares / Décor', 'group' => 'Retail & Shopping', 'bucket' => SectorStylePresets::RETAIL_SHOPPING],
        ['slug' => 'artisan-maker', 'label' => 'Artisan / Handmade goods', 'group' => 'Retail & Shopping', 'bucket' => SectorStylePresets::RETAIL_SHOPPING],

        ['slug' => 'plumber', 'label' => 'Plumber', 'group' => 'Home & Trade Services', 'bucket' => SectorStylePresets::HOME_SERVICES],
        ['slug' => 'electrician', 'label' => 'Electrician', 'group' => 'Home & Trade Services', 'bucket' => SectorStylePresets::HOME_SERVICES],
        ['slug' => 'builder', 'label' => 'Builder / Carpenter', 'group' => 'Home & Trade Services', 'bucket' => SectorStylePresets::HOME_SERVICES],
        ['slug' => 'painter', 'label' => 'Painter / Decorator', 'group' => 'Home & Trade Services', 'bucket' => SectorStylePresets::HOME_SERVICES],
        ['slug' => 'cleaner', 'label' => 'Cleaner', 'group' => 'Home & Trade Services', 'bucket' => SectorStylePresets::HOME_SERVICES],
        ['slug' => 'landscaper', 'label' => 'Landscaper / Gardener', 'group' => 'Home & Trade Services', 'bucket' => SectorStylePresets::HOME_SERVICES],
        ['slug' => 'handyman', 'label' => 'Handyman', 'group' => 'Home & Trade Services', 'bucket' => SectorStylePresets::HOME_SERVICES],
        ['slug' => 'removalist', 'label' => 'Removalist / Moving', 'group' => 'Home & Trade Services', 'bucket' => SectorStylePresets::HOME_SERVICES],
        ['slug' => 'pest-control', 'label' => 'Pest control', 'group' => 'Home & Trade Services', 'bucket' => SectorStylePresets::HOME_SERVICES],

        ['slug' => 'accommodation', 'label' => 'Accommodation / Stays', 'group' => 'Hospitality & Events', 'bucket' => SectorStylePresets::HOSPITALITY],
        ['slug' => 'event-venue', 'label' => 'Event venue', 'group' => 'Hospitality & Events', 'bucket' => SectorStylePresets::HOSPITALITY],
        ['slug' => 'event-planner', 'label' => 'Event planner', 'group' => 'Hospitality & Events', 'bucket' => SectorStylePresets::HOSPITALITY],
        ['slug' => 'wedding-planner', 'label' => 'Wedding planner', 'group' => 'Hospitality & Events', 'bucket' => SectorStylePresets::HOSPITALITY],
        ['slug' => 'bartender', 'label' => 'Bartender / Mobile bar', 'group' => 'Hospitality & Events', 'bucket' => SectorStylePresets::HOSPITALITY],

        ['slug' => 'mechanic', 'label' => 'Mechanic / Auto repair', 'group' => 'Automotive', 'bucket' => SectorStylePresets::AUTOMOTIVE],
        ['slug' => 'car-detailer', 'label' => 'Car detailer / Wash', 'group' => 'Automotive', 'bucket' => SectorStylePresets::AUTOMOTIVE],
        ['slug' => 'auto-electrician', 'label' => 'Auto electrician', 'group' => 'Automotive', 'bucket' => SectorStylePresets::AUTOMOTIVE],
        ['slug' => 'tyre-service', 'label' => 'Tyre / Wheel service', 'group' => 'Automotive', 'bucket' => SectorStylePresets::AUTOMOTIVE],

        ['slug' => 'photographer', 'label' => 'Photographer', 'group' => 'Creative & Entertainment', 'bucket' => SectorStylePresets::CREATIVE_ENTERTAINMENT],
        ['slug' => 'videographer', 'label' => 'Videographer', 'group' => 'Creative & Entertainment', 'bucket' => SectorStylePresets::CREATIVE_ENTERTAINMENT],
        ['slug' => 'graphic-designer', 'label' => 'Graphic designer', 'group' => 'Creative & Entertainment', 'bucket' => SectorStylePresets::CREATIVE_ENTERTAINMENT],
        ['slug' => 'artist', 'label' => 'Artist / Illustrator', 'group' => 'Creative & Entertainment', 'bucket' => SectorStylePresets::CREATIVE_ENTERTAINMENT],
        ['slug' => 'musician', 'label' => 'Musician / DJ', 'group' => 'Creative & Entertainment', 'bucket' => SectorStylePresets::CREATIVE_ENTERTAINMENT],
        ['slug' => 'content-creator', 'label' => 'Content creator / Influencer', 'group' => 'Creative & Entertainment', 'bucket' => SectorStylePresets::CREATIVE_ENTERTAINMENT],
        ['slug' => 'writer', 'label' => 'Writer / Copywriter', 'group' => 'Creative & Entertainment', 'bucket' => SectorStylePresets::CREATIVE_ENTERTAINMENT],

        ['slug' => 'tutor', 'label' => 'Tutor', 'group' => 'Education & Coaching', 'bucket' => SectorStylePresets::EDUCATION_COACHING],
        ['slug' => 'life-coach', 'label' => 'Life / Business coach', 'group' => 'Education & Coaching', 'bucket' => SectorStylePresets::EDUCATION_COACHING],
        ['slug' => 'music-teacher', 'label' => 'Music teacher', 'group' => 'Education & Coaching', 'bucket' => SectorStylePresets::EDUCATION_COACHING],
        ['slug' => 'driving-instructor', 'label' => 'Driving instructor', 'group' => 'Education & Coaching', 'bucket' => SectorStylePresets::EDUCATION_COACHING],
        ['slug' => 'dance-instructor', 'label' => 'Dance instructor', 'group' => 'Education & Coaching', 'bucket' => SectorStylePresets::EDUCATION_COACHING],
        ['slug' => 'course-creator', 'label' => 'Course creator / Educator', 'group' => 'Education & Coaching', 'bucket' => SectorStylePresets::EDUCATION_COACHING],

        // No bucket of its own — falls in with the trustworthy/neutral
        // professional-services preset so a chosen "other" never looks broken.
        ['slug' => 'other', 'label' => 'Something else', 'group' => 'Other', 'bucket' => SectorStylePresets::PROFESSIONAL_SERVICES],
    ];

    /**
     * Ordered keyword => sector-slug map for classifying a raw Google/Instagram
     * business category string. Specific-before-generic ordering discipline
     * ('barber' before 'bar', etc.) — classify() returns the FIRST substring
     * match, so a generic keyword must never precede a more specific colliding
     * one.
     *
     * @var array<string, string>
     */
    private const KEYWORD_SECTORS = [
        'barber' => 'barber',
        'hair' => 'hair-salon',
        'nail' => 'nail-technician',
        'makeup' => 'makeup-artist',
        'make-up' => 'makeup-artist',
        'spa' => 'spa',
        'tattoo' => 'tattoo-artist',
        'gym' => 'gym',
        'fitness' => 'gym',
        'yoga' => 'yoga-instructor',
        'trainer' => 'personal-trainer',
        'chiropractor' => 'chiropractor',
        'dentist' => 'dentist',
        'physio' => 'physiotherapist',
        'sport' => 'gym',
        'photographer' => 'photographer',
        'photo' => 'photographer',
        'art gallery' => 'artist',
        'gallery' => 'artist',
        'music' => 'musician',
        'real estate' => 'real-estate-agent',
        'accountant' => 'accountant',
        'lawyer' => 'lawyer',
        'attorney' => 'lawyer',
        'consultant' => 'consultant',
        'clothing' => 'clothing-boutique',
        'florist' => 'florist',
        'flower' => 'florist',
        'jewel' => 'jewellery',
        'gift shop' => 'gift-shop',
        'plumber' => 'plumber',
        'electrician' => 'electrician',
        'clean' => 'cleaner',
        'landscap' => 'landscaper',
        'hotel' => 'accommodation',
        'event venue' => 'event-venue',
        'event planner' => 'event-planner',
        'wedding' => 'wedding-planner',
        'car repair' => 'mechanic',
        'auto repair' => 'mechanic',
        'mechanic' => 'mechanic',
        'car wash' => 'car-detailer',
        'car dealer' => 'mechanic',
        'tutor' => 'tutor',
        'dance school' => 'dance-instructor',
        'dance' => 'dance-instructor',
        'driving school' => 'driving-instructor',
        'restaurant' => 'restaurant',
        'cafe' => 'cafe',
        'coffee' => 'cafe',
        'bakery' => 'bakery',
        'food truck' => 'food-truck',
        'caterer' => 'caterer',
        'bar' => 'bar',
        // NO bare 'artist' key, deliberately. It reaches the `artist` slug from
        // strings like Instagram's "Artist" category, which tattooists,
        // musicians, hairdressers and photographers all pick — and a sector is
        // STICKY: IdentitySync::applySector returns early when sector_source is
        // set and isn't google-business, so an Instagram-stamped guess locks
        // Google Business out of ever correcting it, permanently. Writing
        // nothing keeps the field open for a better source or the user's own
        // pick. 'art gallery'/'gallery' stay because they are unambiguous.
    ];

    /**
     * Instagram business categories that the shared substring map gets WRONG or
     * misses entirely. Instagram's categories come from Facebook's Page
     * taxonomy — a CLOSED vocabulary, unlike Google's free-ish place-type
     * strings — so this is an EXACT match on the whole normalised category, not
     * a substring pass. Exact matching is what makes it collision-free: a
     * substring entry for lashes would capture "Flash Tattoo".
     *
     * Only entries the fallback gets wrong belong here. Anything the substring
     * map already resolves correctly ("Hair Stylist", "Barber Shop", "Tattoo &
     * Piercing Shop", "Restaurant") is deliberately absent.
     *
     * Keys MUST be lowercase and trimmed — fromInstagramCategory() normalises
     * the input before looking up, and does not normalise these.
     *
     * @var array<string, string>
     */
    private const INSTAGRAM_CATEGORY_SECTORS = [
        // Corrections — the substring map returns the wrong slug for these.
        'fitness trainer' => 'personal-trainer',
        'fitness coach' => 'personal-trainer',
        'music lessons & instruction school' => 'music-teacher',
        'music teacher' => 'music-teacher',

        // The substring map's trailing 'bar' and 'sport' keys capture these.
        // Three would otherwise resolve to the FOOD slug 'bar' and flip
        // can_use_booking off on a business account.
        'sports bar' => 'bar',
        'juice bar' => 'cafe',
        'bartender' => 'bartender',
        'barre studio' => 'yoga-instructor',
        'sportswear store' => 'clothing-boutique',
        'hair removal service' => 'esthetician',

        // Creative
        'digital creator' => 'content-creator',
        'content creator' => 'content-creator',
        'blogger' => 'content-creator',
        'videographer' => 'videographer',
        'video creator' => 'videographer',
        'graphic designer' => 'graphic-designer',
        'writer' => 'writer',

        // Beauty & personal care
        'skin care service' => 'esthetician',
        'skincare service' => 'esthetician',
        'waxing service' => 'esthetician',
        'eyelash service' => 'brows-lashes',
        'eyebrow service' => 'brows-lashes',
        'massage service' => 'spa',
        'massage therapist' => 'spa',

        // Health & fitness
        'pilates studio' => 'yoga-instructor',
        'nutritionist' => 'nutritionist',
        'dietitian' => 'nutritionist',
        'physical therapist' => 'physiotherapist',

        // Education & coaching
        'life coach' => 'life-coach',
        'business coach' => 'life-coach',

        // Professional services
        'consulting agency' => 'consultant',
        'marketing agency' => 'marketing-agency',
        'advertising agency' => 'marketing-agency',
        'financial planner' => 'financial-advisor',
        'insurance agent' => 'insurance-broker',

        // Trades & automotive
        'automotive repair shop' => 'mechanic',
        'plumbing service' => 'plumber',
        'contractor' => 'builder',
        'general contractor' => 'builder',

        // Hospitality
        'bed and breakfast' => 'accommodation',
        'vacation home rental' => 'accommodation',

        // NOT mapped, deliberately: 'health/beauty', 'beauty, cosmetic &
        // personal care', 'public figure', 'personal blog', 'entrepreneur',
        // 'product/service', 'local business'. Too vague to pick a sector from,
        // and sector drives FOOD_SECTORS capability gating — null is safer than
        // a guess, and the user can pick from the dashboard.
    ];

    /**
     * Keywords safe to substring-match against FREE TEXT — an Instagram handle
     * or display name — when the category is too vague to classify.
     *
     * Separate from KEYWORD_SECTORS on purpose. That map is safe against
     * Facebook's closed category vocabulary and dangerous against free text:
     * it holds 'spa' at index 5 and 'fitness' at index 8, so "Spartan Fitness"
     * resolves to 'spa'. Word-boundary anchoring would fix that but break the
     * run-together handles this exists to catch ('\btattoo' misses
     * crucibletattooco). A vetted map with plain substring matching does both.
     *
     * A key qualifies only if it (1) is >=5 characters, (2) is not a substring
     * of a common English word or Australian surname, (3) names a TRADE rather
     * than a medium, and (4) cannot be manufactured by joining across a
     * separator in a plausible handle. Clause 4 is why there is no bare 'hair':
     * normalisation turns beth.airbnb into bethairbnb.
     *
     * NO VALUE MAY BE IN FOOD_SECTORS. A wrong food slug flips four
     * capabilities and misroutes links via LinkRouter's own copy of the arms.
     * That rules out coffee/catering/baker — Baker is a top-20 AU surname.
     *
     * ORDER: first substring hit wins. Where a key is commonly a surname
     * ('barber') or a qualifier of another trade ('realestate', 'wedding'), it
     * MUST come after every trade key that can co-occur with it in one handle.
     * The medium someone practises beats the subject they practise it on.
     *
     * @var array<string, string>
     */
    private const TEXT_KEYWORD_SECTORS = [
        // Media first — these co-occur with surnames and qualifiers below.
        'photograph' => 'photographer',
        'videograph' => 'videographer',
        'graphicdesign' => 'graphic-designer',

        // Beauty & personal care
        'hairstylist' => 'hair-salon',
        'hairdress' => 'hair-salon',
        'hairsalon' => 'hair-salon',
        'hairstudio' => 'hair-salon',
        'barber' => 'barber',
        'tattoo' => 'tattoo-artist',
        'makeup' => 'makeup-artist',
        'lashes' => 'brows-lashes',
        'browsandlashes' => 'brows-lashes',
        'airbrushtanning' => 'esthetician',
        'spraytan' => 'esthetician',
        'skincare' => 'esthetician',
        'esthetic' => 'esthetician',
        'massage' => 'spa',
        'nailtech' => 'nail-technician',
        'nailsalon' => 'nail-technician',

        // Health & fitness
        'pilates' => 'yoga-instructor',
        'barrepilates' => 'yoga-instructor',
        'yogateacher' => 'yoga-instructor',
        'personaltrainer' => 'personal-trainer',
        'fitness' => 'gym',
        'physio' => 'physiotherapist',
        'chiro' => 'chiropractor',
        'dentist' => 'dentist',
        'nutrition' => 'nutritionist',

        // Trades & automotive
        'plumb' => 'plumber',
        'electrician' => 'electrician',
        'landscap' => 'landscaper',
        'carpentry' => 'builder',
        'mechanic' => 'mechanic',
        'cardetailing' => 'car-detailer',

        // Retail & professional — after the media keys above.
        'florist' => 'florist',
        'jeweller' => 'jewellery',
        'realestate' => 'real-estate-agent',
        'bookkeep' => 'accountant',
        'tutoring' => 'tutor',
    ];

    /**
     * Classify free text (an Instagram handle or display name) into a sector.
     *
     * Normalises to a-z only — dots, underscores, spaces, digits and emoji all
     * removed — then takes the first substring hit in map order. Stripping
     * separators is what lets 'crucibletattooco' match; the map's clause-4
     * admission rule is what stops it manufacturing false positives.
     */
    public static function classifyText(?string $raw): ?string
    {
        if (! is_string($raw)) {
            return null;
        }

        $normalised = preg_replace('/[^a-z]/', '', strtolower($raw)) ?? '';
        if ($normalised === '') {
            return null;
        }

        foreach (self::TEXT_KEYWORD_SECTORS as $keyword => $slug) {
            if (str_contains($normalised, $keyword)) {
                return $slug;
            }
        }

        return null;
    }

    /**
     * The picker payload: sectors grouped into their sections, in list order.
     *
     * @return list<array{group: string, options: list<array{slug: string, label: string}>}>
     */
    public static function all(): array
    {
        $groups = [];
        foreach (self::SECTORS as $sector) {
            $groups[$sector['group']][] = [
                'slug' => $sector['slug'],
                'label' => $sector['label'],
            ];
        }

        // Preserve first-seen group order (SECTORS is already ordered by group).
        return array_map(
            fn (string $group, array $options) => ['group' => $group, 'options' => $options],
            array_keys($groups),
            array_values($groups),
        );
    }

    public static function isValid(string $slug): bool
    {
        foreach (self::SECTORS as $sector) {
            if ($sector['slug'] === $slug) {
                return true;
            }
        }

        return false;
    }

    public static function labelFor(string $slug): ?string
    {
        foreach (self::SECTORS as $sector) {
            if ($sector['slug'] === $slug) {
                return $sector['label'];
            }
        }

        return null;
    }

    /**
     * The SectorStylePresets bucket a sector belongs to — read by
     * ProfileDesignPresets to auto-style a sitepage. Null for unknown slugs.
     */
    public static function bucketFor(string $slug): ?string
    {
        foreach (self::SECTORS as $sector) {
            if ($sector['slug'] === $slug) {
                return $sector['bucket'];
            }
        }

        return null;
    }

    /**
     * Whether a sector slug is in the Food & Drink group — the single predicate
     * every food-derived AccountCapabilities flag calls through. Null (no
     * sector chosen/synced yet) is NOT food: a business with no sector defaults
     * to the booking-only capability set until an industry is picked or synced.
     */
    public static function isFood(?string $sector): bool
    {
        return $sector !== null && in_array($sector, self::FOOD_SECTORS, true);
    }

    /**
     * Map a raw Google Business category (Places `primaryTypeDisplayName`, e.g.
     * "Italian restaurant", "Barber shop") to the closest curated sector slug
     * via the shared ordered-keyword classifier. Null when nothing matches or
     * the input is empty — callers leave the stored sector untouched on null.
     */
    public static function fromGoogleCategory(?string $category): ?string
    {
        if (! is_string($category) || trim($category) === '') {
            return null;
        }

        return self::classify($category, self::KEYWORD_SECTORS);
    }

    /**
     * Map a raw Instagram business category (Apify `businessCategoryName`, e.g.
     * "Hair Stylist", "Artist", "None,Fast food restaurant") to the closest
     * curated sector slug.
     *
     * Instagram comma-joins up to three categories, PRIMARY FIRST, so this
     * resolves on the first SEGMENT that matches either map — not on the first
     * map that matches any segment. Two maps are consulted per segment: the
     * Instagram-vocabulary exact map, then the shared ordered-keyword
     * classifier fromGoogleCategory() uses, so a category the exact map doesn't
     * list still lands on the same sector Google would give the same business.
     *
     * Null when nothing matches or the input is empty — callers leave the
     * stored sector untouched on null.
     */
    public static function fromInstagramCategory(?string $category): ?string
    {
        if (! is_string($category) || trim($category) === '') {
            return null;
        }

        // Whole-string exact FIRST, before any splitting — a genuine category
        // name can itself contain a comma ("Beauty, Cosmetic & Personal Care"),
        // and splitting one of those apart would match on a fragment.
        $exact = self::INSTAGRAM_CATEGORY_SECTORS[strtolower(trim($category))] ?? null;
        if ($exact !== null) {
            return $exact;
        }

        // Then segment by segment, IN ORDER, trying BOTH maps on each segment
        // before moving to the next. Checking one map across every segment
        // first would let a secondary category outrank the primary one: a
        // restaurant whose page also lists "Digital Creator" would resolve to
        // content-creator, isFood() would go false, and can_use_menu /
        // can_use_reservations / can_use_online_ordering would silently switch
        // off — permanently, since sector is sticky once Instagram stamps it.
        //
        // No trailing whole-string classify(): a single (comma-free) input is
        // itself one segment, and no KEYWORD_SECTORS key contains a comma, so
        // splitting cannot lose a substring match. Pinned by a test.
        foreach (self::categorySegments($category) as $segment) {
            $mapped = self::INSTAGRAM_CATEGORY_SECTORS[strtolower($segment)]
                ?? self::classify($segment, self::KEYWORD_SECTORS);

            if ($mapped !== null) {
                return $mapped;
            }
        }

        return null;
    }

    /**
     * The comma-separated segments of a raw category string: trimmed, with
     * empty and placeholder segments dropped, original order preserved.
     *
     * Instagram returns multiple categories comma-joined and includes its
     * literal "None" as a real segment — `hungryjacksau` returns
     * "None,Fast food restaurant" on a fully successful scrape (verified
     * 2026-08-11). A whole-string placeholder check therefore leaves the junk
     * prefix in place, both for classification and for the stored payload
     * (InstagramConnectionSeeder reads this too, because businessCategory is on
     * the public wire).
     *
     * @return list<string>
     */
    public static function categorySegments(?string $raw): array
    {
        if (! is_string($raw) || trim($raw) === '') {
            return [];
        }

        return array_values(array_filter(
            array_map('trim', explode(',', trim($raw))),
            fn (string $segment) => $segment !== ''
                && ! in_array(strtolower($segment), self::PLACEHOLDER_CATEGORIES, true),
        ));
    }

    /**
     * Classify a raw category string against an ORDERED keyword => slug map.
     * First substring match wins (case-insensitive) — KEYWORD_SECTORS must
     * order colliding keywords specific-before-generic ('barber' before
     * 'bar', so "Barber shop" doesn't fall through to the bar keyword).
     *
     * @param  array<string, string>  $orderedKeywordToSlug
     */
    private static function classify(string $raw, array $orderedKeywordToSlug): ?string
    {
        $lower = strtolower(trim($raw));
        if ($lower === '' || in_array($lower, self::PLACEHOLDER_CATEGORIES, true)) {
            return null;
        }

        foreach ($orderedKeywordToSlug as $keyword => $slug) {
            if (str_contains($lower, $keyword)) {
                return $slug;
            }
        }

        return null;
    }
}
