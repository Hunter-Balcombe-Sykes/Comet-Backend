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
 * Tixr — ticketing, link-only (2026-08-28). Host-level, the Moshtix shape.
 */
class Tixr
{
    public static function brand(): Brand
    {
        return Brand::make('tixr', 'Tixr', 'https://www.tixr.com');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('tixr.tickets')
                ->displayName('Tixr')
                ->multiAccount(10)
                ->routing(RoutingClass::Events)
                ->shelf(Shelf::Events)
                ->identifier(IdentifierKind::Url)
                // Detect-only: links arrive from scanned bios and site scans,
                // and the legacy harvester carries no classify() entry, so a
                // manual connect card would 422 its own URL (BrandCoverageTest).
                ->notConnectable()
                ->refreshEvery(0)
                ->detect(
                    Detector::url('tixr.com')->strength(EvidenceStrength::MarketplaceListing),
                )
                ->build(),
        ];
    }
}
