<?php

namespace App\Services\Design\Presets\Factors;

use App\Models\Core\Site\IntegrationConnection;
use App\Services\Design\Presets\CategoryStylePresets;
use App\Services\Design\Presets\DesignFactor;
use App\Services\Design\Presets\FactorMode;

// One-shot factor: a Google Business connection's declared type (Places API
// primaryTypeDisplayName, e.g. "Italian restaurant", "Barber shop") classifies
// into a shared style bucket (CategoryStylePresets) and applies that bucket's
// preset. An unrecognised or missing type contributes nothing, so the
// sitepage keeps the package defaults.
//
// The business type lands in the connection payload as `category`, written
// SYNCHRONOUSLY at connect via fetchPlaceDetails — so the connect observer
// resolves this without waiting on the async Apify enrichment (which uses
// saveQuietly and bypasses the observer).
class GoogleBusinessTypeFactor implements DesignFactor
{
    public const SOURCE = 'google-business:type';

    public const INTEGRATION = 'google-business';

    // Ordered specific-before-generic: 'barber' MUST precede 'bar' or
    // "Barber shop" would match the generic 'bar' keyword first and
    // misclassify as food_drink.
    private const KEYWORD_BUCKETS = [
        'barber' => CategoryStylePresets::BEAUTY_PERSONAL_CARE,
        'hair' => CategoryStylePresets::BEAUTY_PERSONAL_CARE,
        'nail' => CategoryStylePresets::BEAUTY_PERSONAL_CARE,
        'spa' => CategoryStylePresets::BEAUTY_PERSONAL_CARE,
        'tattoo' => CategoryStylePresets::BEAUTY_PERSONAL_CARE,
        'gym' => CategoryStylePresets::HEALTH_FITNESS,
        'fitness' => CategoryStylePresets::HEALTH_FITNESS,
        'yoga' => CategoryStylePresets::HEALTH_FITNESS,
        'trainer' => CategoryStylePresets::HEALTH_FITNESS,
        'chiropractor' => CategoryStylePresets::HEALTH_FITNESS,
        'sport' => CategoryStylePresets::HEALTH_FITNESS,
        'photographer' => CategoryStylePresets::CREATIVE_ENTERTAINMENT,
        'photo' => CategoryStylePresets::CREATIVE_ENTERTAINMENT,
        'art gallery' => CategoryStylePresets::CREATIVE_ENTERTAINMENT,
        'gallery' => CategoryStylePresets::CREATIVE_ENTERTAINMENT,
        'music' => CategoryStylePresets::CREATIVE_ENTERTAINMENT,
        'real estate' => CategoryStylePresets::PROFESSIONAL_SERVICES,
        'accountant' => CategoryStylePresets::PROFESSIONAL_SERVICES,
        'lawyer' => CategoryStylePresets::PROFESSIONAL_SERVICES,
        'attorney' => CategoryStylePresets::PROFESSIONAL_SERVICES,
        'consultant' => CategoryStylePresets::PROFESSIONAL_SERVICES,
        'clothing' => CategoryStylePresets::RETAIL_SHOPPING,
        'florist' => CategoryStylePresets::RETAIL_SHOPPING,
        'flower' => CategoryStylePresets::RETAIL_SHOPPING,
        'jewel' => CategoryStylePresets::RETAIL_SHOPPING,
        'gift shop' => CategoryStylePresets::RETAIL_SHOPPING,
        'plumber' => CategoryStylePresets::HOME_SERVICES,
        'electrician' => CategoryStylePresets::HOME_SERVICES,
        'clean' => CategoryStylePresets::HOME_SERVICES,
        'landscap' => CategoryStylePresets::HOME_SERVICES,
        'hotel' => CategoryStylePresets::HOSPITALITY,
        'event venue' => CategoryStylePresets::HOSPITALITY,
        'event planner' => CategoryStylePresets::HOSPITALITY,
        'car repair' => CategoryStylePresets::AUTOMOTIVE,
        'auto repair' => CategoryStylePresets::AUTOMOTIVE,
        'mechanic' => CategoryStylePresets::AUTOMOTIVE,
        'car wash' => CategoryStylePresets::AUTOMOTIVE,
        'car dealer' => CategoryStylePresets::AUTOMOTIVE,
        'tutor' => CategoryStylePresets::EDUCATION_COACHING,
        'dance school' => CategoryStylePresets::EDUCATION_COACHING,
        'dance' => CategoryStylePresets::EDUCATION_COACHING,
        'driving school' => CategoryStylePresets::EDUCATION_COACHING,
        'restaurant' => CategoryStylePresets::FOOD_DRINK,
        'cafe' => CategoryStylePresets::FOOD_DRINK,
        'coffee' => CategoryStylePresets::FOOD_DRINK,
        'bakery' => CategoryStylePresets::FOOD_DRINK,
        'food truck' => CategoryStylePresets::FOOD_DRINK,
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
        // Base rank for Google Business — explicitly higher than
        // InstagramCategoryFactor's, so Google wins any contested column.
        return 50;
    }

    /** @return array<string, string> */
    public function detect(IntegrationConnection $connection): array
    {
        $category = data_get($connection->payload, 'category');
        if (! is_string($category)) {
            return [];
        }

        $bucket = CategoryStylePresets::classify($category, self::KEYWORD_BUCKETS);

        return $bucket === null ? [] : CategoryStylePresets::forBucket($bucket);
    }
}
