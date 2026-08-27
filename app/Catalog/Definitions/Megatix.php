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
 * Megatix — AU ticketing (wave 2, 2026-08-28), the Ticketek shape.
 * Only event pages exist (no organiser entity page — verified 2026-08-28),
 * so host-level MarketplaceListing detect is the whole grammar.
 */
class Megatix
{
    public static function brand(): Brand
    {
        return Brand::make('megatix', 'Megatix', 'https://megatix.com.au');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('megatix.tickets')
                ->displayName('Megatix')
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
                    Detector::url('megatix.com.au')
                        ->strength(EvidenceStrength::MarketplaceListing),
                )
                ->build(),
        ];
    }
}
