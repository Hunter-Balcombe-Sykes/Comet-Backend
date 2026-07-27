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
 * Eat App. New link-only surface — today a
 * WebsiteLinkHarvester::RESERVATION_HOSTS label ('Eat App',
 * WebsiteLinkHarvester.php:67) that collapses into the generic
 * 'reservations' pseudo-bucket; this surface is the P1 upgrade to a
 * first-class brand. Host-only detector, no capture.
 */
class EatApp
{
    public static function brand(): Brand
    {
        return Brand::make('eat_app', 'Eat App', 'https://www.eatapp.co');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('eat_app.reserve')
                ->displayName('Eat App')
                ->routing(RoutingClass::Reservations)
                ->shelf(Shelf::Food)
                ->identifier(IdentifierKind::Url)
                ->refreshEvery(0)
                ->detect(
                    Detector::url('eatapp.co')->strength(EvidenceStrength::ProfileLink),
                )
                ->build(),
        ];
    }
}
