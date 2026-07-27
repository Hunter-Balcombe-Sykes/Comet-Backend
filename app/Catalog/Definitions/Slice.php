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
 * Slice — WLH-label ordering brand, new link-only surface. Real host is
 * slicelife.com, NOT slice.com (WebsiteLinkHarvester::ORDERING_HOSTS,
 * verbatim).
 */
class Slice
{
    public static function brand(): Brand
    {
        return Brand::make('slice', 'Slice', 'https://slicelife.com');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('slice.order')
                ->displayName('Slice')
                ->routing(RoutingClass::Ordering)
                ->shelf(Shelf::Food)
                ->identifier(IdentifierKind::Url)
                ->refreshEvery(0)
                ->notConnectable()
                ->detect(
                    Detector::url('slicelife.com')->strength(EvidenceStrength::ProfileLink),
                )
                ->build(),
        ];
    }
}
