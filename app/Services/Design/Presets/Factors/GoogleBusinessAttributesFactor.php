<?php

namespace App\Services\Design\Presets\Factors;

use App\Models\Core\Site\IntegrationConnection;
use App\Services\Design\Presets\DesignFactor;
use App\Services\Design\Presets\FactorMode;
use App\Services\Platforms\Registry\Platform;

// Refinement factor: a Google Business listing's ATTRIBUTES (price level,
// live music, cocktails/wine, outdoor seating, good-for-children) narrow the
// bucket preset the type factor picked. It contributes only the few columns it
// means to refine, at a priority in band D (refiners, 50-59 per factors-engine
// spec §4) just above the band-C type factor (52 > 40), so those columns
// override the bucket while everything else (accent, font) still comes from
// the bucket underneath — a per-column refinement, not a competing bucket.
//
// Signal → treatment (first match wins; entrance animation removed 2026-07-10,
// each signal now leans pace plus ONE material/shape axis so the refinement
// stays legible without it):
//   nightlife  (liveMusic OR serves.cocktails)      → energetic: fast + glass
//                (layered back-bar translucency — the night-venue material)
//   upscale    (priceLevel EXPENSIVE/VERY_…)        → refined: slow + light
//                weight + outline (the premium restraint language shared with
//                the fine-dining recipe and the store luxury tier)
//   family     (goodForChildren AND outdoorSeating) → friendly: normal +
//                rounded corners (the legible safe/approachable cue)
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
        // Band D (refiners) — see class docblock.
        return 52;
    }

    /** @return array<string, string> */
    public function detect(IntegrationConnection $connection): array
    {
        $payload = $connection->payload;

        $liveMusic = data_get($payload, 'amenities.liveMusic') === true;
        $cocktails = data_get($payload, 'amenities.serves.cocktails') === true;
        if ($liveMusic || $cocktails) {
            return [
                'motion_pace' => 'fast',
                'effect_surface' => 'glass',
            ];
        }

        $priceLevel = data_get($payload, 'priceLevel');
        if (in_array($priceLevel, ['PRICE_LEVEL_EXPENSIVE', 'PRICE_LEVEL_VERY_EXPENSIVE'], true)) {
            return [
                'motion_pace' => 'slow',
                'weight_regular' => '300',
                'effect_surface' => 'outline',
            ];
        }

        $children = data_get($payload, 'amenities.goodForChildren') === true;
        $outdoor = data_get($payload, 'amenities.outdoorSeating') === true;
        if ($children && $outdoor) {
            return [
                'motion_pace' => 'normal',
                'border_radius' => '1rem',
            ];
        }

        return [];
    }
}
