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
 * Chope. New link-only surface — today a
 * WebsiteLinkHarvester::RESERVATION_HOSTS label
 * (WebsiteLinkHarvester.php:65) that collapses into the generic
 * 'reservations' pseudo-bucket; this surface is the P1 upgrade to a
 * first-class brand. No config entry — host-only detector, no capture.
 */
class Chope
{
    public static function brand(): Brand
    {
        return Brand::make('chope', 'Chope', 'https://www.chope.co');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('chope.reserve')
                ->displayName('Chope')
                ->routing(RoutingClass::Reservations)
                ->shelf(Shelf::Food)
                ->identifier(IdentifierKind::Url)
                ->refreshEvery(0)
                ->detect(
                    Detector::url('chope.co')->strength(EvidenceStrength::ProfileLink),
                )
                ->build(),
        ];
    }
}
