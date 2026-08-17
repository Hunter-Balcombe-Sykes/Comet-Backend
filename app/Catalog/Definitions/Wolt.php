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
 * Wolt — WLH-label ordering brand, new link-only surface. Host from
 * WebsiteLinkHarvester::ORDERING_HOSTS, verbatim.
 */
class Wolt
{
    public static function brand(): Brand
    {
        return Brand::make('wolt', 'Wolt', 'https://wolt.com');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('wolt.order')
                ->displayName('Wolt')
                ->routing(RoutingClass::Ordering)
                ->shelf(Shelf::Food)
                ->identifier(IdentifierKind::Url)
                ->refreshEvery(0)
                ->detect(
                    Detector::url('wolt.com')->strength(EvidenceStrength::ProfileLink),
                )
                ->build(),
        ];
    }
}
