<?php

namespace App\Services\Profile;

use App\Services\Design\Presets\CategoryStylePresets;

/**
 * Curated, user-facing industry list for the profile "sector" field.
 *
 * Every entry carries a `bucket` = one of the ten CategoryStylePresets bucket
 * constants, so a chosen sector could later drive the same design presets the
 * Google/Instagram category factors already resolve. The bucket is metadata
 * only here (nothing consumes it for styling yet) — but keeping it on every row
 * means the sector and the platform-derived buckets speak the same vocabulary,
 * which is what makes fromGoogleCategory() honest: it maps Google's raw category
 * to a sector whose bucket agrees with what GoogleBusinessTypeFactor would pick.
 *
 * The list is deliberately mid-size (~70 entries) — broad enough that most
 * solo professionals find themselves, tight enough to stay a curated picker
 * rather than the full Google place-type enum.
 */
final class SectorTaxonomy
{
    /**
     * Ordered sector rows. Order within a group is the display order in the
     * picker. `group` is the section header the picker renders.
     *
     * @var list<array{slug: string, label: string, group: string, bucket: string}>
     */
    private const SECTORS = [
        // ── Food & Drink ────────────────────────────────────────────────
        ['slug' => 'restaurant', 'label' => 'Restaurant', 'group' => 'Food & Drink', 'bucket' => CategoryStylePresets::FOOD_DRINK],
        ['slug' => 'cafe', 'label' => 'Café / Coffee shop', 'group' => 'Food & Drink', 'bucket' => CategoryStylePresets::FOOD_DRINK],
        ['slug' => 'bakery', 'label' => 'Bakery', 'group' => 'Food & Drink', 'bucket' => CategoryStylePresets::FOOD_DRINK],
        ['slug' => 'bar', 'label' => 'Bar / Pub', 'group' => 'Food & Drink', 'bucket' => CategoryStylePresets::FOOD_DRINK],
        ['slug' => 'food-truck', 'label' => 'Food truck / Street food', 'group' => 'Food & Drink', 'bucket' => CategoryStylePresets::FOOD_DRINK],
        ['slug' => 'caterer', 'label' => 'Caterer', 'group' => 'Food & Drink', 'bucket' => CategoryStylePresets::FOOD_DRINK],
        ['slug' => 'personal-chef', 'label' => 'Personal chef', 'group' => 'Food & Drink', 'bucket' => CategoryStylePresets::FOOD_DRINK],

        // ── Beauty & Personal Care ──────────────────────────────────────
        ['slug' => 'hair-salon', 'label' => 'Hair salon', 'group' => 'Beauty & Personal Care', 'bucket' => CategoryStylePresets::BEAUTY_PERSONAL_CARE],
        ['slug' => 'barber', 'label' => 'Barber', 'group' => 'Beauty & Personal Care', 'bucket' => CategoryStylePresets::BEAUTY_PERSONAL_CARE],
        ['slug' => 'nail-technician', 'label' => 'Nail technician', 'group' => 'Beauty & Personal Care', 'bucket' => CategoryStylePresets::BEAUTY_PERSONAL_CARE],
        ['slug' => 'makeup-artist', 'label' => 'Makeup artist', 'group' => 'Beauty & Personal Care', 'bucket' => CategoryStylePresets::BEAUTY_PERSONAL_CARE],
        ['slug' => 'esthetician', 'label' => 'Esthetician / Skincare', 'group' => 'Beauty & Personal Care', 'bucket' => CategoryStylePresets::BEAUTY_PERSONAL_CARE],
        ['slug' => 'spa', 'label' => 'Spa / Massage', 'group' => 'Beauty & Personal Care', 'bucket' => CategoryStylePresets::BEAUTY_PERSONAL_CARE],
        ['slug' => 'tattoo-artist', 'label' => 'Tattoo artist', 'group' => 'Beauty & Personal Care', 'bucket' => CategoryStylePresets::BEAUTY_PERSONAL_CARE],
        ['slug' => 'brows-lashes', 'label' => 'Brows & lashes', 'group' => 'Beauty & Personal Care', 'bucket' => CategoryStylePresets::BEAUTY_PERSONAL_CARE],

        // ── Health & Fitness ────────────────────────────────────────────
        ['slug' => 'personal-trainer', 'label' => 'Personal trainer', 'group' => 'Health & Fitness', 'bucket' => CategoryStylePresets::HEALTH_FITNESS],
        ['slug' => 'gym', 'label' => 'Gym / Studio', 'group' => 'Health & Fitness', 'bucket' => CategoryStylePresets::HEALTH_FITNESS],
        ['slug' => 'yoga-instructor', 'label' => 'Yoga / Pilates instructor', 'group' => 'Health & Fitness', 'bucket' => CategoryStylePresets::HEALTH_FITNESS],
        ['slug' => 'nutritionist', 'label' => 'Nutritionist / Dietitian', 'group' => 'Health & Fitness', 'bucket' => CategoryStylePresets::HEALTH_FITNESS],
        ['slug' => 'physiotherapist', 'label' => 'Physiotherapist', 'group' => 'Health & Fitness', 'bucket' => CategoryStylePresets::HEALTH_FITNESS],
        ['slug' => 'chiropractor', 'label' => 'Chiropractor', 'group' => 'Health & Fitness', 'bucket' => CategoryStylePresets::HEALTH_FITNESS],
        ['slug' => 'therapist', 'label' => 'Therapist / Counsellor', 'group' => 'Health & Fitness', 'bucket' => CategoryStylePresets::HEALTH_FITNESS],
        ['slug' => 'dentist', 'label' => 'Dentist', 'group' => 'Health & Fitness', 'bucket' => CategoryStylePresets::HEALTH_FITNESS],

        // ── Professional Services ───────────────────────────────────────
        ['slug' => 'accountant', 'label' => 'Accountant / Bookkeeper', 'group' => 'Professional Services', 'bucket' => CategoryStylePresets::PROFESSIONAL_SERVICES],
        ['slug' => 'lawyer', 'label' => 'Lawyer / Solicitor', 'group' => 'Professional Services', 'bucket' => CategoryStylePresets::PROFESSIONAL_SERVICES],
        ['slug' => 'financial-advisor', 'label' => 'Financial advisor', 'group' => 'Professional Services', 'bucket' => CategoryStylePresets::PROFESSIONAL_SERVICES],
        ['slug' => 'consultant', 'label' => 'Consultant', 'group' => 'Professional Services', 'bucket' => CategoryStylePresets::PROFESSIONAL_SERVICES],
        ['slug' => 'real-estate-agent', 'label' => 'Real estate agent', 'group' => 'Professional Services', 'bucket' => CategoryStylePresets::PROFESSIONAL_SERVICES],
        ['slug' => 'insurance-broker', 'label' => 'Insurance broker', 'group' => 'Professional Services', 'bucket' => CategoryStylePresets::PROFESSIONAL_SERVICES],
        ['slug' => 'mortgage-broker', 'label' => 'Mortgage broker', 'group' => 'Professional Services', 'bucket' => CategoryStylePresets::PROFESSIONAL_SERVICES],
        ['slug' => 'marketing-agency', 'label' => 'Marketing / PR', 'group' => 'Professional Services', 'bucket' => CategoryStylePresets::PROFESSIONAL_SERVICES],
        ['slug' => 'it-services', 'label' => 'IT / Tech services', 'group' => 'Professional Services', 'bucket' => CategoryStylePresets::PROFESSIONAL_SERVICES],
        ['slug' => 'virtual-assistant', 'label' => 'Virtual assistant', 'group' => 'Professional Services', 'bucket' => CategoryStylePresets::PROFESSIONAL_SERVICES],

        // ── Retail & Shopping ───────────────────────────────────────────
        ['slug' => 'clothing-boutique', 'label' => 'Clothing / Fashion boutique', 'group' => 'Retail & Shopping', 'bucket' => CategoryStylePresets::RETAIL_SHOPPING],
        ['slug' => 'jewellery', 'label' => 'Jewellery', 'group' => 'Retail & Shopping', 'bucket' => CategoryStylePresets::RETAIL_SHOPPING],
        ['slug' => 'florist', 'label' => 'Florist', 'group' => 'Retail & Shopping', 'bucket' => CategoryStylePresets::RETAIL_SHOPPING],
        ['slug' => 'gift-shop', 'label' => 'Gift shop', 'group' => 'Retail & Shopping', 'bucket' => CategoryStylePresets::RETAIL_SHOPPING],
        ['slug' => 'homewares', 'label' => 'Homewares / Décor', 'group' => 'Retail & Shopping', 'bucket' => CategoryStylePresets::RETAIL_SHOPPING],
        ['slug' => 'artisan-maker', 'label' => 'Artisan / Handmade goods', 'group' => 'Retail & Shopping', 'bucket' => CategoryStylePresets::RETAIL_SHOPPING],

        // ── Home & Trade Services ───────────────────────────────────────
        ['slug' => 'plumber', 'label' => 'Plumber', 'group' => 'Home & Trade Services', 'bucket' => CategoryStylePresets::HOME_SERVICES],
        ['slug' => 'electrician', 'label' => 'Electrician', 'group' => 'Home & Trade Services', 'bucket' => CategoryStylePresets::HOME_SERVICES],
        ['slug' => 'builder', 'label' => 'Builder / Carpenter', 'group' => 'Home & Trade Services', 'bucket' => CategoryStylePresets::HOME_SERVICES],
        ['slug' => 'painter', 'label' => 'Painter / Decorator', 'group' => 'Home & Trade Services', 'bucket' => CategoryStylePresets::HOME_SERVICES],
        ['slug' => 'cleaner', 'label' => 'Cleaner', 'group' => 'Home & Trade Services', 'bucket' => CategoryStylePresets::HOME_SERVICES],
        ['slug' => 'landscaper', 'label' => 'Landscaper / Gardener', 'group' => 'Home & Trade Services', 'bucket' => CategoryStylePresets::HOME_SERVICES],
        ['slug' => 'handyman', 'label' => 'Handyman', 'group' => 'Home & Trade Services', 'bucket' => CategoryStylePresets::HOME_SERVICES],
        ['slug' => 'removalist', 'label' => 'Removalist / Moving', 'group' => 'Home & Trade Services', 'bucket' => CategoryStylePresets::HOME_SERVICES],
        ['slug' => 'pest-control', 'label' => 'Pest control', 'group' => 'Home & Trade Services', 'bucket' => CategoryStylePresets::HOME_SERVICES],

        // ── Hospitality & Events ────────────────────────────────────────
        ['slug' => 'accommodation', 'label' => 'Accommodation / Stays', 'group' => 'Hospitality & Events', 'bucket' => CategoryStylePresets::HOSPITALITY],
        ['slug' => 'event-venue', 'label' => 'Event venue', 'group' => 'Hospitality & Events', 'bucket' => CategoryStylePresets::HOSPITALITY],
        ['slug' => 'event-planner', 'label' => 'Event planner', 'group' => 'Hospitality & Events', 'bucket' => CategoryStylePresets::HOSPITALITY],
        ['slug' => 'wedding-planner', 'label' => 'Wedding planner', 'group' => 'Hospitality & Events', 'bucket' => CategoryStylePresets::HOSPITALITY],
        ['slug' => 'bartender', 'label' => 'Bartender / Mobile bar', 'group' => 'Hospitality & Events', 'bucket' => CategoryStylePresets::HOSPITALITY],

        // ── Automotive ──────────────────────────────────────────────────
        ['slug' => 'mechanic', 'label' => 'Mechanic / Auto repair', 'group' => 'Automotive', 'bucket' => CategoryStylePresets::AUTOMOTIVE],
        ['slug' => 'car-detailer', 'label' => 'Car detailer / Wash', 'group' => 'Automotive', 'bucket' => CategoryStylePresets::AUTOMOTIVE],
        ['slug' => 'auto-electrician', 'label' => 'Auto electrician', 'group' => 'Automotive', 'bucket' => CategoryStylePresets::AUTOMOTIVE],
        ['slug' => 'tyre-service', 'label' => 'Tyre / Wheel service', 'group' => 'Automotive', 'bucket' => CategoryStylePresets::AUTOMOTIVE],

        // ── Creative & Entertainment ────────────────────────────────────
        ['slug' => 'photographer', 'label' => 'Photographer', 'group' => 'Creative & Entertainment', 'bucket' => CategoryStylePresets::CREATIVE_ENTERTAINMENT],
        ['slug' => 'videographer', 'label' => 'Videographer', 'group' => 'Creative & Entertainment', 'bucket' => CategoryStylePresets::CREATIVE_ENTERTAINMENT],
        ['slug' => 'graphic-designer', 'label' => 'Graphic designer', 'group' => 'Creative & Entertainment', 'bucket' => CategoryStylePresets::CREATIVE_ENTERTAINMENT],
        ['slug' => 'artist', 'label' => 'Artist / Illustrator', 'group' => 'Creative & Entertainment', 'bucket' => CategoryStylePresets::CREATIVE_ENTERTAINMENT],
        ['slug' => 'musician', 'label' => 'Musician / DJ', 'group' => 'Creative & Entertainment', 'bucket' => CategoryStylePresets::CREATIVE_ENTERTAINMENT],
        ['slug' => 'content-creator', 'label' => 'Content creator / Influencer', 'group' => 'Creative & Entertainment', 'bucket' => CategoryStylePresets::CREATIVE_ENTERTAINMENT],
        ['slug' => 'writer', 'label' => 'Writer / Copywriter', 'group' => 'Creative & Entertainment', 'bucket' => CategoryStylePresets::CREATIVE_ENTERTAINMENT],

        // ── Education & Coaching ────────────────────────────────────────
        ['slug' => 'tutor', 'label' => 'Tutor', 'group' => 'Education & Coaching', 'bucket' => CategoryStylePresets::EDUCATION_COACHING],
        ['slug' => 'life-coach', 'label' => 'Life / Business coach', 'group' => 'Education & Coaching', 'bucket' => CategoryStylePresets::EDUCATION_COACHING],
        ['slug' => 'music-teacher', 'label' => 'Music teacher', 'group' => 'Education & Coaching', 'bucket' => CategoryStylePresets::EDUCATION_COACHING],
        ['slug' => 'driving-instructor', 'label' => 'Driving instructor', 'group' => 'Education & Coaching', 'bucket' => CategoryStylePresets::EDUCATION_COACHING],
        ['slug' => 'dance-instructor', 'label' => 'Dance instructor', 'group' => 'Education & Coaching', 'bucket' => CategoryStylePresets::EDUCATION_COACHING],
        ['slug' => 'course-creator', 'label' => 'Course creator / Educator', 'group' => 'Education & Coaching', 'bucket' => CategoryStylePresets::EDUCATION_COACHING],

        // ── Other ───────────────────────────────────────────────────────
        // No bucket of its own — falls in with the trustworthy/neutral
        // professional-services preset so a chosen "other" never looks broken.
        ['slug' => 'other', 'label' => 'Something else', 'group' => 'Other', 'bucket' => CategoryStylePresets::PROFESSIONAL_SERVICES],
    ];

    /**
     * Ordered keyword => sector-slug map for classifying a raw Google Business
     * category string. Same specific-before-generic ordering discipline as
     * GoogleBusinessTypeFactor::KEYWORD_BUCKETS ('barber' before 'bar', etc.) —
     * CategoryStylePresets::classify() returns the FIRST substring match, so a
     * generic keyword must never precede a more specific colliding one.
     *
     * Only the keywords GoogleBusinessTypeFactor covers are mapped, re-pointed
     * from a style bucket to the closest curated sector slug — keeping the two
     * classifiers in lock-step (same inputs → agreeing bucket).
     *
     * @var array<string, string>
     */
    private const KEYWORD_SECTORS = [
        'barber' => 'barber',
        'hair' => 'hair-salon',
        'nail' => 'nail-technician',
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
    ];

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
     * The CategoryStylePresets bucket a sector belongs to — the styling hook
     * the taxonomy was built to carry (consumed by SectorFactor). Null for
     * unknown slugs.
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

        return CategoryStylePresets::classify($category, self::KEYWORD_SECTORS);
    }
}
