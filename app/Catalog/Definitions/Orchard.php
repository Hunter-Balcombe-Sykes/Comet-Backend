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
 * The Orchard — orcd.co smart links, link-only (2026-08-28). Narrower than
 * its Linkfire/Feature.fm siblings: orcd.co is not open signup, it is issued
 * to artists The Orchard distributes. Still a genuine multi-tenant host, and
 * it turned up in the triage queue with real hits.
 */
class Orchard
{
    public static function brand(): Brand
    {
        return Brand::make('orchard', 'The Orchard', 'https://www.theorchard.com');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('orchard.release')
                ->displayName('The Orchard')
                ->multiAccount(10)
                ->routing(RoutingClass::Content)
                ->shelf(Shelf::Music)
                ->identifier(IdentifierKind::Url)
                // Detect-only: links arrive from scanned bios and site scans,
                // and the legacy harvester carries no classify() entry, so a
                // manual connect card would 422 its own URL (BrandCoverageTest).
                ->notConnectable()
                ->refreshEvery(0)
                ->detect(
                    Detector::url('orcd.co')->strength(EvidenceStrength::ProfileLink),
                )
                ->build(),
        ];
    }
}
