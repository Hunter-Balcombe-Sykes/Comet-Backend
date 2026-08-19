<?php

namespace App\Catalog\Definitions;

use App\Catalog\Brand;
use App\Catalog\Detector;
use App\Catalog\Enums\EvidenceStrength;
use App\Catalog\Enums\IdentifierKind;
use App\Catalog\Enums\IdentifierSource;
use App\Catalog\Enums\RoutingClass;
use App\Catalog\Enums\Shelf;
use App\Catalog\Surface;
use App\Catalog\SurfaceBuilder;

/**
 * Stan — config-only brand (config('partna.social_platforms.stan'), no
 * PlatformRegistry entry, no WLH entry at all). Routes as Shop (a creator
 * storefront), shelved under Education per the explicit brief override.
 * Handle grammar + path extractor come straight from that config entry
 * (stan.store/{handle}), the one authoritative source for this brand.
 */
class Stan
{
    public static function brand(): Brand
    {
        return Brand::make('stan', 'Stan', 'https://www.stan.store');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('stan.store')
                // F7 (2026-08-20): in lockstep with the shop family's
                // MAX_BRANDS (10, T9) — the catalog's default of 1 was
                // blocking Engine-1 store placements at ONE store while every
                // other door allowed ten (caught live: the046.com).
                ->multiAccount(10)
                ->displayName('Stan')
                ->routing(RoutingClass::Shop)
                ->shelf(Shelf::Education)
                ->identifier(IdentifierKind::Handle)
                ->refreshEvery(0)
                ->note('stan.store creator storefront')
                ->canonicalUrl('https://stan.store/{handle}')
                ->notConnectable()
                ->detect(
                    Detector::url('stan.store')
                        ->path('#^/(?<handle>[a-zA-Z0-9_-]{2,40})/?$#')
                        ->captures('handle')
                        ->from(IdentifierSource::Path)
                        ->strength(EvidenceStrength::ProfileLink),
                )
                ->build(),
        ];
    }
}
