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
 * Menulog — WLH-label ordering brand, new link-only surface. Host from
 * WebsiteLinkHarvester::ORDERING_HOSTS (verbatim); WLH itself never writes a
 * distinct 'menulog' platform value — every ordering-bucket host collapses to
 * the generic 'online-ordering' pseudo-platform today (WLH:352).
 */
class Menulog
{
    public static function brand(): Brand
    {
        return Brand::make('menulog', 'Menulog', 'https://www.menulog.com.au');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('menulog.order')
                ->displayName('Menulog')
                ->routing(RoutingClass::Ordering)
                ->shelf(Shelf::Food)
                ->identifier(IdentifierKind::Url)
                ->refreshEvery(0)
                ->notConnectable()
                ->detect(
                    Detector::url('menulog.com.au')->strength(EvidenceStrength::ProfileLink),
                )
                ->build(),
        ];
    }
}
