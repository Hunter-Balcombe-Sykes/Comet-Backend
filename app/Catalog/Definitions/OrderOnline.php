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
 * Order Online — WLH-label ordering brand, new link-only surface. Host
 * ("order.online", a .online gTLD registrable domain) from
 * WebsiteLinkHarvester::ORDERING_HOSTS, verbatim.
 */
class OrderOnline
{
    public static function brand(): Brand
    {
        return Brand::make('order_online', 'Order Online', 'https://www.order.online');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('order_online.order')
                ->displayName('Order Online')
                ->routing(RoutingClass::Ordering)
                ->shelf(Shelf::Food)
                ->identifier(IdentifierKind::Url)
                ->refreshEvery(0)
                ->notConnectable()
                ->detect(
                    Detector::url('order.online')->strength(EvidenceStrength::ProfileLink),
                )
                ->build(),
        ];
    }
}
