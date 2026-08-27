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
 * Eventfinda — AU/NZ events directory (wave 2, 2026-08-28), the Ticketek
 * shape across its regional TLDs. Verified example:
 * eventfinda.com.au/venue/boutique-nightclub-melbourne (200).
 */
class Eventfinda
{
    public static function brand(): Brand
    {
        return Brand::make('eventfinda', 'Eventfinda', 'https://www.eventfinda.com.au');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('eventfinda.tickets')
                ->displayName('Eventfinda')
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
                    Detector::url('eventfinda.com.au')
                        ->strength(EvidenceStrength::MarketplaceListing),
                    Detector::url('eventfinda.co.nz')
                        ->strength(EvidenceStrength::MarketplaceListing),
                    Detector::url('eventfinda.com')
                        ->strength(EvidenceStrength::MarketplaceListing),
                )
                ->build(),
        ];
    }
}
