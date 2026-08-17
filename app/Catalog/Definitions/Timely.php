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
 * Timely — 27-set booking, detect-only. Real host is gettimely.com, not
 * timely.com (PRSP:433's HostMatch, verbatim). gettimely.com is also a
 * Hosts.php multi-tenant suffix override, but no per-tenant capture was
 * instructed — plain host detector, consistent with the other 27-set brands.
 */
class Timely
{
    public static function brand(): Brand
    {
        return Brand::make('timely', 'Timely', 'https://www.gettimely.com');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('timely.book')
                ->displayName('Timely')
                ->routing(RoutingClass::Booking)
                ->shelf(Shelf::Booking)
                ->identifier(IdentifierKind::Url)
                ->refreshEvery(0)
                ->detect(
                    Detector::url('gettimely.com')->strength(EvidenceStrength::ProfileLink),
                )
                ->build(),
        ];
    }
}
