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
 * Obee — restaurant reservations and vouchers, link-only (2026-08-28).
 * Found on Bar Liberty's site scan as vouchers.obeeapp.com/<venue>/…; the
 * product also serves obee.com.au. The Resy shape: Reservations + Food.
 */
class Obee
{
    public static function brand(): Brand
    {
        return Brand::make('obee', 'Obee', 'https://www.obee.com.au');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('obee.reserve')
                ->displayName('Obee')
                ->multiAccount(10)
                ->routing(RoutingClass::Reservations)
                ->shelf(Shelf::Food)
                ->identifier(IdentifierKind::Url)
                // Detect-only: links arrive from scanned bios and site scans,
                // and the legacy harvester carries no classify() entry, so a
                // manual connect card would 422 its own URL (BrandCoverageTest).
                ->notConnectable()
                ->refreshEvery(0)
                ->detect(
                    Detector::url('obeeapp.com')->strength(EvidenceStrength::ProfileLink),
                    Detector::url('obee.com.au')->strength(EvidenceStrength::ProfileLink),
                )
                ->build(),
        ];
    }
}
