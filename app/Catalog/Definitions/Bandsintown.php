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
 * Bandsintown — link-only artist events page (wave 2, 2026-08-28).
 * /a/{numeric-id} with an optional slug tail. Verified example:
 * bandsintown.com/a/445444-timmy-trumpet (bot-403 but form-true).
 */
class Bandsintown
{
    public static function brand(): Brand
    {
        return Brand::make('bandsintown', 'Bandsintown', 'https://www.bandsintown.com');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('bandsintown.artist')
                ->displayName('Bandsintown')
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
                ->canonicalUrl('https://www.bandsintown.com/a/{id}')
                ->detect(
                    Detector::url('bandsintown.com')
                        ->path('#^/a/(?<id>\d{1,12})(?:-[a-z0-9-]+)?/?$#i')
                        ->captures('id')
                        ->from(IdentifierSource::Path)
                        ->strength(EvidenceStrength::ProfileLink),
                )
                ->build(),
        ];
    }
}
