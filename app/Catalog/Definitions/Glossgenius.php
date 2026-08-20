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
 * GlossGenius. One of the 27-provider stopgap set (PICR.php:178-222) —
 * detect-only card, no connect/fetch, host verbatim from PRSP:467-470.
 * WebsiteLinkHarvester also matches glossgenius.com and agrees with this key —
 * Phase 6 split BOOKING_PLATFORM per brand, so it returns 'glossgenius'.
 */
class Glossgenius
{
    public static function brand(): Brand
    {
        return Brand::make('glossgenius', 'GlossGenius', 'https://www.glossgenius.com');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('glossgenius.book')
                ->legacyPlatform('glossgenius')
                ->displayName('GlossGenius')
                ->routing(RoutingClass::Booking)
                ->shelf(Shelf::Booking)
                ->identifier(IdentifierKind::Url)
                ->refreshEvery(0)
                ->detect(
                    Detector::url('glossgenius.com')->strength(EvidenceStrength::ProfileLink),
                )
                ->build(),
        ];
    }
}
