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
 * Laylo — fan drops and release/tour notifications, link-only (2026-08-28).
 * Events-class rather than Content: a Laylo page is a dated drop or on-sale
 * an artist points fans at. ProfileLink — laylo.com/<artist>/… is the
 * artist's own page, like the smart-link brands.
 */
class Laylo
{
    public static function brand(): Brand
    {
        return Brand::make('laylo', 'Laylo', 'https://www.laylo.com');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('laylo.drop')
                ->displayName('Laylo')
                ->multiAccount(10)
                ->routing(RoutingClass::Events)
                ->shelf(Shelf::Music)
                ->identifier(IdentifierKind::Url)
                // Detect-only: links arrive from scanned bios and site scans,
                // and the legacy harvester carries no classify() entry, so a
                // manual connect card would 422 its own URL (BrandCoverageTest).
                ->notConnectable()
                ->refreshEvery(0)
                ->detect(
                    Detector::url('laylo.com')->strength(EvidenceStrength::ProfileLink),
                )
                ->build(),
        ];
    }
}
