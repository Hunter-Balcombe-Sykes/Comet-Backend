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
 * Setmore — WLH-label booking brand, new link-only surface. Host from
 * WebsiteLinkHarvester::BOOKING_HOSTS (verbatim). setmore.com is also a
 * Hosts.php multi-tenant suffix override (tenant booking pages commonly live
 * at <tenant>.setmore.com) — no per-tenant capture was instructed for this
 * brand, so a plain host detector is used, consistent with the other
 * suffix-override brands in this half (timely/gettimely.com). See sidecar
 * AMBIGUOUS notes.
 */
class Setmore
{
    public static function brand(): Brand
    {
        return Brand::make('setmore', 'Setmore', 'https://www.setmore.com');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('setmore.book')
                ->displayName('Setmore')
                ->routing(RoutingClass::Booking)
                ->shelf(Shelf::Booking)
                ->identifier(IdentifierKind::Url)
                ->refreshEvery(0)
                ->detect(
                    Detector::url('setmore.com')->strength(EvidenceStrength::ProfileLink),
                )
                ->build(),
        ];
    }
}
