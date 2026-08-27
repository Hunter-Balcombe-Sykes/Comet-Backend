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
 * Boulevard. One of the 27-provider stopgap set (PICR.php:178-222) —
 * detect-only card, no connect/fetch, host verbatim from PRSP:467-470.
 * WebsiteLinkHarvester also matches boulevard.io and agrees with this key —
 * Phase 6 split BOOKING_PLATFORM per brand, so it returns 'boulevard'.
 */
class Boulevard
{
    public static function brand(): Brand
    {
        return Brand::make('boulevard', 'Boulevard', 'https://www.boulevard.io');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('boulevard.book')
                ->legacyPlatform('boulevard')
                ->displayName('Boulevard')
                ->routing(RoutingClass::Booking)
                ->shelf(Shelf::Booking)
                ->identifier(IdentifierKind::Url)
                ->refreshEvery(0)
                ->detect(
                    Detector::url('boulevard.io')->strength(EvidenceStrength::ProfileLink),
                    // joinblvd.com: the live customer booking-widget host —
                    // dashboard.boulevard.io 301s onto it (plan-03 batch 5,
                    // verified against a real medspa's own shared link).
                    Detector::url('joinblvd.com')->strength(EvidenceStrength::ProfileLink),
                )
                ->build(),
        ];
    }
}
