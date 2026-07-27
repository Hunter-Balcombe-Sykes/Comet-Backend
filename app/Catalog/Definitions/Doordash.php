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
 * DoorDash. New link-only surface — today a
 * WebsiteLinkHarvester::ORDERING_HOSTS label
 * (WebsiteLinkHarvester.php:90) that collapses into the generic
 * 'online-ordering' pseudo-bucket (also present verbatim in
 * config('partna.menu.platforms').doordash's host_pattern,
 * config/partna.php:857); this surface is the P1 upgrade to a first-class
 * brand. Host-only detector, no capture.
 */
class Doordash
{
    public static function brand(): Brand
    {
        return Brand::make('doordash', 'DoorDash', 'https://www.doordash.com');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('doordash.order')
                ->displayName('DoorDash')
                ->routing(RoutingClass::Ordering)
                ->shelf(Shelf::Food)
                ->identifier(IdentifierKind::Url)
                ->refreshEvery(0)
                ->detect(
                    Detector::url('doordash.com')->strength(EvidenceStrength::ProfileLink),
                )
                ->build(),
        ];
    }
}
