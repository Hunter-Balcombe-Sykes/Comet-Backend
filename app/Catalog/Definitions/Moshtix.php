<?php

namespace App\Catalog\Definitions;

use App\Catalog\Brand;
use App\Catalog\Detector;
use App\Catalog\Enums\EvidenceStrength;
use App\Catalog\Enums\IdentifierKind;
use App\Catalog\Enums\RoutingClass;
use App\Catalog\Enums\Shelf;
use App\Catalog\Surface;
use App\Catalog\SurfaceBuilder;

/**
 * Moshtix — AU ticketing (wave 2, 2026-08-28), the Ticketek shape: a
 * moshtix link on a bio is an event/venue marketplace listing, host-level
 * detect at MarketplaceListing strength. No artist entity pages exist.
 * Verified example: moshtix.com.au/v2/venues/lazybones-lounge-…/7848 (200).
 */
class Moshtix
{
    public static function brand(): Brand
    {
        return Brand::make('moshtix', 'Moshtix', 'https://www.moshtix.com.au');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('moshtix.tickets')
                ->displayName('Moshtix')
                ->multiAccount(10)
                ->routing(RoutingClass::Events)
                ->shelf(Shelf::Events)
                ->identifier(IdentifierKind::Url)
                // Detect-only (wave 2): links route from scanned bios; the legacy
                // harvester carries no classify() entry, so a manual connect card
                // would 422 its own URL (BrandCoverageTest). Card comes later with
                // harvester support, if ever needed.
                ->notConnectable()
                ->refreshEvery(0)
                ->detect(
                    Detector::url('moshtix.com.au')
                        ->strength(EvidenceStrength::MarketplaceListing),
                )
                ->build(),
        ];
    }
}
