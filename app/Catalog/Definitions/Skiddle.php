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
 * Skiddle — UK events directory (wave 2, 2026-08-28), the Ticketek shape.
 * /artists/ is a genre browser, not per-artist pages, so no path capture
 * exists to build on. Verified example: skiddle.com/venues/1/ (bot-403).
 */
class Skiddle
{
    public static function brand(): Brand
    {
        return Brand::make('skiddle', 'Skiddle', 'https://www.skiddle.com');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('skiddle.tickets')
                ->displayName('Skiddle')
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
                    Detector::url('skiddle.com')
                        ->strength(EvidenceStrength::MarketplaceListing),
                )
                ->build(),
        ];
    }
}
