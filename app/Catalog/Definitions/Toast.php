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
 * Toast — WLH-label ordering brand ("Toast Takeout" in WLH's label set), new
 * link-only surface. Real host is toasttab.com, NOT toast.com
 * (WebsiteLinkHarvester::ORDERING_HOSTS, verbatim).
 */
class Toast
{
    public static function brand(): Brand
    {
        return Brand::make('toast', 'Toast', 'https://pos.toasttab.com');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('toast.order')
                ->displayName('Toast')
                ->routing(RoutingClass::Ordering)
                ->shelf(Shelf::Food)
                ->identifier(IdentifierKind::Url)
                ->refreshEvery(0)
                ->detect(
                    Detector::url('toasttab.com')->strength(EvidenceStrength::ProfileLink),
                )
                ->build(),
        ];
    }
}
