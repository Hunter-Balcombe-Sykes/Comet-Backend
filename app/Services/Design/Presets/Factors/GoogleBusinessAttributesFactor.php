<?php

namespace App\Services\Design\Presets\Factors;

use App\Models\Core\Site\IntegrationConnection;
use App\Services\Design\Presets\DesignFactor;
use App\Services\Design\Presets\FactorMode;
use App\Services\Platforms\Registry\Platform;

// Refinement factor: a Google Business listing's ATTRIBUTES (price level,
// live music, cocktails/wine, outdoor seating, good-for-children) narrow the
// bucket preset the type factor picked. It contributes only the few motion /
// weight columns it means to refine, at a priority just above the type factor
// (55 > 50), so those columns override the bucket while everything else
// (colours, font, radius) still comes from the bucket underneath — a
// per-column refinement, not a competing bucket.
//
// Signal → treatment (first match wins):
//   nightlife  (liveMusic OR serves.cocktails)   → energetic: stagger + fast
//   upscale    (priceLevel EXPENSIVE/VERY_…)     → refined: fade + slow + light weight
//   family     (goodForChildren AND outdoorSeating) → friendly: rise + normal
//
// Auto mode: attributes refresh with the listing (weekly Place Details pull),
// so re-detect on every resolve rather than freezing the first read.
class GoogleBusinessAttributesFactor implements DesignFactor
{
    public const SOURCE = 'google-business:attributes';

    public const INTEGRATION = Platform::GoogleBusiness->value;

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
        return FactorMode::Auto;
    }

    public function priority(): int
    {
        return 55;
    }

    /** @return array<string, string> */
    public function detect(IntegrationConnection $connection): array
    {
        $payload = $connection->payload;

        $liveMusic = data_get($payload, 'amenities.liveMusic') === true;
        $cocktails = data_get($payload, 'amenities.serves.cocktails') === true;
        if ($liveMusic || $cocktails) {
            return [
                'motion_entrance' => 'stagger',
                'motion_pace' => 'fast',
            ];
        }

        $priceLevel = data_get($payload, 'priceLevel');
        if (in_array($priceLevel, ['PRICE_LEVEL_EXPENSIVE', 'PRICE_LEVEL_VERY_EXPENSIVE'], true)) {
            return [
                'motion_entrance' => 'fade',
                'motion_pace' => 'slow',
                'weight_regular' => '300',
            ];
        }

        $children = data_get($payload, 'amenities.goodForChildren') === true;
        $outdoor = data_get($payload, 'amenities.outdoorSeating') === true;
        if ($children && $outdoor) {
            return [
                'motion_entrance' => 'rise',
                'motion_pace' => 'normal',
            ];
        }

        return [];
    }
}
