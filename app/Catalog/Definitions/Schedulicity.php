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
 * Schedulicity — WLH-label booking brand, new link-only surface. Host from
 * WebsiteLinkHarvester::BOOKING_HOSTS (verbatim); since Phase 6 WLH returns
 * this surface key ('schedulicity.book') rather than a generic 'booking' bucket.
 */
class Schedulicity
{
    public static function brand(): Brand
    {
        return Brand::make('schedulicity', 'Schedulicity', 'https://www.schedulicity.com');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('schedulicity.book')
                ->displayName('Schedulicity')
                ->routing(RoutingClass::Booking)
                ->shelf(Shelf::Booking)
                ->identifier(IdentifierKind::Url)
                ->refreshEvery(0)
                ->detect(
                    Detector::url('schedulicity.com')->strength(EvidenceStrength::ProfileLink),
                )
                ->build(),
        ];
    }
}
