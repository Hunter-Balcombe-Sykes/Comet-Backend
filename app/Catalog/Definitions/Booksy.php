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
 * Booksy. One of the 27-provider stopgap set (PICR.php:178-222) —
 * detect-only card, no connect/fetch, host verbatim from PRSP:429.
 * WebsiteLinkHarvester also matches booksy.com and agrees with this key:
 * Phase 6 split BOOKING_PLATFORM per brand, so it returns 'booksy'. (It used
 * to collapse to a generic 'booking' pseudo-platform; that is no longer true.)
 */
class Booksy
{
    public static function brand(): Brand
    {
        return Brand::make('booksy', 'Booksy', 'https://booksy.com');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('booksy.book')
                ->legacyPlatform('booksy')
                ->displayName('Booksy')
                ->routing(RoutingClass::Booking)
                ->shelf(Shelf::Booking)
                ->identifier(IdentifierKind::Url)
                ->refreshEvery(0)
                ->detect(
                    Detector::url('booksy.com')->strength(EvidenceStrength::ProfileLink),
                )
                ->build(),
        ];
    }
}
