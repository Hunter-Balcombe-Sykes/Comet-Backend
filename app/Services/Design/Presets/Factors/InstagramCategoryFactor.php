<?php

namespace App\Services\Design\Presets\Factors;

use App\Models\Core\Site\IntegrationConnection;
use App\Services\Design\Presets\CategoryStylePresets;
use App\Services\Design\Presets\DesignFactor;
use App\Services\Design\Presets\FactorMode;
use App\Services\Platforms\Registry\Platform;

// One-shot factor: an Instagram connection's declared business category
// (payload.businessCategory, scraped from the account's Facebook Page
// category — e.g. "Beauty, Cosmetic & Personal Care") classifies into a
// shared style bucket (CategoryStylePresets) and applies that bucket's
// preset. Lower priority than GoogleBusinessTypeFactor, so a connected
// Google Business always wins any contested column — Instagram only shows
// through on columns/sites where Google isn't connected or didn't match.
//
// Instagram's category is far less reliable than Google's: it's optional,
// and in practice a large share of accounts carry the literal string "None"
// (CategoryStylePresets::classify() treats that — and blank — as no match).
// Instagram also has no recurring refresh cron (unlike YouTube/Vimeo/etc.),
// so one-shot is the only mode that makes sense here; "auto" would never
// actually re-fire.
class InstagramCategoryFactor implements DesignFactor
{
    public const SOURCE = 'instagram:category';

    public const INTEGRATION = Platform::Instagram->value;

    // Ordered specific-before-generic: 'barber' MUST precede 'bar'.
    private const KEYWORD_BUCKETS = [
        'barber' => CategoryStylePresets::BEAUTY_PERSONAL_CARE,
        'hair' => CategoryStylePresets::BEAUTY_PERSONAL_CARE,
        'nail' => CategoryStylePresets::BEAUTY_PERSONAL_CARE,
        'spa' => CategoryStylePresets::BEAUTY_PERSONAL_CARE,
        'beauty' => CategoryStylePresets::BEAUTY_PERSONAL_CARE,
        'cosmetic' => CategoryStylePresets::BEAUTY_PERSONAL_CARE,
        'tattoo' => CategoryStylePresets::BEAUTY_PERSONAL_CARE,
        'gym' => CategoryStylePresets::HEALTH_FITNESS,
        'fitness' => CategoryStylePresets::HEALTH_FITNESS,
        'yoga' => CategoryStylePresets::HEALTH_FITNESS,
        'trainer' => CategoryStylePresets::HEALTH_FITNESS,
        'wellness' => CategoryStylePresets::HEALTH_FITNESS,
        'photographer' => CategoryStylePresets::CREATIVE_ENTERTAINMENT,
        'videographer' => CategoryStylePresets::CREATIVE_ENTERTAINMENT,
        'artist' => CategoryStylePresets::CREATIVE_ENTERTAINMENT,
        'musician' => CategoryStylePresets::CREATIVE_ENTERTAINMENT,
        'designer' => CategoryStylePresets::CREATIVE_ENTERTAINMENT,
        'consult' => CategoryStylePresets::PROFESSIONAL_SERVICES,
        'real estate' => CategoryStylePresets::PROFESSIONAL_SERVICES,
        'lawyer' => CategoryStylePresets::PROFESSIONAL_SERVICES,
        'legal' => CategoryStylePresets::PROFESSIONAL_SERVICES,
        'accountant' => CategoryStylePresets::PROFESSIONAL_SERVICES,
        'coach' => CategoryStylePresets::EDUCATION_COACHING,
        'tutor' => CategoryStylePresets::EDUCATION_COACHING,
        'dance' => CategoryStylePresets::EDUCATION_COACHING,
        'boutique' => CategoryStylePresets::RETAIL_SHOPPING,
        'clothing' => CategoryStylePresets::RETAIL_SHOPPING,
        'jewel' => CategoryStylePresets::RETAIL_SHOPPING,
        'retail' => CategoryStylePresets::RETAIL_SHOPPING,
        'shopping' => CategoryStylePresets::RETAIL_SHOPPING,
        'florist' => CategoryStylePresets::RETAIL_SHOPPING,
        'plumb' => CategoryStylePresets::HOME_SERVICES,
        'electric' => CategoryStylePresets::HOME_SERVICES,
        'clean' => CategoryStylePresets::HOME_SERVICES,
        'hotel' => CategoryStylePresets::HOSPITALITY,
        'wedding planner' => CategoryStylePresets::HOSPITALITY,
        'event planner' => CategoryStylePresets::HOSPITALITY,
        'auto repair' => CategoryStylePresets::AUTOMOTIVE,
        'car repair' => CategoryStylePresets::AUTOMOTIVE,
        'car dealer' => CategoryStylePresets::AUTOMOTIVE,
        'restaurant' => CategoryStylePresets::FOOD_DRINK,
        'cafe' => CategoryStylePresets::FOOD_DRINK,
        'coffee' => CategoryStylePresets::FOOD_DRINK,
        'bakery' => CategoryStylePresets::FOOD_DRINK,
        'food' => CategoryStylePresets::FOOD_DRINK,
        'bar' => CategoryStylePresets::FOOD_DRINK,
    ];

    public function key(): string
    {
        return self::SOURCE;
    }

    public function integration(): string
    {
        return self::INTEGRATION;
    }

    public function mode(): FactorMode
    {
        return FactorMode::OneShot;
    }

    public function priority(): int
    {
        // Lower than GoogleBusinessTypeFactor's 40 — Google wins contested columns.
        return 30;
    }

    /** @return array<string, string> */
    public function detect(IntegrationConnection $connection): array
    {
        $category = data_get($connection->payload, 'businessCategory');
        if (! is_string($category)) {
            return [];
        }

        $bucket = CategoryStylePresets::classify($category, self::KEYWORD_BUCKETS);

        return $bucket === null ? [] : CategoryStylePresets::forBucket($bucket);
    }
}
