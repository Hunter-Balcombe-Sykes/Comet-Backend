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
 * Trustpilot — link-only review listing (wave 2, 2026-08-28). The
 * identifier is the reviewed company's own registrable domain (dots and
 * all). Verified example: au.trustpilot.com/review/www.cheaperdomains.com.au
 * — regional hosts are subdomains, covered by the registrable key.
 */
class Trustpilot
{
    public static function brand(): Brand
    {
        return Brand::make('trustpilot', 'Trustpilot', 'https://www.trustpilot.com');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('trustpilot.listing')
                ->displayName('Trustpilot')
                ->routing(RoutingClass::Social)
                ->shelf(Shelf::Business)
                ->identifier(IdentifierKind::Url)
                // Detect-only (wave 2): links route from scanned bios; the legacy
                // harvester carries no classify() entry, so a manual connect card
                // would 422 its own URL (BrandCoverageTest). Card comes later with
                // harvester support, if ever needed.
                ->notConnectable()
                ->refreshEvery(0)
                ->detect(
                    Detector::url('trustpilot.com')
                        ->path('#^/review/(?<handle>[a-z0-9.-]+\.[a-z]{2,24})/?$#i')
                        ->captures('handle')
                        ->from(IdentifierSource::Path)
                        ->strength(EvidenceStrength::ProfileLink),
                )
                ->build(),
        ];
    }
}
