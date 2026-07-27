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
 * SimplyBook.me — WLH-label booking brand, new link-only surface. Host
 * ("simplybook.me") from WebsiteLinkHarvester::BOOKING_HOSTS, verbatim; also
 * a Hosts.php multi-tenant suffix override — see Setmore's identical note.
 */
class SimplybookMe
{
    public static function brand(): Brand
    {
        return Brand::make('simplybook_me', 'SimplyBook.me', 'https://simplybook.me');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('simplybook_me.book')
                ->displayName('SimplyBook.me')
                ->routing(RoutingClass::Booking)
                ->shelf(Shelf::Booking)
                ->identifier(IdentifierKind::Url)
                ->refreshEvery(0)
                ->notConnectable()
                ->detect(
                    Detector::url('simplybook.me')->strength(EvidenceStrength::ProfileLink),
                )
                ->build(),
        ];
    }
}
