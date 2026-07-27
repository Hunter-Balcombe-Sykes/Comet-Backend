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
 * Acuity Scheduling. New link-only surface — today a
 * WebsiteLinkHarvester::BOOKING_HOSTS label (WebsiteLinkHarvester.php:116)
 * that collapses into the generic 'booking' pseudo-bucket; this surface is
 * the P1 upgrade to a first-class brand. No config entry, no registered
 * ConnectStrategy — host-only detector, no capture.
 */
class Acuity
{
    public static function brand(): Brand
    {
        return Brand::make('acuity', 'Acuity Scheduling', 'https://www.acuityscheduling.com');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('acuity.book')
                ->displayName('Acuity Scheduling')
                ->routing(RoutingClass::Booking)
                ->shelf(Shelf::Booking)
                ->identifier(IdentifierKind::Url)
                ->refreshEvery(0)
                ->detect(
                    Detector::url('acuityscheduling.com')->strength(EvidenceStrength::ProfileLink),
                )
                ->build(),
        ];
    }
}
