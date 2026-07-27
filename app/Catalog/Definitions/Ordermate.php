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
 * OrderMate — WLH-label ordering brand, new link-only surface. Host
 * ("ordermate.online") from WebsiteLinkHarvester::ORDERING_HOSTS, verbatim.
 */
class Ordermate
{
    public static function brand(): Brand
    {
        return Brand::make('ordermate', 'OrderMate', 'https://www.ordermate.online');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('ordermate.order')
                ->displayName('OrderMate')
                ->routing(RoutingClass::Ordering)
                ->shelf(Shelf::Food)
                ->identifier(IdentifierKind::Url)
                ->refreshEvery(0)
                ->notConnectable()
                ->detect(
                    Detector::url('ordermate.online')->strength(EvidenceStrength::ProfileLink),
                )
                ->build(),
        ];
    }
}
