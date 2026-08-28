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
 * Heyzine — PDF-to-flipbook publishing, link-only (2026-08-28). Found on
 * Readings' site scan as readings.hflip.co/RM-JUN26.html — their monthly
 * magazine. hflip.co is Heyzine's short-link host, not a separate brand,
 * which is exactly why it needed checking before it got its own entry.
 */
class Heyzine
{
    public static function brand(): Brand
    {
        return Brand::make('heyzine', 'Heyzine', 'https://heyzine.com');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('heyzine.publication')
                ->displayName('Heyzine')
                ->multiAccount(10)
                ->routing(RoutingClass::Content)
                ->shelf(Shelf::Media)
                ->identifier(IdentifierKind::Url)
                // Detect-only: links arrive from scanned bios and site scans,
                // and the legacy harvester carries no classify() entry, so a
                // manual connect card would 422 its own URL (BrandCoverageTest).
                ->notConnectable()
                ->refreshEvery(0)
                ->detect(
                    Detector::url('heyzine.com')->strength(EvidenceStrength::ProfileLink),
                    Detector::url('hflip.co')->strength(EvidenceStrength::ProfileLink),
                )
                ->build(),
        ];
    }
}
