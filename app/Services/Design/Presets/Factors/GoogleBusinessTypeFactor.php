<?php

namespace App\Services\Design\Presets\Factors;

use App\Models\Core\Site\IntegrationConnection;
use App\Services\Design\Presets\DesignFactor;
use App\Services\Design\Presets\FactorMode;

// One-shot factor: a Google Business connection that is a restaurant applies the
// restaurant design preset. Any other business type — or an unknown/missing
// category — contributes nothing, so the sitepage keeps the package defaults.
//
// The business type lands in the connection payload as `category` (the Place's
// primaryTypeDisplayName, e.g. "Restaurant", "Italian restaurant"), written
// SYNCHRONOUSLY at connect via fetchPlaceDetails — so the connect observer
// resolves this without waiting on the async Apify enrichment (which uses
// saveQuietly and bypasses the observer).
class GoogleBusinessTypeFactor implements DesignFactor
{
    public const SOURCE = 'google-business:type';

    public const INTEGRATION = 'google-business';

    // Restaurant preset: off-white bg, reddish-orange accent, small light body
    // text, very small radius, Forma DJR, quick animations. Everything else —
    // the grey/contrast palette, the text/weight/space scales, theme mode —
    // derives automatically from these at render time.
    private const RESTAURANT_PRESET = [
        'color_bg' => '#f7f4ee',
        'color_accent' => '#e0491f',
        'text_xs' => '0.8rem',
        'weight_regular' => '300',
        'border_radius' => '0.25rem',
        'typography_font_family' => 'forma-djr',
        'motion_pace' => 'fast',
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
        // Base rank for Google Business. Uncontested with a single factor; a
        // global integration ranking gets centralised once a second factor exists.
        return 50;
    }

    /** @return array<string, string> */
    public function detect(IntegrationConnection $connection): array
    {
        $category = data_get($connection->payload, 'category');

        if (! is_string($category) || ! str_contains(strtolower($category), 'restaurant')) {
            return [];
        }

        return self::RESTAURANT_PRESET;
    }
}
